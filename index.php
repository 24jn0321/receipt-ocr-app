<?php
/**
 * 🧾 小票解析系统 - 稳健修复版 (精准去重并保留商品)
 */

$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 

$results = [];
$storageFile = 'ocr_data.json';
$logFile = 'ocr.log';

if (isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action == 'csv' && file_exists($storageFile)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['文件', '项目', '金额']);
        $data = json_decode(file_get_contents($storageFile), true);
        foreach ($data as $res) {
            foreach ($res['items'] as $it) fputcsv($output, [$res['file'], $it['name'], $it['price']]);
            fputcsv($output, [$res['file'], '合计', $res['total']]);
        }
        fclose($output); exit;
    }
    if ($action == 'log' && file_exists($logFile)) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename=ocr.log');
        readfile($logFile);
        exit;
    }
}

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

        $rawTextLog = "=== OCR START: $fileName ===\n";
        $currentItems = [];
        $sumAmount = 0;
        $stopFlag = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            $noSpaceText = str_replace([' ', '　', '＊', '*', '◎', '√', '軽', '轻'], '', $text);

            // 1. 遇到结算关键词，停止后续商品提取（针对红框中的消费税明细）
            if (preg_match('/合計|合計|消費税|対象|課税|支払|残高/u', $noSpaceText)) {
                if (!empty($currentItems)) $stopFlag = true;
                continue;
            }
            if ($stopFlag) continue;

            // 2. 匹配带金额的行
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                $namePart = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));

                // 3. 核心逻辑：如果本行名字太短或者是纯金额，去上一行找名字
                if (mb_strlen($namePart) < 2 || preg_match('/^[¥￥\d,\s]+$/u', $namePart)) {
                    $foundName = "";
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prev = trim($lines[$j]['text']);
                        if (mb_strlen($prev) >= 2 && !preg_match('/領|収|証|合|計|%|電話|店|番号/u', $prev)) {
                            $foundName = $prev;
                            break;
                        }
                    }
                    $namePart = $foundName ?: $namePart;
                }

                // 清洗名字
                $finalName = str_replace(['(', ')', '（', '）', '.', '．', '軽', '轻', '＊', '*', ' '], '', $namePart);
                
                // 4. 最终过滤与去重
                if (mb_strlen($finalName) >= 2 && !preg_match('/Family|新宿|電話|登録|領収|対象|消費税|合計/u', $finalName)) {
                    
                    // 【去重检查】：如果这个商品的名字和价格跟上一个完全一样，说明是小票的重复行，跳过
                    $isDuplicate = false;
                    if (!empty($currentItems)) {
                        $lastItem = end($currentItems);
                        if ($lastItem['name'] == $finalName && $lastItem['price'] == $price) {
                            $isDuplicate = true;
                        }
                    }

                    if (!$isDuplicate) {
                        $currentItems[] = ['name' => $finalName, 'price' => $price];
                        $sumAmount += $price;
                    }
                }
            }
        }
        
        foreach ($lines as $ln) { $rawTextLog .= "RAW: " . $ln['text'] . "\n"; }
        file_put_contents($logFile, $rawTextLog . "=== END ===\n\n", FILE_APPEND);
        $results[] = ['file' => $fileName, 'items' => $currentItems, 'total' => $sumAmount];
    }
    file_put_contents($storageFile, json_encode($results));
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>小票解析系统</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 650px; margin: auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .card { border-left: 5px solid #1a73e8; background: #fafafa; padding: 15px; margin-top: 15px; border-radius: 8px; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
        .total { font-size: 20px; font-weight: bold; color: #d93025; text-align: right; margin-top: 10px; }
        .btn { width: 100%; padding: 12px; background: #1a73e8; color: white; border: none; cursor: pointer; border-radius: 6px; }
        .footer { margin-top: 25px; text-align: center; }
        .link { text-decoration: none; padding: 8px 15px; color: #0078d4; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <h2 style="text-align:center;">🧾 小票解析系统</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple required><br><br>
            <button type="submit" class="btn">开始解析</button>
        </form>

        <?php if ($results): ?>
            <?php foreach ($results as $res): ?>
                <div class="card">
                    <small style="color:#999"><?= htmlspecialchars($res['file']) ?></small>
                    <?php if (empty($res['items'])): ?>
                        <p>未发现有效商品，请检查 ocr.log。</p>
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

        <div class="footer">
            <a href="?action=csv" class="link">📥 下载数据</a> | 
            <a href="?action=log" class="link">📜 查看日志</a>
        </div>
    </div>
</body>
</html>
