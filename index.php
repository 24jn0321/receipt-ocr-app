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

// データベース接続
try {
    $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("数据库连接失败: " . $e->getMessage());
}

/* =====================
   2. 功能函数
   ===================== */
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
    $max_attempts = 15; 
    for ($i = 0; $i < $max_attempts; $i++) {
        sleep(2);
        $ch = curl_init(trim($url));
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ["Ocp-Apim-Subscription-Key: $key"],
            CURLOPT_RETURNTRANSFER => true
        ]);
        $response = curl_exec($ch);
        $res = json_decode($response, true);
        curl_close($ch);
        if (isset($res['status']) && $res['status'] === 'succeeded') return $res;
    }
    return null;
}

/* =====================
   3. メイン処理
   ===================== */
$displayItems = [];

if (!empty($_FILES['images']['tmp_name'][0])) {
    file_put_contents("ocr.log", ""); 

    foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
        $name = basename($_FILES['images']['name'][$i]);
        $path = $uploadDir . $name;
        
        if (move_uploaded_file($tmp, $path)) {
            $opUrl = analyzeImage($path, $endpoint, $key);
            $ocr = getResult($opUrl, $key);
            
            file_put_contents("ocr.log", "--- FILE: $name ---\n" . json_encode($ocr, JSON_UNESCAPED_UNICODE) . "\n\n", FILE_APPEND);

            if ($ocr && isset($ocr['analyzeResult']['readResults'])) {
                foreach ($ocr['analyzeResult']['readResults'] as $page) {
                    
                    $foundHeader = false;  // 是否找到了领收证头部
                    $isExtracting = false; // 是否已经过了分隔线

                    foreach ($page['lines'] as $line) {
                        $text = $line['text'];

                        // A. 寻找标志：領収
                        if (!$foundHeader && mb_strpos($text, '領収') !== false) {
                            $foundHeader = true;
                            continue;
                        }

                        // B. 寻找分隔线：==== 或 ----
                        if ($foundHeader && !$isExtracting) {
                            if (preg_match('/[=\-\—]{3,}/', $text)) {
                                $isExtracting = true;
                                continue;
                            }
                        }

                        // C. 核心抓取逻辑（等号线之后）
                        if ($isExtracting) {
                            // 正则：抓取 [名称] [金额]
                            if (preg_match('/^(.+?)[ \t　]*[¥￥]?([0-9,]{2,7})/u', $text, $m)) {
                                $pName = trim($m[1]);
                                $pName = preg_replace('/^[◎*＊]\s*/u', '', $pName); // 清理特殊符号
                                $price = (int)str_replace(',', '', $m[2]);

                                // 检查是否是“合計”行
                                $isTotalRow = (mb_strpos($pName, '合') !== false && mb_strpos($pName, '計') !== false);

                                if ($price > 0) {
                                    $displayItems[] = [
                                        'name' => $pName, 
                                        'price' => $price, 
                                        'is_total' => $isTotalRow
                                    ];

                                    // 存入数据库（非合计行存入商品表，合计行可以根据需求决定是否存储）
                                    $stmt = $conn->prepare("INSERT INTO receipts (image_name, product_name, price) VALUES (?, ?, ?)");
                                    $stmt->execute([$name, $pName, $price]);

                                    // 如果抓到了合计行，就此停止当前收据的扫描
                                    if ($isTotalRow) {
                                        $isExtracting = false;
                                        break; 
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    // 生成 CSV
    $csvFile = 'result.csv';
    $handle = fopen($csvFile, 'w');
    fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); 
    foreach ($displayItems as $item) {
        fputcsv($handle, [$item['name'], $item['price']]);
    }
    fclose($handle);
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>FamilyMart 收据识别系统</title>
    <style>
        body { font-family: sans-serif; margin: 20px; background-color: #f4f7f6; }
        .container { max-width: 600px; margin: auto; background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h2 { color: #333; border-bottom: 2px solid #0078d4; padding-bottom: 10px; margin-bottom: 20px; }
        .result-box { margin-top: 25px; border: 1px solid #eee; border-radius: 8px; overflow: hidden; }
        .item-row { display: flex; justify-content: space-between; padding: 12px 15px; border-bottom: 1px solid #f0f0f0; }
        .item-row:last-child { border-bottom: none; }
        .total-highlight { background-color: #fff1f1; font-weight: bold; font-size: 1.2em; color: #d13438; border-top: 2px solid #333; }
        .price { font-family: monospace; }
        .btn { display: inline-block; background: #0078d4; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-top: 20px; }
        input[type="file"] { margin-bottom: 10px; }
    </style>
</head>
<body>

<div class="container">
    <h2>🏪 FamilyMart 识别 (含合计行)</h2>
    <form method="post" enctype="multipart/form-data">
        <input type="file" name="images[]" multiple required><br>
        <button type="submit" style="padding: 8px 20px; cursor: pointer;">上传并解析</button>
    </form>

<?php if (!empty($displayItems)): ?>
    <div class="result-box">
        <?php foreach ($displayItems as $item): ?>
            <div class="item-row <?php echo $item['is_total'] ? 'total-highlight' : ''; ?>">
                <span><?php echo htmlspecialchars($item['name']); ?></span>
                <span class="price">¥<?php echo number_format($item['price']); ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <a href="result.csv" class="btn" download>下载 CSV 报表</a>
<?php endif; ?>

</div>
</body>
</html>
