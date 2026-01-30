<?php
/**
 * 🧾 小票解析・検証システム (OCR Log対応版)
 */

// 1. 設定情報
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 

$results = [];
$storageFile = 'ocr_data.json';
$logFile = 'ocr.log';

// --- A. ダウンロード処理 ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    // CSVダウンロード
    if ($action == 'csv' && file_exists($storageFile)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_export.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ファイル名', '項目名', '金額']);
        $data = json_decode(file_get_contents($storageFile), true);
        foreach ($data as $res) {
            foreach ($res['items'] as $it) fputcsv($output, [$res['file'], $it['name'], $it['price']]);
            fputcsv($output, [$res['file'], '合計', $res['total']]);
        }
        fclose($output); exit;
    }

    // 検証ログ(ocr.log)ダウンロード
    if ($action == 'log' && file_exists($logFile)) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename=ocr_debug.log');
        readfile($logFile);
        exit;
    }
}

// --- B. OCR解析処理 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        
        $fileName = $_FILES['receipts']['name'][$key];
        $imgData = file_get_contents($tmpName);
        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
        
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];

        // --- 検証用ログへの書き込み ---
        $timestamp = date('Y-m-d H:i:s');
        $rawTextLog = "=== OCR START: $fileName ($timestamp) ===\n";
        foreach ($lines as $line) {
            $rawTextLog .= "RAW_LINE: " . $line['text'] . "\n";
        }

        $currentItems = [];
        $sumAmount = 0;
        $stopFlag = false;

        // 解析ロジック
        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            $noSpace = str_replace([' ', '　', '=', '-', '_', '＊', '*', '◎'], '', $text);

            if (preg_match('/合計|合計|支払|残高|番号|カード|対象|消費税/u', $noSpace)) {
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            if ($stopFlag) continue;

            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                $name = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                
                if (mb_strlen($name) < 2 && $i > 0) {
                    $name = trim($lines[$i-1]['text']);
                }

                $cleanName = str_replace(['＊', '*', '軽', '◎', '(', ')', '.', '．'], '', $name);
                
                if (mb_strlen($cleanName) >= 2 && !preg_match('/Family|新宿|電話|登録|領収/u', $cleanName)) {
                    $currentItems[] = ['name' => $cleanName, 'price' => $price];
                    $sumAmount += $price;
                    $rawTextLog .= "  -> EXTRACTED: $cleanName | $price\n"; // 抽出結果もログに
                }
            }
        }
        $rawTextLog .= "=== OCR END: TOTAL ¥$sumAmount ===\n\n";
        file_put_contents($logFile, $rawTextLog, FILE_APPEND); // ログ保存

        $results[] = ['file' => $fileName, 'items' => $currentItems, 'total' => $sumAmount];
    }
    file_put_contents($storageFile, json_encode($results));
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OCR結果検証システム</title>
    <style>
        body { font-family: "Helvetica Neue", Arial, sans-serif; background: #f0f2f5; padding: 20px; color: #333; }
        .container { max-width: 700px; margin: auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h2 { text-align: center; color: #1a73e8; }
        .upload-area { border: 2px dashed #ccc; padding: 20px; text-align: center; margin-bottom: 20px; border-radius: 8px; }
        .card { border: 1px solid #e0e0e0; background: #fafafa; padding: 15px; margin-top: 15px; border-radius: 8px; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; font-size: 14px; }
        .total { font-size: 18px; font-weight: bold; color: #d93025; text-align: right; margin-top: 10px; }
        .btn { width: 100%; padding: 12px; background: #1a73e8; color: white; border: none; cursor: pointer; border-radius: 6px; font-weight: bold; }
        .btn:hover { background: #1557b0; }
        .actions { display: flex; gap: 10px; margin-top: 30px; justify-content: center; border-top: 1px solid #eee; padding-top: 20px; }
        .link-btn { text-decoration: none; padding: 8px 16px; border-radius: 4px; font-size: 13px; font-weight: bold; }
        .csv-link { background: #34a853; color: white; }
        .log-link { background: #5f6368; color: white; }
    </style>
</head>
<body>
    <div class="container">
        <h2>🧾 レシートOCR解析</h2>
        
        <div class="upload-area">
            <form method="post" enctype="multipart/form-data">
                <input type="file" name="receipts[]" multiple required>
                <p style="font-size: 12px; color: #666;">画像を複数選択できます</p>
                <button type="submit" class="btn">解析を実行してログを記録</button>
            </form>
        </div>

        <?php if ($results): ?>
            <h3>抽出結果 (画面表示)</h3>
            <?php foreach ($results as $res): ?>
                <div class="card">
                    <div style="font-size: 11px; color: #777;">📄 <?= htmlspecialchars($res['file']) ?></div>
                    <?php if (empty($res['items'])): ?>
                        <p style="font-size: 13px; color: #999;">項目が抽出されませんでした。ocr.logを確認してください。</p>
                    <?php else: ?>
                        <?php foreach ($res['items'] as $it): ?>
                            <div class="row">
                                <span><?= htmlspecialchars($it['name']) ?></span>
                                <span>¥<?= number_format($it['price']) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <div class="total">合計 ¥<?= number_format($res['total']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="actions">
            <?php if (file_exists($storageFile)): ?>
                <a href="?action=csv" class="link-btn csv-link">📥 CSVをダウンロード</a>
            <?php endif; ?>
            <?php if (file_exists($logFile)): ?>
                <a href="?action=log" class="link-btn log-link">📜 検証用ログ (ocr.log)</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
