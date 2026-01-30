<?php
/**
 * 🧾 小票解析系统 - 全家小票专用稳定版
 * 解决：重复金额行、税额行、空跑逻辑
 */

$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 

$results = [];
$storageFile = 'ocr_data.json';
$logFile = 'ocr.log';

// --- A. 下载逻辑 ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action == 'csv' && file_exists($storageFile)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt.csv');
        echo "\xEF\xBB\xBF"; $output = fopen('php://output', 'w');
        fputcsv($output, ['文件', '项目', '金额']);
        $data = json_decode(file_get_contents($storageFile), true);
        foreach ($data as $res) {
            foreach ($res['items'] as $it) fputcsv($output, [$res['file'], $it['name'], $it['price']]);
            fputcsv($output, [$res['file'], '合计', $res['total']]);
        }
        fclose($output); exit;
    }
    if ($action == 'log' && file_exists($logFile)) {
        header('Content-Type: text/plain; charset=utf-8');
        readfile($logFile); exit;
    }
}

// --- B. OCR 解析逻辑 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
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
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];

        $currentItems = [];
        $sumAmount = 0;
        $stopFlag = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            // 彻底清洗掉无意义符号
            $pureText = str_replace([' ', '　', '＊', '*', '◎', '√', '軽', '轻', '(', ')', '（', '）'], '', $text);

            // 1. 拦截结算区（看到这些词，后面的 ¥ 一律不要了）
            if (preg_match('/合計|合計|合計|消費税|支払|残高|対象商品/u', $pureText)) {
                if (!empty($currentItems)) $stopFlag = true;
                continue;
            }
            if ($stopFlag) continue;

            // 2. 识别带 ¥ 的行
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                
                // 关键点：提取名字（去掉价格后的文本）
                $rawName = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                $cleanName = str_replace([' ', '　', '＊', '*', '◎', '√', '軽', '轻', '(', ')', '．', '.'], '', $rawName);

                // 【核心改进】：如果这行“除了价格就没别的了”或者是“纯数字/税费词”，绝对跳过！
                // 这样就能挡住红框里的 ¥168 (重复行) 和 内消費税等
                if (mb_strlen($cleanName) < 1 || preg_match('/^[¥￥\d,\s]+$/u', $cleanName) || preg_match('/対象|消費税/u', $cleanName)) {
                    continue; 
                }

                // 3. 向上溯源：如果本行文字太短（可能只是个残留字符），才去上一行找真名
                if (mb_strlen($cleanName) < 2) {
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prev = trim($lines[$j]['text']);
                        if (mb_strlen($prev) >= 2 && !preg_match('/領|収|証|合|計|%|電話|店|番号/u', $prev)) {
                            $cleanName = str_replace(['◎','√','＊','*',' '], '', $prev);
                            break;
                        }
                    }
                }

                // 4. 最终排除与去重
                if (mb_strlen($cleanName) >= 2 && !preg_match('/Family|新宿|電話|登録|領収|対象|合計/u', $cleanName)) {
                    // 检查是否和上一个抓到的完全一样（防止极近距离重复）
                    $isDup = false;
                    if (!empty($currentItems)) {
                        $last = end($currentItems);
                        if ($last['name'] == $cleanName && $last['price'] == $price) $isDup = true;
                    }
                    
                    if (!$isDup) {
                        $currentItems[] = ['name' => $cleanName, 'price' => $price];
                        $sumAmount += $price;
                    }
                }
            }
        }
        
        // 记录日志
        $logEntry = "=== FILE: $fileName ===\n";
        foreach ($currentItems as $it) { $logEntry .= "ITEM: {$it['name']} | {$it['price']}\n"; }
        file_put_contents($logFile, $logEntry . "TOTAL: $sumAmount\n\n", FILE_APPEND);

        $results[] = ['file' => $fileName, 'items' => $currentItems, 'total' => $sumAmount];
    }
    file_put_contents($storageFile, json_encode($results));
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>小票解析系统 - 最终修复</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .box { max-width: 600px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .card { border-left: 5px solid #1a73e8; background: #fafafa; padding: 15px; margin-top: 15px; border-radius: 4px; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
        .total { font-size: 22px; font-weight: bold; color: #d32f2f; text-align: right; margin-top: 10px; }
        .btn { width: 100%; padding: 12px; background: #1a73e8; color: white; border: none; cursor: pointer; border-radius: 6px; font-size: 16px; }
        .footer { margin-top: 20px; text-align: center; border-top: 1px solid #eee; padding-top: 15px; }
        .footer a { text-decoration: none; color: #1a73e8; font-size: 14px; margin: 0 10px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">🧾 小票解析系统</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple required><br><br>
            <button type="submit" class="btn">开始解析并过滤干扰</button>
        </form>

        <?php if ($results): ?>
            <?php foreach ($results as $res): ?>
                <div class="card">
                    <div style="color:#888; font-size:12px; margin-bottom:5px;">📄 <?= htmlspecialchars($res['file']) ?></div>
                    <?php if (empty($res['items'])): ?>
                        <p style="color:#999">未发现有效商品。</p>
                    <?php else: ?>
                        <?php foreach ($res['items'] as $it): ?>
                            <div class="row">
                                <span><?= htmlspecialchars($it['name']) ?></span>
                                <span>¥<?= number_format($it['price']) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <div class="total">合计 ¥<?= number_format($res['total']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="footer">
            <a href="?action=csv">📥 下载 CSV</a> | <a href="?action=log">📜 查看日志</a>
        </div>
    </div>
</body>
</html>
