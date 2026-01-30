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
        $sumAmount = 0; // 新增：用于存放累加的商品总和

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);

            // 1. 商品提取逻辑（保留你原本认为好用的过滤词）
            if (!preg_match('/[¥￥]/u', $text) && 
                !preg_match('/Family|新宿|电话|登録|2024|レジ|領収|対象|消費税|支払|残高|証|単価/u', $text) &&
                mb_strlen($text) >= 2) {
                
                if (isset($lines[$i + 1])) {
                    $nextText = $lines[$i + 1]['text'];
                    // 修正：更精准地提取 ¥ 后的数字，去掉“轻”等干扰
                    if (preg_match('/[¥￥]([\d,]+)/u', $nextText, $matches)) {
                        $price = (int)str_replace(',', '', $matches[1]);
                        
                        // 排除杂项金额（消费税等，即使在下一行也要过滤）
                        if (!preg_match('/消費税|対象|残高|支払/u', $nextText)) {
                            $cleanName = str_replace(['＊', '*', '轻', '軽'], '', $text);
                            $currentFileItems[] = ['name' => trim($cleanName), 'price' => $price];
                            
                            $sumAmount += $price; // 关键：把每个商品的单价加起来
                            $i++; 
                            continue;
                        }
                    }
                }
            }
        }
        // 直接用累加的金额作为最终合计，解决 ¥0 或合计错误的问题
        $results[] = ['file' => $fileName, 'items' => $currentFileItems, 'total' => $sumAmount];
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>收据解析修复版</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .container { max-width: 700px; margin: auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .receipt-result { border-left: 6px solid #00a95c; background: #fdfdfd; padding: 15px; margin-bottom: 20px; }
        .item-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #ddd; }
        .total-
