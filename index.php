<?php
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

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
            $text = trim($lines[$i]['text']);

            // 1. 商品名の抽出ロジック
            // 「証」「領収」「合計」などの不要な単語を避け、かつ2文字以上の行を対象にする
            if (!preg_match('/[¥￥]/u', $text) && 
                !preg_match('/Family|新宿|電話|登録|2024|レジ|領収|対象|消費税|支払|残高|証|単価/u', $text) &&
                mb_strlen($text) >= 2) {
                
                // 次の行に金額があるか探す
                if (isset($lines[$i + 1])) {
                    $nextText = $lines[$i + 1]['text'];
                    if (preg_match('/[¥￥]([\d,]+)/u', $nextText, $matches)) {
                        $price = (int)str_replace(',', '', $matches[1]);
                        
                        // 「軽」や「＊」だけを削除（◎は残す！）
                        $cleanName = str_replace(['＊', '*', '軽'], '', $text);
                        $currentFileItems[] = ['name' => trim($cleanName), 'price' => $price];
                        
                        $i++; // 金額の行をスキップ
                        continue;
                    }
                }
            }
            
            // 2. 合計金額の抽出
            if (mb_strpos($text, '計') !== false && !mb_strpos($text, '消費税')) {
                $searchArea = $text . ($lines[$i+1]['text'] ?? '') . ($lines[$i+2]['text'] ?? '');
                if (preg_match('/[¥￥]([\d,]+)/u', $searchArea, $m)) {
                    $val = (int)str_replace(',', '', $m[1]);
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
    <title>コンビニレシート解析</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .container { max-width: 700px; margin: auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .receipt-result { border-left: 6px solid #00a95c; background: #fdfdfd; padding: 15px; margin-bottom: 20px; border-bottom: 1px solid #eee; }
        .item-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #ddd; }
        .total-row { font-size: 1.6em; font-weight: bold; color: #d32f2f; margin-top: 15px; text-align: right; }
        .btn { padding: 10px 20px; background: #0078d4; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <h2>コンビニレシート解析</h2>
        <form action="" method="post" enctype="multipart/form-data">
            <p>画像をアップロードしてください：</p>
            <input type="file" name="receipts[]" multiple accept="image/*"><br><br>
            <button type="submit" class="btn">解析実行</button>
        </form>

        <?php if (!empty($results)): ?>
            <hr>
            <?php foreach ($results as $res): ?>
                <div class="receipt-result">
                    <p style="color: #666;">📄 <?php echo htmlspecialchars($res['file']); ?></p>
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
