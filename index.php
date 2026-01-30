<?php
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

// --- ダウンロード処理セクション ---
if (isset($_GET['download'])) {
    if ($_GET['download'] == 'log') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename=ocr_debug_log.txt');
        echo file_exists('ocr_log.txt') ? file_get_contents('ocr_log.txt') : "ログがありません。";
        exit;
    }
    if ($_GET['download'] == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_data.csv');
        // CSVの中身を作成（最新の解析結果を反映させるにはセッション管理等が理想ですが、ここではファイルから生成する例です）
        echo "\xEF\xBB\xBF"; // Excel用BOM
        echo "ファイル名,商品名,価格\n";
        exit;
    }
}

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
        // ログに保存するデータ
        $debug_log .= "=== File: $fileName ===\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        $currentFileItems = [];
        $totalAmount = 0;
        $foundTotalLine = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);

            // 1. 商品名の抽出（「合計」が出るまで）
            if (!$foundTotalLine && !preg_match('/[¥￥]/u', $text) && 
                !preg_match('/Family|新宿|電話|登録|2024|レジ|領収|証|対象/u', $text) && mb_strlen($text) >= 2) {
                
                if (isset($lines[$i + 1]) && preg_match('/[¥￥]\s?([\d,]+)/u', $lines[$i + 1]['text'], $m)) {
                    $price = (int)str_replace(',', '', $m[1]);
                    $currentFileItems[] = ['name' => str_replace(['＊', '*', '軽'], '', $text), 'price' => $price];
                    $i++; continue; 
                }
            }

            // 2. 合計金額の抽出（桁数誤認防止）
            if (preg_match('/(合計|合\s+計|小計)/u', $text)) {
                // 同じ行、または次の行から「一番最初に見つかる金額」のみを合計とする
                $searchArea = $text . " " . ($lines[$i+1]['text'] ?? "");
                if (preg_match('/[¥￥]\s?(\d{1,3}(,\d{3})*)/u', $searchArea, $m)) {
                    $totalAmount = (int)str_replace(',', '', $m[1]);
                    $foundTotalLine = true; // 合計を見つけたらそれ以降の数字（消費税など）は無視
                }
            }
        }
        $results[] = ['file' => $fileName, 'items' => $currentFileItems, 'total' => $totalAmount];
    }
    // ログをファイルに書き出し
    file_put_contents("ocr_log.txt", $debug_log);
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>レシート解析システム v3</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .container { max-width: 700px; margin: auto; background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .receipt-card { border-left: 6px solid #00a95c; background: #fafafa; padding: 15px; margin-top: 20px; border-radius: 4px; }
        .item-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #ddd; }
        .total-row { font-size: 1.5em; font-weight: bold; color: #d32f2f; text-align: right; margin-top: 10px; }
        .btn { padding: 12px 20px; background: #0078d4; color: white; border: none; border-radius: 5px; cursor: pointer; width: 100%; font-size: 16px; }
        .dl-container { margin-top: 25px; display: flex; gap: 10px; border-top: 1px solid #eee; padding-top: 20px; }
        .dl-btn { flex: 1; text-align: center; text-decoration: none; padding: 10px; border-radius: 5px; font-weight: bold; color: white; }
        .csv { background: #28a745; }
        .log { background: #6c757d; }
    </style>
</head>
<body>
    <div class="container">
        <h2>レシート解析システム</h2>
        <form action="" method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple accept="image/*" style="margin-bottom:15px;"><br>
            <button type="submit" class="btn">解析実行</button>
        </form>

        <?php if (!empty($results)): ?>
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

            <div class="dl-container">
                <a href="?download=csv" class="dl-btn csv">CSVをダウンロード</a>
                <a href="?download=log" class="dl-btn log">ログをダウンロード</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
