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
    die("数据库连接失败: " . $e->getMessage());
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
        $response = curl_exec($ch);
        $res = json_decode($response, true);
        curl_close($ch);
        if (isset($res['status']) && $res['status'] === 'succeeded') return $res;
    }
    return null;
}

// --- 3. 核心业务逻辑 ---
$displayItems = [];
$totalAmount = 0;
$processed = false;

if (!empty($_FILES['images']['tmp_name'][0])) {
    $processed = true;
    file_put_contents("ocr.log", ""); // 清空旧日志

    foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
        $name = basename($_FILES['images']['name'][$i]);
        $path = $uploadDir . $name;
        
        if (move_uploaded_file($tmp, $path)) {
            $opUrl = analyzeImage($path, $endpoint, $key);
            $ocr = getResult($opUrl, $key);
            
            // 写入日志供下载/查看
            file_put_contents("ocr.log", "--- FILE: $name ---\n" . json_encode($ocr, JSON_UNESCAPED_UNICODE) . "\n\n", FILE_APPEND);

            if ($ocr && isset($ocr['analyzeResult']['readResults'])) {
                foreach ($ocr['analyzeResult']['readResults'][0]['lines'] as $line) {
                    $text = $line['text'];
                    
                    // 过滤黑名单（不显示无关行）
                    if (preg_match('/責No|レジ|番号|領収|合計|小计|消费税|対象|再発行/u', $text)) continue;

                    // 匹配逻辑：[商品名] [任意空格/字符] [金额数字] [轻/税等可选符号]
                    if (preg_match('/^(.+?)\s+.*?([0-9,]{2,5})\s*[轻|軽|*|＊|内]?$/u', $text, $m)) {
                        $pName = trim($m[1]);
                        $price = (int)str_replace(',', '', $m[2]);

                        if ($price >= 50 && $price < 10000) {
                            $displayItems[] = ['name' => $pName, 'price' => $price];
                            $totalAmount += $price;
                            
                            // 写入数据库
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
    if (!empty($displayItems)) {
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
    <title>FamilyMart 识别系统</title>
    <style>
        body { font-family: "Helvetica Neue", Arial, sans-serif; background: #f0f2f5; margin: 0; padding: 40px 20px; }
        .container { max-width: 650px; margin: auto; background: #fff; padding: 30px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        h2 { color: #1a73e8; border-bottom: 2px solid #e8f0fe; padding-bottom: 10px; }
        .upload-form { background: #f8f9fa; padding: 20px; border-radius: 10px; border: 1px dashed #ccc; text-align: center; }
        .result-section { margin-top: 30px; }
        .item-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #eee; }
        .price { font-weight: bold; color: #333; }
        .total-box { font-size: 1.6em; font-weight: bold; text-align: right; margin: 20px 0; color: #d93025; }
        .actions { display: flex; gap: 10px; margin-top: 20px; padding: 20px; background: #e7f3ff; border-radius: 10px; }
        .btn { flex: 1; text-align: center; padding: 12px; background: #1a73e8; color: white; text-decoration: none; border-radius: 6px; font-weight: bold; }
        .btn-log { background: #5f6368; }
        .btn:hover { opacity: 0.9; }
    </style>
</head>
<body>

<div class="container">
    <h2>🏪 FamilyMart 解析系统</h2>
    
    <div class="upload-form">
        <form method="post" enctype="multipart/form-data">
            <p>选择收据图片（支持多选）</p>
            <input type="file" name="images[]" multiple required style="margin-bottom: 15px;">
            <br>
            <button type="submit" style="padding: 10px 25px; background: #34a853; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px;">开始上传并解析</button>
        </form>
    </div>

    <?php if ($processed && !empty($displayItems)): ?>
        <div class="result-section">
            <h3>解析成功：</h3>
            <?php foreach ($displayItems as $it): ?>
                <div class="item-row">
                    <span><?php echo htmlspecialchars($it['name']); ?></span>
                    <span class="price">¥<?php echo number_format($it['price']); ?></span>
                </div>
            <?php endforeach; ?>

            <div class="total-box">合计：¥<?php echo number_format($totalAmount); ?></div>

            <div class="actions">
                <a href="result.csv" class="btn" download>📥 下载 CSV 结果</a>
                <a href="ocr.log" class="btn btn-log" target="_blank">📄 查看 OCR 日志</a>
            </div>
        </div>
    <?php elseif ($processed): ?>
        <div style="margin-top:20px; color: #d93025; background: #fce8e6; padding: 15px; border-radius: 8px;">
            未检测到商品信息。请检查图片是否清晰，或查看日志排查。
            <br><br><a href="ocr.log" target="_blank">查看 ocr.log</a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
