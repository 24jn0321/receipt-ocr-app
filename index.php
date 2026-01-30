<?php
/**
 * 🧾 小票全兼容解析系统 - 完整修复版
 */

// 1. 配置信息 (请在此处填入你的 Azure Key)
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 

$results = [];
$storageFile = 'ocr_data.json';

// --- A. CSV 下载逻辑 ---
if (isset($_GET['action']) && $_GET['action'] == 'csv') {
    if (file_exists($storageFile)) {
        $sessionData = json_decode(file_get_contents($storageFile), true);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt.csv');
        echo "\xEF\xBB\xBF"; // 防止 Excel 打开乱码
        $output = fopen('php://output', 'w');
        fputcsv($output, ['文件名', '项目', '金额']);
        foreach ($sessionData as $res) {
            foreach ($res['items'] as $it) {
                fputcsv($output, [$res['file'], $it['name'], $it['price']]);
            }
            fputcsv($output, [$res['file'], '合计', $res['total']]);
        }
        fclose($output); 
        exit;
    }
}

// --- B. 图片上传与 OCR 解析逻辑 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        
        $imgData = file_get_contents($tmpName);
        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
        
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/octet-stream', 
            'Ocp-Apim-Subscription-Key: ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 兼容本地开发环境
        
        $response = curl_exec($ch);
        $errorMsg = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            die("CURL 错误: " . $errorMsg);
        }

        $data = json_decode($response, true);
        
        // 错误提示：如果 API 返回了报错（比如 Key 错了）
        if (isset($data['error'])) {
            die("API 错误: " . ($data['error']['message'] ?? '未知错误'));
        }

        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        $currentItems = [];
        $sumAmount = 0;
        $stopFlag = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            $noSpace = str_replace([' ', '　', '=', '-', '_', '＊', '*', '◎'], '', $text);

            // 1. 核心防御：结算区过滤
            if (preg_match('/合计|合計|支付|支払|残高|番号|カード|対象|消費税/u', $noSpace)) {
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            if ($stopFlag) continue;

            // 2. 识别价格行
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                $name = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                
                // 如果本行没抓到名字，取上一行
                if (mb_strlen($name) < 2 && $i > 0) {
                    $name = trim($lines[$i-1]['text']);
                }

                $cleanName = str_replace(['＊', '*', '轻', '軽', '◎', '(', '（', ')', '）', '.', '．', '…'], '', $name);
                
                if (mb_strlen($cleanName) >= 2 && !preg_match('/Family|新宿|电话|登録|領収/u', $cleanName)) {
                    $currentItems[] = ['name' => $cleanName, 'price' => $price];
                    $sumAmount += $price;
                }
            }
        }
        $results[] = [
            'file' => $_FILES['receipts']['name'][$key], 
            'items' => $currentItems, 
            'total' => $sumAmount
        ];
    }
    // 存档供 CSV 下载
    file_put_contents($storageFile, json_encode($results));
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>小票全兼容解析系统</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; line-height: 1.6; }
        .box { max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .card { border-left: 5px solid #00a95c; background: #fdfdfd; padding: 15px; margin-top: 15px; border-radius: 4px; }
        .row { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px dashed #ddd; }
        .total { font-size: 20px; font-weight: bold; color: #d32f2f; text-align: right; margin-top: 10px; }
        .btn { width: 100%; padding: 12px; background: #0078d4; color: white; border: none; cursor: pointer; border-radius: 4px; font-size: 16px; }
        .btn:hover { background: #005a9e; }
        input[type="file"] { margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">🧾 小票解析系统</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple required>
            <button type="submit" class="btn">开始解析图片</button>
        </form>

        <?php if ($results): ?>
            <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">
            <?php foreach ($results as $res): ?>
                <div class="card">
                    <div style="font-size: 12px; color:#999; margin-bottom: 5px;">文件：<?= htmlspecialchars($res['file']) ?></div>
                    <?php if(empty($res['items'])): ?>
                        <p style="color:#999;">未识别到有效商品项目。</p>
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
            
            <div style="text-align:center; margin-top: 20px;">
                <a href="?action=csv" style="color: #0078d4; text-decoration: none; font-weight: bold;">📥 下载 CSV 报表</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
