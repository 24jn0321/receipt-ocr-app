<?php
/**
 * 🧾 レシート解析システム - OCR検証・ログ強化版
 * 修改：将 OCR 原始识别出的每一行内容都记入 ocr.log
 */

// --- 1. 設定と環境設定 ---
@set_time_limit(600);
@ini_set('memory_limit', '512M');

$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 
$logFile = 'ocr.log';

function writeDebugLog($msg) {
    global $logFile;
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n", FILE_APPEND);
}

// --- 2. Azure SQL 接続 ---
$serverName = "tcp:receipt-server-24jn0.database.windows.net,1433"; 
$connectionOptions = ["Database" => "receiptdb", "Uid" => "sqladmin", "PWD" => "Abc842727925", "CharacterSet" => "UTF-8"];
$conn = sqlsrv_connect($serverName, $connectionOptions);

if ($conn === false) {
    writeDebugLog("【FATAL】DB接続失敗: " . print_r(sqlsrv_errors(), true));
    die("Database Connection Error.");
}

// --- 3. アクション処理 (CSV/LOG/CLEAR) ---
if (isset($_GET['action'])) {
    if ($_GET['action'] == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_'.date('Ymd').'.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ファイル名', '項目', '金額', '日時']);
        $sql = "SELECT r.file_name, r.processed_at, i.item_name, i.price FROM receipts r JOIN receipt_items i ON r.id = i.receipt_id";
        $stmt = sqlsrv_query($conn, $sql);
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            fputcsv($output, [$row['file_name'], $row['item_name'], $row['price'], $row['processed_at']->format('Y-m-d H:i:s')]);
        }
        exit;
    }
    if ($_GET['action'] == 'download_log') {
        if (file_exists($logFile)) {
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="ocr_verify.log"');
            readfile($logFile); exit;
        }
    }
    if ($_GET['action'] == 'clear_view') {
        header("Location: " . strtok($_SERVER["PHP_SELF"], '?')); exit;
    }
}

// --- 4. OCR 解析 & ログ記録 ---
$results = [];
$totalAllAmount = 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    writeDebugLog("========== 新規解析タスク開始 ==========");
    
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        $fileName = $_FILES['receipts']['name'][$key];
        writeDebugLog("【処理開始】ファイル名: $fileName");

        $imgData = file_get_contents($tmpName);
        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
        
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            writeDebugLog("【ERROR】Azure APIエラー ($httpCode): $response");
            continue;
        }

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        
        // --- 検証用：OCRした生の内容をログに書き出す ---
        writeDebugLog("--- OCR生データ一覧 ($fileName) ---");
        foreach ($lines as $idx => $line) {
            writeDebugLog("Line $idx: " . $line['text']);
        }
        writeDebugLog("-------------------------------------");

        $currentItems = [];
        $stopFlag = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            // 合計付近の文字が出たら商品抽出を止める
            if (preg_match('/合計|合計金額|内消費税|消費税/u', $text)) {
                if (!empty($currentItems)) $stopFlag = true;
                continue;
            }
            if ($stopFlag) continue;

            // 金額パターン (¥1,234)
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                $nameInLine = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                $cleanName = str_replace(['＊', '*', '轻', '軽', '(', ')', ' ', '　'], '', $nameInLine);

                // 行内に商品名がない場合、上の行を探す
                if (mb_strlen($cleanName) < 2) {
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prev = trim($lines[$j]['text']);
                        if (mb_strlen($prev) >= 2 && !preg_match('/領|収|証|計|%|¥|￥/u', $prev)) {
                            $cleanName = $prev; break;
                        }
                    }
                }

                if (!empty($cleanName) && !preg_match('/電話|番号|合計|消費税/u', $cleanName)) {
                    $currentItems[] = ['name' => $cleanName, 'price' => $price];
                    writeDebugLog("  -> 抽出成功: $cleanName | ¥$price");
                }
            }
        }

        // DB保存
        if (!empty($currentItems)) {
            $sqlR = "INSERT INTO receipts (file_name) OUTPUT INSERTED.id VALUES (?)";
            $stmtR = sqlsrv_query($conn, $sqlR, [$fileName]);
            if ($stmtR && sqlsrv_fetch($stmtR)) {
                $receiptId = sqlsrv_get_field($stmtR, 0);
                foreach ($currentItems as $it) {
                    $sqlI = "INSERT INTO receipt_items (receipt_id, item_name, price) VALUES (?, ?, ?)";
                    sqlsrv_query($conn, $sqlI, [$receiptId, $it['name'], $it['price']]);
                    $totalAllAmount += $it['price'];
                }
                $results[] = ['file' => $fileName, 'items' => $currentItems];
                writeDebugLog("【完了】DBに保存しました (ID: $receiptId)");
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>OCR検証システム</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f9; padding: 20px; color: #333; }
        .container { max-width: 700px; margin: auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .card { border: 1px solid #ddd; padding: 15px; margin-bottom: 10px; border-left: 5px solid #007bff; border-radius: 4px; }
        .item-row { display: flex; justify-content: space-between; border-bottom: 1px dashed #eee; padding: 5px 0; }
        .btn { padding: 12px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; }
        .nav-links { margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px; display: flex; gap: 10px; }
        .total-box { font-size: 24px; font-weight: bold; color: #d9534f; text-align: center; margin-top: 20px; }
    </style>
</head>
<body>
<div class="container">
    <h2 style="text-align: center;">📜 レシートOCR解析 & 検証</h2>
    
    <form action="" method="post" enctype="multipart/form-data">
        <input type="file" name="receipts[]" multiple required style="display: block; margin-bottom: 20px; width: 100%;">
        <button type="submit" class="btn" style="width: 100%;">解析を実行</button>
    </form>

    <?php if ($results): ?>
        <h3 style="margin-top: 30px; color: #007bff;">✅ 今回の解析結果:</h3>
        <?php foreach ($results as $res): ?>
            <div class="card">
                <div style="font-weight: bold; color: #666; font-size: 0.9em; margin-bottom: 10px;">📄 <?= htmlspecialchars($res['file']) ?></div>
                <?php foreach ($res['items'] as $it): ?>
                    <div class="item-row">
                        <span><?= htmlspecialchars($it['name']) ?></span>
                        <span>¥<?= number_format($it['price']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <div class="total-box">合計金額: ¥<?= number_format($totalAllAmount) ?></div>
    <?php endif; ?>

    <div class="nav-links">
        <a href="?action=csv" class="btn" style="background: #28a745;">📥 CSVダウンロード</a>
        <a href="?action=download_log" class="btn" style="background: #6c757d;">📝 ログ(ocr.log)確認</a>
        <a href="?action=clear_view" class="btn" style="background: #f0ad4e;">🔄 クリア</a>
    </div>
</div>
</body>
</html>
