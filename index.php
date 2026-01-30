<?php
/**
 * 🧾 小票解析系统 - 过滤税费与重复金额版
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

        $timestamp = date('Y-m-d H:i:s');
        $rawTextLog = "=== OCR START: $fileName ($timestamp) ===\n";

        $currentItems = [];
        $sumAmount = 0;
        $stopFlag = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            $noSpace = str_replace([' ', '　', '=', '-', '_', '＊', '*', '◎'], '', $text);

            // 1. 核心过滤：如果遇到这些词，说明进入了税费结算区，停止抓取新商品
            if (preg_match('/合計|合計|合計|消費税|内訳|対象|課税|支払|残高|マネー|番号/u', $noSpace)) {
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            if ($stopFlag) continue;

            // 2. 匹配金额行
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                
                // 提取本行文字作为名字（去掉金额部分）
                $name = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));

                // 3. 向上溯源：如果当前行名字为空、太短或是纯金额（如 ¥198），则去上一行找名字
                if (mb_strlen($name) < 2 || preg_match('/^[¥￥\d,\s]+$/u', $name)) {
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prevText = trim($lines[$j]['text']);
                        // 排除单字符和干扰标题词
                        if (mb_strlen($prevText) >= 2 && !preg_match('/領|収|証|合|計|%|再発行/u', $prevText)) {
                            $name = $prevText;
                            break;
                        }
                    }
                }

                // 清洗名字
                $cleanName = str_replace(['＊', '*', '轻', '軽', '◎', '(', ')', '.', '．', '√', ' '], '', $name);
                
                // 4. 最终关卡：排除已知的系统词汇
                if (mb_strlen($cleanName) >= 2 && !preg_match('/Family|新宿|電話|登録|領収|対象|消費税|合計/u', $cleanName)) {
                    $currentItems[] = ['name' => $cleanName, 'price' => $price];
                    $sumAmount += $price;
                }
            }
        }
        
        // 记录日志
        foreach ($lines as $line) { $rawTextLog .= "RAW_LINE: " . $line['text'] . "\n"; }
        foreach ($currentItems as $ci) { $rawTextLog .= "  -> EXTRACTED: {$ci['name']} | {$ci['price']}\n"; }
        file_put_contents($logFile, $rawTextLog . "=== END ===\n\n", FILE_APPEND);

        $results[] = ['file' => $fileName, 'items' => $currentItems, 'total' => $sumAmount];
    }
    file_put_contents($storageFile, json_encode($results));
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>小票解析系统 - 优化版</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 700px; margin: auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .card { border: 1px solid #e0e0e0; background: #fafafa; padding: 15px; margin-top: 15px; border-radius: 8px; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
        .total { font-size: 20px; font-weight: bold; color: #d93025; text-align: right; margin-top: 10px; }
        .btn { width: 100%; padding: 12px; background: #1a73e8; color: white; border: none; cursor: pointer; border-radius: 6px; font-weight: bold; }
        .actions { display: flex; gap: 10px; margin-top: 30px; justify-content: center; }
        .link-btn { text-decoration: none; padding: 10px 20px; border-radius: 4px; color: white; font-weight: bold; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <h2 style="text-align:center;">🧾 小票解析 (过滤干扰项)</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple required><br><br>
            <button type="submit" class="btn">解析图片</button>
        </form>

        <?php if ($results): ?>
            <?php foreach ($results as $res): ?>
                <div class="card">
                    <div style="font-size: 11px; color: #999;">📄 <?= htmlspecialchars($res['file']) ?></div>
                    <?php if (empty($res['items'])): ?>
                        <p>未找到有效商品。</p>
                    <?php else: ?>
                        <?php foreach ($res['items'] as $it): ?>
                            <div class="row">
                                <span><?= htmlspecialchars($it['name']) ?></span>
                                <span>¥<?= number_format($it['price']) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <div class="total">合计 ¥<?= number_format($res['total']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="actions">
            <a href="?action=csv" class="link-btn" style="background:#34a853;">📥 CSV下载</a>
            <a href="?action=log" class="link-btn" style="background:#5f6368;">📜 ocr.log下载</a>
        </div>
    </div>
</body>
</html>
