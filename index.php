<?php
// Azure Vision API 設定
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$results = [];
$debug_log = "";

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
        $debug_log .= "--- File: $fileName ---\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        $currentFileItems = [];
        $foundTotalAmount = 0;
        $stopScanning = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);

            // 1. 合計金額の検出 (「合計」または「計」)
            if ((mb_strpos($text, '計') !== false) && !mb_strpos($text, '消費税')) {
                $searchArea = $text . ($lines[$i+1]['text'] ?? '') . ($lines[$i+2]['text'] ?? '');
                if (preg_match('/[¥￥]\s?([\d,]+)/u', $searchArea, $m)) {
                    $val = (int)str_replace(',', '', $m[1]);
                    // 合計金額は通常、単価より大きいため、最大値を保持
                    if ($val > $foundTotalAmount) $foundTotalAmount = $val;
                    $stopScanning = true; 
                }
            }

            if ($stopScanning) continue;

            // 2. 商品名と価格の抽出
            if (!preg_match('/[¥￥]/u', $text) && 
                !preg_match('/Family|新宿|電話|登録|2024|レジ|領収|証|対象/u', $text) &&
                mb_strlen($text) >= 2) {
                
                if (isset($lines[$i + 1])) {
                    $nextText = $lines[$i + 1]['text'];
                    // 金額行の判定を厳格化
                    if (preg_match('/[¥￥]\s?([\d,]+)/u', $nextText, $matches)) {
                        $price = (int)str_replace(',', '', $matches[1]);
                        $cleanName = str_replace(['＊', '*', '軽'], '', $text);
                        $currentFileItems[] = ['name' => trim($cleanName), 'price' => $price];
                        $i++; 
                        continue;
                    }
                }
            }
        }
        $results[] = ['file' => $fileName, 'items' => $currentFileItems, 'total' => $foundTotalAmount];
    }
    // ログをセッション等に保存（簡易的にファイル出力も可）
    file_put_contents("ocr_log.txt", $debug_log);
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>コンビニレシート解析システム</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 700px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .receipt-card { border-left: 6px solid #00a95c; background: #f9f9f9; padding: 15px; margin-top: 20px; border-radius: 4px; }
        .item-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #ddd; }
        .total-row { font-size: 1.5em; font-weight: bold; color: #d32f2f; text-align: right; margin-top: 10px; }
        .btn { padding: 12px; background: #0078d4; color: white; border: none; border-radius: 5px; cursor: pointer; width: 100%; font-size: 16px; }
        .footer-links { margin-top: 20px; border-top: 1px solid #eee; padding-top: 15px; }
        .footer-links a { margin-right: 15px; color: #0078d4; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <h2>レシート解析システム</h2>
        <form action="" method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple accept="image/*"><br><br>
            <button type="submit" class="btn">解析実行</button>
        </form>

        <?php if (!empty($results)): ?>
            <hr>
            <h3>解析結果</h3>
            <?php foreach ($results as $res): ?>
                <div class="receipt-card">
                    <small style="color: #666;">📄 <?php echo htmlspecialchars($res['file']); ?></small>
                    <?php foreach ($res['items'] as $item): ?>
                        <div class="item-row">
                            <span><?php echo htmlspecialchars($item['name']); ?></span>
                            <span>¥<?php echo number_format($item['price']); ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="total-row">合計 ¥<?php echo number_format($res['total']); ?></div>
                </div>
            <?php endforeach; ?>

            <div class="footer-links">
                <a href="#" onclick="alert('CSV出力機能をここに実装可能です')">CSVダウンロード</a> | 
                <a href="ocr_log.txt" target="_blank">ログ確認</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
