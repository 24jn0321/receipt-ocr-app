<?php
/**
 * 🧾 小票解析系统 - 多图增强稳定版
 * 修复：3张及以上图片识别失败的问题
 */

// 1. 强制提升环境容错
@set_time_limit(600);           // 增加到10分钟，防止多图处理超时
@ini_set('memory_limit', '512M'); // 提高内存，防止大图解压崩溃

$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 

$results = [];
$totalAllAmount = 0; 
$storageFile = 'ocr_data.json';
$logFile = 'ocr.log';

// --- A. 功能接口 ---
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

// --- B. OCR 核心解析逻辑 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    file_put_contents($logFile, "--- NEW SCAN SESSION " . date('Y-m-d H:i:s') . " ---\n");
    
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        
        // 【关键修复 1】：增加动态延时。处理超过2张图时，每张间隔 2 秒，彻底避开 API 频率限制
        if ($key > 0) { sleep(2); } 

        $fileName = $_FILES['receipts']['name'][$key];
        $imgData = file_get_contents($tmpName);
        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
        
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        // 【关键修复 2】：增加网络超时设置
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); 
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch); 
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            file_put_contents($logFile, "  [ERROR] $fileName: $curlError\n", FILE_APPEND);
            continue;
        }

        $data = json_decode($response, true);
        
        // 【调试日志】：如果返回了错误消息，记录下来
        if (isset($data['error'])) {
            file_put_contents($logFile, "  [API ERROR] $fileName: " . ($data['error']['message'] ?? 'Unknown') . "\n", FILE_APPEND);
            continue;
        }

        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        $currentItems = [];
        $stopFlag = false;
        
        file_put_contents($logFile, "[PROCESSING]: $fileName (" . count($lines) . " lines found)\n", FILE_APPEND);

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            $pureText = str_replace([' ', '　', '＊', '*', '√', '軽', '轻', '(', ')', '8%', '10%'], '', $text);

            if (preg_match('/合計|合计|内消費税|消費税|対象|支払|残高|再発行/u', $pureText)) {
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            if ($stopFlag) continue;

            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                $nameInLine = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                $cleanNameInLine = str_replace(['＊', '*', '轻', '軽', '(', ')', '.', '．', ' '], '', $nameInLine);

                if (mb_strlen($cleanNameInLine) < 2 || preg_match('/^[¥￥\d,\s]+$/u', $cleanNameInLine)) {
                    $foundName = "";
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prev = trim($lines[$j]['text']);
                        $cleanPrev = str_replace(['＊', '*', ' ', '√', '軽', '轻'], '', $prev);
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
                    $isDuplicate = false;
                    foreach ($currentItems as $item) {
                        if ($item['name'] === $finalName && $item['price'] === $price) { $isDuplicate = true; break; }
                    }
                    if (!$isDuplicate) {
                        $currentItems[] = ['name' => $finalName, 'price' => $price];
                        file_put_contents($logFile, "    -> ADDED: $finalName ($price)\n", FILE_APPEND);
                    }
                }
            }
        }
        $results[] = ['file' => $fileName, 'items' => $currentItems];
    }
    file_put_contents($storageFile, json_encode($results, JSON_UNESCAPED_UNICODE));
} else if (file_exists($storageFile)) {
    $results = json_decode(file_get_contents($storageFile), true);
}

if ($results) {
    foreach ($results as $res) {
        foreach ($res['items'] as $it) { $totalAllAmount += $it['price']; }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>小票解析汇总系统</title>
    <style>
        body { font-family: 'PingFang SC', sans-serif; background: #f4f7f6; padding: 10px; }
        .box { max-width: 650px; margin: auto; background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .card { border-left: 5px solid #2ecc71; background: #fafafa; padding: 12px; margin-top: 15px; border-radius: 4px; border-bottom: 1px solid #ddd; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #eee; font-size: 14px; }
        .grand-total-box { margin-top: 25px; padding: 20px; background: #fff5f5; border: 2px solid #e74c3c; border-radius: 10px; text-align: right; }
        .grand-total-amount { font-size: 28px; font-weight: bold; color: #e74c3c; }
        .btn { width: 100%; padding: 14px; background: #3498db; color: white; border: none; cursor: pointer; border-radius: 6px; font-weight: bold; font-size: 16px; }
        .btn:disabled { background: #95a5a6; cursor: not-allowed; }
        .actions { margin-top: 30px; display: flex; justify-content: center; gap: 10px; }
        .link-btn { text-decoration: none; font-size: 13px; color: #3498db; border: 1px solid #3498db; padding: 8px 12px; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">🧾 小票全汇总解析</h2>
        
        <form id="uploadForm" method="post" enctype="multipart/form-data">
            <input type="file" id="fileInput" name="receipts[]" multiple required style="margin-bottom:15px; width:100%;"><br>
            <button type="submit" id="submitBtn" class="btn">开始解析 (支持多图)</button>
            <p id="status" style="display:none; color:#3498db; font-size:14px; margin-top:10px; text-align:center;">📸 正在处理，多张图片需要较长时间，请勿刷新...</p>
        </form>

        <script>
        document.getElementById('uploadForm').onsubmit = async function(e) {
            const btn = document.getElementById('submitBtn');
            const status = document.getElementById('status');
            const files = document.getElementById('fileInput').files;
            
            if (files.length > 0) {
                btn.disabled = true;
                status.style.display = "block";
                btn.innerText = `正在处理 (0/${files.length})...`;
                
                // 注意：这里我们让表单正常提交，或者你可以继续使用之前的异步 fetch 逻辑
                // 为了稳定起见，如果是多图，建议直接传统的表单提交
            }
        };
        </script>

        <?php if ($results): ?>
            <div style="margin-top:20px;">
                <?php foreach ($results as $res): ?>
                    <div class="card">
                        <small style="color:#999; font-size:11px;">📄 <?= htmlspecialchars($res['file']) ?></small>
                        <?php if(empty($res['items'])): ?>
                            <div style="color:#e74c3c; font-size:12px;">此张图片解析失败或未发现商品</div>
                        <?php else: ?>
                            <?php foreach ($res['items'] as $it): ?>
                                <div class="row">
                                    <span><?= htmlspecialchars($it['name']) ?></span>
                                    <span>¥<?= number_format($it['price']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div class="grand-total-box">
                    <div style="color:#666; font-size:14px;">已选小票总计额</div>
                    <div class="grand-total-amount">¥<?= number_format($totalAllAmount) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="actions">
            <a href="?action=csv" class="link-btn">📥 下载 CSV 结果</a>
            <a href="?action=log" class="link-btn" style="color:#7f8c8d; border-color:#7f8c8d;">📜 查看解析日志</a>
        </div>
    </div>
</body>
</html>
