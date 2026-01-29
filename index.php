<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- 1. 配置 (Azure & DB) ---
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$key      = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 
$serverName = "receipt-server-24jn0.database.windows.net";
$database   = "receiptdb";
$username   = "sqladmin"; 
$password   = "Abc842727925";
$uploadDir = "uploads/";
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

try {
    $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("数据库连接失败");
}

// --- 2. 功能函数 ---
function analyzeImage($image, $endpoint, $key) {
    $url = rtrim($endpoint, '/') . "/vision/v3.2/read/analyze";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Ocp-Apim-Subscription-Key: $key", "Content-Type: application/octet-stream"],
        CURLOPT_POSTFIELDS => file_get_contents($image),
        CURLOPT_HEADER => true,
        CURLOPT_RETURNTRANSFER => true
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    preg_match('/Operation-Location: (.*)/i', $res, $m);
    return isset($m[1]) ? trim($m[1]) : null;
}

function getResult($url, $key) {
    for ($i = 0; $i < 12; $i++) {
        sleep(2);
        $ch = curl_init(trim($url));
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ["Ocp-Apim-Subscription-Key: $key"], CURLOPT_RETURNTRANSFER => true]);
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);
        if (isset($res['status']) && $res['status'] === 'succeeded') return $res;
    }
    return null;
}

// --- 3. 业务处理 ---
$displayItems = [];
$totalAmount = 0;
$processed = false;

if (!empty($_FILES['images']['tmp_name'][0])) {
    $processed = true;
    file_put_contents("ocr.log", ""); 

    foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
        $name = basename($_FILES['images']['name'][$i]);
        $path = $uploadDir . $name;
        if (move_uploaded_file($tmp, $path)) {
            $ocr = getResult(analyzeImage($path, $endpoint, $key), $key);
            file_put_contents("ocr.log", "FILE: $name\n" . json_encode($ocr, JSON_UNESCAPED_UNICODE) . "\n\n", FILE_APPEND);

            if ($ocr && isset($ocr['analyzeResult']['readResults'])) {
                foreach ($ocr['analyzeResult']['readResults'][0]['lines'] as $line) {
                    $text = $line['text'];

                    // 1. 严格过滤：排除包含日期、责任号、合计、消费税等关键词的行
                    if (preg_match('/202[4-6]年|責No|番号|合計|小計|消費税|支払|対象|レジ|残高/u', $text)) continue;

                    // 2. 核心正则：根据你提供的 LOG，匹配 [名称] + [空格/符号] + [数字] + [可能有的轻/税/杂质]
                    // 修正后的正则：匹配 1-4 位数字，并处理末尾可能有的"轻"
                    if (preg_match('/^(.+?)\s+.*?([0-9,]{2,5})\s*(?:軽|轻|＊|\*|税|内)?$/u', $text, $m)) {
                        $pName = trim($m[1]);
                        $price = (int)str_replace(',', '', $m[2]);

                        // 3. 金额二次校验：FamilyMart 饮料通常在 50-1000 元
                        if ($price > 50 && $price < 10000) {
                            $displayItems[] = ['name' => $pName, 'price' => $price];
                            $totalAmount += $price;

                            try {
                                $stmt = $conn->prepare("INSERT INTO receipts (image_name, product_name, price) VALUES (?, ?, ?)");
                                $stmt->execute([$name, $pName, $price]);
                            } catch (Exception $e) {}
                        }
                    }
                }
            }
        }
    }
    // 生成 CSV
    if ($displayItems) {
        $h = fopen('result.csv', 'w');
        fprintf($h, chr(0xEF).chr(0xBB).chr(0xBF));
        foreach ($displayItems as $it) { fputcsv($h, [$it['name'], $it['price']]); }
        fputcsv($h, ['合计', $totalAmount]);
        fclose($h);
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>FamilyMart 解析系统</title>
    <style>
        body { font-family: sans-serif; max-width: 600px; margin: 40px auto; background: #f4f7f6; line-height: 1.6; }
        .card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .item-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; }
        .total { font-size: 1.5em; font-weight: bold; color: #d13438; text-align: right; margin: 20px 0; }
        .btn-box { margin-top: 30px; display: flex; gap: 10px; }
        .btn { flex: 1; text-align: center; padding: 12px; background: #0078d4; color: white; text-decoration: none; border-radius: 6px; font-weight: bold; }
        .btn-log { background: #666; }
    </style>
</head>
<body>
<div class="card">
    <h2>🏪 FamilyMart 解析 (日志修正版)</h2>
    <form method="post" enctype="multipart/form-data">
        <input type="file" name="images[]" multiple required>
        <button type="submit" style="margin-top:10px; padding: 10px 20px; background:#28a745; color:white; border:none; border-radius:5px; cursor:pointer;">上传并识别</button>
    </form>

    <?php if ($processed): ?>
        <hr style="margin:20px 0;">
        <?php if ($displayItems): ?>
            <h3>识别到商品：</h3>
            <?php foreach ($displayItems as $it): ?>
                <div class="item-row">
                    <span><?php echo htmlspecialchars($it['name']); ?></span>
                    <span>¥<?php echo number_format($it['price']); ?></span>
                </div>
            <?php endforeach; ?>
            <div class="total">合计金额：¥<?php echo number_format($totalAmount); ?></div>
        <?php else: ?>
            <p style="color:red;">未能提取到有效商品数据，请检查日志。</p>
        <?php endif; ?>

        <div class="btn-box">
            <a href="result.csv" class="btn">📥 下载 CSV</a>
            <a href="ocr.log" class="btn btn-log" target="_blank">📄 查看日志</a>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
