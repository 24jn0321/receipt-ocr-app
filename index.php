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

// --- 3. 核心逻辑 ---
$displayItems = [];
$totalAmount = 0;
$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

if ($isPost && !empty($_FILES['images']['tmp_name'][0])) {
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

                    // 【核心手术 1：强力排除】只要包含这些词，绝对不是商品
                    if (preg_match('/責No|レジ|番号|領収|合計|小計|消費税|対象|支払|残高|2024年/u', $text)) continue;

                    // 【核心手术 2：极其宽松的抓取】
                    // 只要这一行里有 ¥ 符号，且后面跟着数字，我就认定它是商品行
                    if (preg_match('/^(.+?)[^\d]+¥\s*([0-9,]{2,5})/u', $text, $m)) {
                        $pName = trim($m[1]);
                        $price = (int)str_replace(',', '', $m[2]);

                        if ($price > 20) {
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
    $h = fopen('result.csv', 'w');
    fprintf($h, chr(0xEF).chr(0xBB).chr(0xBF));
    foreach ($displayItems as $it) { fputcsv($h, [$it['name'], $it['price']]); }
    fputcsv($h, ['合计', $totalAmount]);
    fclose($h);
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>FamilyMart 解析系统</title>
    <style>
        body { font-family: sans-serif; max-width: 600px; margin: 40px auto; background: #f8f9fa; }
        .card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .item-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; }
        .total { font-size: 1.5em; font-weight: bold; color: #d13438; text-align: right; margin: 20px 0; }
        .btn-box { margin-top: 30px; display: flex; gap: 10px; padding: 15px; background: #eef3f8; border-radius: 8px; }
        .btn { flex: 1; text-align: center; padding: 12px; background: #0078d4; color: white; text-decoration: none; border-radius: 6px; font-weight: bold; cursor: pointer; border: none; }
        .btn-log { background: #666; }
    </style>
</head>
<body>
<div class="card">
    <h2>🏪 FamilyMart 解析系统</h2>
    <form method="post" enctype="multipart/form-data">
        <input type="file" name="images[]" multiple required>
        <button type="submit" class="btn" style="margin-top:10px; width:100%; background:#28a745;">上传并识别</button>
    </form>

    <?php if ($isPost): ?>
        <hr style="margin:20px 0;">
        <?php if ($displayItems): ?>
            <?php foreach ($displayItems as $it): ?>
                <div class="item-row">
                    <span><?php echo htmlspecialchars($it['name']); ?></span>
                    <span>¥<?php echo number_format($it['price']); ?></span>
                </div>
            <?php endforeach; ?>
            <div class="total">合计：¥<?php echo number_format($totalAmount); ?></div>
        <?php else: ?>
            <p style="color:red; font-weight:bold;">未能检测到商品，请确保图片清晰并查看下方日志。</p>
        <?php endif; ?>

        <div class="btn-box">
            <a href="result.csv" class="btn" download>📥 下载 CSV</a>
            <a href="ocr.log" class="btn btn-log" target="_blank">📄 查看日志</a>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
