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
        $response = curl_exec($ch); curl_close($ch);

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];

        $currentItems = [];
        $sumAmount = 0;
        $stopScanning = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            $noSpace = str_replace([' ', '　', '=', '-', '_', '＊', '*', '◎'], '', $text);

            // 1. 严格封锁结算区：一旦看到这些词，后面哪怕有 ¥ 也不再抓取商品
            if (preg_match('/合計|合计|支払|支付|残高|番号|カード/u', $noSpace)) {
                $stopScanning = true;
                continue;
            }
            if ($stopScanning) continue;

            // 2. 识别带 ¥ 的金额行
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                
                // 排除税费行（如 8%对象、内消费税等）
                if (preg_match('/对象|対象|消費税|内訳/u', $noSpace)) continue;

                // --- 名字提取策略 ---
                $name = "";
                // 先尝试从本行 ¥ 符号前面拿名字
                $rawName = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                
                if (mb_strlen($rawName) >= 2 && !preg_match('/领收|領収|对象|対象/u', $rawName)) {
                    $name = $rawName;
                } else if ($i > 0) {
                    // 【关键修正】：如果本行没名字，向上找一行，但必须排除掉包含“领收”或“对象”的行
                    for ($prevIdx = $i - 1; $prevIdx >= max(0, $i - 2); $prevIdx--) {
                        $prevText = trim($lines[$prevIdx]['text']);
                        if (mb_strlen($prevText) >= 2 && !preg_match('/領収|领收|对象|対象|Family|电话|登録/u', $prevText)) {
                            $name = $prevText;
                            break;
                        }
                    }
                }

                if (!empty($name)) {
                    $cleanName = str_replace(['＊', '*', '轻', '軽', '◎', '(', '（', ')', '）', '.', '．', '…'], '', $name);
                    $currentItems[] = ['name' => $cleanName, 'price' => $price];
                    $sumAmount += $price;
                }
            }
        }
        $results[] = ['file' => $_FILES['receipts']['name'][$key], 'items' => $currentItems, 'total' => $sumAmount];
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>收据解析最终优化版</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .box { max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .card { border-left: 5px solid #00a95c; background: #fdfdfd; padding: 15px; margin-top: 15px; border-bottom: 1px solid #eee; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #ddd; }
        .total { font-size: 24px; font-weight: bold; color: #d32f2f; text-align: right; margin-top: 15px; }
        .btn { width: 100%; padding: 12px; background: #0078d4; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">🧾 小票解析系统</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple><br><br>
            <button type="submit" class="btn">解析全部图片</button>
        </form>

        <?php if ($results): ?>
            <?php foreach ($results as $res): ?>
                <div class="card">
                    <small style="color:#999"><?= htmlspecialchars($res['file']) ?></small>
                    <?php foreach ($res['items'] as $it): ?>
                        <div class="row">
                            <span><?= htmlspecialchars($it['name']) ?></span>
                            <span>¥<?= number_format($it['price']) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="total">合计 ¥<?= number_format($res['total']) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
