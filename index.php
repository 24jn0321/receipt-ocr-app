<?php
/**
 * 🧾 小票解析系统 - 多图并行处理版
 */

$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 

$results = [];
$storageFile = 'ocr_data.json';
$logFile = 'ocr.log';

// --- A. 下载处理 ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action == 'csv' && file_exists($storageFile)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_export.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['文件名', '项目', '金额']);
        $data = json_decode(file_get_contents($storageFile), true);
        foreach ($data as $res) {
            foreach ($res['items'] as $it) fputcsv($output, [$res['file'], $it['name'], $it['price']]);
            fputcsv($output, [$res['file'], '合计', $res['total']]);
        }
        fclose($output); exit;
    }
    if ($action == 'log' && file_exists($logFile)) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename=ocr_debug.log');
        readfile($logFile); exit;
    }
}

// --- B. 核心解析逻辑 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    file_put_contents($logFile, "--- BATCH START: " . date('Y-m-d H:i:s') . " ---\n");
    
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

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            $pureText = str_replace([' ', '　', '＊', '*', '◎', '√', '軽', '轻', '(', ')'], '', $text);

            if (preg_match('/合計|合计|内消費税|消費税|対象|支払|残高|再発行/u', $pureText)) {
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            if ($stopFlag) continue;

            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                $nameInLine = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                $cleanNameInLine = str_replace(['＊', '*', '轻', '軽', '◎', '(', ')', '.', '．', ' '], '', $nameInLine);

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
                    }
                }
            }
        }
        // 将结果压入总数组
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
    <title>批量小票解析系统</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 1000px; margin: auto; }
        .upload-section { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); text-align: center; margin-bottom: 20px; }
        
        /* 网格布局：一行显示两个结果卡片 */
        .results-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(450px, 1fr)); gap: 20px; }
        
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); border-top: 4px solid #3498db; }
        .file-name { font-size: 12px; color: #888; border-bottom: 1px solid #eee; padding-bottom: 8px; margin-bottom: 10px; display: block; }
        .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #f0f0f0; }
        .total { font-size: 18px; font-weight: bold; color: #e74c3c; text-align: right; margin-top: 12px; }
        .btn { padding: 12px 30px; background: #3498db; color: white; border: none; cursor: pointer; border-radius: 6px; font-weight: bold; font-size: 16px; }
        .actions { margin-top: 40px; text-align: center; border-top: 1px solid #ddd; padding-top: 20px; }
        .link-btn { text-decoration: none; padding: 10px 20px; border: 1px solid #3498db; color: #3498db; border-radius: 5px; font-weight: bold; margin: 0 10px; }
        .link-btn:hover { background: #3498db; color: white; }
    </style>
</head>
<body>
    <div class="container">
        <div class="upload-section">
            <h2>📸 批量小票智能解析</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="file" name="receipts[]" multiple required style="margin-bottom:20px;"><br>
                <button type="submit" class="btn">开始扫描全部图片</button>
            </form>
        </div>

        <div class="results-grid">
            <?php if ($results): ?>
                <?php foreach ($results as $res): ?>
                    <div class="card">
                        <span class="file-name">📄 文件名: <?= htmlspecialchars($res['file']) ?></span>
                        <?php if (empty($res['items'])): ?>
                            <p style="color:#999; text-align:center;">未识别到有效商品</p>
                        <?php else: ?>
                            <?php foreach ($res['items'] as $it): ?>
                                <div class="row">
                                    <span><?= htmlspecialchars($it['name']) ?></span>
                                    <span>¥<?= number_format($it['price']) ?></span>
                                </div>
                            <?php endforeach; ?>
                            <div class="total">合计金额：¥<?= number_format($res['total']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($results): ?>
        <div class="actions">
            <a href="?action=csv" class="link-btn">📥 下载汇总 CSV 报表</a>
            <a href="?action=log" class="link-btn" style="color:#7f8c8d; border-color:#7f8c8d;">📜 查看解析日志 (ocr.log)</a>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
