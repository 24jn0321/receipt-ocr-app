<?php
// エラー表示
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* =====================
   1. 設定（Azure & DB）
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
    die("データベース接続失敗: " . $e->getMessage());
}

/* =====================
   2. 関数
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
        sleep(1);
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
$receiptTotal = 0; // レシートに記載された合計金額

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
                        
                        // 合計行の判定
                        if (preg_match('/合計.*[¥￥]?([0-9,]{2,7})/', $text, $mt)) {
                            $receiptTotal = (int)str_replace(',', '', $mt[1]);
                            continue;
                        }

                        // 商品行の判定 (商品名 + 金額)
                        // 末尾の「軽」「税」「*」などを除外する正規表現
                        if (preg_match('/^(.+?)\s*[¥￥]?([0-9,]{2,7})\s*(軽|税|＊|\*)?$/u', $text, $m)) {
                            $pName = trim($m[1]);
                            
                            // 不要な記号の削除
                            $pName = preg_replace('/^[◎*＊]\s*/u', '', $pName); // 先頭の記号
                            $pName = preg_replace('/\s*軽$/u', '', $pName);     // 末尾の「軽」
                            
                            $price = (int)str_replace(',', '', $m[2]);

                            // 除外ワード
                            $exclude = ['合計', '小計', '対象', '預り', 'お釣', '現金', '消費税', '再発行', '登録番号', '電話', 'レジ', '残高'];
                            $isSkip = false;
                            foreach ($exclude as $w) {
                                if (mb_strpos($pName, $w) !== false) { $isSkip = true; break; }
                            }

                            if (!$isSkip && $price > 0) {
                                $displayItems[] = ['name' => $pName, 'price' => $price];
                                // DB保存
                                $stmt = $conn->prepare("INSERT INTO receipts (image_name, product_name, price) VALUES (?, ?, ?)");
                                $stmt->execute([$name, $pName, $price]);
                            }
                        }
                    }
                }
            }
        }
    }

    // CSV作成
    $csvFile = 'result.csv';
    $handle = fopen($csvFile, 'w');
    fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); 
    foreach ($displayItems as $item) {
        fputcsv($handle, [$item['name'], $item['price']]);
    }
    fputcsv($handle, ['合計', $receiptTotal]);
    fclose($handle);
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>FamilyMart レシート識別システム</title>
    <style>
        body { font-family: "Helvetica Neue", Arial, sans-serif; background-color: #f0f2f5; margin: 0; padding: 40px; }
        .container { max-width: 600px; margin: auto; background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h2 { color: #00a650; border-bottom: 3px solid #00a650; padding-bottom: 10px; margin-top: 0; }
        .upload-section { background: #f8f9fa; border: 2px dashed #ccc; padding: 20px; text-align: center; border-radius: 8px; }
        .result-box { margin-top: 30px; border-top: 1px solid #eee; }
        .item-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f1f1f1; }
        .total-row { font-size: 1.4em; font-weight: bold; color: #e02020; margin-top: 20px; text-align: right; }
        .btn { display: inline-block; background: #0078d4; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-top: 20px; font-size: 14px; }
        .btn-log { background: #666; }
    </style>
</head>
<body>

<div class="container">
    <h2>🏪 FamilyMart レシート解析</h2>
    <div class="upload-section">
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="images[]" multiple required>
            <br><br>
            <button type="submit" style="padding: 10px 20px; cursor: pointer;">アップロードして解析</button>
        </form>
    </div>

<?php if (!empty($displayItems)): ?>
    <div class="result-box">
        <h3>抽出結果</h3>
        <?php foreach ($displayItems as $item): ?>
            <div class="item-row">
                <span><?php echo htmlspecialchars($item['name']); ?></span>
                <span>¥<?php echo number_format($item['price']); ?></span>
            </div>
        <?php endforeach; ?>
        
        <div class="total-row">
            合計　¥<?php echo number_format($receiptTotal); ?>
        </div>

        <a href="result.csv" class="btn" download>CSVをダウンロード</a>
        <a href="ocr.log" class="btn btn-log" target="_blank">ocr.logを確認</a>
    </div>
<?php endif; ?>
</div>

</body>
</html>
