<?php
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$results = [];

// --- 下载功能处理 ---
if (isset($_GET['action'])) {
    $sessionData = file_exists('last_ocr.json') ? json_decode(file_get_contents('last_ocr.json'), true) : [];
    
    if ($_GET['action'] == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_data.csv');
        echo "\xEF\xBB\xBF"; // 防止 Excel 乱码
        $output = fopen('php://output', 'w');
        fputcsv($output, ['文件名', '项目', '金额']);
        foreach ($sessionData as $res) {
            foreach ($res['items'] as $item) fputcsv($output, [$res['file'], $item['name'], $item['price']]);
            fputcsv($output, [$res['file'], '合计', $res['total']]);
        }
        fclose($output); exit;
    }
    
    if ($_GET['action'] == 'log') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename=ocr_debug.log');
        echo "OCR 原始识别日志备份\n" . str_repeat('=', 30) . "\n";
        print_r($sessionData); exit;
    }
}

// --- OCR 处理逻辑 ---
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
        $inZone = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            $noSpace = str_replace([' ', '　'], '', $text);

            // 1. 范围锁定：看到领收证开始，看到残高/卡号/二维码彻底结束
            if (mb_strpos($noSpace, '領収証') !== false) { $inZone = true; continue; }
            if (mb_strpos($noSpace, '残高') !== false || mb_strpos($noSpace, '番号') !== false) { $inZone = false; break; }

            if ($inZone) {
                // 排除不需要的行
                if (preg_match('/消費税|対象|支払|合計|レジ/u', $noSpace)) continue;

                // 检查这一行是否有钱
                if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                    $price = (int)str_replace(',', '', $matches[1]);
                    
                    // 尝试获取商品名
                    // 先看同一行 ¥ 符号前有没有文字
                    $name = trim(preg_replace('/[¥￥].*$/u', '', $text));
                    $name = str_replace(['＊', '*', '轻', '軽', '◎', '.'], '', $name);

                    // 如果这一行名字太短，去上一行找
                    if (mb_strlen($name) < 2 && $i > 0) {
                        $prevText = trim($lines[$i-1]['text']);
                        if (!preg_match('/[¥￥]/u', $prevText)) {
                            $name = str_replace(['＊', '*', '轻', '軽', '◎', '.'], '', $prevText);
                        }
                    }

                    if ($price > 0 && mb_strlen($name) >= 2) {
                        $currentItems[] = ['name' => $name, 'price' => $price];
                        $sumAmount += $price;
                    }
                }
            }
        }
        $results[] = ['file' => $_FILES['receipts']['name'][$key], 'items' => $currentItems, 'total' => $sumAmount];
    }
    file_put_contents('last_ocr.json', json_encode($results));
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>小票最终修复版</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 20px; }
        .box { max-width: 650px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .receipt-card { border-left: 5px solid #00a95c; background: #fafafa; padding: 15px; margin-top: 20px; border-bottom: 1px solid #eee; }
        .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #ccc; }
        .total { font-size: 24px; font-weight: bold; color: #d32f2f; text-align: right; margin-top: 10px; }
        .btn { width: 100%; padding: 12px; background: #0078d4; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; }
        .tools { display: flex; gap: 10px; margin-top: 20px; justify-content: center; }
        .tools a { text-decoration: none; font-size: 14px; color: #0078d4; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">🧾 收据智能解析系统</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple accept="image/*"><br><br>
            <button type="submit" class="btn">开始精准扫描</button>
        </form>

        <?php if ($results): ?>
            <?php foreach ($results as $res): ?>
                <div class="receipt-card">
                    <small style="color: #999;"><?= htmlspecialchars($res['file']) ?></small>
                    <?php foreach ($res['items'] as $it): ?>
                        <div class="row">
                            <span><?= htmlspecialchars($it['name']) ?></span>
                            <span>¥<?= number_format($it['price']) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="total">合计 ¥<?= number_format($res['total']) ?></div>
                </div>
            <?php endforeach; ?>

            <div class="tools">
                <a href="?action=csv">📥 下载 CSV 报表</a>
                <span style="color:#ddd;">|</span>
                <a href="?action=log">📄 下载识别日志</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
