<?php
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$results = [];

// --- 1. 下载功能逻辑 (CSV 和 日志) ---
if (isset($_GET['action'])) {
    $sessionData = file_exists('ocr_cache.json') ? json_decode(file_get_contents('ocr_cache.json'), true) : [];
    
    if ($_GET['action'] == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_export.csv');
        echo "\xEF\xBB\xBF"; // 解决Excel乱码
        $output = fopen('php://output', 'w');
        fputcsv($output, ['文件名', '商品名', '单价']);
        foreach ($sessionData as $res) {
            foreach ($res['items'] as $it) fputcsv($output, [$res['file'], $it['name'], $it['price']]);
            fputcsv($output, [$res['file'], '合计(累加)', $res['total']]);
        }
        fclose($output); exit;
    }
    
    if ($_GET['action'] == 'log') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=ocr_debug_log.json');
        echo json_encode($sessionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); exit;
    }
}

// --- 2. 核心解析逻辑 (还原回你最满意的版本) ---
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
        $sumTotal = 0; // 用于累加每一项商品金额

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);

            // 完全还原你的过滤逻辑
            if (!preg_match('/[¥￥]/u', $text) && 
                !preg_match('/Family|新宿|电话|登録|2024|レジ|領収|対象|消費税|支払|残高|証|单价|単価/u', $text) &&
                mb_strlen($text) >= 2) {
                
                if (isset($lines[$i + 1])) {
                    $nextText = $lines[$i + 1]['text'];
                    if (preg_match('/[¥￥]([\d,]+)/u', $nextText, $matches)) {
                        
                        // 额外保护：确保不是最后的余额或支付行
                        if (!preg_match('/残高|支払|対象/u', $nextText)) {
                            $price = (int)str_replace(',', '', $matches[1]);
                            $cleanName = str_replace(['＊', '*', '轻', '軽', '◎'], '', $text);
                            
                            $currentFileItems[] = ['name' => trim($cleanName), 'price' => $price];
                            $sumTotal += $price; // 执行金额累加
                            
                            $i++; // 跳过金额行
                            continue;
                        }
                    }
                }
            }
        }
        $results[] = ['file' => $fileName, 'items' => $currentFileItems, 'total' => $sumTotal];
    }
    // 保存缓存用于下载
    file_put_contents('ocr_cache.json', json_encode($results));
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>收据解析系统 - 最终还原版</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .container { max-width: 700px; margin: auto; background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .receipt-result { border-left: 6px solid #00a95c; background: #fdfdfd; padding: 15px; margin-bottom: 20px; border-bottom: 1px solid #eee; }
        .item-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #ddd; }
        .total-row { font-size: 1.6em; font-weight: bold; color: #d32f2f; margin-top: 15px; text-align: right; }
        .btn { padding: 10px 20px; background: #0078d4; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; width: 100%; }
        .download-area { margin-top: 20px; text-align: center; border-top: 1px solid #eee; padding-top: 15px; }
        .download-area a { margin: 0 15px; color: #0078d4; text-decoration: none; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <h2 style="text-align:center;">📑 收据智能解析系统</h2>
        <form action="" method="post" enctype="multipart/form-data">
            <p>请选择多张小票图片：</p>
            <input type="file" name="receipts[]" multiple accept="image/*"><br><br>
            <button type="submit" class="btn">还原解析并累加金额</button>
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

            <div class="download-area">
                <a href="?action=csv">📊 下载 CSV 报表</a>
                <a href="?action=log">📜 下载日志文件</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
