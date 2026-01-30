<?php
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$results = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;

        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
        $imgData = file_get_contents($tmpName);

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resp = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($resp, true);
        $lines = $json['readResult']['blocks'][0]['lines'] ?? [];

        $items = [];
        $total = 0;
        $scan_stop = false; // 核心开关：一旦找到合计，彻底停止后续所有抓取

        for ($i = 0; $i < count($lines); $i++) {
            if ($scan_stop) break;

            $text = trim($lines[$i]['text']);

            // 1. 识别“合计”：这是最高优先级，一旦发现，提取金额并立刻“封笔”
            if (preg_match('/(合計|合\s*計|小計|计)/u', $text)) {
                // 在同一行或紧接着的下一行找数字
                $combined_text = $text . ($lines[$i+1]['text'] ?? '');
                if (preg_match('/[¥￥]\s?([\d,]+)/u', $combined_text, $m)) {
                    $total = (int)str_replace(',', '', $m[1]);
                    $scan_stop = true; // 锁定合计，后面所有的东西（消费税、余额等）全部不要
                    continue;
                }
            }

            // 2. 识别“红框商品”：只在还没找到合计之前运行
            // 过滤干扰词：过滤店名、时间、电话、注册号、领收证、消费税
            if (!$scan_stop && 
                !preg_match('/Family|新宿|电话|2024|证|号|店|领収|対象|消费税|残高|支払/u', $text) && 
                mb_strlen($text) > 2) {
                
                // 检查下一行是否有 ¥ 价格
                if (isset($lines[$i+1]) && preg_match('/[¥￥]\s?([\d,]+)/u', $lines[$i+1]['text'], $m)) {
                    $price = (int)str_replace(',', '', $m[1]);
                    
                    // 清理名称，只留下干净的商品名
                    $cleanName = str_replace(['＊', '*', '轻'], '', $text);
                    
                    $items[] = [
                        'name' => trim($cleanName),
                        'price' => $price
                    ];
                    $i++; // 跳过价格行，进入下一循环
                }
            }
        }
        $results[] = ['items' => $items, 'total' => $total];
    }
}
?>

<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>精准小票识别</title>
    <style>
        body { font-family: -apple-system, sans-serif; background: #f0f2f5; display: flex; justify-content: center; padding: 20px; }
        .card { width: 100%; max-width: 400px; background: white; padding: 20px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .item-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px dashed #eee; font-size: 16px; color: #333; }
        .total-row { margin-top: 20px; text-align: right; color: #e53935; font-size: 24px; font-weight: 900; }
        .upload-btn { background: #007aff; color: white; border: none; padding: 12px; width: 100%; border-radius: 8px; font-size: 16px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="card">
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple style="margin-bottom:15px;"><br>
            <button type="submit" class="upload-btn">扫 描</button>
        </form>

        <?php foreach ($results as $res): ?>
            <div style="margin-top:25px; border-top: 2px solid #333; padding-top: 10px;">
                <?php foreach ($res['items'] as $it): ?>
                    <div class="item-row">
                        <span><?= htmlspecialchars($it['name']) ?></span>
                        <span>¥<?= number_format($it['price']) ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="total-row">合计 ¥<?= number_format($res['total']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
