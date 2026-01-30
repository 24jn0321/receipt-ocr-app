<?php
// --- Azure AI Document Intelligence 設定 ---
$endpoint = "https://receipt-analysis-ai.cognitiveservices.azure.com/";
$apiKey   = "vWlPmkplbveP5uM5pFtXc13o2PDFk22m5bnKLKUkegBExZtnCo9JQQJ99CAACi0881XJ3w3AAALACOGQtWQ";
$apiUrl   = rtrim($endpoint, '/') . "/formrecognizer/documentModels/prebuilt-receipt:analyze?api-version=2023-07-31";

// --- Azure SQL Database 設定 ---
$serverName = "receipt-server-24jn0.database.windows.net";
$database   = "receiptdb";
$username   = "sqladmin"; 
$password   = "Abc842727925";

$connectionString = "sqlsrv:server=tcp:$serverName,1433;Database=$database";
$logFile = 'ocr.log';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    try {
        // 変数名を $username, $password に統一
        $pdo = new PDO($connectionString, $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        die("DB接続エラー: " . $e->getMessage());
    }

    echo "<h2>解析結果</h2>";

    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;

        $fileName = $_FILES['receipts']['name'][$key];
        $imageData = file_get_contents($tmpName);

        // 1. Analyze Document 呼び出し
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/octet-stream",
            "Ocp-Apim-Subscription-Key: $apiKey"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imageData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $response = curl_exec($ch);
        
        preg_match('/Operation-Location: (.*)/i', $response, $matches);
        if (!isset($matches[1])) {
            echo "API呼び出しエラー (送信失敗): " . $fileName . "<br>";
            continue;
        }
        $statusUrl = trim($matches[1]);

        // 2. 解析完了までループ待機
        do {
            sleep(1);
            $ch = curl_init($statusUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Ocp-Apim-Subscription-Key: $apiKey"]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $resBody = json_decode(curl_exec($ch), true);
        } while ($resBody['status'] === 'running' || $resBody['status'] === 'notStarted');

        // ログ出力
        file_put_contents($logFile, "[".date('Y-m-d H:i:s')."] $fileName\n" . json_encode($resBody, JSON_UNESCAPED_UNICODE) . "\n\n", FILE_APPEND);

        // 3. データ抽出と表示
        $displayStrings = [];
        $totalAmount = 0;

        if (isset($resBody['analyzeResult']['documents'][0]['fields'])) {
            $fields = $resBody['analyzeResult']['documents'][0]['fields'];

            // 合計金額の取得
            $totalAmount = $fields['Total']['valueNumber'] ?? 0;

            // 商品明細の取得
            if (isset($fields['Items']['valueArray'])) {
                foreach ($fields['Items']['valueArray'] as $item) {
                    $rawName = $item['valueObject']['Description']['valueString'] ?? '';
                    $price   = $item['valueObject']['TotalPrice']['valueNumber'] ?? 0;

                    // 「軽」や記号、余計な空白を削除
                    $cleanName = trim(preg_replace('/[軽＊\*]/u', '', $rawName));
                    
                    if ($cleanName !== "") {
                        $displayStrings[] = "{$cleanName} ¥" . number_format($price);
                        
                        // DB保存
                        $stmt = $pdo->prepare("INSERT INTO receipts (filename, item_name, price, total_amount) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$fileName, $cleanName, $price, $totalAmount]);
                    }
                }
            }
        }

        // 4. 指定された形式で画面表示
        echo "<div style='border:11px solid #ddd; padding:10px; margin-bottom:10px;'>";
        echo "<strong>ファイル: $fileName</strong><br>";
        echo implode(', ', $displayStrings);
        echo ", 合計 ¥" . number_format($totalAmount);
        echo "</div>";
    }
    echo '<hr><a href="index.php">戻る</a> | <a href="download_csv.php">CSVダウンロード</a> | <a href="ocr.log">ログ確認</a>';
}
