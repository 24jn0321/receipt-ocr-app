<?php
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$results = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
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
        
        $json = json_decode($resp, true);
        $lines = $json['readResult']['blocks'][0]['lines'] ?? [];

        $items = []; $total = 0;
        $in_product_zone = false;

        foreach ($lines as $line) {
            $text = str_replace([' ', '　'], '', $line['text']);

            // 1. 开启商品区：看到“領収証”开始抓
            if (mb_strpos($text, '領収証') !== false) { $in_product_zone = true; continue; }

            // 2. 抓合计并结束：看到“合計”拿钱，然后彻底关掉该图的处理
            if (mb_strpos($text, '合計') !== false) {
                if (preg_match('/(\d{1,3}(,\d{3})*)/', $text, $m)) {
                    $total = (int)str_replace(',', '', $m[1]);
                    $in_product_zone = false; break; 
                }
            }

            // 3. 抓商品：只在商品区内，且包含 ¥ 
            if ($in_product_zone && mb_strpos($text, '¥') !== false) {
                // 排除你画红框的那些（消费税、対象等）
                if (preg_match('/(消費税|対象|支払|番号|再発行)/u', $text)) continue;

                $parts = explode('¥', $line['text']);
                if (count($parts) >= 2) {
                    $name = trim(str_replace(['＊', '*', '轻', '◎'], '', $parts[0]));
                    if (preg_match('/(\d+)/', $parts[1], $m)) {
                        $price = (int)$m[1];
                        if (!empty($name) && $price > 0) {
                            $items[] = ['name' => (strpos($line['text'], '◎') !== false ? '◎' : '') . $name, 'price' => $price];
                        }
                    }
                }
            }
        }
        $results[] = ['file' => $_FILES['receipts']['name'][$key], 'items' => $items, 'total' => $total];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>小票解析最终修正版</title>
    <style>
        body { font-family: sans-serif; background: #f4f4f9; padding: 20px; }
        .box { max-width: 500px; margin: auto; background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .btn { width: 100%; padding: 15px; background: #007bff; color: white; border: none; border-radius: 8px; font-size: 18px; cursor: pointer; }
        .res-card { border-left: 5px solid #28a745; background: #f9f9f9; padding: 15px; margin-top: 20px; }
        .row { display: flex; justify-content: space-between; border-bottom: 1px dashed #ddd; padding: 10px 0; }
        .total-val { text-align: right; color: #dc3545; font-size: 26px; font-weight: bold; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">📑 小票解析准确版</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple><br><br>
            <button type="submit" class="btn">开始扫描</button>
        </form>
        <?php foreach ($results as $res): ?>
            <div class="res-card">
                <small style="color:#999;"><?
