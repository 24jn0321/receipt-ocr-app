<?php
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$results = [];

// --- 1. 下载逻辑 ---
if (isset($_GET['dl'])) {
    if ($_GET['dl'] == 'csv' && file_exists('data.json')) {
        $data = json_decode(file_get_contents('data.json'), true);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=receipt.csv');
        echo "\xEF\xBB\xBF"; $f = fopen('php://output', 'w');
        fputcsv($f, ['文件名', '商品名', '金额']);
        foreach($data as $r) {
            foreach($r['items'] as $it) fputcsv($f, [$r['file'], $it['name'], $it['price']]);
            fputcsv($f, [$r['file'], '合计', $r['total']]);
        }
        fclose($f); exit;
    }
    if ($_GET['dl'] == 'log' && file_exists('debug_log.txt')) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename=debug_log.txt');
        readfile('debug_log.txt'); exit;
    }
}

// --- 2. 核心解析逻辑 ---
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
        
        $raw_log .= "--- " . $_FILES['receipts']['name'][$key] . " ---\n" . $resp . "\n\n";
        $json = json_decode($resp, true);
        $lines = $json['readResult']['blocks'][0]['lines'] ?? [];

        $items = []; $total = 0; $is_stop = false;
        
        // 整理所有行，提取 Y 坐标和内容
        $processed_lines = [];
        foreach ($lines as $l) {
            $y = $l['boundingPolygon'][0]['y']; // 取每行左上角的 Y 坐标
            $processed_lines[] = ['y' => $y, 'text' => trim($l['text'])];
        }

        for ($i = 0; $i < count($processed_lines); $i++) {
            if ($is_stop) break;
            $line = $processed_lines[$i];
            $text = str_replace([' ', '　'], '', $line['text']);

            // A. 合计判断
            if (mb_strpos($text, '合計') !== false || mb_strpos($text, '小計') !== false) {
                if (preg_match('/(\d{1,3}(,\d{3})*)/', $text, $m)) {
                    $total = (int)str_replace(',', '', $m[1]);
                    $is_stop = true; continue;
                }
                // 如果当前行没数字，看后一行（同一高度）
                if (isset($processed_lines[$i+1]) && abs($processed_lines[$i+1]['y'] - $line['y']) < 20) {
                    if (preg_match('/(\d{1,3}(,\d{3})*)/', $processed_lines[$i+1]['text'], $m)) {
                        $total = (int)str_replace(',', '', $m[1]);
                        $is_stop = true; continue;
                    }
                }
            }

            // B. 商品判断（只要包含 ¥ 符号）
            if (mb_strpos($text, '¥') !== false || mb_strpos($text, '￥') !== false) {
                // 排除黑名单
                if (preg_match('/(消費税|残高|支払|対象|電話|番号|領収|再発行)/u', $text)) continue;

                $price = 0;
                if (preg_match('/(\d{1,3}(,\d{3})*)/', $text, $m)) {
                    $price = (int)str_replace(',', '', $m[1]);
                }

                // 找名字：如果当前行太短只有价格，就去上一行拿名字
                $name = preg_replace('/[¥￥\d,，]|轻|＊|\*/u', '', $line['text']);
                if (mb_strlen($name) < 2 && $i > 0) {
                    $name = $processed_lines[$i-1]['text'];
                }
                
                $cleanName = trim(str_replace(['＊', '*', '轻'], '', $name));
                if (mb_strlen($cleanName) > 1 && !preg_match('/(Family|新宿|2024)/u', $cleanName)) {
                    $items[] = ['name' => $cleanName, 'price' => $price];
                }
            }
        }
        $results[] = ['file' => $_FILES['receipts']['name'][$key], 'items' => $items, 'total' => $total];
    }
    file_put_contents('debug_log.txt', $raw_log);
    file_put_contents('data.json', json_encode($results));
}
?>

<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>小票解析系统准确版</title>
    <style>
        body { font-family: -apple-system, sans-serif; background: #f4f7f6; padding: 20px; }
        .card { max-width: 500px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .upload-area { border: 2px dashed #ccc; padding: 20px; text-align: center; margin-bottom: 20px; cursor: pointer; }
        .btn-run { background: #007aff; color: white; border: none; padding: 12px; width: 100%; border-radius: 6px; font-size: 16px; cursor: pointer; }
        .res-item { border-left: 5px solid #28a745; background: #f8f9fa; padding: 15px; margin-top: 15px; border-radius: 4px; }
        .row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px dashed #ddd; }
        .total-val { text-align: right; color: #d32f2f; font-size: 26px; font-weight: bold; margin-top: 10px; }
        .footer { margin-top: 25px; display: flex; justify-content: center; gap: 20px; border-top: 1px solid #eee; padding-top: 15px; }
        .footer a { color: #007aff; text-decoration: none; font-size: 14px; display: flex; align-items: center; gap: 5px; }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="text-align:center;">📑 小票解析系统准确版</h2>
        <form method="post" enctype="multipart/form-data">
            <div class="upload-area">
                <input type="file" name="receipts[]" multiple>
            </div>
            <button type="submit" class="btn-run">开始扫描</button>
        </form>

        <?php foreach ($results as $res): ?>
            <div class="res-item">
                <small style="color:#666;">📄 <?=$res['file']?></small>
                <?php foreach ($res['items'] as $it): ?>
                    <div class="row">
                        <span><?=htmlspecialchars($it['name'])?></span>
                        <span>¥<?=number_format($it['price'])?></span>
                    </div>
                <?php endforeach; ?>
                <div class="total-val">合计 ¥<?=number_format($res['total'])?></div>
            </div>
        <?php endforeach; ?>

        <?php if($results): ?>
        <div class="footer">
            <a href="?dl=csv">📥 CSV下载</a>
            <a href="?dl=log">📄 日志确认</a>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
