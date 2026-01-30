<?php
/**
 * 🧾 小票解析系统 - 最终整合完美版
 * 解决：不漏掉分行商品（如アポロチョー），不重复抓取垃圾行（如 ¥168 重复行）
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
        echo "\xEF\xBB\xBF"; $output = fopen('php://output', 'w');
        fputcsv($output, ['文件名', '商品名', '金额']);
        $data = json_decode(file_get_contents($storageFile), true);
        foreach ($data as $res) {
            foreach ($res['items'] as $it) fputcsv($output, [$res['file'], $it['name'], $it['price']]);
            fputcsv($output, [$res['file'], '合计', $res['total']]);
        }
        fclose($output); exit;
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
        $response = curl_exec($ch); curl_close($ch);

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];

        $currentItems = [];
        $sumAmount = 0;
        $stopFlag = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            $noSpace = str_replace([' ', '　', '＊', '*', '◎', '√', '軽', '轻'], '', $text);

            // 1. 区域防御：看到结算词汇，彻底停止
            if (preg_match('/合計|合计|消費税|支払|支付|残高|対象|番号/u', $noSpace)) {
                if (!empty($currentItems)) $stopFlag = true;
                continue;
            }
            if ($stopFlag) continue;

            // 2. 识别金额行
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                
                // 获取当前行 ¥ 之前的文字作为名字
                $name = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                
                // 【关键修复】：如果这行名字太短（或者是纯金额重复行），则向上找一行
                if (mb_strlen($name) < 2) {
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prevText = trim($lines[$j]['text']);
                        // 向上找的名字不能是纯数字，也不能包含结算关键词
                        if (mb_strlen($prevText) >= 2 && !preg_match('/^[¥￥\d,\s]+$/u', $prevText) && !preg_match('/領|収|証|合|计|計|%|店/u', $prevText)) {
                            $name = $prevText;
                            break;
                        }
                    }
                }

                // 清洗名字
                $cleanName = str_replace(['＊', '*', '轻', '軽', '◎', '(', '（', ')', '）', '.', '．', ' '], '', $name);
                
                // 3. 最终关卡：排除杂质和去重
                if (mb_strlen($cleanName) >= 2 && !preg_match('/Family|新宿|电话|登録|領収|対象|消費税|合计/u', $cleanName)) {
                    
                    // 【去重机制】：如果当前抓到的和上一个是同一个商品（名字+价格都一样），就跳过
                    $isDuplicate = false;
                    if (!empty($currentItems)) {
                        $last = end($currentItems);
                        if ($last['name'] === $cleanName && $last['price'] === $price) {
                            $isDuplicate = true;
                        }
                    }

                    if (!$isDuplicate) {
                        $currentItems[] = ['name' => $cleanName, 'price' => $price];
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
    <title>小票解析最终整合版</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 20px; }
        .box { max-width: 600px; margin: auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .card { border-left: 5px solid #1a73e8; background: #fafafa; padding: 15px; margin-top: 15px; border-radius: 8px; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #ddd; }
        .total { font-size: 22px; font-weight: bold; color: #d32f2f; text-align: right; margin-top: 10px; }
        .btn { width: 100%; padding: 12px; background: #1a73e8; color: white; border: none; cursor: pointer; border-radius: 6px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">🧾 小票解析系统 (整合修复版)</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple required><br><br>
            <button type="submit" class="btn">解析全部图片</button>
        </form>

        <?php if ($results): ?>
            <?php foreach ($results as $res): ?>
                <div class="card">
                    <small style="color:#999"><?= htmlspecialchars($res['file']) ?></small>
                    <?php if (empty($res['items'])): ?>
                        <p style="color:#999">未发现商品。</p>
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
            <p style="text-align:center; margin-top:20px;"><a href="?action=csv" style="text-decoration:none; color:#1a73e8;">📥 下载 CSV 报表</a></p>
        <?php endif; ?>
    </div>
</body>
</html>
