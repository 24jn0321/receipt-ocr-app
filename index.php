<?php
// 1. 基本設定とAzure情報の入力
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; // 末尾に / があってもなくてもOK
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

// データベース接続情報 (Azure SQL Database)
$serverName = "receipt-server-24jn0.database.windows.net";
$database   = "receiptdb";
$username   = "sqladmin"; 
$password   = "Abc842727925";

$logFile = 'ocr.log';
$csvFile = 'receipt_data.csv';
$results = [];

// データベース接続 (PDO)
try {
    $dsn = "sqlsrv:server=$dbHost;Database=$dbName";
    $pdo = new PDO($dsn, $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // テーブルがなければ作成 (初回のみ)
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
    die("DB接続エラー: " . $e->getMessage());
}

// 2. アップロード処理
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    $logData = "--- OCR Process Start: " . date('Y-m-d H:i:s') . " ---\n";
    $csvData = [];
    $csvData[] = ['ファイル名', '項目名', '金額'];

    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        $fileName = $_FILES['receipts']['name'][$key];

        // Azure AI Vision API (v4.0) 呼び出し
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
        $logData .= "File: $fileName - Raw: " . $response . "\n";

        // ファミマ専用抽出ロジック
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        $currentFileItems = [];
        $extractedTotal = 0;

        foreach ($lines as $line) {
            $text = $line['content'];
            // 「軽」「＊」などの不要文字を削除
            $cleanText = str_replace(['軽', '＊', '*', '◎'], '', $text);

            // 商品名と金額（¥数字）のパターンにマッチさせる
            if (preg_match('/(.*?)\s*[¥\|￥]\s*([\d,]+)/u', $cleanText, $matches)) {
                $name = trim($matches[1]);
                $price = (int)str_replace(',', '', $matches[2]);

                if (strpos($name, '合計') !== false) {
                    $extractedTotal = $price;
                } elseif (!empty($name) && $name !== "合計") {
                    $currentFileItems[] = ['name' => $name, 'price' => $price];
                    // DB保存
                    $stmt = $pdo->prepare("INSERT INTO ReceiptItems (filename, item_name, price, is_total) VALUES (?, ?, ?, 0)");
                    $stmt->execute([$fileName, $name, $price]);
                    $csvData[] = [$fileName, $name, $price];
                }
            }
        }
        // 合計金額の保存
        if ($extractedTotal > 0) {
            $stmt = $pdo->prepare("INSERT INTO ReceiptItems (filename, item_name, price, is_total) VALUES (?, '合計', ?, 1)");
            $stmt->execute([$fileName, $extractedTotal]);
            $csvData[] = [$fileName, '合計', $extractedTotal];
        }

        $results[] = ['file' => $fileName, 'items' => $currentFileItems, 'total' => $extractedTotal];
    }

    // ログとCSVの書き出し
    file_put_contents($logFile, $logData, FILE_APPEND);
    $fp = fopen($csvFile, 'w');
    // Excelで開けるようにBOMを付与
    fwrite($fp, "\xEF\xBB\xBF");
    foreach ($csvData as $row) { fputcsv($fp, $row); }
    fclose($fp);
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ファミマレシートOCRシステム</title>
    <style>
        body { font-family: 'Helvetica Neue', Arial, sans-serif; background: #f0f2f5; padding: 40px; }
        .container { max-width: 800px; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .result-item { border-left: 4px solid #00a95c; background: #f9f9f9; padding: 15px; margin: 15px 0; }
        .btn { display: inline-block; padding: 10px 20px; background: #0078d4; color: white; text-decoration: none; border-radius: 5px; margin-right: 10px; border: none; cursor: pointer; }
        .btn-download { background: #28a745; }
    </style>
</head>
<body>
    <div class="container">
        <h2>コンビニレシート解析 (Azure AI Vision)</h2>
        <p>ファミリーマートのレシート画像を複数選択してアップロードしてください。</p>
        
        <form action="" method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple accept="image/*" style="margin-bottom: 20px;"><br>
            <button type="submit" class="btn">解析を実行する</button>
        </form>

        <?php if (!empty($results)): ?>
            <hr>
            <h3>抽出結果</h3>
            <?php foreach ($results as $res): ?>
                <div class="result-item">
                    <strong>📄 <?php echo htmlspecialchars($res['file']); ?></strong><br>
                    <?php 
                    $output = [];
                    foreach ($res['items'] as $i) { $output[] = "{$i['name']} ¥" . number_format($i['price']); }
                    echo implode(", ", $output);
                    ?>
                    <br><strong>合計 ¥<?php echo number_format($res['total']); ?></strong>
                </div>
            <?php endforeach; ?>

            <div style="margin-top: 30px;">
                <a href="<?php echo $csvFile; ?>" class="btn btn-download">CSVをダウンロード</a>
                <a href="<?php echo $logFile; ?>" target="_blank" class="btn" style="background: #666;">OCRログを表示</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
