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
        $foundTotal = false; // 合計ラインを見つけたかどうかのフラグ

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);

            // 1. 合計金額の判定（ここより下の行は商品として処理しない）
            if (mb_strpos($text, '計') !== false && !mb_strpos($text, '消費税')) {
                $searchArea = $text . ($lines[$i+1]['text'] ?? '') . ($lines[$i+2]['text'] ?? '');
                if (preg_match('/[¥￥]([\d,]+)/u', $searchArea, $m)) {
                    $val = (int)str_replace(',', '', $m[1]);
                    if ($val > $totalAmount) $totalAmount = $val;
                    $foundTotal = true; // ストッパー発動
                }
                continue;
            }

            // 合計金額が見つかった後は、商品名の探索を中止する（カード番号などの誤認防止）
            if ($foundTotal) continue;

            // 2. 商品名の抽出ロジック
            // 除外キーワードを増やし、より正確に
            if (!preg_match('/[¥￥]/u', $text) && 
                !preg_match('/Family|新宿|電話|登録|2024|レジ|領収|対象|消費税|支払|残高|証|単価/u', $text) &&
                mb_strlen($text) >= 2) {
                
                // 次の行に金額があるか確認
                if (isset($lines[$i + 1])) {
                    $nextText = $lines[$i + 1]['text'];
                    if (preg_match('/[¥￥]([\d,]+)/u', $nextText, $matches)) {
                        $price = (int)str_replace(',', '', $matches[1]);
                        
                        // 「軽」や「＊」だけを削除（◎は残す！）
                        $cleanName = str_replace(['＊', '*', '軽'], '', $text);
                        $currentFileItems[] = ['name' => trim($cleanName), 'price' => $price];
                        
                        $i++; 
                        continue;
                    }
                }
            }
        }
        $results[] = ['file' => $fileName, 'items' => $currentFileItems, 'total' => $totalAmount];
    }
}
?>
