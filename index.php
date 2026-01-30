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
        $inSettleZone = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            $noSpace = str_replace([' ', '　'], '', $text);

            // 1. 结算区预警：看到这些词，后面的 ¥ 行绝对不是商品
            if (preg_match('/合計|合计|支払|支付|残高|番号|再発行/u', $noSpace)) {
                $inSettleZone = true;
                continue;
            }
            if ($inSettleZone) continue;

            // 2. 识别金额行
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                
                // 排除税费说明行
                if (preg_match('/对象|対象|消費税|内訳/u', $noSpace)) continue;

                $finalName = "";
                // 尝试本行提取名字（去掉 ¥ 及其后的数字）
                $thisLineName = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                
                if (mb_strlen($thisLineName) >= 2 && !preg_match('/領収|领收/u', $thisLineName)) {
                    $finalName = $thisLineName;
                } else {
                    // 【跳跃搜索补丁】：如果本行没名字，向上找最多3行，跳过带“对象/消费税/领收”的行
                    for ($p = $i - 1; $p >= max(0, $i - 3); $p--) {
                        $pText = trim($lines[$p]['text']);
                        $pNoSpace = str_replace([' ', '　'], '', $pText);
                        if (mb_strlen($pNoSpace) >= 2 && !preg_match('/領収|领收|对象|対象|消費税|Family|電話|登録/u', $pNoSpace)) {
                            $finalName = $pText;
                            break;
                        }
                    }
                }

                if (!empty($finalName)) {
                    // 仅清理末尾的虚线点，保留开头的符号（如 ◎）
                    $cleanName = rtrim($finalName, ".．… ");
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
    <title>收据解析全修复版</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .box { max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .card { border-left: 5px solid #00a95c; background: #fafafa; padding: 15px; margin-top: 20px; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #ccc; }
        .total { font-size: 26px; font-weight: bold; color: #d32f2f; text-align: right; margin-top: 15px; }
        .btn { width: 100%; padding: 12px; background: #0078d4; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">🧾 小票解析系统 (V5稳定版)</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple accept="image/*"><br><br>
            <button type="submit" class="btn">开始全量解析</button>
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
