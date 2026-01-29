<?php
// エラー表示（デバッグ用：完了後は削除してもOK）
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
    die("DB接続エラー: " . $e->getMessage());
}

/* =====================
   2. OCR 功能函数
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
        sleep(2); // Azureの処理待ち
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
    file_put_contents("ocr.log", ""); // ログ初期化

    foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
        $name = basename($_FILES['images']['name'][$i]);
        $path = $uploadDir . $name;
        
        if (move_uploaded_file($tmp, $path)) {
            $opUrl = analyzeImage($path, $endpoint, $key);
            $ocr = getResult($opUrl, $key);
            
            // ocr.log への書き込み [要件対応]
            file_put_contents("ocr.log", "FILE: $name\n" . json_encode($ocr, JSON_UNESCAPED_UNICODE) . "\n\n", FILE_APPEND);

            if ($ocr && isset($ocr['analyzeResult']['readResults'])) {
                foreach ($ocr['analyzeResult']['readResults'] as $page) {
                    foreach ($page['lines'] as $line) {
                        $text = $line['text'];
                        
                        // 正則：商品名 + 金額 (軽、*、◎ 等を除去) [要件対応]
                        if (preg_match('/^(.+?)[\s　]+[¥￥]?([0-9,]{2,7})(?:\s*[軽|*|＊|内|税])?$/u', $text, $m)) {
                            $pName = trim($m[1]);
                            // ◎や*が先頭にある場合も削除
                            $pName = preg_replace('/^[◎*＊]\s*/u', '', $pName); 
                            $price = (int)str_replace(',', '', $m[2]);
                            
                            // 不要な行（合計、小計など）を除外
                            $exclude = ['合計', '小計', '対象', '預り', 'お釣', '現 金', '消費税'];
                            $isSkip = false;
                            foreach ($exclude as $w) { if (mb_strpos($pName, $w) !== false) $isSkip = true; }

                            if (!$isSkip && $price > 0) {
                                $displayItems[] = ['name' => $pName, 'price' => $price];
                                $totalAmount += $price;
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

    // CSV 生成 [要件対応]
    $csvFile = 'result.csv';
    $handle = fopen($csvFile, 'w');
    fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // Excel文字化け防止
    foreach ($displayItems as $item) {
        fputcsv($handle, [$item['name'], $item['price']]);
    }
    fputcsv($handle, ['合計', $totalAmount]);
    fclose($handle);
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>FamilyMart レシート識別</title>
    <style>
        body { font-family: sans-serif; max-width: 700px; margin: 40px auto; background: #f0f2f5; }
        .card { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .upload-zone { border: 2px dashed #0078d4; padding: 20px; text-align: center; margin-bottom: 20px; }
        .item-list { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .item-list td { padding: 10px; border-bottom: 1px solid #eee; }
        .price { text-align: right; font-weight: bold; }
        .total-box { font-size: 1.5em; text-align: right; color: #d13438; margin-top: 20px; }
        .download-links { margin-top: 30px; padding: 15px; background: #eef; border-radius: 8px; }
        .btn { display: inline-block; padding: 8px 15px; background: #0078d4; color: white; text-decoration: none; border-radius: 4px; margin-right: 10px; }
    </style>
</head>
<body>
<div class="card">
    <h2>🏪 FamilyMart レシート識別システム</h2>
    <div class="upload-zone">
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="images[]" multiple required accept="image/*">
            <br><br>
            <button type="submit" style="padding:10px 20px; cursor:pointer;">アップロードして解析</button>
        </form>
    </div>

    <?php if ($displayItems): ?>
        <h3>抽出結果:</h3>
        <table class="item-list">
            <?php foreach ($displayItems as $it): ?>
            <tr>
                <td><?php echo htmlspecialchars($it['name']); ?></td>
                <td class="price">¥<?php echo number_format($it['price']); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <div class="total-box">合計：¥<?php echo number_format($totalAmount); ?></div>

        <div class="download-links">
            <strong>📋 提出用ファイル:</strong><br><br>
            <a href="result.csv" class="btn" download>CSVファイルをダウンロード</a>
            <a href="ocr.log" class="btn" target="_blank">ocr.log を表示</a>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
