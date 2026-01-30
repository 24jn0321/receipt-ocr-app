<?php
// Azure Vision API 設定
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

// データベース設定（保存機能を追加する場合に使用）
$serverName = "receipt-server-24jn0.database.windows.net";
$database   = "receiptdb";
$username   = "sqladmin"; 
$password   = "Abc842727925";

$results = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        $fileName = $_FILES['receipts']['name'][$key];

        // APIリクエストURL (v4.0 Read機能を使用)
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
        $foundTotal = false; // 「合計」検知フラグ

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);

            // 1. 合計金額の判定とストッパー設定
            if ((mb_strpos($text, '計') !== false || mb_strpos($text, '合計') !== false) && !mb_strpos($text, '消費税')) {
                // 同じ行または直後の行から金額を探す
                $searchArea = $text . ($lines[$i+1]['text'] ?? '') . ($lines[$i+2]['text'] ?? '');
                if (preg_match('/[¥￥]([\d,]+)/u', $searchArea, $m)) {
                    $val = (int)str_replace(',', '', $m[1]);
                    if ($val > $totalAmount) $totalAmount = $val;
                    $foundTotal = true; // これ以降の行は商品名として処理しない
                }
                continue;
            }

            // 合計金額以降（カード情報など）は無視する
            if ($foundTotal) continue;

            // 2. 商品名の抽出ロジック
            // 不要なキーワードや短すぎる文字を除外
            if (!preg_match('/[¥￥]/u', $text) && 
                !preg_match('/Family|新宿|電話|登録|2024|レジ|領収|対象|消費税|支払|残高|証|単価/u', $text) &&
                mb_strlen($text) >= 2) {
                
                // 次の行に金額(¥)があるか確認
                if (isset($lines[$i + 1])) {
                    $nextText = $lines[$i + 1]['text'];
                    if (preg_match('/[¥￥]([\d,]+)/u', $nextText, $matches)) {
                        $price = (int)str_replace(',', '', $matches[1]);
                        
                        // 「軽」「＊」などは削除するが、「◎」は残す
                        $cleanName = str_replace(['＊', '*', '軽'], '', $text);
                        $currentFileItems[] = [
                            'name' => trim($cleanName), 
                            'price' => $price
                        ];
                        
                        $i++; // 金額行をスキップ
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
    <title>レシート解析システム</title>
    <style>
        body { font-family: "Helvetica Neue", Arial, sans-serif; background: #f0f2f5; padding: 20px; color: #333; }
        .container { max-width: 600px; margin: auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h2 { border-bottom: 2px solid #00a95c; padding-bottom: 10px; color: #00a95c; }
        .upload-section { margin-bottom: 30px; padding: 20px; border: 2px dashed #ccc; border-radius: 8px; text-align: center; }
        .receipt-result { background: #fff; padding: 15px; margin-bottom: 20px; border: 1px solid #e0e0e0; border-radius: 8px; }
        .file-name { font-size: 0.9em; color: #666; margin-bottom: 10px; display: block; }
        .item-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
        .item-row:last-child { border-bottom: none; }
        .total-row { font-size: 1.5em; font-weight: bold; color: #d32f2f; margin-top: 15px; text-align: right; border-top: 2px solid #eee; padding-top: 10px; }
        .btn { padding: 12px 25px; background: #0078d4; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 1em; width: 100%; transition: 0.3s; }
        .btn:hover { background: #005a9e; }
    </style>
</head>
<body>
    <div class="container">
        <h2>レシート解析システム</h2>
        
        <div class="upload-section">
            <form action="" method="post" enctype="multipart/form-data">
                <input type="file" name="receipts[]" multiple accept="image/*" style="margin-bottom:15px;"><br>
                <button type="submit" class="btn">解析を実行する</button>
            </form>
        </div>

        <?php if (!empty($results)): ?>
            <h3>解析結果</h3>
            <?php foreach ($results as $res): ?>
                <div class="receipt-result">
                    <span class="file-name">📄 <?php echo htmlspecialchars($res['file']); ?></span>
                    
                    <?php if (empty($res['items'])): ?>
                        <p style="color:red;">商品が見つかりませんでした。</p>
                    <?php else: ?>
                        <?php foreach ($res['items'] as $i): ?>
                            <div class="item-row">
                                <span><?php echo htmlspecialchars($i['name']); ?></span>
                                <span>¥<?php echo number_format($i['price']); ?></span>
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
