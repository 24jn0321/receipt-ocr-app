<?php
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$results = [];

// --- 下载功能逻辑 ---
if (isset($_GET['action'])) {
    $sessionData = file_exists('ocr_cache.json') ? json_decode(file_get_contents('ocr_cache.json'), true) : [];
    if ($_GET['action'] == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['File', 'Item', 'Price']);
        foreach ($sessionData as $res) {
            foreach ($res['items'] as $it) fputcsv($output, [$res['file'], $it['name'], $it['price']]);
            fputcsv($output, [$res['file'], 'TOTAL', $res['total']]);
        }
        fclose($output); exit;
    }
    if ($_GET['action'] == 'log') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename=log.json');
        echo json_encode($sessionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); exit;
    }
}

// --- 核心解析逻辑 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        $fileName = $_FILES['receipts']['name'][$key];
        $imageData = file_get_contents($tmpName);

        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
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
        $sumTotal = 0;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            $cleanText = str_replace([' ', '　', '＊', '*', '轻', '軽', '◎'], '', $text);

            // 过滤无用行
            if (preg_match('/Family|新宿|电话|登録|2024|レジ|領収|対象|消費税|支払|残高|証|単価/u', $cleanText)) continue;

            // --- 补丁：处理 3 号小票这种“名字和钱在同一行”的情况 ---
            if (preg_match('/^(.*)[¥￥]([\d,]+)/u', $text, $matches)) {
                $name = trim(str_replace(['.', '．'], '', $matches[1]));
                $price = (int)str_replace(',', '', $matches[2]);
                if (mb_strlen($name) >= 2 && !preg_match('/合计|合計|支付|残高/u', $name)) {
                    $currentFileItems[] = ['name' => $name, 'price' => $price];
                    $sumTotal += $price;
                    continue; 
                }
            }

            // --- 还原：原本对 1 号和 2 号小票有效的“跨行”逻辑 ---
            if (mb_strlen($cleanText) >= 2 && !preg_match('/[¥￥]/u', $text)) {
                if (isset($lines[$i + 1])) {
                    $nextText = $lines[$i + 1]['text'];
                    if (preg_match('/[¥￥]([\d,]+)/u', $nextText, $matches)) {
                        // 确保下一行不是最后的余额行
                        if (!preg_match('/残高|支払|对象/u', $nextText)) {
                            $price = (int)str_replace(',', '', $matches[1]);
                            $currentFileItems[] = ['name' => trim($cleanText), 'price' => $price];
                            $sumTotal += $price;
                            $i++; 
                        }
                    }
                }
            }
        }
        $results[] = ['file' => $fileName, 'items' => $currentFileItems, 'total' => $sumTotal];
    }
    file_put_contents('ocr_cache.json', json_encode($results));
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>小票解析最终修复版</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .box { max-width: 650px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .receipt-card { border-left: 5px solid #00a95c; background: #fafafa; padding: 15px; margin-top: 20px; border-bottom: 1px solid #eee; }
        .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #ccc; }
        .total { font-size: 24px; font-weight: bold; color: #d32f2f; text-align: right; margin-top: 10px; }
        .btn { width: 100%; padding: 12px; background: #0078d4; color: white; border: none; border-radius: 6px; cursor: pointer; }
        .download { text-align: center; margin-top: 20px; }
        .download a { margin: 0 10px; color: #0078d4; text-decoration: none; font-size: 14px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">🧾 收据智能解析系统</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple accept="image/*"><br><br>
            <button type="submit" class="btn">解析全部小票</button>
        </form>

        <?php if ($results): ?>
            <?php foreach ($results as $res): ?>
                <div class="receipt-card">
                    <small style="color: #999;"><?= htmlspecialchars($res['file']) ?></small>
                    <?php if (empty($res['items'])): ?>
                        <p style="color:orange;">未检测到商品</p>
                    <?php else: ?>
                        <?php foreach ($res['items'] as $it): ?>
                            <div class="row">
                                <span><?= htmlspecialchars($it['name']) ?></span>
                                <span>¥<?= number_format($it['price']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <div class="total">合计 ¥<?= number_format($res['total']) ?></div>
                </div>
            <?php endforeach; ?>
            <div class="download">
                <a href="?action=csv">📥 下载 CSV 报表</a> | <a href="?action=log">📜 下载日志文件</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
