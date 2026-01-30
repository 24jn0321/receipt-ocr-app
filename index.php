<?php
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

// --- ダウンロード処理 ---
if (isset($_GET['download'])) {
    if ($_GET['download'] == 'log') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename=ocr_log.txt');
        echo file_exists('ocr_log.txt') ? file_get_contents('ocr_log.txt') : "ログがありません。";
        exit;
    }
}

$results = [];
$all_extracted_text = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        $fileName = $_FILES['receipts']['name'][$key];

        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
        $imageData = file_get_contents($tmpName);

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imageData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $all_extracted_text .= "=== File: $fileName ===\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        $items = [];
        $total = 0;
        $scan_finished = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);

            // 1. 商品名と単価の抽出（赤枠部分のロジック）
            // 「証」や「領収」などはスキップし、商品名らしき行を探す
            if (!$scan_finished && 
                !preg_match('/Family|新宿|電話|登録|2024|領収|証|レジ|貴No/u', $text) && 
                mb_strlen($text) > 2) {
                
                // 次の行、または同じ行に単価（¥数字）があるか確認
                $price_line = "";
                if (preg_match('/[¥￥]\s?(\d+)/u', $text, $m)) {
                    $price_line = $text;
                } elseif (isset($lines[$i+1]) && preg_match('/[¥￥]\s?(\d+)/u', $lines[$i+1]['text'], $m)) {
                    $price_line = $lines[$i+1]['text'];
                    $i++; // 次の行を消費
                }

                if ($price_line) {
                    preg_match('/[¥￥]\s?([\d,]+)/u', $price_line, $m);
                    $val = (int)str_replace(',', '', $m[1]);
                    
                    // 「合計」の行に到達したか判定
                    if (preg_match('/合\s*計|小\s*計/u', $text)) {
                        $total = $val;
                        $scan_finished = true; // 合計以降（消費税や残高）は見ない
                    } else {
                        // 純粋な商品として追加
                        $items[] = [
                            'name' => str_replace(['＊', '*', '軽'], '', $text),
                            'price' => $val
                        ];
                    }
                }
            }
        }
        $results[] = ['file' => $fileName, 'items' => $items, 'total' => $total];
    }
    file_put_contents("ocr_log.txt", $all_extracted_text);
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>レシート一括解析</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .card { border: 1px solid #ddd; padding: 15px; margin-bottom: 20px; border-radius: 5px; border-left: 5px solid #00a95c; }
        .item { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px dashed #eee; }
        .total { text-align: right; font-size: 1.4em; font-weight: bold; color: #d32f2f; margin-top: 10px; }
        .btn { background: #0078d4; color: white; padding: 10px; border: none; width: 100%; cursor: pointer; border-radius: 4px; }
        .dl-link { display: inline-block; margin-top: 10px; color: #0078d4; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <h2>コンビニレシート一括解析</h2>
        <form action="" method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple accept="image/*"><br><br>
            <button type="submit" class="btn">3枚まとめて解析実行</button>
        </form>

        <?php if ($results): ?>
            <hr>
            <?php foreach ($results as $res): ?>
                <div class="card">
                    <small>📄 <?php echo htmlspecialchars($res['file']); ?></small>
                    <?php foreach ($res['items'] as $item): ?>
                        <div class="item">
                            <span><?php echo htmlspecialchars($item['name']); ?></span>
                            <span>¥<?php echo number_format($item['price']); ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="total">合計 ¥<?php echo number_format($res['total']); ?></div>
                </div>
            <?php endforeach; ?>
            
            <div style="text-align: center;">
                <a href="?download=log" class="dl-link">📄 ログファイルをダウンロード</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
