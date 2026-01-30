<?php
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$serverName = "receipt-server-24jn0.database.windows.net";
$database   = "receiptdb";
$username   = "sqladmin"; 
$password   = "Abc842727925";

$results = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        $fileName = $_FILES['receipts']['name'][$key];

        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
        $imageData = file_get_contents($tmpName);

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imageData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        
        $currentFileItems = [];
        $totalAmount = 0;

        for ($i = 0; $i < count($lines); $i++) {
            $text = $lines[$i]['text'];

            // 1. 商品名の抽出 (ザバス... や ◎天然水...)
            // 金額(¥)が含まれていない、かつ特定のキーワードを含まない行を探す
            if (!preg_match('/[¥￥]/u', $text) && !preg_match('/Family|新宿|電話|登録|2024|レジ|領収|対象|消費税|支払|残高/u', $text)) {
                
                // 次の行（またはその次）に金額があるか探す
                for ($j = 1; $j <= 2; $j++) {
                    if (isset($lines[$i + $j])) {
                        $nextText = $lines[$i + $j]['text'];
                        if (preg_match('/[¥￥]([\d,]+)/u', $nextText, $matches)) {
                            $price = (int)str_replace(',', '', $matches[1]);
                            
                            // 余計な文字（◎や軽など）を削除して保存
                            $cleanName = str_replace(['◎', '＊', '*', '軽'], '', $text);
                            $currentFileItems[] = ['name' => trim($cleanName), 'price' => $price];
                            
                            // 商品が見つかったら、ループを飛ばす
                            $i += $j; 
                            break;
                        }
                    }
                }
            }
            
            // 2. 合計金額の抽出 (「合計」という文字そのものではなく、大きなフォントの「計」周辺を探す)
            if (mb_strpos($text, '計') !== false && !mb_strpos($text, '消費税')) {
                // 同じ行か次の行に合計金額があるはず
                $searchArea = $text . ($lines[$i+1]['text'] ?? '') . ($lines[$i+2]['text'] ?? '');
                if (preg_match('/[¥￥]([\d,]+)/u', $searchArea, $m)) {
                    $val = (int)str_replace(',', '', $m[1]);
                    // 支払い金額(355)を合計(355)として取得（お釣りなどと混同しないよう最大値を取る）
                    if ($val > $totalAmount) $totalAmount = $val;
                }
            }
        }
        $results[] = ['file' => $fileName, 'items' => $currentFileItems, 'total' => $totalAmount];
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ファミマレシートOCR</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .container { max-width: 700px; margin: auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .receipt-result { border-left: 6px solid #00a95c; background: #fdfdfd; padding: 15px; margin-bottom: 20px; border-bottom: 1px solid #eee; }
        .item-row { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px dashed #ddd; }
        .total-row { font-size: 1.4em; font-weight: bold; color: #d32f2f; margin-top: 10px; text-align: right; }
        .btn { padding: 10px 20px; background: #0078d4; color: white; border: none; border-radius: 5px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="container">
        <h2>コンビニレシート解析</h2>
        <form action="" method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple accept="image/*"><br><br>
            <button type="submit" class="btn">解析実行</button>
        </form>

        <?php if (!empty($results)): ?>
            <hr>
            <?php foreach ($results as $res): ?>
                <div class="receipt-result">
                    <strong>📄 <?php echo htmlspecialchars($res['file']); ?></strong>
                    <?php foreach ($res['items'] as $i): ?>
                        <div class="item-row">
                            <span><?php echo htmlspecialchars($i['name']); ?></span>
                            <span>¥<?php echo number_format($i['price']); ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="total-row">合計 ¥<?php echo number_format($res['total']); ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
