<?php
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$results = [];

// --- 功能：CSV与日志下载 ---
if (isset($_GET['dl'])) {
    if ($_GET['dl'] == 'csv' && file_exists('last_data.json')) {
        $data = json_decode(file_get_contents('last_data.json'), true);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=receipt.csv');
        echo "\xEF\xBB\xBF"; $f = fopen('php://output', 'w');
        fputcsv($f, ['文件', '商品', '单价']);
        foreach($data as $r) {
            foreach($r['items'] as $it) fputcsv($f, [$r['file'], $it['name'], $it['price']]);
            fputcsv($f, [$r['file'], 'TOTAL', $r['total']]);
        }
        fclose($f); exit;
    }
    if ($_GET['dl'] == 'log' && file_exists('debug_raw.txt')) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename=debug.txt');
        readfile('debug_raw.txt'); exit;
    }
}

// --- 核心识别逻辑 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    $raw_log = "";
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        $imgData = file_get_contents($tmpName);
        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resp = curl_exec($ch); curl_close($ch);
        $raw_log .= $resp . "\n\n";
        $json = json_decode($resp, true);
        $lines = $json['readResult']['blocks'][0]['lines'] ?? [];

        $items = []; $total = 0; $stop = false;
        foreach ($lines as $line) {
            if ($stop) break;
            $text = str_replace([' ', '　'], '', $line['text']);

            // 1. 合计锁定（只要含"合计"或"合計"）
            if (preg_match('/(合計|合计|計)/u', $text)) {
                if (preg_match('/(\d{1,3}(,\d{3})*)/', $text, $m)) {
                    $total = (int)str_replace(',', '', $m[1]);
                    $stop = true; continue; // 看到合计直接停止，红框杂质进不来
                }
            }

            // 2. 项目识别（含 ¥ 且不在黑名单内）
            if (preg_match('/[¥￥]/u', $text)) {
                // 黑名单过滤：排除红框里的杂质
                if (preg_match('/(消費税|残高|支払|対象|番号|领收|軽)/u', $text)) continue;

                // 拆分：◎チョコバターメロンパ ¥168
                $parts = preg_split('/[¥￥]/u', $line['text']);
                if (count($parts) >= 2) {
                    $name = trim(str_replace(['＊', '*', '◎'], '', $parts[0]));
                    if (preg_match('/(\d{1,3}(,\d{3})*)/', $parts[1], $m)) {
                        $price = (int)str_replace(',', '', $m[1]);
                        if (!empty($name)) $items[] = ['name' => (strpos($line['text'], '◎') !== false ? '◎' : '') . $name, 'price' => $price];
                    }
                }
            }
        }
        $results[] = ['file' => $_FILES['receipts']['name'][$key], 'items' => $items, 'total' => $total];
    }
    file_put_contents('debug_raw.txt', $raw_log);
    file_put_contents('last_data.json', json_encode($results));
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>小票扫描准确版</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 20px; }
        .box { max-width: 500px; margin: auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        .header { text-align: center; font-size: 24px; font-weight: bold; margin-bottom: 25px; }
        .btn-main { background: #007aff; color: white; border: none; padding: 15px; width: 100%; border-radius: 8px; font-size: 18px; cursor: pointer; }
        .result-card { border-left: 6px solid #34c759; background: #fafafa; padding: 15px; margin-top: 20px; border-radius: 4px; }
        .row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px dashed #ddd; }
        .total { text-align: right; color: #d32f2f; font-size: 28px; font-weight: bold; margin-top: 15px; }
        .nav { display: flex; justify-content: center; gap: 20px; margin-top: 30px; border-top: 1px solid #eee; padding-top: 15px; }
        .nav a { color: #007aff; text-decoration: none; font-size: 14px; }
    </style>
</head>
<body>
    <div class="box">
        <div class="header">📑 小票解析准确版</div>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple style="margin-bottom:20px;"><br>
            <button type="submit" class="btn-main">开始扫描</button>
        </form>

        <?php foreach ($results as $res): ?>
            <div class="result-card">
                <small style="color:#888;">📄 <?=$res['file']?></small>
                <?php foreach ($res['items'] as $it): ?>
                    <div class="row"><span><?=$it['name']?></span><span>¥<?=number_format($it['price'])?></span></div>
                <?php endforeach; ?>
                <div class="total">合计 ¥<?=number_format($res['total'])?></div>
            </div>
        <?php endforeach; ?>

        <?php if($results): ?>
        <div class="nav">
            <a href="?dl=csv">📥 CSV下载</a>
            <a href="?dl=log">📄 日志确认</a>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
