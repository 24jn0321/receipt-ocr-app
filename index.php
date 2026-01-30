<?php
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$results = [];

// --- 1. 下载功能 ---
if (isset($_GET['action'])) {
    $sessionData = file_exists('ocr_res.json') ? json_decode(file_get_contents('ocr_res.json'), true) : [];
    if ($_GET['action'] == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['文件名', '商品名', '金额']);
        foreach ($sessionData as $res) {
            foreach ($res['items'] as $it) fputcsv($output, [$res['file'], $it['name'], $it['price']]);
            fputcsv($output, [$res['file'], '合计', $res['total']]);
        }
        fclose($output); exit;
    }
    if ($_GET['action'] == 'log') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename=debug.json');
        echo json_encode($sessionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); exit;
    }
}

// --- 2. 核心 OCR 逻辑 ---
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
        $startScanning = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            $noSpace = str_replace([' ', '　', '=', '-', '_'], '', $text);

            // 锚点：看到领收证才开始抓，看到残高或卡号立即停止
            if (mb_strpos($noSpace, '領収') !== false) { $startScanning = true; continue; }
            if (mb_strpos($noSpace, '残高') !== false || mb_strpos($noSpace, '番号') !== false) { break; }

            if ($startScanning) {
                // 排除非商品杂质行
                if (preg_match('/対象|消費税|支払|合计|合計|レジ|軽減/u', $noSpace)) continue;

                // 只要这行有金额符号
                if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                    $price = (int)str_replace(',', '', $matches[1]);
                    
                    // 尝试获取商品名：先看本行 ¥ 前面的字
                    $name = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                    $name = str_replace(['＊', '*', '轻', '軽', '◎', '(', '（', ')', '）'], '', $name);

                    // 如果本行没名字，就往上一行抓
                    if (mb_strlen($name) < 2 && $i > 0) {
                        $prevText = trim($lines[$i-1]['text']);
                        $name = str_replace(['＊', '*', '轻', '軽', '◎', '.', '…', '(', '（', ')', '）', '領収証'], '', $prevText);
                    }

                    if (mb_strlen($name) >= 2 && $price > 0) {
                        $currentItems[] = ['name' => $name, 'price' => $price];
                        $sumAmount += $price;
                    }
                }
            }
        }
        $results[] = ['file' => $_FILES['receipts']['name'][$key], 'items' => $currentItems, 'total' => $sumAmount];
    }
    file_put_contents('ocr_res.json', json_encode($results));
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>小票解析终极修复版</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 20px; line-height: 1.6; }
        .box { max-width: 600px; margin: auto; background: white; padding: 30px; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
        .receipt-card { border-left: 5px solid #00a95c; background: #fafafa; padding: 15px; margin-top: 20px; position: relative; }
        .row { display: flex; justify-content: space-between; border-bottom: 1px dashed #ddd; padding: 8px 0; }
        .total-box { text-align: right; color: #d32f2f; font-size: 24px; font-weight: bold; margin-top: 15px; }
        .btn { width: 100%; padding: 12px; background: #0078d4; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; font-weight: bold; }
        .links { margin-top: 25px; text-align: center; border-top: 1px solid #eee; padding-top: 15px; }
        .links a { margin: 0 15px; text-decoration: none; color: #0078d4; font-size: 14px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center; margin-bottom:25px;">🧾 小票解析系统</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple accept="image/*" style="margin-bottom:20px;"><br>
            <button type="submit" class="btn">开始深度扫描</button>
        </form>

        <?php if ($results): ?>
            <?php foreach ($results as $res): ?>
                <div class="receipt-card">
                    <div style="font-size:12px; color:#999; margin-bottom:10px;">📄 <?= htmlspecialchars($res['file']) ?></div>
                    <?php foreach ($res['items'] as $it): ?>
                        <div class="row">
                            <span><?= htmlspecialchars($it['name']) ?></span>
                            <span>¥<?= number_format($it['price']) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="total-box">合计 ¥<?= number_format($res['total']) ?></div>
                </div>
            <?php endforeach; ?>

            <div class="links">
                <a href="?action=csv">📥 CSV数据下载</a>
                <a href="?action=log">🔍 识别日志备份</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
