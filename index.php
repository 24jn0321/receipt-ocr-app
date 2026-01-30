<?php
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$results = [];

// --- 1. 下载处理逻辑 ---
if (isset($_GET['action'])) {
    $sessionData = file_exists('ocr_data.json') ? json_decode(file_get_contents('ocr_data.json'), true) : [];
    
    if ($_GET['action'] == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_summary.csv');
        echo "\xEF\xBB\xBF"; // 修正Excel乱码
        $output = fopen('php://output', 'w');
        fputcsv($output, ['文件名', '商品名', '金额']);
        foreach ($sessionData as $res) {
            foreach ($res['items'] as $it) fputcsv($output, [$res['file'], $it['name'], $it['price']]);
            fputcsv($output, [$res['file'], '合计', $res['total']]);
        }
        fclose($output); exit;
    }

    if ($_GET['action'] == 'log') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=ocr_debug.json');
        echo json_encode($sessionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); exit;
    }
}

// --- 2. OCR 解析逻辑 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        
        $imgData = file_get_contents($tmpName);
        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
        
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch); curl_close($ch);

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];

        $currentItems = [];
        $sumAmount = 0;
        $stopFlag = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            $noSpace = str_replace([' ', '　', '=', '-', '_', '＊', '*', '◎'], '', $text);

            // 【防御】一旦看到结算关键词，停止后续商品抓取
            if (preg_match('/合计|合計|支付|支払|残高|番号|カード/u', $noSpace)) {
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            if ($stopFlag) continue;

            // 【识别】带 ¥ 的行
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                
                // 排除税费行（不把“8%对象”当名字）
                if (preg_match('/对象|対象|消費税/u', $noSpace)) continue;

                // 【提取名字逻辑】
                $name = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                
                // 如果本行没名字或名字是杂质，则跳跃式向上找
                if (mb_strlen($name) < 2 || preg_match('/对象|対象/u', $name)) {
                    for ($back = 1; $back <= 3; $back++) {
                        if (isset($lines[$i - $back])) {
                            $prevText = trim($lines[$i - $back]['text']);
                            // 排除常见的非商品抬头词汇
                            if (!preg_match('/对象|対象|消費税|領収|领收|Family|新宿|电话|登録/u', $prevText) && mb_strlen($prevText) > 1) {
                                $name = $prevText;
                                break;
                            }
                        }
                    }
                }

                $cleanName = str_replace(['＊', '*', '轻', '軽', '◎', '(', '（', ')', '）', '.', '．', '…'], '', $name);
                
                if (mb_strlen($cleanName) >= 2) {
                    $currentItems[] = ['name' => $cleanName, 'price' => $price];
                    $sumAmount += $price;
                }
            }
        }
        $results[] = ['file' => $_FILES['receipts']['name'][$key], 'items' => $currentItems, 'total' => $sumAmount];
    }
    file_put_contents('ocr_data.json', json_encode($results));
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>小票全系列兼容解析</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 20px; }
        .box { max-width: 650px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .card { border-left: 6px solid #00a95c; background: #fafafa; padding: 15px; margin-top: 20px; border-radius: 4px; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #ccc; }
        .total { font-size: 24px; font-weight: bold; color: #d32f2f; text-align: right; margin-top: 15px; }
        .btn { width: 100%; padding: 12px; background: #0078d4; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .tools { text-align: center; margin-top: 20px; border-top: 1px solid #eee; padding-top: 15px; }
        .tools a { margin: 0 10px; color: #0078d4; text-decoration: none; font-size: 14px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">🧾 小票智能解析系统</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple><br><br>
            <button type="submit" class="btn">解析 1、2、3 号全系列图片</button>
        </form>

        <?php if ($results): ?>
            <?php foreach ($results as $res): ?>
                <div class="card">
                    <small style="color:#999"><?= htmlspecialchars($res['file']) ?></small>
                    <?php foreach ($res['items'] as $it): ?>
                        <div class="row">
                            <span><?= htmlspecialchars($it['name']) ?></span>
                            <span>¥<?= number_format($it['price']) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="total">合计 ¥<?= number_format($res['total']) ?></div>
                </div>
            <?php endforeach; ?>
            
            <div class="tools">
                <a href="?action=csv">📥 下载 CSV 报表</a> | 
                <a href="?action=log">📜 下载原始识别日志</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
