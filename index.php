<?php
/**
 * 🧾 小票解析系统 - 整合修复版
 * 目标：不漏掉跨行商品（如アポロチョー），不重复抓取单行商品（如チョコバター）
 */

$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 

$results = [];
$storageFile = 'ocr_data.json';
$logFile = 'ocr.log';

// --- A. 数据导出逻辑 ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action == 'csv' && file_exists($storageFile)) {
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
}

// --- B. 核心 OCR 解析逻辑 ---
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
        $response = curl_exec($ch); curl_close($ch);

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];

        $currentItems = [];
        $sumAmount = 0;
        $stopFlag = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            // 清洗掉无意义的符号
            $pureText = str_replace([' ', '　', '＊', '*', '◎', '√', '軽', '轻', '(', ')'], '', $text);

            // 1. 区域封锁：看到结算关键词，立刻停止抓取
            if (preg_match('/合計|合计|消費税|支払|残高|対象商品|番号|登録/u', $pureText)) {
                if (!empty($currentItems)) $stopFlag = true;
                continue;
            }
            if ($stopFlag) continue;

            // 2. 匹配带 ¥ 的金额行
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                
                // 【核心改进】：提取本行文字（去掉 ¥ 及其后面的数字）
                $nameInLine = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                $cleanNameInLine = str_replace(['＊', '*', '轻', '軽', '◎', '(', ')', '.', '．', ' '], '', $nameInLine);

                $finalName = "";

                // 判断：如果本行已经有超过2个字的名字，就优先用本行的（解决第一张票问题）
                if (mb_strlen($cleanNameInLine) >= 2 && !preg_match('/^[¥￥\d,\s]+$/u', $cleanNameInLine)) {
                    $finalName = $cleanNameInLine;
                } else {
                    // 如果本行没名字（解决第三张票问题），才向上找
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prev = trim($lines[$j]['text']);
                        $cleanPrev = str_replace(['＊', '*', '◎', ' ', '√'], '', $prev);
                        // 排除结算词和太短的干扰词
                        if (mb_strlen($cleanPrev) >= 2 && !preg_match('/領|収|証|合|計|%|店|番号/u', $cleanPrev)) {
                            $finalName = $cleanPrev;
                            break;
                        }
                    }
                }

                // 3. 最终过滤与【去重】
                if (mb_strlen($finalName) >= 2 && !preg_match('/Family|新宿|電話|登録|領収|対象|消費税|合計/u', $finalName)) {
                    
                    // 检查是否和刚刚抓到的商品完全一样（防止重复行）
                    $isDuplicate = false;
                    if (!empty($currentItems)) {
                        $lastItem = end($currentItems);
                        if ($lastItem['name'] === $finalName && $lastItem['price'] === $price) {
                            $isDuplicate = true;
                        }
                    }

                    if (!$isDuplicate) {
                        $currentItems[] = ['name' => $finalName, 'price' => $price];
                        $sumAmount += $price;
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
        body { font-family: -apple-system, sans-serif; background: #f5f7fa; padding: 20px; color: #333; }
        .box { max-width: 600px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.05); }
        .card { border-left: 5px solid #34a853; background: #f9f9f9; padding: 15px; margin-top: 15px; border-radius: 4px; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #ddd; }
        .total { font-size: 22px; font-weight: bold; color: #d93025; text-align: right; margin-top: 10px; }
        .btn { width: 100%; padding: 12px; background: #1a73e8; color: white; border: none; cursor: pointer; border-radius: 6px; font-size: 16px; font-weight: bold; }
        .footer { margin-top: 25px; text-align: center; font-size: 14px; }
        .footer a { color: #1a73e8; text-decoration: none; margin: 0 10px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">🧾 小票解析 (全场景兼容版)</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple required style="margin-bottom:20px;"><br>
            <button type="submit" class="btn">开始智能解析</button>
        </form>

        <?php if ($results): ?>
            <?php foreach ($results as $res): ?>
                <div class="card">
                    <div style="font-size:12px; color:#888; margin-bottom:8px;">📄 <?= htmlspecialchars($res['file']) ?></div>
                    <?php if (empty($res['items'])): ?>
                        <p style="color:#999;">未识别到有效商品信息。</p>
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
            <a href="?action=csv">📥 下载 CSV 报表</a>
        </div>
    </div>
</body>
</html>
