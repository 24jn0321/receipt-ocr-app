<?php
// Azure 配置
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$results = [];

// --- 功能：CSV 和 日志下载 ---
if (isset($_GET['download'])) {
    if ($_GET['download'] == 'log' && file_exists('ocr_log.txt')) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename=log.txt');
        readfile('ocr_log.txt'); exit;
    }
    if ($_GET['download'] == 'csv' && file_exists('last_data.json')) {
        $data = json_decode(file_get_contents('last_data.json'), true);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=receipt.csv');
        echo "\xEF\xBB\xBF"; // 防止乱码
        $f = fopen('php://output', 'w');
        fputcsv($f, ['文件名', '商品名称', '单价']);
        foreach($data as $r) {
            foreach($r['items'] as $it) fputcsv($f, [$r['file'], $it['name'], $it['price']]);
            fputcsv($f, [$r['file'], 'TOTAL', $r['total']]);
        }
        fclose($f); exit;
    }
}

// --- 核心识别逻辑 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    $debug_log = "";
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        $fileName = $_FILES['receipts']['name'][$key];

        // API 请求
        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
        $imgData = file_get_contents($tmpName);

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resp = curl_exec($ch);
        curl_close($ch);

        $debug_log .= "=== $fileName ===\n" . $resp . "\n\n";
        $json = json_decode($resp, true);
        $lines = $json['readResult']['blocks'][0]['lines'] ?? [];

        $items = []; $total = 0; $is_finished = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            if ($is_finished) break; // 拿到合计就停，防止读到消费税或卡余额

            // 1. 识别“合计”行
            if (preg_match('/(合計|合\s*計|小計)/u', $text)) {
                // 在当前行或下一行找金额
                $search = $text . ($lines[$i+1]['text'] ?? '');
                if (preg_match('/[¥￥]\s?([\d,]+)/u', $search, $m)) {
                    $total = (int)str_replace(',', '', $m[1]);
                    $is_finished = true; 
                    continue;
                }
            }

            // 2. 识别“商品 + 价格” (红框部分)
            // 过滤掉店名、日期、电话等杂质
            if (!preg_match('/Family|新宿|电话|2024|证|号|店|No/u', $text) && mb_strlen($text) > 2) {
                // 如果这一行没有价格，看下一行是不是价格
                if (!preg_match('/[¥￥]/u', $text)) {
                    if (isset($lines[$i+1]) && preg_match('/[¥￥]\s?([\d,]+)/u', $lines[$i+1]['text'], $m)) {
                        $price = (int)str_replace(',', '', $m[1]);
                        $cleanName = str_replace(['＊','*','轻','◎'], '', $text);
                        $items[] = ['name' => $cleanName, 'price' => $price];
                        $i++; // 跳过价格行
                    }
                }
            }
        }
        $results[] = ['file' => $fileName, 'items' => $items, 'total' => $total];
    }
    file_put_contents('ocr_log.txt', $debug_log);
    file_put_contents('last_data.json', json_encode($results));
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>小票识别系统</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .box { max-width: 600px; margin: auto; background: #fff; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .receipt { border-left: 5px solid #00a95c; background: #f9f9f9; padding: 15px; margin-bottom: 20px; }
        .row { display: flex; justify-content: space-between; border-bottom: 1px dashed #ccc; padding: 8px 0; }
        .total { text-align: right; color: #d32f2f; font-size: 1.5em; font-weight: bold; padding-top: 10px; }
        .btn { background: #0078d4; color: white; border: none; padding: 12px; width: 100%; border-radius: 5px; cursor: pointer; }
        .dl-bar { display: flex; gap: 10px; margin-top: 20px; }
        .dl-btn { flex: 1; text-align: center; padding: 10px; background: #666; color: white; text-decoration: none; border-radius: 5px; font-size: 14px; }
    </style>
</head>
<body>
    <div class="box">
        <h2>🧾 小票扫描器</h2>
        <form method="post" enctype="multipart/form-data">
            <p style="color:red; font-size:12px;">※ 如提示 413 错误，请尝试“截图”后上传截图，或分次上传。</p>
            <input type="file" name="receipts[]" multiple><br><br>
            <button type="submit" class="btn">开始识别所有图片</button>
        </form>

        <?php foreach ($results as $res): ?>
            <div class="receipt">
                <div style="font-size: 12px; color: #888; margin-bottom: 10px;">📄 <?=$res['file']?></div>
                <?php foreach ($res['items'] as $it): ?>
                    <div class="row">
                        <span><?=$it['name']?></span>
                        <span>¥<?=number_format($it['price'])?></span>
                    </div>
                <?php endforeach; ?>
                <div class="total">合计 ¥<?=number_format($res['total'])?></div>
            </div>
        <?php endforeach; ?>

        <?php if($results): ?>
            <div class="dl-bar">
                <a href="?download=csv" class="dl-btn" style="background:#28a745;">下载 CSV 结果</a>
                <a href="?download=log" class="dl-btn">下载扫描日志 (txt)</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
