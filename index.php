<?php
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$results = [];

// 下载处理
if (isset($_GET['action'])) {
    $sessionData = file_exists('ocr_data.json') ? json_decode(file_get_contents('ocr_data.json'), true) : [];
    if ($_GET['action'] == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['文件名', '项目', '金额']);
        foreach ($sessionData as $res) {
            foreach ($res['items'] as $it) fputcsv($output, [$res['file'], $it['name'], $it['price']]);
            fputcsv($output, [$res['file'], '合计', $res['total']]);
        }
        fclose($output); exit;
    }
}

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
        $stopFlag = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            $noSpace = str_replace([' ', '　', '=', '-', '_', '＊', '*', '◎'], '', $text);

            // 1. 核心防御：一旦进入结算区（合计/支付/残高），立刻停止抓取商品
            if (preg_match('/合计|合計|支付|支払|残高|番号|カード|対象|消費税/u', $noSpace)) {
                // 如果已经抓到过商品，看到这些词就彻底结束
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            
            if ($stopFlag) continue; // 已经在结算区了，后面的行都不看

            // 2. 识别带 ¥ 的行
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                
                // 尝试提取本行名字
                $name = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                
                // 【针对3号及所有小票】：如果本行没抓到名字，就取上一行
                if (mb_strlen($name) < 2 && $i > 0) {
                    $name = trim($lines[$i-1]['text']);
                }

                // 清洗名字
                $cleanName = str_replace(['＊', '*', '轻', '軽', '◎', '(', '（', ')', '）', '.', '．', '…'], '', $name);
                
                // 排除一些误抓的地址或标题
                if (mb_strlen($cleanName) >= 2 && !preg_match('/Family|新宿|电话|登録|領収/u', $cleanName)) {
                    $currentItems[] = ['name' => $cleanName, 'price' => $price];
                    $sumAmount += $price;
                }
            }
        }
        $results[] = ['file' => $_FILES['receipts']['name'][$key], 'items' => $currentItems, 'total' => $sumAmount];
    }
    file_put_contents('ocr_data.json', json_encode($results));
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>小票全兼容解析</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .box { max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .card { border-left: 5px solid #00a95c; background: #fdfdfd; padding: 15px; margin-top: 15px; }
        .row { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px dashed #ddd; }
        .total { font-size: 22px; font-weight: bold; color: #d32f2f; text-align: right; margin-top: 10px; }
        .btn { width: 100%; padding: 10px; background: #0078d4; color: white; border: none; cursor: pointer; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">🧾 小票解析系统 (全兼容版)</h2>
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
            <p style="text-align:center;"><a href="?action=csv">📥 下载 CSV 报表</a></p>
        <?php endif; ?>
    </div>
</body>
</html>
