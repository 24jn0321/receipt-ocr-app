<?php
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$results = [];

// --- 下载功能 ---
if (isset($_GET['action'])) {
    $sessionData = file_exists('ocr_cache.json') ? json_decode(file_get_contents('ocr_cache.json'), true) : [];
    if ($_GET['action'] == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['文件名', '商品名', '金额']);
        foreach ($sessionData as $res) {
            foreach ($res['items'] as $it) fputcsv($output, [$res['file'], $it['name'], $it['price']]);
            fputcsv($output, [$res['file'], '合计', $res['total']]);
        }
        fclose($output); exit;
    }
    if ($_GET['action'] == 'log') {
        header('Content-Type: application/json');
        echo file_get_contents('ocr_cache.json'); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        
        $imageData = file_get_contents($tmpName);
        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imageData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        
        $currentFileItems = [];
        $sumTotal = 0;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            // 预处理：去掉干扰字符
            $cleanText = str_replace([' ', '　', '＊', '*', '轻', '軽', '◎', '(', '（'], '', $text);

            // 【核心防线】如果这一行包含卡号、残高、支付、对象、消费税，直接死封，不准提取
            if (preg_match('/卡号|番号|残高|支払|対象|消費税|合计|合計|合计|电话|新宿|2024|レジ/u', $cleanText)) {
                continue;
            }

            // 方案 A：同行提取 (针对 3 号票，如：アポロチョコレート ¥198)
            if (preg_match('/^(.*)[¥￥]([\d,]+)/u', $text, $matches)) {
                $name = trim(str_replace(['.', '．'], '', $matches[1]));
                $price = (int)str_replace(',', '', $matches[2]);
                
                // 二次检查名字，防止抓到怪东西
                if (mb_strlen($name) >= 2 && !preg_match('/番号|残高|支払/u', $name)) {
                    $currentFileItems[] = ['name' => $name, 'price' => $price];
                    $sumTotal += $price;
                    continue;
                }
            }

            // 方案 B：跨行提取 (针对 1, 2 号票)
            if (mb_strlen($cleanText) >= 2 && !preg_match('/[¥￥]/u', $text)) {
                if (isset($lines[$i + 1])) {
                    $nextText = trim($lines[$i + 1]['text']);
                    // 同样要防止下一行是“残高”
                    if (preg_match('/[¥￥]([\d,]+)/u', $nextText, $matches) && !preg_match('/残高|支払|番号/u', $nextText)) {
                        $price = (int)str_replace(',', '', $matches[1]);
                        $currentFileItems[] = ['name' => trim($text), 'price' => $price];
                        $sumTotal += $price;
                        $i++; 
                    }
                }
            }
        }
        $results[] = ['file' => $_FILES['receipts']['name'][$key], 'items' => $currentFileItems, 'total' => $sumTotal];
    }
    file_put_contents('ocr_cache.json', json_encode($results));
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>小票解析最终稳定版</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .box { max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .card { border-left: 5px solid #00a95c; background: #fdfdfd; padding: 15px; margin-top: 15px; border-bottom: 1px solid #eee; }
        .row { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px dashed #ddd; }
        .total { font-size: 22px; font-weight: bold; color: #d32f2f; text-align: right; margin-top: 10px; }
        .btn { width: 100%; padding: 10px; background: #0078d4; color: white; border: none; cursor: pointer; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">🧾 收据智能解析系统</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple accept="image/*"><br><br>
            <button type="submit" class="btn">开始扫描全部小票</button>
        </form>

        <?php if ($results): ?>
            <?php foreach ($results as $res): ?>
                <div class="card">
                    <small style="color:999"><?= htmlspecialchars($res['file']) ?></small>
                    <?php foreach ($res['items'] as $it): ?>
                        <div class="row">
                            <span><?= htmlspecialchars($it['name']) ?></span>
                            <span>¥<?= number_format($it['price']) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="total">合计 ¥<?= number_format($res['total']) ?></div>
                </div>
            <?php endforeach; ?>
            <div style="text-align:center; margin-top:20px;">
                <a href="?action=csv">📥 下载 CSV</a> | <a href="?action=log">📜 原始日志</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
