<?php
// Azure 配置
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$results = [];

// --- 下载功能 ---
if (isset($_GET['download'])) {
    if ($_GET['download'] == 'log' && file_exists('ocr_log.txt')) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename=log.txt');
        readfile('ocr_log.txt'); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    $debug_log = "";
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

        $debug_log .= "=== $fileName ===\n" . $resp . "\n\n";
        $json = json_decode($resp, true);
        $lines = $json['readResult']['blocks'][0]['lines'] ?? [];

        $items = []; 
        $total = 0; 
        $is_total_locked = false; // 合计金额锁

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);

            // A. 合计识别逻辑（最高优先级）
            // 只要看到“计”字，且还没锁定合计，就去抓这一行的金额
            if (!$is_total_locked && preg_match('/(合計|合\s*計|小計|計)/u', $text)) {
                if (preg_match('/[¥￥]\s?([\d,]+)/u', $text, $m)) {
                    $total = (int)str_replace(',', '', $m[1]);
                    $is_total_locked = true; 
                    continue; // 锁定后直接跳过
                }
            }

            // B. 商品识别逻辑（红框部分）
            // 排除掉不相关的行（日期、店名、合计行锁定后也不再看）
            if (!$is_total_locked && 
                !preg_match('/Family|新宿|电话|2024|证|号|店|领収|対象|消费税/u', $text) && 
                mb_strlen($text) > 2) {
                
                // 检查下一行是否有 ¥ 符号的金额
                if (isset($lines[$i+1]) && preg_match('/[¥￥]\s?([\d,]+)/u', $lines[$i+1]['text'], $m)) {
                    $price = (int)str_replace(',', '', $m[1]);
                    // 清理名称里的特殊符号，保留 ◎
                    $cleanName = str_replace(['＊','*','轻'], '', $text);
                    
                    $items[] = [
                        'name' => trim($cleanName),
                        'price' => $price
                    ];
                    $i++; // 跳过金额行
                }
            }
        }
        $results[] = ['file' => $fileName, 'items' => $items, 'total' => $total];
    }
    file_put_contents('ocr_log.txt', $debug_log);
}
?>

<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>收据扫描</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .box { max-width: 500px; margin: auto; background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .receipt { border-left: 5px solid #00a95c; background: #fafafa; padding: 15px; margin: 15px 0; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #ddd; }
        .total-area { text-align: right; color: #d32f2f; font-size: 1.5em; font-weight: bold; padding-top: 10px; }
        .btn { background: #0078d4; color: white; border: none; padding: 12px; width: 100%; border-radius: 6px; cursor: pointer; font-size: 16px; }
        .dl-link { display: inline-block; margin-top: 15px; color: #666; text-decoration: none; font-size: 14px; }
    </style>
</head>
<body>
    <div class="box">
        <h2>🧾 小票扫描准确版</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple><br><br>
            <button type="submit" class="btn">开始扫描</button>
        </form>

        <?php foreach ($results as $res): ?>
            <div class="receipt">
                <small style="color:#999;">📄 <?=$res['file']?></small>
                <?php foreach ($res['items'] as $it): ?>
                    <div class="row">
                        <span><?=$it['name']?></span>
                        <span>¥<?=number_format($it['price'])?></span>
                    </div>
                <?php endforeach; ?>
                <div class="total-area">合计 ¥<?=number_format($res['total'])?></div>
            </div>
        <?php endforeach; ?>

        <?php if($results): ?>
            <div style="text-align:center;">
                <a href="?download=log" class="dl-link">⬇ 下载日志查看原始数据</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
