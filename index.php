<?php
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$results = [];

// --- 1. 下载处理逻辑 (CSV 和 日志) ---
if (isset($_GET['action'])) {
    $sessionData = file_exists('ocr_data.json') ? json_decode(file_get_contents('ocr_data.json'), true) : [];
    
    if ($_GET['action'] == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['文件名', '项目', '金额']);
        foreach ($sessionData as $res) {
            foreach ($res['items'] as $it) fputcsv($output, [$res['file'], $it['name'], $it['price']]);
            fputcsv($output, [$res['file'], '合计', $res['total']]);
        }
        fclose($output); exit;
    }

    if ($_GET['action'] == 'log') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=ocr_debug_log.json');
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
            // 清理干扰字符用于判断
            $noSpace = str_replace([' ', '　', '=', '-', '_', '＊', '*', '◎'], '', $text);

            // 【防御】进入结算区停止抓取，防止抓到底部的余额 ¥462
            if (preg_match('/合计|合計|支付|支払|残高|番号|カード/u', $noSpace)) {
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            if ($stopFlag) continue;

            // 【识别金额行】
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                
                // 排除单纯的税率说明行（如“8%对象 ¥198”）
                if (preg_match('/对象|対象|消費税/u', $noSpace)) continue;

                $foundName = "";
                // 1. 先尝试从本行取名字
                $rawName = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                
                // 2. 如果本行没名字或名字包含“对象”，则多级向上溯源
                if (mb_strlen($rawName) < 2 || preg_match('/对象|対象/u', $rawName)) {
                    for ($back = 1; $back <= 3; $back++) { // 向上跳过 8%对象 等行
                        if (isset($lines[$i - $back])) {
                            $prevText = trim($lines[$i - $back]['text']);
                            // 排除非商品信息的噪音行
                            if (!preg_match('/对象|対象|消費税|領収|领收|Family|新宿|电话|登録/u', $prevText) && mb_strlen($prevText) > 1) {
                                $foundName = $prevText;
                                break;
                            }
                        }
                    }
                } else {
                    $foundName = $rawName;
                }

                if (!empty($foundName)) {
                    // 清理名字里的符号，但保留核心文字
                    $cleanName = str_replace(['＊', '*', '轻', '軽', '◎', '(', '（', ')', '）', '.', '．', '…'], '', $foundName);
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
    <title>小票全兼容解析系统</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .box { max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .card { border-left: 5px solid #00a95c; background: #fdfdfd; padding: 15px; margin-top: 15px; border-radius: 4px; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #ddd; }
        .total { font-size: 24px; font-weight: bold; color: #d32f2f; text-align: right; margin-top: 15px; }
        .btn { width: 100%; padding: 12px; background: #0078d4; color: white; border: none; cursor: pointer; border-radius: 4px; font-weight: bold; }
        .footer-tools { text-align: center; margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee; }
        .footer-tools a { margin: 0 15px; text-decoration: none; color: #0078d4; font-size: 14px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">🧾 小票全兼容解析 (V6)</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple><br><br>
            <button type="submit" class="btn">执行全量解析</button>
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
            
            <div class="footer-tools">
                <a href="?action=csv">📊 下载 CSV 报表</a>
                <a href="?action=log">📜 下载调试日志 (JSON)</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
