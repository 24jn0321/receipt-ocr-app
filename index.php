<?php
// ====== 配置 ======
$endpoint = "https://YOUR-VISION.cognitiveservices.azure.com/vision/v3.2/ocr";
$apiKey   = "YOUR_AZURE_AI_VISION_KEY";

$logFile = "ocr.log";
$csvFile = "result.csv";

$results = [];

// ====== 提交后处理 ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['receipts'])) {

    file_put_contents($logFile, ""); // 清空日志
    $fp = fopen($csvFile, 'w');

    foreach ($_FILES['receipts']['tmp_name'] as $i => $tmp) {

        $imageData = file_get_contents($tmp);

        // --- Azure OCR ---
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                "Ocp-Apim-Subscription-Key: $apiKey",
                "Content-Type: application/octet-stream"
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $imageData,
            CURLOPT_RETURNTRANSFER => true
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        file_put_contents($logFile, $response . PHP_EOL, FILE_APPEND);

        $json = json_decode($response, true);

        // --- OCR文字合并 ---
        $text = "";
        foreach ($json['regions'] ?? [] as $r) {
            foreach ($r['lines'] as $l) {
                foreach ($l['words'] as $w) {
                    $text .= $w['text'] . " ";
                }
                $text .= "\n";
            }
        }

        // --- ファミマ专用抽取 ---
        preg_match_all('/([ァ-ンーA-Za-z0-9◎]+)\\s+¥?(\\d+)/u', $text, $m, PREG_SET_ORDER);

        $items = [];
        $total = 0;

        foreach ($m as $row) {
            if ($row[1] === '合計') {
                $total = (int)$row[2];
            } else {
                $items[] = ['name'=>$row[1], 'price'=>(int)$row[2]];
                fputcsv($fp, [$row[1], $row[2]]);
            }
        }
        fputcsv($fp, ['合計', $total]);

        $results[] = ['items'=>$items, 'total'=>$total];
    }
    fclose($fp);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>ファミマ レシートOCR</title>
</head>
<body>

<h2>ファミリーマート レシートOCR</h2>

<form method="post" enctype="multipart/form-data">
  <input type="file" name="receipts[]" multiple accept="image/*" required>
  <br><br>
  <button type="submit">アップロードして解析</button>
</form>

<?php if (!empty($results)): ?>
<hr>
<h3>抽出結果</h3>
<?php foreach ($results as $r): ?>
  <?php foreach ($r['items'] as $it): ?>
    <?= htmlspecialchars($it['name']) ?> ¥<?= $it['price'] ?><br>
  <?php endforeach; ?>
  合計 ¥<?= $r['total'] ?>
  <hr>
<?php endforeach; ?>

<a href="result.csv">CSVダウンロード</a><br>
<a href="ocr.log">ocr.log ダウンロード</a>
<?php endif; ?>

</body>
</html>
