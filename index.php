<?php
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$results = [];

// --- 功能1：下载处理 ---
if (isset($_GET['dl'])) {
    if ($_GET['dl'] == 'csv' && file_exists('data.json')) {
        $data = json_decode(file_get_contents('data.json'), true);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=receipt_data.csv');
        echo "\xEF\xBB\xBF"; $f = fopen('php://output', 'w');
        fputcsv($f, ['文件名', '项目', '金额']);
        foreach($data as $r) {
            foreach($r['items'] as $it) fputcsv($f, [$r['file'], $it['name'], $it['price']]);
            fputcsv($f, [$r['file'], '--- 合计 ---', $r['total']]);
        }
        fclose($f); exit;
    }
    if ($_GET['dl'] == 'log' && file_exists('debug_log.txt')) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename=debug_log.txt');
        readfile('debug_log.txt'); exit;
    }
}

// --- 功能2：核心解析 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    $log_str = "";
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
        $log_str .= "--- ".$_FILES['receipts']['name'][$key]." ---\n".$resp."\n\n";
        $json = json_decode($resp, true);
        $lines = $json['readResult']['blocks'][0]['lines'] ?? [];

        $items = []; $total = 0; $stop = false;
        foreach ($lines as $line) {
            if ($stop) break;
            $text = str_replace([' ', '　'], '', $line['text']); // 强行删掉空格

            // 1. 抓合计：只要包含“合计”
            if (mb_strpos($text, '合計') !== false) {
                if (preg_match('/(\d{1,3}(,\d{3})*)/', $text, $m)) {
                    $total = (int)str_replace(',', '', $m[1]);
                    $stop = true; // 拿完合计立刻停，不看消费税和余额
                }
                continue;
            }

            // 2. 抓项目：包含 ¥ 或 ￥ 
            if (preg_match('/[¥￥]/u', $text)) {
                if (preg_match('/(消費税|残高|支払|対象|電話|番号)/u', $text)) continue;

                // 拆分：把 ¥ 左右两边切开
                $parts = preg_split('/[¥￥]/u', $line['text']);
                if (count($parts) >= 2) {
                    $name = trim(str_replace(['＊', '*', '轻', '◎'], '', $parts[0]));
                    // 提取金额数字
                    if (preg_match('/(\d{1,3}(,\d{3})*)/', $parts[1], $m)) {
                        $price = (int)str_replace(',', '', $m[1]);
                        if (!empty($name)) {
                            $items[] = ['name' => (strpos($line['text'], '◎') !== false ? '◎' : '') . $name, 'price' => $price];
                        }
                    }
                }
            }
        }
        $results[] = ['file' => $_FILES['receipts']['name'][$key], 'items' => $items, 'total' => $total];
    }
    file_put_contents('debug_log.txt', $log_str);
    file_put_contents('data.json', json_encode($results));
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>小票解析系统</title>
    <style>
        body { font-family: -apple-system, "Helvetica Neue", sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 500px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h2 { text-align: center; color: #1a1a1a; margin-bottom: 25px; }
        .upload-section { border: 2px dashed #d1d5db; padding: 20px; text-align: center; border-radius: 8px; margin-bottom: 20px; }
        .btn-main { background: #007aff; color: white; border: none; padding: 12px 30px; border-radius: 6px; font-size: 16px; cursor: pointer; width: 100%; }
        .result-card { border-left: 5px solid #34c759; background: #f9fafb; padding: 15px; margin-top: 20px; border-radius: 4px; }
        .row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #e5e7eb; }
        .total-row { text-align: right; color: #ff3b30; font-size: 24px; font-weight: bold; margin-top: 15px; }
        .footer-links { margin-top: 30px; display: flex; justify-content: center; gap: 20px; border-top: 1px solid #eee; padding-top: 15px; }
        .footer-links a { color: #007aff; text-decoration: none; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>🧾 小票解析系统</h2>
        <form method="post" enctype="multipart/form-data">
            <div class="upload-section">
                <input type="file" name="receipts[]" multiple id="fileInput">
            </div>
            <button type="submit" class="btn-main">解析执行</button>
        </form>

        <?php foreach ($results as $res): ?>
            <div class="result-card">
                <div style="font-size:12px; color:#6b7280; margin-bottom:10px;">📄 文件：<?=$res['file']?></div>
                <?php if(empty($res['items'])): ?>
                    <div style="color:#999; text-align:center;">未检测到商品</div>
                <?php endif; ?>
                <?php foreach ($res['items'] as $it): ?>
                    <div class="row">
                        <span><?= htmlspecialchars($it['name']) ?></span>
                        <span>¥<?= number_format($it['price']) ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="total-row">合计 ¥<?= number_format($res['total']) ?></div>
            </div>
        <?php endforeach; ?>

        <?php if($results): ?>
        <div class="footer-links">
            <a href="?dl=csv">📥 CSV下载</a>
            <a href="?dl=log">📄 日志确认</a>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
