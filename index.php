<?php
/* =====================
   1. 構成（Azure 設定）
   ===================== */
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$key      = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 

// データベース接続情報
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
   2. OCR機能関数
   ===================== */
function analyzeImage($imagePath, $endpoint, $key) {
    $url = rtrim($endpoint, '/') . "/vision/v3.2/read/analyze";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Ocp-Apim-Subscription-Key: $key", "Content-Type: application/octet-stream"],
        CURLOPT_POSTFIELDS => file_get_contents($imagePath),
        CURLOPT_HEADER => true,
        CURLOPT_RETURNTRANSFER => true
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    preg_match('/Operation-Location: (.*)/i', $res, $m);
    return isset($m[1]) ? trim($m[1]) : null;
}

function getOcrResult($url, $key) {
    $attempt = 0;
    do {
        sleep(1);
        $ch = curl_init(trim($url));
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ["Ocp-Apim-Subscription-Key: $key"],
            CURLOPT_RETURNTRANSFER => true
        ]);
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);
        $attempt++;
    } while (isset($res['status']) && $res['status'] !== 'succeeded' && $attempt < 15);
    return $res;
}

/* =====================
   3. メイン処理（アップロード後）
   ===================== */
$displayItems = [];
$csvData = [];

if (!empty($_FILES['images']['tmp_name'][0])) {
    file_put_contents("ocr.log", ""); // ログをリセット

    foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
        $fileName = basename($_FILES['images']['name'][$i]);
        $targetPath = $uploadDir . $fileName;

        if (move_uploaded_file($tmp, $targetPath)) {
            $opUrl = analyzeImage($targetPath, $endpoint, $key);
            if (!$opUrl) continue;
            
            $ocr = getOcrResult($opUrl, $key);
            // ocr.log への書き込み
            file_put_contents("ocr.log", "--- File: $fileName ---\n" . json_encode($ocr, JSON_UNESCAPED_UNICODE) . "\n\n", FILE_APPEND);

            if (isset($ocr['analyzeResult']['readResults'])) {
                foreach ($ocr['analyzeResult']['readResults'] as $page) {
                    foreach ($page['lines'] as $line) {
                        $text = $line['text'];

                        // 【ファミマ専用正規表現】 商品名 + スペース + 値段
                        // 「軽」や「*」を無視するように設計
                        if (preg_match('/^(.+?)[\s　]+[¥￥]?([0-9,]{2,7})(?:\s*[軽|*|＊|内|税])?$/u', $text, $m)) {
                            $pName = trim($m[1]);
                            $price = (int)str_replace(',', '', $m[2]);

                            // 除外リスト（合計、小計、消費税などは抽出しない）
                            $exclude = ['合計', '小計', '消費税', '対象', '再発行', '番号', '現 金', '預り', 'お釣', '釣銭'];
                            $isExclude = false;
                            foreach ($exclude as $word) {
                                if (mb_strpos($pName, $word) !== false) { $isExclude = true; break; }
                            }

                            if (!$isExclude && $price > 0) {
                                $displayItems[] = ['file' => $fileName, 'name' => $pName, 'price' => $price];
                                $csvData[] = [$pName, $price];

                                // データベースへ保存
                                $stmt = $conn->prepare("INSERT INTO receipts (image_name, product_name, price) VALUES (?, ?, ?)");
                                $stmt->execute([$fileName, $pName, $price]);
                            }
                        }
                    }
                }
            }
        }
    }

    // CSV作成
    $fp = fopen('result.csv', 'w');
    fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM（Excel用）
    $totalAll = 0;
    foreach ($csvData as $row) {
        fputcsv($fp, $row);
        $totalAll += $row[1];
    }
    fputcsv($fp, ['合計', $totalAll]);
    fclose($fp);
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>レシート識別システム - FamilyMart専用</title>
    <style>
        body { font-family: sans-serif; max-width: 800px; margin: 40px auto; line-height: 1.6; background: #f4f7f6; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .upload-form { border: 2px dashed #0078d4; padding: 30px; text-align: center; margin-bottom: 20px; }
        .result-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .result-table th, .result-table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        .result-table th { background: #0078d4; color: white; }
        .total-row { font-weight: bold; font-size: 1.2em; color: #d13438; }
        .links { margin-top: 20px; padding: 15px; background: #e7f3ff; border-radius: 5px; }
        .btn { background: #0078d4; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; }
    </style>
</head>
<body>

<div class="card">
    <h2>🧾 ファミリーマート レシート抽出</h2>
    <div class="upload-form">
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="images[]" multiple required accept="image/*">
            <br><br>
            <input type="submit" value="アップロードして解析" style="padding: 10px 20px; cursor: pointer;">
        </form>
    </div>

    <?php if (!empty($displayItems)): ?>
        <h3>抽出結果</h3>
        <table class="result-table">
            <thead>
                <tr>
                    <th>商品名</th>
                    <th>値段</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $sum = 0;
                foreach ($displayItems as $item): 
                    $sum += $item['price'];
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['name']); ?></td>
                    <td>¥<?php echo number_format($item['price']); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td>合計</td>
                    <td>¥<?php echo number_format($sum); ?></td>
                </tr>
            </tbody>
        </table>

        <div class="links">
            <strong>📁 ダウンロード・検証:</strong><br><br>
            <a href="result.csv" class="btn" download>CSVファイルをダウンロード</a>
            <a href="ocr.log" class="btn" target="_blank">ocr.logを表示</a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
