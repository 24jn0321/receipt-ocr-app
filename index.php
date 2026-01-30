<?php
// Azure Vision API 設定
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$results = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        $fileName = $_FILES['receipts']['name'][$key];

        // APIリクエストURL (v4.0 Read機能)
        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
        $imageData = file_get_contents($tmpName);

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/octet-stream',
            'Ocp-Apim-Subscription-Key: ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imageData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        
        $currentFileItems = [];
        $totalAmount = 0;
        $foundTotalLine = false; // 合計以降を無視するためのフラグ

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);

            // 1. 合計金額の検出（ストッパー）
            // 「合計」「合計」「計」などの文字を探す。ただし「消費税」の行は無視
            if ((mb_strpos($text, '合計') !== false || mb_strpos($text, '計') !== false) && mb_strpos($text, '消費税') === false) {
                // 同じ行か、続く2行以内に金額があるか探す
                $searchArea = $text . ($lines[$i+1]['text'] ?? '') . ($lines[$i+2]['text'] ?? '');
                if (preg_match('/[¥￥]([\d,]+)/u', $searchArea, $m)) {
                    $val = (int)str_replace(',', '', $m[1]);
                    // すでに取得した合計より大きい数字があれば更新（お釣りと混同しないため）
                    if ($val > $totalAmount) $totalAmount = $val;
                    $foundTotalLine = true; 
                }
                continue;
            }

            // 合計行を過ぎたら、それ以降の「カード番号」や「残高」は商品として読み飛ばす
            if ($foundTotalLine) continue;

            // 2. 商品名と価格の抽出
            // ¥記号を含まない、かつヘッダー情報（店名や住所）でない2文字以上の行
            if (!preg_match('/[¥￥]/u', $text) && 
                !preg_match('/Family|新宿|電話|登録|2024|レジ|領収|証|対象/u', $text) &&
                mb_strlen($text) >= 2) {
                
                // 次の行に金額(¥)があるかチェック
                if (isset($lines[$i + 1])) {
                    $nextText = $lines[$i + 1]['text'];
                    if (preg_match('/[¥￥]([\d,]+)/u', $nextText, $matches)) {
                        $price = (int)str_replace(',', '', $matches[1]);
                        
                        // 「軽」や「＊」は消すが、「◎」は残す
                        $cleanName = str_replace(['＊', '*', '軽'], '', $text);
                        $currentFileItems[] = [
                            'name' => trim($cleanName), 
                            'price' => $price
                        ];
                        
                        $i++; // 金額の行を処理したので次に進める
                        continue;
                    }
                }
            }
        }
        $results[] = [
            'file' => $fileName, 
            'items' => $currentFileItems, 
            'total' => $totalAmount
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>複数レシート解析システム</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 700px; margin: auto; background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .receipt-card { border-left: 8px solid #00a95c; background: #fafafa; padding: 20px; margin-bottom: 30px; border-radius: 4px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .file-info { color: #666; font-size: 0.85em; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-bottom: 15px; display: block; }
        .item-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #ccc; }
        .total-row { font-size: 1.6em; font-weight: bold; color: #d32f2f; text-align: right; margin-top: 15px; }
        .btn { padding: 12px 20px; background: #0078d4; color: white; border: none; border-radius: 6px; cursor: pointer; width: 100%; font-size: 1.1em; }
        hr { margin: 30px 0; border: none; border-top: 1px solid #eee; }
    </style>
</head>
<body>
    <div class="container">
        <h2>コンビニレシート一括解析</h2>
        <form action="" method="post" enctype="multipart/form-data">
            <p>解析する画像をすべて選択してください：</p>
            <input type="file" name="receipts[]" multiple accept="image/*"><br><br>
            <button type="submit" class="btn">解析実行</button>
        </form>

        <?php if (!empty($results)): ?>
            <hr>
            <?php foreach ($results as $res): ?>
                <div class="receipt-card">
                    <span class="file-info">📄 ファイル名: <?php echo htmlspecialchars($res['file']); ?></span>
                    
                    <?php if (empty($res['items'])): ?>
                        <p style="color:#999;">商品は検出されませんでした。</p>
                    <?php else: ?>
                        <?php foreach ($res['items'] as $item): ?>
                            <div class="item-row">
                                <span><?php echo htmlspecialchars($item['name']); ?></span>
                                <span>¥<?php echo number_format($item['price']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="total-row">合計 ¥<?php echo number_format($res['total']); ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
