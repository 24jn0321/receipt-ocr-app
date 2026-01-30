<?php
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$results = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        $fileName = $_FILES['receipts']['name'][$key];

        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
        $imageData = file_get_contents($tmpName);

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imageData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        
        $currentFileItems = [];
        $sumAmount = 0;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            $noSpaceText = str_replace([' ', '　'], '', $text);

            // 过滤黑名单：如果是消费税、支付方式、残高等，直接跳过整行
            if (preg_match('/Family|新宿|电话|登録|2024|レジ|領収|対象|消費税|支払|残高|証|単価/u', $noSpaceText)) {
                continue;
            }

            // --- 核心修改：增加对“同一行有名字和钱”的处理 ---
            
            // 情况 A: 名字和钱在【同一行】 (如：アポロチョコレート ¥198)
            if (preg_match('/^(.*)[¥￥]([\d,]+)/u', $text, $matches)) {
                $name = trim(str_replace(['＊', '*', '轻', '軽', '◎'], '', $matches[1]));
                $price = (int)str_replace(',', '', $matches[2]);
                
                if (mb_strlen($name) >= 2 && $price > 0) {
                    $currentFileItems[] = ['name' => $name, 'price' => $price];
                    $sumAmount += $price;
                    continue; // 处理完这一行，跳过
                }
            }

            // 情况 B: 名字在这一行，钱在【下一行】 (你之前的逻辑)
            if (!preg_match('/[¥￥]/u', $text) && mb_strlen($text) >= 2) {
                if (isset($lines[$i + 1])) {
                    $nextText = $lines[$i + 1]['text'];
                    if (preg_match('/[¥￥]([\d,]+)/u', $nextText, $matches)) {
                        $price = (int)str_replace(',', '', $matches[1]);
                        
                        // 确保下一行不是消费税或余额
                        if (!preg_match('/消費税|対象|残高|支払/u', $nextText)) {
                            $cleanName = str_replace(['＊', '*', '轻', '軽', '◎'], '', $text);
                            $currentFileItems[] = ['name' => trim($cleanName), 'price' => $price];
                            $sumAmount += $price;
                            $i++; // 跳过下一行（因为钱已经拿到了）
                        }
                    }
                }
            }
        }
        $results[] = ['file' => $fileName, 'items' => $currentFileItems, 'total' => $sumAmount];
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>收据解析最终版</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .container { max-width: 700px; margin: auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .receipt-result { border-left: 6px solid #00a95c; background: #fdfdfd; padding: 15px; margin-bottom: 20px; }
        .item-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #ddd; }
        .total-row { font-size: 1.6em; font-weight: bold; color: #d32f2f; margin-top: 15px; text-align: right; }
        .btn { padding: 10px 20px; background: #0078d4; color: white; border: none; border-radius: 5px; cursor: pointer; width: 100%; }
    </style>
</head>
<body>
    <div class="container">
        <h2 style="text-align:center;">📑 收据智能解析系统</h2>
        <form action="" method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple accept="image/*"><br><br>
            <button type="submit" class="btn">开始扫描</button>
        </form>

        <?php if (!empty($results)): ?>
            <hr>
            <?php foreach ($results as $res): ?>
                <div class="receipt-result">
                    <p style="color: #666; font-size:12px;">📄 <?php echo htmlspecialchars($res['file']); ?></p>
                    <?php foreach ($res['items'] as $i): ?>
                        <div class="item-row">
                            <span><?php echo htmlspecialchars($i['name']); ?></span>
                            <span>¥<?php echo number_format($i['price']); ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="total-row">合计 ¥<?php echo number_format($res['total']); ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
