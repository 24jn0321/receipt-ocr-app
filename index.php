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
   2. 功能関数
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
$totalAmount = 0;

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
                    foreach ($page['lines'] as $line) {
                        $text = $line['text'];

                        // 1. 排除包含地址、电话、日期时间特征的行
                        if (preg_match('/\d{1,2}-\d{1,2}-\d{1,2}/', $text)) continue; // 排除 1-1-17 这种地址
                        if (preg_match('/\d{1,2}:\d{2}/', $text)) continue;       // 排除 9:01 这种时间
                        if (preg_match('/電話|番号|レジ|202\d年/', $text)) continue; // 排除电话、收银台号、年份

                        // 2. 正则匹配：商品名 + 金额
                        // 强制要求金额前有 ¥ 或商品名较长，避免匹配到地址末尾的孤立数字
                        if (preg_match('/^(.{3,})[ \t　]+[¥￥]([0-9,]{2,7})/u', $text, $m)) {
                            $pName = trim($m[1]);
                            $pName = preg_replace('/^[◎*＊]\s*/u', '', $pName); 
                            $price = (int)str_replace(',', '', $m[2]);

                            // 3. 严格黑名单
                            $exclude = [
                                '合計', '小計', '対象', '預り', 'お釣', '現金', '消費税', 
                                '再発行', '责No', 'No.', '残高', '番号', '新宿', '東京都'
                            ];
                            
                            $isSkip = false;
                            foreach ($exclude as $w) { 
                                if (mb_strpos($pName, $w) !== false) {
                                    $isSkip = true;
                                    break;
                                }
                            }

                            if (!$isSkip && $price > 0) {
                                $displayItems[] = ['name' => $pName, 'price' => $price];
                                $totalAmount += $price;
                                
                                $stmt = $conn->prepare("INSERT INTO receipts (image_name, product_name, price) VALUES (?, ?, ?)");
                                $stmt->execute([$name, $pName, $price]);
                            }
                        }
                    }
                }
            }
        }
    }

    // 生成CSV
    $csvFile = 'result.csv';
    $handle = fopen($csvFile, 'w');
    fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); 
    foreach ($displayItems as $item) {
        fputcsv($handle, [$item['name'], $item['price']]);
    }
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
        body { font-family: sans-serif; margin: 20px; line-height: 1.6; background-color: #f4f7f6; }
        .container { max-width: 700px; margin: auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h2 { color: #333; border-bottom: 2px solid #0078d4; padding-bottom: 10px; }
        .result-box { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; border-radius: 8px; margin-top: 20px; }
        .item-row { display: flex; justify-content: space-between; border-bottom: 1px solid #eee; padding: 8px 0; }
        .total-row { font-size: 1.3em; font-weight: bold; text-align: right; margin-top: 15px; color: #d13438; border-top: 2px solid #333; padding-top: 10px; }
        .btn { display: inline-block; background: #0078d4; color: #fff; padding: 8px 15px; text-decoration: none; border-radius: 4px; margin-top: 10px; }
    </style>
</head>
<body>
<div class="container">
    <h2>🏪 FamilyMart 收据识别 (精简版)</h2>
    <form method="post" enctype="multipart/form-data">
        <input type="file" name="images[]" multiple required>
        <button type="submit">开始上传并识别</button>
    </form>

<?php if (!empty($displayItems)): ?>
    <div class="result-box">
        <h3>识别结果</h3>
        <?php foreach ($displayItems as $item): ?>
            <div class="item-row">
                <span><?php echo htmlspecialchars($item['name']); ?></span>
                <span>¥<?php echo number_format($item['price']); ?></span>
            </div>
        <?php endforeach; ?>
        <div class="total-row">合计金额: ¥<?php echo number_format($totalAmount); ?></div>
        <a href="result.csv" class="btn" download>下载结果</a>
    </div>
<?php endif; ?>
</div>
</body>
</html>
