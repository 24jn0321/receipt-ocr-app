<?php
/**
 * 🧾 小票解析系统 - 批量兼容版
 * 解决 413 错误：尝试在代码层提升上传限制
 */

// --- [核心修复] 尝试在代码运行瞬间撑大服务器胃口 ---
@ini_set('upload_max_filesize', '64M');
@ini_set('post_max_size', '64M');
@ini_set('memory_limit', '256M');
@ini_set('max_execution_time', '300');

$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 

$results = [];
$storageFile = 'ocr_data.json';
$logFile = 'ocr.log';

// --- A. 下载功能 (CSV & 日志) ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action == 'csv' && file_exists($storageFile)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_export.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['文件名', '项目', '金额']);
        $data = json_decode(file_get_contents($storageFile), true);
        if ($data) {
            foreach ($data as $res) {
                foreach ($res['items'] as $it) fputcsv($output, [$res['file'], $it['name'], $it['price']]);
                fputcsv($output, [$res['file'], '合计', $res['total']]);
            }
        }
        fclose($output); exit;
    }
    if ($action == 'log' && file_exists($logFile)) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename=ocr_debug.log');
        readfile($logFile); exit;
    }
}

// --- B. OCR 批量解析逻辑 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    file_put_contents($logFile, "--- START BATCH SCAN: " . date('Y-m-d H:i:s') . " ---\n");
    
    // 遍历上传的所有图片
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
        $stopFlag = false; // 每张图独立重置拦截标志
        
        file_put_contents($logFile, "\n[FILE]: $fileName\n", FILE_APPEND);

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            $pureText = str_replace([' ', '　', '＊', '*', '◎', '√', '軽', '轻', '(', ')'], '', $text);

            // 1. 红色框拦截逻辑 (针对税费、合计等干扰项)
            if (preg_match('/合計|合计|内消費税|消費税|対象|支払|残高|再発行/u', $pureText)) {
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            if ($stopFlag) continue;

            // 2. 匹配金额
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                $nameInLine = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                $cleanNameInLine = str_replace(['＊', '*', '轻', '軽', '◎', '(', ')', '.', '．', ' '], '', $nameInLine);

                // 如果是纯金额行，向上找商品名
                if (empty($cleanNameInLine) || preg_match('/^[¥￥\d,\s]+$/u', $cleanNameInLine)) {
                    $foundName = "";
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prev = trim($lines[$j]['text']);
                        $cleanPrev = str_replace(['＊', '*', '◎', ' ', '√', '軽', '轻'], '', $prev);
                        if (mb_strlen($cleanPrev) >= 2 && !preg_match('/領|収|証|合|计|計|%|店|电话|¥|￥/u', $cleanPrev)) {
                            $foundName = $cleanPrev;
                            break;
                        }
                    }
                    $finalName = $foundName;
                } else {
                    $finalName = $cleanNameInLine;
                }

                // 3. 最终校验并存入
                if (!empty($finalName) && !preg_match('/Family|新宿|電話|登録|領収|対象|消費税|合計/u', $finalName)) {
                    $isDuplicate = false;
                    foreach ($currentItems as $item) {
                        if ($item['name'] === $finalName && $item['price'] === $price) {
                            $isDuplicate = true; break;
                        }
                    }
                    if (!$isDuplicate) {
                        $currentItems[] = ['name' => $finalName, 'price' => $price];
                        $sumAmount += $price;
                        file_put_contents($logFile, "  -> ADDED: $finalName ($price)\n", FILE_APPEND);
                    }
                }
            }
        }
        $results[] = ['file' => $fileName, 'items' => $currentItems, 'total' => $sumAmount];
    }
    file_put_contents($storageFile, json_encode($results));
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>小票批量解析-兼容版</title>
    <style>
        body { font-family: -apple-system, "Helvetica Neue", Arial, sans-serif; background: #f0f2f5; padding: 20px; color: #333; }
        .container { max-width: 900px; margin: auto; }
        .upload-box { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); text-align: center; margin-bottom: 25px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; }
        .card { background: white; padding: 20px; border-radius: 10px; border-top: 5px solid #3498db; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #eee; font-size: 14px; }
        .total { font-size: 18px; font-weight: bold; color: #e74c3c; text-align: right; margin-top: 15px; }
        .btn { background: #3498db; color: white; padding: 12px 25px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .actions { margin-top: 30px; display: flex; justify-content: center; gap: 15px; }
        .link-btn { text-decoration: none; padding: 8px 18px; border: 1px solid #3498db; color: #3498db; border-radius: 4px; font-size: 14px; }
        .link-btn:hover { background: #3498db; color: white; }
    </style>
</head>
<body>
    <div class="container">
        <div class="upload-box">
            <h2>🧾 小票智能批量扫描</h2>
            <p style="color:#666; font-size:14px;">支持同时上传多张图片，自动过滤税费干扰项</p>
            <form method="post" enctype="multipart/form-data">
                <input type="file" name="receipts[]" multiple required accept="image/*" style="margin: 20px 0;"><br>
                <button type="submit" class="btn">开始解析 (多图并行)</button>
            </form>
        </div>

        <div class="grid">
            <?php if ($results): ?>
                <?php foreach ($results as $res): ?>
                    <div class="card">
                        <small style="color:#999">📄 <?= htmlspecialchars($res['file']) ?></small>
                        <?php if (empty($res['items'])): ?>
                            <p style="color:#bbb; text-align:center; padding:20px;">未检测到有效商品</p>
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
        </div>

        <?php if ($results || file_exists($storageFile)): ?>
            <div class="actions">
                <a href="?action=csv" class="link-btn" style="background:#2ecc71; color:white; border:none;">📥 下载汇总报表 (CSV)</a>
                <a href="?action=log" class="link-btn">📜 下载调试日志 (ocr.log)</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
