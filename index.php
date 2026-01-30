<?php
/**
 * 🧾 小票解析系统 - 最终修复版 (解决重复金额与税费干扰)
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
        header('Content-Disposition: attachment; filename=receipt_export.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
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
        header('Content-Disposition: attachment; filename=ocr_debug.log');
        readfile($logFile);
        exit;
    }
}

// --- B. OCR解析逻辑 ---
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

        $rawTextLog = "=== OCR START: $fileName (" . date('H:i:s') . ") ===\n";
        $currentItems = [];
        $sumAmount = 0;
        $stopFlag = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            // 预处理：去掉干扰符号
            $cleanText = str_replace(['＊', '*', '◎', '√', ' '], '', $text);

            // 1. 区域判断：一旦出现“合计”或“结算相关词汇”，彻底停止商品抓取
            if (preg_match('/合計|合計|消費税|内消費税|対象|課税|支払|残高|マネー/u', $cleanText)) {
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            if ($stopFlag) continue;

            // 2. 金额抓取
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                
                // 获取当前行的名字部分（去掉价格符号后的文字）
                $namePart = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));

                // 【核心改进】：判定该行是否为“无效重复行”
                // 如果名字部分是空的，或者本身就是金额（如 ¥168），这通常是小票的重复展示行，直接跳过。
                if ($namePart === "" || preg_match('/^[¥￥\d,\s]+$/u', $namePart)) {
                    continue; 
                }

                // 3. 名字溯源：如果本行名字太短（比如只有个“轻”字），向上找真正的商品名
                if (mb_strlen($namePart) < 2 || preg_match('/%|軽/u', $namePart)) {
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prev = trim($lines[$j]['text']);
                        // 过滤掉只有1个字的行或标题词
                        if (mb_strlen($prev) >= 2 && !preg_match('/領|収|証|合|計|%|電話|店/u', $prev)) {
                            $namePart = $prev;
                            break;
                        }
                    }
                }

                // 最终清洗名字
                $finalName = str_replace(['(', ')', '（', '）', '.', '．', '軽', '轻'], '', $namePart);
                
                // 排除列表：确保这些词永远不会作为商品名存入
                if (mb_strlen($finalName) >= 2 && !preg_match('/Family|新宿|電話|登録|領収|対象|消費税|合計/u', $finalName)) {
                    $currentItems[] = ['name' => $finalName, 'price' => $price];
                    $sumAmount += $price;
                }
            }
        }
        
        // 记录日志供验证
        foreach ($lines as $ln) { $rawTextLog .= "RAW: " . $ln['text'] . "\n"; }
        foreach ($currentItems as $it) { $rawTextLog .= "  -> OK: {$it['name']} | {$it['price']}\n"; }
        file_put_contents($logFile, $rawTextLog . "=== END ===\n\n", FILE_APPEND);

        $results[] = ['file' => $fileName, 'items' => $currentItems, 'total' => $sumAmount];
    }
    file_put_contents($storageFile, json_encode($results));
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>小票解析 (过滤干扰项最终版)</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 650px; margin: auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .card { border: 1px solid #eee; background: #fdfdfd; padding: 15px; margin-top: 15px; border-radius: 8px; border-left: 5px solid #1a73e8; }
        .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #ddd; }
        .total { font-size: 20px; font-weight: bold; color: #d93025; text-align: right; margin-top: 10px; }
        .btn { width: 100%; padding: 12px; background: #1a73e8; color: white; border: none; cursor: pointer; border-radius: 6px; font-size: 16px; }
        .footer { display: flex; gap: 10px; margin-top: 25px; justify-content: center; }
        .link { text-decoration: none; padding: 8px 15px; border-radius: 4px; color: white; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <h2 style="text-align:center;">🧾 小票解析系统</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple required><br><br>
            <button type="submit" class="btn">解析选中的图片</button>
        </form>

        <?php if ($results): ?>
            <?php foreach ($results as $res): ?>
                <div class="card">
                    <div style="font-size: 11px; color: #999; margin-bottom: 8px;">文件: <?= htmlspecialchars($res['file']) ?></div>
                    <?php if (empty($res['items'])): ?>
                        <p style="color:#999">未检测到有效商品。</p>
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
            <?php if (file_exists($storageFile)): ?>
                <a href="?action=csv" class="link" style="background:#34a853;">📥 下载 CSV 数据</a>
            <?php endif; ?>
            <?php if (file_exists($logFile)): ?>
                <a href="?action=log" class="link" style="background:#5f6368;">📜 下载 ocr.log</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
