<?php
/* =====================
   1. 設定（Azure AI Vision & DB）
   ===================== */
$endpoint = "https://あなたの.cognitiveservices.azure.com/";
$key      = "あなたのKEY";
$uploadDir = "uploads/";

// DB接続情報
$serverName = "24jn0321.database.windows.net"; 
$database   = "receiptdb";
$username   = "sqladmin";
$password   = "Abc842727925";

/* =====================
   2. データベース接続 (PDO)
   ===================== */
try {
    $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("DB接続エラー: " . $e->getMessage());
}

/* =====================
   3. OCR 関数
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
    return trim($m[1]);
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
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>ファミリーマート レシートOCR</title>
<style>
    body { font-family: sans-serif; margin: 20px; line-height: 1.6; }
    .result-box { background: #f4f4f4; padding: 15px; border-radius: 5px; }
    .links { margin-top: 20px; }
</style>
</head>
<body>

<h2>ファミリーマート レシートOCR</h2>

<form method="post" enctype="multipart/form-data">
  <input type="file" name="images[]" multiple required>
  <br><br>
  <button type="submit">アップロードして解析</button>
</form>

<hr>

<?php
/* =====================
   4. アップロード & OCR & DB保存
   ===================== */
if (!empty($_FILES['images'])) {
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $items = [];
    $total = 0;

    foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
        $name = basename($_FILES['images']['name'][$i]);
        $path = $uploadDir . $name;
        move_uploaded_file($tmp, $path);

        // OCR実行
        $op  = analyzeImage($path, $endpoint, $key);
        $ocr = getResult($op, $key);

        // OCRログ書き込み
        file_put_contents(
            "ocr.log",
            "--- File: $name ---\n" . json_encode($ocr, JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND
        );

        foreach ($ocr['analyzeResult']['readResults'] as $page) {
            foreach ($page['lines'] as $line) {
                $text = $line['text'];

                // ファミマ形式に対応した正規表現: 「商品名 金額」または「商品名 ¥金額」
                // 末尾の「軽」や「*」を無視するように修正
                if (preg_match('/^(.+?)\s+[¥￥]?(\d+)(?:\s*[軽|*])?$/u', $text, $m)) {
                    $prodName = trim($m[1]);
                    $price = (int)$m[2];

                    // 「軽」という文字自体が含まれる行や合計行を除外
                    if (!str_contains($prodName, '軽') && !str_contains($prodName, '小計')) {
                        $items[] = [$prodName, $price];
                        $total += $price;

                        // データベースへ保存
                        $stmt = $conn->prepare("INSERT INTO receipts (image_name, product_name, price) VALUES (?, ?, ?)");
                        $stmt->execute([$name, $prodName, $price]);
                    }
                }
            }
        }
    }

    // CSV生成
    $fp = fopen("result.csv", "w");
    // UTF-8のBOMを追加（Excelで文字化けしないように）
    fwrite($fp, "\xEF\xBB\xBF"); 
    foreach ($items as $item) {
        fputcsv($fp, $item);
    }
    fputcsv($fp, ["合計", $total]);
    fclose($fp);

    // 画面表示
    echo "<div class='result-box'>";
    echo "<h3>抽出結果</h3>";
    foreach ($items as $item) {
        echo htmlspecialchars($item[0]) . " &yen;" . number_format($item[1]) . "<br>";
    }
    echo "<hr><b>合計 &yen;" . number_format($total) . "</b><br></div>";

    echo "<div class='links'>";
    echo "・<a href='result.csv' download>CSVダウンロード</a><br>";
    echo "・<a href='ocr.log' target='_blank'>OCRログを確認</a>";
    echo "</div>";
}
?>

</body>
</html>