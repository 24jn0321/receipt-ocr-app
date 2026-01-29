<?php
// エラー表示（デバッグ用）
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* =====================
   1. 配置（Azure & DB）
   ===================== */
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$key      = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 

$serverName = "receipt-server-24jn0.database.windows.net";
$database   = "receiptdb";
$username   = "sqladmin"; 
$password   = "Abc842727925";

$uploadDir = "uploads/";
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

// 数据库连接
try {
    $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("数据库连接失败: " . $e->getMessage());
}

/* =====================
   2. 处理逻辑
   ===================== */
$displayItems = [];
$calcTotal = 0; // 我们自己计算的总额

if (!empty($_FILES['images']['tmp_name'][0])) {
    foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
        $name = basename($_FILES['images']['name'][$i]);
        $path = $uploadDir . $name;
        
        if (move_uploaded_file($tmp, $path)) {
            // Azure OCR 请求
            $url = rtrim($endpoint, '/') . "/vision/v3.2/read/analyze";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ["Ocp-Apim-Subscription-Key: $key", "Content-Type: application/octet-stream"],
                CURLOPT_POSTFIELDS => file_get_contents($path),
                CURLOPT_HEADER => true,
                CURLOPT_RETURNTRANSFER => true
            ]);
            $res = curl_exec($ch);
            preg_match('/Operation-Location: (.*)/i', $res, $m);
            $opUrl = isset($m[1]) ? trim($m[1]) : null;

            // 获取 OCR 结果
            $ocr = null;
            if ($opUrl) {
                for ($j = 0; $j < 10; $j++) {
                    sleep(1);
                    $ch2 = curl_init($opUrl);
                    curl_setopt($ch2, CURLOPT_HTTPHEADER, ["Ocp-Apim-Subscription-Key: $key"]);
                    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                    $ocr = json_decode(curl_exec($ch2), true);
                    if (isset($ocr['status']) && $ocr['status'] === 'succeeded') break;
                }
            }

            // 记录日志供调试
            file_put_contents("ocr.log", "--- FILE: $name ---\n" . json_encode($ocr, JSON_UNESCAPED_UNICODE) . "\n\n", FILE_APPEND);

            // 解析 JSON
            if ($ocr && isset($ocr['analyzeResult']['readResults'][0]['lines'])) {
                foreach ($ocr['analyzeResult']['readResults'][0]['lines'] as $line) {
                    $text = $line['text'];
                    $y = $line['boundingBox'][1]; // 获取纵向高度

                    // 1. 根据你的 JSON 数据，商品内容大致在 Y=2100 到 2600 像素之间
                    if ($y > 2100 && $y < 2600) {
                        
                        // 过滤掉包含“対象”或“消費税”的行，避免重复计算
                        if (mb_strpos($text, '対象') !== false || mb_strpos($text, '税') !== false) continue;

                        // 2. 匹配 [名称] [金额]
                        if (preg_match('/^(.+?)[ \t　]*[¥￥]?([0-9,]{1,7})/u', $text, $m)) {
                            $pName = trim($m[1]);
                            $pName = preg_replace('/^[◎*＊]\s*/u', '', $pName); // 去掉 ◎ 等符号
                            $pName = preg_replace('/(軽|轻|.*)$/u', '', $pName); // 清理末尾杂质
                            $price = (int)str_replace(',', '', $m[2]);

                            if ($price > 0 && mb_strlen($pName) > 1) {
                                $displayItems[] = ['name' => trim($pName), 'price' => $price];
                                $calcTotal += $price;

                                // 存入数据库
                                $stmt = $conn->prepare("INSERT INTO receipts (image_name, product_name, price) VALUES (?, ?, ?)");
                                $stmt->execute([$name, $pName, $price]);
                            }
                        }
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>FamilyMart 识别计算系统</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .container { max-width: 500px; margin: auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .item-row { display: flex; justify-content: space-between; border-bottom: 1px solid #eee; padding: 10px 0; }
        .total-row { font-size: 1.4em; font-weight: bold; color: #d13438; border-top: 2px solid #333; margin-top: 15px; padding-top: 10px; }
    </style>
</head>
<body>

<div class="container">
    <h2>🏪 收据识别 & 自动算账</h2>
    <form method="post" enctype="multipart/form-data">
        <input type="file" name="images[]" multiple required>
        <button type="submit" style="margin-top:10px; padding: 8px 16px; cursor:pointer;">开始识别并求和</button>
    </form>

    <?php if (!empty($displayItems)): ?>
        <div style="margin-top:20px;">
            <?php foreach ($displayItems as $item): ?>
                <div class="item-row">
                    <span><?php echo htmlspecialchars($item['name']); ?></span>
                    <span>¥<?php echo number_format($item['price']); ?></span>
                </div>
            <?php endforeach; ?>

            <div class="item-row total-row">
                <span>合計</span>
                <span>¥<?php echo number_format($calcTotal); ?></span>
            </div>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
