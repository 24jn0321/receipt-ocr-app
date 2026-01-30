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
        $resp = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($resp, true);
        $lines = $json['readResult']['blocks'][0]['lines'] ?? [];

        $items = []; $total = 0;
        $is_after_header = false; // 是否过了“领收证”
        $is_total_done = false;   // 是否已经抓到合计

        foreach ($lines as $line) {
            $text = trim($line['text']);

            // 1. 开启点：看到“領収証”，才开始正式识别商品
            if (preg_match('/領\s*収\s*証/u', $text)) {
                $is_after_header = true; continue;
            }

            if (!$is_after_header || $is_total_done) continue;

            // 2. 识别合计：一旦看到“合計”，抓完数立马走人，后面什么都不看
            if (preg_match('/合計/u', $text)) {
                if (preg_match('/([\d,]+)/', $text, $m)) {
                    $total = (int)str_replace(',', '', $m[1]);
                    $is_total_done = true; // 彻底锁死
                }
                continue;
            }

            // 3. 识别商品：包含 ¥ 的行，且只要文字和数字
            if (preg_match('/[¥￥]/u', $text)) {
                // 排除干扰行（比如消费税这类虽然带¥但不是商品的内容）
                if (preg_match('/(税|対象|支払)/u', $text)) continue;

                // 提取：把名字和价格分出来
                // 格式如：◎チョコバターメロンパ ¥168轻
                if (preg_match('/^(.*?)[¥￥]\s?([\d,]+)/u', $text, $m)) {
                    $rawName = trim($m[1]);
                    $price = (int)str_replace(',', '', $m[2]);

                    // 清洗名字：去掉末尾多余的符号
                    $cleanName = str_replace(['＊', '*', '轻'], '', $rawName);
                    
                    if (!empty($cleanName)) {
                        $items[] = ['name' => $cleanName, 'price' => $price];
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
    <title>FamilyMart 专用解析</title>
    <style>
        body { font-family: sans-serif; background: #f4f4f4; padding: 20px; }
        .receipt-box { max-width: 400px; margin: 20px auto; background: white; padding: 20px; border: 1px solid #ddd; border-radius: 8px; }
        .item-line { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #eee; }
        .total-line { margin-top: 15px; text-align: right; color: #d32f2f; font-size: 22px; font-weight: bold; }
        .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div style="text-align:center;">
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple>
            <button type="submit">解析这些小票</button>
        </form>
    </div>

    <?php foreach ($results as $res): ?>
        <div class="receipt-box">
            <div class="header">解析结果：<?=$res['file']?></div>
            <?php foreach ($res['items'] as $it): ?>
                <div class="item-line">
                    <span><?= htmlspecialchars($it['name']) ?></span>
                    <span>¥<?= number_format($it['price']) ?></span>
                </div>
            <?php endforeach; ?>
            <div class="total-line">总计 ¥<?= number_format($res['total']) ?></div>
        </div>
    <?php endforeach; ?>
</body>
</html>
