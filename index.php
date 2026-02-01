<?php
/**
 * 🧾 小票解析系统 - 过滤干扰项 + 日志功能版
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
        foreach ($data as $res) {
            foreach ($res['items'] as $it) fputcsv($output, [$res['file'], $it['name'], $it['price']]);
            fputcsv($output, [$res['file'], '合计', $res['total']]);
        }
        fclose($output); exit;
    }
    // 【找回日志下载】
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
        
        // 记录原始识别内容到日志
        file_put_contents($logFile, "\n[FILE]: $fileName\n", FILE_APPEND);

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            // 写入日志方便排查
            file_put_contents($logFile, "  RAW: $text\n", FILE_APPEND);

            $pureText = str_replace([' ', '　', '＊', '*', '◎', '√', '軽', '轻', '(', ')', '(', ')'], '', $text);

            // 1. 【红色框拦截】关键词过滤：一旦看到这些，说明进入税费/统计区
            if (preg_match('/合計|合计|合計|内消費税|消費税|対象|支払|残高|再発行/u', $pureText)) {
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            if ($stopFlag) continue;

            // 2. 匹配金额行
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                
                // 提取本行文字（去掉 ¥ 及其后的数字）
                $nameInLine = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                $cleanNameInLine = str_replace(['＊', '*', '轻', '軽', '◎', '(', ')', '.', '．', ' '], '', $nameInLine);

                // 【关键修复】：如果本行只有 ¥168 这种纯金额，或者名字被清洗后变成了空的/只有税标
                // 这种就是您红框里“重复的金额行”，必须跳过
                if (empty($cleanNameInLine) || preg_match('/^[¥￥\d,\s]+$/u', $cleanNameInLine)) {
                    // 如果本行是空的，尝试向上寻找商品名
                    $foundName = "";
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prev = trim($lines[$j]['text']);
                        $cleanPrev = str_replace(['＊', '*', '◎', ' ', '√', '軽', '轻'], '', $prev);
                        // 排除结算词、单字符干扰和纯数字
                        if (mb_strlen($cleanPrev) >= 2 && !preg_match('/領|収|証|合|计|計|%|店|电话|電話|¥|￥/u', $cleanPrev)) {
                            $foundName = $cleanPrev;
                            break;
                        }
                    }
                    $finalName = $foundName;
                } else {
                    $finalName = $cleanNameInLine;
                }

                // 3. 最终校验：确保名字不是空的且不是干扰项
                if (!empty($finalName) && !preg_match('/Family|新宿|電話|登録|領収|対象|消費税|合計|内訳/u', $finalName)) {
                    
                    // 去重检查：防止同一商品被解析两次
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
        $results[] = ['file' => $fileName, 'items' => $currentItems, 'total' => $sumAmount];
    }
    file_put_contents($storageFile, json_encode($results));
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
        .card { border-left: 5px solid #2ecc71; background: #fafafa; padding: 15px; margin-top: 15px; border-radius: 4px; }
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
        <h2 style="text-align:center;">🧾 小票解析 (去除干扰版)</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple required style="margin-bottom:15px;"><br>
            <button type="submit" class="btn">执行解析</button>
        </form>

        <?php if ($results): ?>
            <?php foreach ($results as $res): ?>
                <div class="card">
                    <small style="color:#999">📄 <?= htmlspecialchars($res['file']) ?></small>
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
            <a href="?action=log" class="link-btn" style="color:#7f8c8d; border-color:#7f8c8d;">📜 下载验证日志 (ocr.log)</a>
        </div>
    </div>
</body>
</html>
