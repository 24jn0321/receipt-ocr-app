<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* =====================
   1. 配置 (Azure & DB)
   ===================== */
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
   3. 核心业务
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
            
            // 写入日志
            file_put_contents("ocr.log", "FILE: $name\n" . json_encode($ocr, JSON_UNESCAPED_UNICODE) . "\n\n", FILE_APPEND);

            if ($ocr && isset($ocr['analyzeResult']['readResults'])) {
                foreach ($ocr['analyzeResult']['readResults'] as $page) {
                    foreach ($page['lines'] as $line) {
                        $text = $line['text'];
                        
                        // 过滤非商品行
                        $exclude = ['合計', '小計', '消費税', '預り', 'お釣', '現 金', '再発行', '責No', '軽'];
                        $isSkip = false;
                        foreach ($exclude as $w) { if (mb_strpos($text, $w) !== false && mb_strlen($text) < 10) $isSkip = true; }
                        if ($isSkip) continue;

                        // 核心正则：匹配 [商品名] + [空格或符号] + [金额数字] + [可选的杂质字符]
                        if (preg_match('/^(.+?)\s+[¥￥Y]?\s*([0-9,]{2,6})(?:\s*[軽|*|＊|内|税])?$/u', $text, $m)) {
                            $pName = trim($m[1]);
                            $price = (int)str_replace(',', '', $m[2]);

                            if ($price > 0 && $price < 20000) {
                                $displayItems[] = ['name' => $pName, 'price' => $price];
                                $totalAmount += $price;

                                // 存入数据库
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

    // CSV 生成
    if ($displayItems) {
        $csvFile = 'result.csv';
        $handle = fopen($csvFile, 'w');
        fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); 
        foreach ($displayItems as $item) { fputcsv($handle, [$item['name'], $item['price']]); }
        fputcsv($handle, ['合計', $totalAmount]);
        fclose($handle);
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>FamilyMart 识别系统</title>
    <style>
        body { font-family: sans-serif; max-width: 600px; margin: 40px auto; background: #f4f7f6; color: #333; }
        .card { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .item-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; }
        .total-area { font-size: 1.5em; font-weight: bold; text-align: right; margin-top: 20px; color: #d13438; }
        .download-box { margin-top: 30px; padding: 20px; background: #f0f4f8; border-radius: 8px; border: 1px solid #d1e1f0; }
        .btn { display: inline-block; padding: 12px 20px; background: #0078d4; color: white; text-decoration: none; border-radius: 6px; margin-right: 10px; font-weight: bold; }
        .btn:hover { background: #005a9e; }
    </style>
</head>
<body>
<div class="card">
    <h2 style="border-left: 5px solid #0078d4; padding-left: 15px;">🏪 レシート解析システム</h2>
    <p style="font-size: 0.9em; color: #666;">FamilyMartのレシート画像をアップロードしてください。</p>
    
    <form method="post" enctype="multipart/form-data" style="margin: 20px 0;">
        <input type="file" name="images[]" multiple required style="margin-bottom: 15px; display: block;">
        <button type="submit" style="background: #28a745; color: white; border: none; padding: 12px 25px; border-radius: 6px; cursor: pointer; font-size: 1em;">解析を実行する</button>
    </form>

    <?php if ($displayItems): ?>
        <hr>
        <h3>抽出された商品</h3>
        <?php foreach ($displayItems as $it): ?>
            <div class="item-row">
                <span><?php echo htmlspecialchars($it['name']); ?></span>
                <span>¥<?php echo number_format($it['price']); ?></span>
            </div>
        <?php endforeach; ?>
        
        <div class="total-area">合計：¥<?php echo number_format($totalAmount); ?></div>

        <div class="download-box">
            <p style="margin-top:0;"><strong>📥 結果のダウンロード</strong></p>
            <a href="result.csv" class="btn" download>CSV出力</a>
            <a href="ocr.log" class="btn" target="_blank">OCRログを確認</a>
        </div>
    <?php elseif ($_SERVER['REQUEST_METHOD'] == 'POST'): ?>
        <div style="background: #fff3cd; padding: 15px; border-radius: 6px; margin-top: 20px;">
            ⚠️ 商品が抽出されませんでした。ログを確認してください。
            <br><a href="ocr.log" target="_blank" style="color: #856404;">ocr.logを表示</a>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
