<?php
/**
 * 🧾 小票解析系统 - 多图并发处理修复版
 */

$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 

$results = [];
$storageFile = 'ocr_data.json';
$logFile = 'ocr.log';

// --- A. 下载处理 (CSV & LOG) ---
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
        readfile($logFile);
        exit;
    }
}

// --- B. OCR 解析逻辑 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    // 每次上传清空旧日志
    file_put_contents($logFile, "--- OCR DEBUG LOG " . date('Y-m-d H:i:s') . " ---\n");
    
    // 关键：循环处理所有上传的文件
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
        
        file_put_contents($logFile, "\n[FILE]: $fileName\n", FILE_APPEND);

        // --- 核心识别逻辑循环 ---
        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            file_put_contents($logFile, "  RAW: $text\n", FILE_APPEND);

            $pureText = str_replace([' ', '　', '＊', '*', '◎', '√', '軽', '轻', '(', ')'], '', $text);

            if (preg_match('/合計|合计|内消費税|消費税|対象|支払|残高|再発行/u', $pureText)) {
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            if ($stopFlag) continue;

            // 匹配金额行
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                $nameInLine = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                $cleanNameInLine = str_replace(['＊', '*', '轻', '軽', '◎', '(', ')', '.', '．', ' '], '', $nameInLine);

                // --- 找回你的核心代码：如果本行没有名字，向上寻找 ---
                if (empty($cleanNameInLine) || preg_match('/^[¥￥\d,\s]+$/u', $cleanNameInLine)) {
                    $foundName = "";
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prev = trim($lines[$j]['text']);
                        $cleanPrev = str_replace(['＊', '*', '◎', ' ', '√', '軽', '轻'], '', $prev);
                        if (mb_strlen($cleanPrev) >= 2 && !preg_match('/領|収|証|合|计|計|%|店|电话|電話|¥|￥/u', $cleanPrev)) {
                            $foundName = $cleanPrev;
                            break;
                        }
                    }
                    $finalName = $foundName;
                } else {
                    $finalName = $cleanNameInLine;
                }

                if (!empty($finalName) && !preg_match('/Family|新宿|電話|登録|領収|対象|消費税|合計|内訳/u', $finalName)) {
                    // 去重检查
                    $isDuplicate = false;
                    foreach ($currentItems as $item) {
                        if ($item['name'] === $finalName && $item['price'] === $price) {
                            $isDuplicate = true; break;
                        }
                    }

                    if (!$isDuplicate) {
                        $currentItems[] = ['name' => $finalName, 'price' => $price];
                        $sumAmount += $price;
                        file_put_contents($logFile, "    -> ADDED: $finalName ($price)\n", FILE_APPEND);
                    }
                }
            }
        }
        // 将单张小票结果存入总结果集
        $results[] = ['file' => $fileName, 'items' => $currentItems, 'total' => $sumAmount];
    }
    // 处理完所有图片后，统一保存
    file_put_contents($storageFile, json_encode($results, JSON_UNESCAPED_UNICODE));
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>小票解析终极版</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .box { max-width: 650px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .card { border-left: 5px solid #2ecc71; background: #fafafa; padding: 15px; margin-top: 15px; border-radius: 4px; border-bottom: 1px solid #ddd; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #eee; }
        .total { font-size: 20px; font-weight: bold; color: #e74c3c; text-align: right; margin-top: 10px; }
        .btn { width: 100%; padding: 12px; background: #3498db; color: white; border: none; cursor: pointer; border-radius: 6px; font-weight: bold; }
        .actions { margin-top: 30px; display: flex; justify-content: center; gap: 20px; border-top: 1px solid #eee; padding-top: 20px; }
        .link-btn { text-decoration: none; font-size: 14px; font-weight: bold; color: #3498db; border: 1px solid #3498db; padding: 8px 15px; border-radius: 5px; }
        .link-btn:hover { background: #3498db; color: white; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">🧾 小票解析 (多图稳定版)</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple required style="margin-bottom:15px;"><br>
            <button type="submit" class="btn">执行解析</button>
        </form>

        <?php if ($results): ?>
            <?php foreach ($results as $res): ?>
                <div class="card">
                    <small style="color:#999">📄 文件: <?= htmlspecialchars($res['file']) ?></small>
                    <?php if (empty($res['items'])): ?>
                        <p style="color:#999; font-size:14px;">未检测到有效商品信息。</p>
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

        <div class="actions">
            <a href="?action=csv" class="link-btn">📥 下载 CSV 报表</a>
            <a href="?action=log" class="link-btn" style="color:#7f8c8d; border-color:#7f8c8d;">📜 下载验证日志</a>
        </div>
    </div>
</body>
</html>
