<?php
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$results = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
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

        $json = json_decode($resp, true);
        $lines = $json['readResult']['blocks'][0]['lines'] ?? [];

        $items = [];
        $total = 0;
        $total_found = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);

            // 1. 先判断是不是“合计”行
            // 如果看到“合计”或者“计”，且还没找到过总额，就取这一行的数字
            if (!$total_found && preg_match('/(合計|合\s*計|小計|計)/u', $text)) {
                if (preg_match('/[¥￥]\s?([\d,]+)/u', $text, $m)) {
                    $total = (int)str_replace(',', '', $m[1]);
                    $total_found = true; // 只要找到了合计，后面所有的数字都不再读取
                    continue;
                }
            }

            // 2. 如果还没到合计行，就识别商品名和单价
            if (!$total_found) {
                // 过滤掉不相关的行
                if (!preg_match('/Family|新宿|电话|2024|证|号|店|领収|対象|消费税/u', $text) && mb_strlen($text) > 2) {
                    
                    // 检查下一行（或者当前行尾部）是否有单价
                    if (isset($lines[$i+1]) && preg_match('/[¥￥]\s?([\d,]+)/u', $lines[$i+1]['text'], $m)) {
                        $price = (int)str_replace(',', '', $m[1]);
                        
                        // 只去掉“轻”和星号，保留“◎”
                        $cleanName = str_replace(['＊', '*', '轻'], '', $text);
                        
                        $items[] = [
                            'name' => trim($cleanName),
                            'price' => $price
                        ];
                        $i++; // 跳过价格行，防止价格被当成下一个商品
                    }
                }
            }
        }
        $results[] = ['file' => $fileName, 'items' => $items, 'total' => $total];
    }
}
?>

<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>单张精准扫描</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .box { max-width: 450px; margin: auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .res-item { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px dashed #ccc; font-size: 16px; }
        .total-box { margin-top: 20px; text-align: right; color: #d32f2f; font-size: 24px; font-weight: bold; border-top: 2px solid #eee; padding-top: 10px; }
    </style>
</head>
<body>
    <div class="box">
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple><br><br>
            <button type="submit" style="width:100%; height:40px; cursor:pointer;">扫 描</button>
        </form>

        <?php foreach ($results as $res): ?>
            <div style="margin-top:20px;">
                <?php foreach ($res['items'] as $it): ?>
                    <div class="res-item">
                        <span><?=$it['name']?></span>
                        <span>¥<?=number_format($it['price'])?></span>
                    </div>
                <?php endforeach; ?>
                <div class="total-box">合计 ¥<?=number_format($res['total'])?></div>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
