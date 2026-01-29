<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- 配置区 ---
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

// --- OCR 处理函数 ---
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

// --- 核心业务逻辑 ---
$displayItems = [];
$totalAmount = 0;

if (!empty($_FILES['images']['tmp_name'][0])) {
    file_put_contents("ocr.log", ""); 
    foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
        $name = basename($_FILES['images']['name'][$i]);
        $path = $uploadDir . $name;
        if (move_uploaded_file($tmp, $path)) {
            $opUrl = analyzeImage($path, $endpoint, $key);
            $ocr = getResult($opUrl, $key);
            file_put_contents("ocr.log", "FILE: $name\n" . json_encode($ocr, JSON_UNESCAPED_UNICODE) . "\n\n", FILE_APPEND);

            if ($ocr && isset($ocr['analyzeResult']['readResults'])) {
                foreach ($ocr['analyzeResult']['readResults'] as $page) {
                    foreach ($page['lines'] as $line) {
                        $text = $line['text'];
                        
                        // 1. 黑名单：彻底排除干扰行
                        if (preg_match('/責No|レジ|番号|領収|合計|小計|消費税|対象|再発行/u', $text)) continue;

                        // 2. 核心正则：匹配 [商品名] + [空格/￥] + [数字] + [结尾杂质]
                        // 特别加强了对宽空格 \s+ 和 价格末尾“轻”字的容错
                        if (preg_match('/^(.+?)[\s　¥￥]+([0-9,]{2,6})(?:\s*[轻|軽|*|＊|内])?$/u', $text, $m)) {
                            $pName = trim($m[1]);
                            $price = (int)str_replace(',', '', $m[2]);

                            if ($price > 50 && $price < 10000) {
                                $displayItems[] = ['name' => $pName, 'price' => $price];
                                $totalAmount += $price;
                                // 写入 DB
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
    <title>FamilyMart 收据识别</title>
    <style>
        body { font-family: sans-serif; max-width: 600px; margin: 40px auto; background: #f4f7f6; }
        .card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .item { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; }
        .total { font-size: 1.5em; font-weight: bold; color: #d13438; text-align: right; margin-top: 20px; }
        .download-box { margin-top: 25px; background: #eef3f8; padding: 15px; border-radius: 8px; }
        .btn { display: inline-block; padding: 10px 15px; background: #0078d4; color: white; text-decoration: none; border-radius: 5px; margin-right: 10px; font-weight: bold; }
    </style>
</head>
<body>
<div class="card">
    <h2>🏪 FamilyMart 收据识别系统</h2>
    <form method="post" enctype="multipart/form-data">
        <input type="file" name="images[]" multiple required>
        <button type="submit" style="padding: 10px 20px; cursor: pointer; background: #28a745; color: white; border: none; border-radius: 5px;">开始上传并解析</button>
    </form>

    <?php if ($displayItems): ?>
        <div style="margin-top: 25px;">
            <h3>解析结果：</h3>
            <?php foreach ($displayItems as $it): ?>
                <div class="item">
                    <span><?php echo htmlspecialchars($it['name']); ?></span>
                    <span>¥<?php echo number_format($it['price']); ?></span>
                </div>
            <?php endforeach; ?>
            <div class="total">合计：¥<?php echo number_format($totalAmount); ?></div>
            
            <div class="download-box">
                <strong>📥 下载验证数据：</strong><br><br>
                <a href="result.csv" class="btn" download>下载 CSV 文件</a>
                <a href="ocr.log" class="btn" target="_blank">查看 ocr.log 日志</a>
            </div>
        </div>
    <?php elseif ($_SERVER['REQUEST_METHOD'] == 'POST'): ?>
        <div style="color: red; margin-top: 20px; padding: 15px; background: #fff1f0; border-radius: 5px;">
            未能提取到商品。请确认图片清晰并检查日志。
            <br><br><a href="ocr.log" target="_blank">查看 ocr.log</a>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
