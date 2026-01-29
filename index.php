<?php
/* =====================
   1. 配置（Azure AI Vision 和 数据库）
   ===================== */
// ★ 请在此处填写你 Azure AI Vision 的端点和密钥
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$key      = "填写你的Vision密钥"; 
$uploadDir = "uploads/";

// 数据库连接信息（根据你提供的信息已填好）
$serverName = "receipt-server-24jn0.database.windows.net"; // 后面一定要带 .database.windows.net
$database   = "receiptdb";
$username   = "sqladmin"; 
$password   = "Abc842727925";

/* =====================
   2. 建立数据库连接 (PDO)
   ===================== */
try {
    $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("数据库连接失败: " . $e->getMessage());
}

/* =====================
   3. OCR 解析函数
   ===================== */
function analyzeImage($image, $endpoint, $key) {
    $url = $endpoint . "vision/v3.2/read/analyze";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Ocp-Apim-Subscription-Key: $key",
            "Content-Type: application/octet-stream"
        ],
        CURLOPT_POSTFIELDS => file_get_contents($image),
        CURLOPT_HEADER => true,
        CURLOPT_RETURNTRANSFER => true
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    preg_match('/Operation-Location: (.*)/', $res, $m);
    return isset($m[1]) ? trim($m[1]) : null;
}

function getResult($url, $key) {
    do {
        sleep(1);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ["Ocp-Apim-Subscription-Key: $key"],
            CURLOPT_RETURNTRANSFER => true
        ]);
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);
    } while ($res['status'] !== 'succeeded');

    return $res;
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>全家便利店收据识别系统</title>
<style>
    body { font-family: sans-serif; margin: 20px; }
    .result-box { background: #f4f4f4; padding: 15px; border-radius: 5px; margin-top: 20px; }
</style>
</head>
<body>
<h2>全家便利店收据识别 (OCR + Azure SQL)</h2>
<form method="post" enctype="multipart/form-data">
  <input type="file" name="images[]" multiple required>
  <button type="submit">开始上传并识别</button>
</form>

<?php
if (!empty($_FILES['images']['tmp_name'][0])) {
    $items = [];
    $total = 0;

    foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
        $name = basename($_FILES['images']['name'][$i]);
        $path = $uploadDir . $name;
        
        if (move_uploaded_file($tmp, $path)) {
            $opUrl = analyzeImage($path, $endpoint, $key);
            if (!$opUrl) continue;
            $ocr = getResult($opUrl, $key);

            // 记录 OCR 日志
            file_put_contents("ocr.log", "--- 文件: $name ---\n".json_encode($ocr, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);

            foreach ($ocr['analyzeResult']['readResults'] as $page) {
                foreach ($page['lines'] as $line) {
                    // 针对全家收据的正则匹配：提取商品名和价格
                    if (preg_match('/^(.+?)\s+[¥￥]?(\d+)(?:\s*[轻|*])?$/u', $line['text'], $m)) {
                        $pName = trim($m[1]);
                        $price = (int)$m[2];

                        // 排除非商品行（如小计、合计等）
                        if (!preg_match('/(小计|合计|対象|軽)/u', $pName)) {
                            $items[] = [$pName, $price];
                            $total += $price;

                            // ★ 执行数据库插入 ★
                            $stmt = $conn->prepare("INSERT INTO receipts (image_name, product_name, price) VALUES (?, ?, ?)");
                            $stmt->execute([$name, $pName, $price]);
                        }
                    }
                }
            }
        }
    }

    // 生成 CSV 文件
    $fp = fopen("result.csv", "w");
    fwrite($fp, "\xEF\xBB\xBF"); // 防止 Excel 乱码
    foreach ($items as $item) fputcsv($fp, $item);
    fputcsv($fp, ["合计", $total]);
    fclose($fp);

    echo "<div class='result-box'><h3>识别结果</h3>";
    foreach ($items as $item) echo htmlspecialchars($item[0])." - ¥".number_format($item[1])."<br>";
    echo "<h4>总金额: ¥".number_format($total)."</h4>";
    echo "<a href='result.csv' target='_blank'>下载 CSV 报表</a> | <a href='ocr.log' target='_blank'>查看 OCR 日志</a></div>";
}
?>
</body>
</html>