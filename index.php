<?php
/**
 * 🧾 小票解析系统 - 最终汇总版
 * 修改点：
 * 1. 移除了单张小票的“小计”，改为页面底部的“全汇总合计”。
 * 2. 优化了结果展示逻辑。
 */

@set_time_limit(300);
@ini_set('memory_limit', '256M');

$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 

$results = [];
$totalAllAmount = 0; // 新增：全局总计变量
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
        $grandTotal = 0;
        if ($data) {
            foreach ($data as $res) {
                foreach ($res['items'] as $it) {
                    fputcsv($output, [$res['file'], $it['name'], $it['price']]);
                    $grandTotal += $it['price'];
                }
            }
            fputcsv($output, ['---', '全汇总总计', $grandTotal]);
        }
        fclose($output); exit;
    }
    if ($action == 'log' && file_exists($logFile)) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename=ocr_debug.log');
        readfile($logFile); exit;
    }
}

// --- B. OCR 核心逻辑 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    file_put_contents($logFile, "--- OCR DEBUG LOG " . date('Y-m-d H:i:s') . " ---\n");
    
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        if ($key > 0) { sleep(1); } 

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
                    $currentItems[] = ['name' => $finalName, 'price' => $price];
                }
            }
        }
        $results[] = ['file' => $fileName, 'items' => $currentItems];
    }
    file_put_contents($storageFile, json_encode($results, JSON_UNESCAPED_UNICODE));
} else if (file_exists($storageFile)) {
    // 页面刷新时从文件读取旧数据以计算总计
    $results = json_decode(file_get_contents($storageFile), true);
}

// 计算总计
if ($results) {
    foreach ($results as $res) {
        foreach ($res['items'] as $it) {
            $totalAllAmount += $it['price'];
        }
    }
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
        .card { border-left: 5px solid #2ecc71; background: #fafafa; padding: 10px 15px; margin-top: 10px; border-radius: 4px; }
        .row { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px dashed #eee; font-size: 14px; }
        .grand-total-box { margin-top: 25px; padding: 20px; background: #fff5f5; border: 2px solid #e74c3c; border-radius: 10px; text-align: right; }
        .grand-total-label { font-size: 16px; color: #666; }
        .grand-total-amount { font-size: 28px; font-weight: bold; color: #e74c3c; }
        .btn { width: 100%; padding: 12px; background: #3498db; color: white; border: none; cursor: pointer; border-radius: 6px; font-weight: bold; }
        .actions { margin-top: 30px; display: flex; justify-content: center; gap: 20px; }
        .link-btn { text-decoration: none; font-size: 14px; font-weight: bold; color: #3498db; border: 1px solid #3498db; padding: 8px 15px; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">🧾 小票解析 (全汇总增强版)</h2>
        
        <form id="uploadForm" method="post" enctype="multipart/form-data">
            <input type="file" id="fileInput" name="receipts[]" multiple required style="margin-bottom:15px;"><br>
            <button type="submit" id="submitBtn" class="btn">执行解析</button>
            <p id="status" style="display:none; color:#3498db; font-size:14px; margin-top:10px; text-align:center;">正在处理多张小票，请稍候...</p>
        </form>

        <script>
        document.getElementById('uploadForm').onsubmit = async function(e) {
            e.preventDefault();
            const btn = document.getElementById('submitBtn');
            const status = document.getElementById('status');
            const files = document.getElementById('fileInput').files;
            if (files.length === 0) return;
            btn.disabled = true;
            status.style.display = "block";
            const formData = new FormData();
            for (let i = 0; i < files.length; i++) {
                const reader = new FileReader();
                const compressedFile = await new Promise(resolve => {
                    reader.readAsDataURL(files[i]);
                    reader.onload = (e) => {
                        const img = new Image();
                        img.src = e.target.result;
                        img.onload = () => {
                            const canvas = document.createElement('canvas');
                            let w = img.width, h = img.height;
                            if (w > 1200) { h *= 1200 / w; w = 1200; }
                            canvas.width = w; canvas.height = h;
                            canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                            canvas.toBlob(b => resolve(new File([b], files[i].name, {type:'image/jpeg'})), 'image/jpeg', 0.8);
                        };
                    };
                });
                formData.append('receipts[]', compressedFile, files[i].name);
            }
            fetch('', { method: 'POST', body: formData })
            .then(r => r.text())
            .then(html => {
                document.open(); document.write(html); document.close();
            });
        };
        </script>

        <?php if ($results): ?>
            <div style="margin-top:20px;">
                <?php foreach ($results as $res): ?>
                    <div class="card">
                        <small style="color:#999; font-size:11px;">📄 <?= htmlspecialchars($res['file']) ?></small>
                        <?php foreach ($res['items'] as $it): ?>
                            <div class="row">
                                <span><?= htmlspecialchars($it['name']) ?></span>
                                <span>¥<?= number_format($it['price']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <div class="grand-total-box">
                    <span class="grand-total-label">全汇总总计</span><br>
                    <span class="grand-total-amount">¥<?= number_format($totalAllAmount) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <div class="actions">
            <a href="?action=csv" class="link-btn">📥 下载汇总报表</a>
            <a href="?action=log" class="link-btn" style="color:#7f8c8d; border-color:#7f8c8d;">📜 查看日志</a>
        </div>
    </div>
</body>
</html>
