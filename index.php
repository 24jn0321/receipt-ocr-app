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
        $scannedTotal = 0;   // 从小票上直接读到的合计
        $calculatedTotal = 0; // 我们自己把商品加起来的合计
        $inProductZone = false;

        foreach ($lines as $line) {
            $text = trim($line['text']);
            $noSpaceText = str_replace([' ', '　'], '', $text);

            // 1. 区域判定：看到“領収証”开始，看到“合計”结束
            if (mb_strpos($noSpaceText, '領収証') !== false) { $inProductZone = true; continue; }
            
            // 2. 抓取“合计”金额（防止扫出 ¥0）
            if (mb_strpos($noSpaceText, '合計') !== false || mb_strpos($noSpaceText, '合計') !== false) {
                if (preg_match('/[¥￥]?([\d,]{2,})/', $noSpaceText, $m)) {
                    $scannedTotal = (int)str_replace(',', '', $m[1]);
                }
                $inProductZone = false; // 看到合计后停止抓取商品
                continue;
            }

            // 3. 抓取商品（必须包含 ¥ 符号）
            if ($inProductZone && preg_match('/[¥￥]/u', $text)) {
                // 排除红框杂项：消费税、支付方式、余额
                if (preg_match('/(消費税|対象|支払|残高|再発行)/u', $noSpaceText)) continue;

                // 分割商品名和价格
                $parts = preg_split('/[¥￥]/u', $text);
                if (count($parts) >= 2) {
                    $name = trim(str_replace(['＊', '*', '轻', '軽'], '', $parts[0]));
                    // 提取数字
                    if (preg_match('/(\d+)/', $parts[1], $m)) {
                        $price = (int)$m[1];
                        if ($price > 0 && !empty($name)) {
                            $currentFileItems[] = ['name' => $name, 'price' => $price];
                            $calculatedTotal += $price; // 累加金额
                        }
                    }
                }
            }
        }

        // 如果扫到的合计是0或者不正常，就用我们自己算的累加值
        $finalTotal = ($scannedTotal > 0) ? $scannedTotal : $calculatedTotal;

        $results[] = [
            'file' => $fileName, 
            'items' => $currentFileItems, 
            'total' => $finalTotal
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>小票解析计算版</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .container { max-width: 700px; margin: auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .receipt-result { border-left: 6px solid #00a95c; background: #fdfdfd; padding: 15px; margin-bottom: 20px; border-bottom: 1px solid #eee; }
        .item-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #ddd; }
        .total-row { font-size: 1.6em; font-weight: bold; color: #d32f2f; margin-top: 15px; text-align: right; }
        .btn { padding: 10px 20px; background: #0078d4; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; width: 100%; }
    </style>
</head>
<body>
    <div class="container">
        <h2 style="text-align:center;">📑 小票解析系统（自动合计版）</h2>
        <form action="" method="post" enctype="multipart/form-data">
            <p>请上传小票照片：</p>
            <input type="file" name="receipts[]" multiple accept="image/*"><br><br>
            <button type="submit" class="btn">解析并计算金额</button>
        </form>

        <?php if (!empty($results)): ?>
            <hr>
            <?php foreach ($results as $res): ?>
                <div class="receipt-result">
                    <p style="color: #666; font-size: 12px;">📄 <?php echo htmlspecialchars($res['file']); ?></p>
                    <?php if (empty($res['items'])): ?>
                        <p style="text-align:center; color:#ccc;">未识别到有效商品</p>
                    <?php endif; ?>
                    
                    <?php foreach ($res['items'] as $i): ?>
                        <div class="item-row">
                            <span><?php echo htmlspecialchars($i['name']); ?></span>
                            <span>¥<?php echo number_format($i['price']); ?></span>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="total-row">
                        <small style="font-size: 0.5em; color: #999; font-weight: normal;">计算合计：</small>
                        合计 ¥<?php echo number_format($res['total']); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
