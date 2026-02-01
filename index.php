<?php
/**
 * 🧾 小票解析系统 - 多图总汇总版
 */

$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 

$results = [];
$allTotal = 0; // 【新增】用于统计所有图片的总和
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
            fputcsv($output, [$res['file'], '小计', $res['total']]);
        }
        fclose($output); exit;
    }
}

// --- B. OCR 解析逻辑 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    file_put_contents($logFile, "--- OCR LOG " . date('Y-m-d H:i:s') . " ---\n");
    
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
            $pureText = str_replace([' ', '　', '＊', '*', '◎', '√', '軽', '轻', '(', ')'], '', $text);

            if (preg_match('/合計|合计|内消費税|消費税|対象|支払|残高/u', $pureText)) {
                if (!empty($currentItems)) $stopFlag = true; continue; 
            }
            if ($stopFlag) continue;

            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                $nameInLine = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                $cleanNameInLine = str_replace(['＊', '*', '轻', '軽', '◎', '(', ')', ' '], '', $nameInLine);

                if (empty($cleanNameInLine) || preg_match('/^[¥￥\d,\s]+$/u', $cleanNameInLine)) {
                    $foundName = "未知项目";
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
                    $currentItems[] = ['name' => $finalName, 'price' => $price];
                    $sumAmount += $price;
                }
            }
        }
        $results[] = ['file' => $fileName, 'items' => $currentItems, 'total' => $sumAmount];
        $allTotal += $sumAmount; // 【关键】累加总金额
    }
    file_put_contents($storageFile, json_encode($results));
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>小票汇总解析</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; color: #333; }
        .box { max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .card { border-bottom: 2px solid #eee; padding: 10px 0; margin-bottom: 10px; }
        .row { display: flex; justify-content: space-between; font-size: 14px; padding: 4px 0; }
        .subtotal { text-align: right; color: #666; font-size: 13px; }
        .grand-total-box { margin-top: 20px; padding: 15px; background: #fff3f3; border: 2px solid #ffcccc; border-radius: 8px; text-align: center; }
        .grand-total-box h3 { margin: 0; color: #e74c3c; font-size: 24px; }
        .btn { width: 100%; padding: 10px; background: #3498db; color: white; border: none; cursor: pointer; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">🧾 小票解析汇总</h2>
        
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple required style="margin-bottom:10px;">
            <button type="submit" class="btn">上传并解析多张图片</button>
        </form>

        <?php if ($results): ?>
            <div style="margin-top:20px;">
                <?php foreach ($results as $res): ?>
                    <div class="card">
                        <small style="color:#999">图片: <?= htmlspecialchars($res['file']) ?></small>
                        <?php foreach ($res['items'] as $it): ?>
                            <div class="row">
                                <span><?= htmlspecialchars($it['name']) ?></span>
                                <span>¥<?= number_format($it['price']) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <div class="subtotal">单图小计: ¥<?= number_format($res['total']) ?></div>
                    </div>
                <?php endforeach; ?>

                <div class="grand-total-box">
                    <p style="margin-bottom:5px; color:#666;">--- 所有图片总计 ---</p>
                    <h3>总金额：¥<?= number_format($allTotal) ?></h3>
                </div>
                
                <p style="text-align:center; margin-top:15px;">
                    <a href="?action=csv" style="color:#3498db; text-decoration:none;">📥 下载 CSV 报表</a>
                </p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
