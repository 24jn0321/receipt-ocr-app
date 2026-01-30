<?php
// エラー表示（デバッグ用）
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* =====================
   1. 配置（Azure & DB）
   ===================== */
$endpoint = "https://receipt-analysis-ai.cognitiveservices.azure.com/"; 
$key      = "1vWlPmkplbveP5uM5pFtXc13o2PDFk22m5bnKLKUkegBExZtnCo9JQQJ99CAACi0881XJ3w3AAALACOGQtWQ"; 

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
    if (!$url) return null; // 修复 trim(null) 报错
    $max_attempts = 15; 
    for ($i = 0; $i < $max_attempts; $i++) {
        sleep(2);
        $ch = curl_init(trim((string)$url));
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
$totalAmount = 0;

if (!empty($_FILES['images']['tmp_name'][0])) {
    file_put_contents("ocr.log", ""); 

    foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
        $name = basename($_FILES['images']['name'][$i]);
        $path = $uploadDir . $name;
        
        if (move_uploaded_file($tmp, $path)) {
            $opUrl = analyzeImage($path, $endpoint, $key);
            
            // 确保有 URL 才进行结果获取
            if ($opUrl) {
                $ocr = getResult($opUrl, $key);
                file_put_contents("ocr.log", "--- FILE: $name ---\n" . json_encode($ocr, JSON_UNESCAPED_UNICODE) . "\n\n", FILE_APPEND);

                if ($ocr && isset($ocr['analyzeResult']['readResults'])) {
                    foreach ($ocr['analyzeResult']['readResults'] as $page) {
                        $isExtracting = false; // 区域提取开关

                        foreach ($page['lines'] as $line) {
                            $text = $line['text'];

                            // 开启信号：等号线或“领收证”
                            if (preg_match('/[=\-]{3,}|領\s*収\s*証/u', $text)) {
                                $isExtracting = true;
                                continue;
                            }

                            if ($isExtracting) {
                                // 匹配 [名称] [金额]
                                if (preg_match('/^(.+?)[ \t　]*[¥￥]?([0-9,]{2,7})/u', $text, $m)) {
                                    $pName = trim($m[1]);
                                    $price = (int)str_replace(',', '', $m[2]);

                                    // 识别到合计行则抓取金额并停止该文件提取
                                    if (mb_strpos($pName, '合') !== false && mb_strpos($pName, '計') !== false) {
                                        $totalAmount = $price;
                                        $isExtracting = false;
                                        break;
                                    }

                                    // 过滤非商品杂行
                                    $exclude = ['対象', '消費税', '預り', 'お釣', '現金', '责No', 'No.'];
                                    $isSkip = false;
                                    foreach ($exclude as $w) { if (mb_strpos($pName, $w) !== false) $isSkip = true; }

                                    if (!$isSkip && $price > 0) {
                                        // 清理特殊符号
                                        $pName = preg_replace('/^[◎*＊]\s*/u', '', $pName);
                                        $pName = preg_replace('/(軽|轻|.*)$/u', '', $pName);
                                        
                                        $displayItems[] = ['name' => trim($pName), 'price' => $price];

                                        // 存入数据库
                                        $stmt = $conn->prepare("INSERT INTO receipts (image_name, product_name, price) VALUES (?, ?, ?)");
                                        $stmt->execute([$name, trim($pName), $price]);
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
    foreach ($displayItems as $item) { fputcsv($handle, [$item['name'], $item['price']]); }
    fputcsv($handle, ['合计金额', $totalAmount]);
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
        .container { max-width: 700px; margin: auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .item-row { display: flex; justify-content: space-between; border-bottom: 1px solid #eee; padding: 8px 0; }
        .total-row { font-size: 1.3em; font-weight: bold; text-align: right; margin-top: 15px; color: #d13438; border-top: 2px solid #333; padding-top: 10px; }
        .btn { display: inline-block; background: #0078d4; color: #fff; padding: 8px 15px; text-decoration: none; border-radius: 4px; margin-right: 10px; margin-top: 15px; }
    </style>
</head>
<body>
<div class="container">
    <h2>🏪 FamilyMart 收据识别 (强化版)</h2>
    <form method="post" enctype="multipart/form-data">
        <input type="file" name="images[]" multiple required>
        <button type="submit">开始上传并识别</button>
    </form>

<?php if (!empty($displayItems)): ?>
    <div style="margin-top: 20px;">
        <h3>识别结果</h3>
        <?php foreach ($displayItems as $item): ?>
            <div class="item-row">
                <span><?php echo htmlspecialchars($item['name']); ?></span>
                <span>¥<?php echo number_format($item['price']); ?></span>
            </div>
        <?php endforeach; ?>
        
        <div class="total-row">合计金额: ¥<?php echo number_format($totalAmount); ?></div>

        <div>
            <a href="result.csv" class="btn" download>下载 CSV</a>
            <a href="ocr.log" class="btn" target="_blank" style="background:#666;">查看日志</a>
        </div>
    </div>
<?php endif; ?>
</div>
</body>
</html>
