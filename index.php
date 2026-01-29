<?php
/* =====================
   1. 設定（Azure AI Vision & DB）
   ===================== */
// Azure AI Vision の情報（ご自身のものに書き換えてください）
$endpoint = "https://あなたのリソース名.cognitiveservices.azure.com/"; 
$key      = "あなたのAPIキー"; 
$uploadDir = "uploads/";

// DB接続情報（提供いただいた内容）
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
    // 接続エラー時は画面に表示して停止
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
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>ファミリーマート レシートOCR</title>
<style>
    body { font-family: sans-serif; margin: 20px; line-height: 1.6; }
    .result-box { background: #f4f4f4; padding: 15px; border-radius: 5px; margin-top: 20px; }
    .links { margin-top: 20px; border-top: 1px solid #ccc; padding-top: 10px; }
</style>
</head>
<body>

<h2>ファミリーマート レシートOCR</h2>

<p>レシート画像をアップロードしてください（複数枚可）</p>
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
if (!empty($_FILES['images']['tmp_name'][0])) {
    // フォルダがない場合は作成
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $items = [];
    $total = 0;

    foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
        $name = basename($_FILES['images']['name'][$i]);
        $path = $uploadDir . $name;
        
        if (move_uploaded_file($tmp, $path)) {
            // OCR実行
            $opUrl = analyzeImage($path, $endpoint, $key);
            if (!$opUrl) continue;
            
            $ocr = getResult($opUrl, $key);

            // OCRログ書き込み (ocr.log)
            file_put_contents(
                "ocr.log",
                "--- File: $name ---\n" . json_encode($ocr, JSON_UNESCAPED_UNICODE) . PHP_EOL,
                FILE_APPEND
            );

            foreach ($ocr['analyzeResult']['readResults'] as $page) {
                foreach ($page['lines'] as $line) {
                    $text = $line['text'];

                    // ファミマ形式に対応した正規表現
                    // 商品名と金額を抽出し、末尾の「軽」や「*」を無視
                    if (preg_match('/^(.+?)\s+[¥￥]?(\d+)(?:\s*[軽|*])?$/u', $text, $m)) {
                        $prodName = trim($m[1]);
                        $price = (int)$m[2];

                        // 除外ワード（小計、合計、軽などは商品として扱わない）
                        if (!preg_match('/(小計|合計|対象|軽)/u', $prodName)) {
                            $items[] = [$prodName, $price];
                            $total += $price;

                            // ★データベースへ保存★
                            $stmt = $conn->prepare("INSERT INTO receipts (image_name, product_name, price) VALUES (?, ?, ?)");
                            $stmt->execute([$name, $prodName, $price]);
                        }
                    }
                }
            }
        }
    }

    // 5. CSV生成
    $fp = fopen("result.csv", "w");
    fwrite($fp, "\xEF\xBB\xBF"); // Excel用BOM
    foreach ($items as $item) {
        fputcsv($fp, $item);
    }
    fputcsv($fp, ["合計", $total]);
    fclose($fp);

    // 6. 画面表示
    echo "<div class='result-box'>";
    echo "<h3>抽出結果</h3>";
    if (empty($items)) {
        echo "抽出できるデータが見つかりませんでした。";
    } else {
        foreach ($items as $item) {
            echo htmlspecialchars($item[0]) . "　¥" . number_format($item[1]) . "<br>";
        }
        echo "<h4>合計　¥" . number_format($total) . "</h4>";
    }
    echo "</div>";

    echo "<div class='links'>";
    echo "・<a href='result.csv' download>CSVファイルをダウンロード</a><br>";
    echo "・<a href='ocr.log' target='_blank'>ocr.log を確認</a>";
    echo "</div>";
}
?>

</body>
</html>