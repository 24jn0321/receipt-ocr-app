<?php
// 1. 基本設定
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

// データベース接続情報
$serverName = "receipt-server-24jn0.database.windows.net";
$database   = "receiptdb";
$username   = "sqladmin"; 
$password   = "Abc842727925";

$logFile = 'ocr.log';
$csvFile = 'receipt_data.csv';
$results = [];

// 2. データベース接続
try {
    $dsn = "sqlsrv:server=$serverName,1433;Database=$database;LoginTimeout=30";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->exec("IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='ReceiptItems' AND xtype='U')
                CREATE TABLE ReceiptItems (
                    id INT IDENTITY(1,1) PRIMARY KEY,
                    filename NVARCHAR(255),
                    item_name NVARCHAR(255),
                    price INT,
                    is_total BIT,
                    created_at DATETIME DEFAULT GETDATE()
                )");
} catch (PDOException $e) {
    // 接続エラーが起きても画面自体は表示させるために、dieではなくエラー表示に留める
    $dbError = $e->getMessage();
}

// 3. 解析処理
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    $logData = "--- OCR Start: " . date('Y-m-d H:i:s') . " ---\n";
    $csvData = [['ファイル名', '項目名', '金額']];

    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        $fileName = $_FILES['receipts']['name'][$key];

        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-02-01-preview&features=read";
        $imageData = file_get_contents($tmpName);

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/octet-stream',
            'Ocp-Apim-Subscription-Key: ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imageData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $logData .= "File: $fileName - Response: " . $response . "\n";

        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        $currentFileItems = [];
        $extractedTotal = 0;

        foreach ($lines as $line) {
            $text = $line['content'];
            $cleanText = str_replace(['軽', '＊', '*', '◎'], '', $text);

            if (preg_match('/(.*?)\s*[¥|￥]\s*([\d,]+)/u', $cleanText, $matches)) {
                $name = trim($matches[1]);
                $price = (int)str_replace(',', '', $matches[2]);

                if (strpos($name, '合計') !== false) {
                    $extractedTotal = $price;
                } elseif (!empty($name) && $name !== "合計") {
                    $currentFileItems[] = ['name' => $name, 'price' => $price];
                    if (isset($pdo)) {
                        $stmt = $pdo->prepare("INSERT INTO ReceiptItems (filename, item_name, price, is_total) VALUES (?, ?, ?, 0)");
                        $stmt->execute([$fileName, $name, $price]);
                    }
                    $csvData[] = [$fileName, $name, $price];
                }
            }
        }
        if ($extractedTotal > 0 && isset($pdo)) {
            $stmt = $pdo->prepare("INSERT INTO ReceiptItems (filename, item_name, price, is_total) VALUES (?, '合計', ?, 1)");
            $stmt->execute([$fileName, $extractedTotal]);
            $csvData[] = [$fileName, '合計', $extractedTotal];
        }
        $results[] = ['file' => $fileName, 'items' => $currentFileItems, 'total' => $extractedTotal];
    }
    file_put_contents($logFile, $logData, FILE_APPEND);
    $fp = fopen($csvFile, 'w');
    fwrite($fp, "\xEF\xBB\xBF");
    foreach ($csvData as $row) { fputcsv($fp, $row); }
    fclose($fp);
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ファミマレシートOCR</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 600px; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin: auto; }
        .error { color: red; background: #fee; padding: 10px; border-radius: 4px; margin-bottom: 10px; }
        .result { border-bottom: 1px solid #ccc; padding: 10px 0; }
        .btn { display: inline-block; padding: 8px 16px; background: #0078d4; color: white; text-decoration: none; border-radius: 4px; border: none; cursor: pointer; }
    </style>
</head>
<body>
    <div class="container">
        <h2>レシート解析システム</h2>
        
        <?php if (isset($dbError)): ?>
            <div class="error">【DB接続警告】<?php echo htmlspecialchars($dbError); ?><br>※ファイアウォールの設定を確認してください。</div>
        <?php endif; ?>

        <form action="" method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple accept="image/*"><br><br>
            <button type="submit" class="btn">解析実行</button>
        </form>

        <?php if (!empty($results)): ?>
            <hr>
            <h3>解析結果</h3>
            <?php foreach ($results as $res): ?>
                <div class="result">
                    <strong><?php echo htmlspecialchars($res['file']); ?></strong><br>
                    <?php foreach ($res['items'] as $i): ?>
                        <?php echo htmlspecialchars($i['name']); ?> ¥<?php echo number_format($i['price']); ?>, 
                    <?php endforeach; ?>
                    <br><strong>合計 ¥<?php echo number_format($res['total']); ?></strong>
                </div>
            <?php endforeach; ?>
            <p>
                <a href="receipt_data.csv" download>CSVダウンロード</a> | 
                <a href="ocr.log" target="_blank">ログ確認</a>
            </p>
        <?php endif; ?>
    </div>
</body>
</html>
