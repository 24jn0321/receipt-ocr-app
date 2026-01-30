<?php
/**
 * 🧾 小票解析系统 - 多图并发处理版
 */

$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 

$results = [];
$storageFile = 'ocr_data.json';
$logFile = 'ocr.log';

// --- A. 下载逻辑 (保持不变) ---
if (isset($_GET['action'])) {
    if ($_GET['action'] == 'csv' && file_exists($storageFile)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_export.csv');
        echo "\xEF\xBB\xBF"; $output = fopen('php://output', 'w');
        fputcsv($output, ['文件名', '项目', '金额']);
        $data = json_decode(file_get_contents($storageFile), true);
        foreach ($data as $res) {
            foreach ($res['items'] as $it) fputcsv($output, [$res['file'], $it['name'], $it['price']]);
            fputcsv($output, [$res['file'], '合计', $res['total']]);
        }
        fclose($output); exit;
    }
    if ($_GET['action'] == 'log' && file_exists($logFile)) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename=ocr_debug.log');
        readfile($logFile); exit;
    }
}

// --- B. 多图 OCR 解析核心 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    file_put_contents($logFile, "--- START BATCH SCAN: " . date('Y-m-d H:i:s') . " ---\n");
    
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        
        $fileName = $_FILES['receipts']['name'][$key];
        $imgData = file_get_contents($tmpName);
        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
        
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch); curl_close($ch);

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];

        $currentItems = [];
        $sumAmount = 0;
        $stopFlag = false;
        
        file_put_contents($logFile, "\n[FILE $key]: $fileName\n", FILE_APPEND);

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            $pureText = str_replace([' ', '　', '＊', '*', '◎', '√', '軽', '轻', '(', ')'], '', $text);

            // 1. 结算区拦截
            if (preg_match('/合計|合计|内消費税|消費税|対象|支払|残高/u', $pureText)) {
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            if ($stopFlag) continue;

            // 2. 金额匹配与干扰项过滤 (核心逻辑)
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                $nameInLine = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                $cleanNameInLine = str_replace(['＊', '*', '轻', '軽', '◎', '(', ')', '.', '．', ' '], '', $nameInLine);

                // 纯金额行则向上找
                if (empty($cleanNameInLine) || preg_match('/^[¥￥\d,\s]+$/u', $cleanNameInLine)) {
                    $foundName = "";
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prev = trim($lines[$j]['text']);
                        $cleanPrev = str_replace(['＊', '*', '◎', ' ', '√', '軽', '轻'], '', $prev);
                        if (mb_strlen($cleanPrev) >= 2 && !preg_match('/領|収|証|合|计|計|%|店|电话|¥|￥/u', $cleanPrev)) {
                            $foundName = $cleanPrev; break;
                        }
                    }
                    $finalName = $foundName;
                } else {
                    $finalName = $cleanNameInLine;
                }

                if (!empty($finalName) && !preg_match('/Family|新宿|電話|登録|領収|対象|消費税|合計/u', $finalName)) {
                    $isDuplicate = false;
                    foreach ($currentItems as $item) {
                        if ($item['name'] === $finalName && $item['price'] === $price) { $isDuplicate = true; break; }
                    }
                    if (!$isDuplicate) {
                        $currentItems[] = ['name' => $finalName, 'price' => $price];
                        $sumAmount += $price;
                    }
                }
            }
        }
        // 将单张小票结果存入数组
        $results[] = [
            'file' => $fileName,
            'items' => $currentItems,
            'total' => $sumAmount
        ];
    }
    file_put_contents($storageFile, json_encode($results));
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>多图智能扫描系统</title>
    <style>
        body { font-family: -apple-system, sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 800px; margin: auto; }
        .upload-card { background: white; padding: 30px; border-radius: 12px; text-align: center; box-shadow: 0 4px 10px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .receipt-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px; }
        .receipt-card { background: white; padding: 20px; border-radius: 10px; border-top: 5px solid #1a73e8; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .item-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px dashed #eee; font-size: 14px; }
        .total-row { text-align: right; color: #d93025; font-size: 20px; font-weight: bold; margin-top: 15px; }
        .btn { background: #1a73e8; color: white; padding: 12px 25px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .btn:hover { background: #1557b0; }
        .file-info { font-size: 12px; color: #666; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px; }
        .footer-btns { margin-top: 30px; display: flex; justify-content: center; gap: 15px; }
        .link-btn { text-decoration: none; padding: 8px 16px; border: 1px solid #ccc; border-radius: 4px; color: #555; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="upload-card">
            <h2>📸 批量小票智能扫描</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="file" name="receipts[]" multiple required accept="image/*">
                <p style="color: #888; font-size: 13px;">按住 Ctrl 或 Shift 可一次选择多张照片</p>
                <button type="submit" class="btn">解析选中的图片</button>
            </form>
        </div>

        <?php if ($results): ?>
            <div class="receipt-grid">
                <?php foreach ($results as $index => $res): ?>
                    <div class="receipt-card">
                        <div class="file-info">#<?= $index + 1 ?>: <?= htmlspecialchars($res['file']) ?></div>
                        <?php if (empty($res['items'])): ?>
                            <p style="color:#999; text-align:center;">未能识别有效内容</p>
                        <?php else: ?>
                            <?php foreach ($res['items'] as $it): ?>
                                <div class="item-row">
                                    <span><?= htmlspecialchars($it['name']) ?></span>
                                    <span>¥<?= number_format($it['price']) ?></span>
                                </div>
                            <?php endforeach; ?>
                            <div class="total-row">合计 ¥<?= number_format($res['total']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="footer-btns">
                <a href="?action=csv" class="link-btn" style="background: #34a853; color: white; border: none;">📥 下载总报表 (CSV)</a>
                <a href="?action=log" class="link-btn">📜 查看解析日志 (ocr.log)</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
