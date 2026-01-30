<?php
// Azure 配置
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$results = [];

// --- 1. 下载处理 ---
if (isset($_GET['download'])) {
    if ($_GET['download'] == 'log' && file_exists('ocr_log.txt')) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename=receipt_log.txt');
        readfile('ocr_log.txt'); exit;
    }
    if ($_GET['download'] == 'csv' && file_exists('last_data.json')) {
        $data = json_decode(file_get_contents('last_data.json'), true);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=data.csv');
        echo "\xEF\xBB\xBF"; // 防止Excel乱码
        $f = fopen('php://output', 'w');
        fputcsv($f, ['文件名', '商品名', '价格']);
        foreach($data as $r) {
            foreach($r['items'] as $it) fputcsv($f, [$r['file'], $it['name'], $it['price']]);
            fputcsv($f, [$r['file'], '--- 合计 ---', $r['total']]);
        }
        fclose($f); exit;
    }
}

// --- 2. 识别处理 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    $log_content = "";
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        $fileName = $_FILES['receipts']['name'][$key];

        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
        $imgData = file_get_contents($tmpName);

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resp = curl_exec($ch);
        curl_close($ch);

        $log_content .= "FILE: $fileName\n" . $resp . "\n\n";
        $json = json_decode($resp, true);
        $lines = $json['readResult']['blocks'][0]['lines'] ?? [];

        $items = []; $total = 0; $stop = false;

        foreach ($lines as $line) {
            if ($stop) break;
            $text = trim($line['text']);

            // A. 合计逻辑：看到“合计”或“小计”，抓数字并关停
            if (preg_match('/(合計|合\s*計|小計|计)/u', $text)) {
                if (preg_match('/[¥￥]\s?([\d,]+)/u', $text, $m)) {
                    $total = (int)str_replace(',', '', $m[1]);
                    $stop = true; continue;
                }
            }

            // B. 商品逻辑：抓取包含 ¥ 的行，且排除掉垃圾词
            if (preg_match('/[¥￥]\s?([\d,]+)/u', $text, $m)) {
                $price = (int)str_replace(',', '', $m[1]);
                
                // 排除消费税、余额等干扰行
                if (preg_match('/(消费税|残高|支払|番号|対象|领収)/u', $text)) continue;

                // 提取名称：去掉价格部分和特殊符号
                $name = preg_replace('/[¥￥]\s?([\d,]+)/u', '', $text);
                $name = trim(str_replace(['＊', '*', '轻'], '', $name));

                if (mb_strlen($name) > 1) {
                    $items[] = ['name' => $name, 'price' => $price];
                }
            }
        }
        $results[] = ['file' => $fileName, 'items' => $items, 'total' => $total];
    }
    file_put_contents('ocr_log.txt', $log_content);
    file_put_contents('last_data.json', json_encode($results));
}
?>

<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>收据一键扫描</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 20px; margin: 0; }
        .box { max-width: 500px; margin: auto; background: white; padding: 25px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .receipt { border-left: 6px solid #00a95c; background: #f9f9f9; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px dashed #ddd; color: #333; }
        .total { text-align: right; color: #e53935; font-size: 24px; font-weight: bold; margin-top: 15px; }
        .btn { background: #007aff; color: white; border: none; padding: 15px; width: 100%; border-radius: 8px; font-size: 16px; cursor: pointer; }
        .dl-bar { display: flex; gap: 10px; margin-top: 20px; }
        .dl-btn { flex: 1; text-align: center; text-decoration: none; padding: 10px; border-radius: 5px; font-size: 14px; color: white; }
    </style>
</head>
<body>
    <div class="box">
        <h2>🧾 小票扫描器</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple><br><br>
            <button type="submit" class="btn">开始扫描所有图片</button>
        </form>

        <?php foreach ($results as $res): ?>
            <div class="receipt">
                <small style="color:#999;">📄 <?=$res['file']?></small>
                <?php foreach ($res['items'] as $it): ?>
                    <div class="row">
                        <span><?= htmlspecialchars($it['name']) ?></span>
                        <span>¥<?= number_format($it['price']) ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="total">合计 ¥<?= number_format($res['total']) ?></div>
            </div>
        <?php endforeach; ?>

        <?php if($results): ?>
            <div class="dl-bar">
                <a href="?download=csv" class="dl-btn" style="background:#28a745">下载 CSV</a>
                <a href="?download=log" class="dl-btn" style="background:#6c757d">下载日志</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
