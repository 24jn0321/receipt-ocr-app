<?php
/**
 * 🧾 小票解析系统 - 保留 ◎ 符号版
 */

@set_time_limit(300);
@ini_set('memory_limit', '256M');

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
            $grandTotal = 0;
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

// --- B. OCR 核心解析逻辑 ---
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
        $response = curl_exec($ch); 
        curl_close($ch);

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];

        $currentItems = [];
        $stopFlag = false;
        
        file_put_contents($logFile, "\n[FILE]: $fileName\n", FILE_APPEND);

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            file_put_contents($logFile, "  RAW: $text\n", FILE_APPEND);

            // 1. 过滤逻辑：注意这里去掉了对 ◎ 的过滤
            $pureText = str_replace([' ', '　', '＊', '*', '√', '軽', '轻', '(', ')', '8%', '10%'], '', $text);

            if (preg_match('/合計|合计|内消費税|消費税|対象|支払|残高|再発行/u', $pureText)) {
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            if ($stopFlag) continue;

            // 2. 匹配金额行
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                
                // 提取本行名字，【保留 ◎】
                $nameInLine = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                // 这里移除了过滤列表中的 ◎
                $cleanNameInLine = str_replace(['＊', '*', '轻', '軽', '(', ')', '.', '．', ' '], '', $nameInLine);

                // 如果本行没抓到有效名字，向上回溯
                if (mb_strlen($cleanNameInLine) < 2 || preg_match('/^[¥￥\d,\s]+$/u', $cleanNameInLine)) {
                    $foundName = "";
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prev = trim($lines[$j]['text']);
                        // 这里也移除了对 ◎ 的过滤
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

                // 3. 最终记录
                if (!empty($finalName) && !preg_match('/Family|新宿|電話|登録|領収|対象|消費税|合計|内訳/u', $finalName)) {
                    $isDuplicate = false;
                    foreach ($currentItems as $item) {
                        if ($item['name'] === $finalName && $item['price'] === $price) {
                            $isDuplicate = true; break;
                        }
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
    <title>小票解析汇总系统</title>
    <style>
        body { font-family: 'PingFang SC', sans-serif; background: #f4f7f6; padding: 20px; }
        .box { max-width: 650px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .card { border-left: 5px solid #2ecc71; background: #fafafa; padding: 15px; margin-top: 15px; border-radius: 4px; border-bottom: 1px solid #ddd; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #eee; font-size: 14px; }
        .grand-total-box { margin-top: 25px; padding: 20px; background: #fff5f5; border: 2px solid #e74c3c; border-radius: 10px; text-align: right; }
        .grand-total-amount { font-size: 28px; font-weight: bold; color: #e74c3c; }
        .btn { width: 100%; padding: 12px; background: #3498db; color: white; border: none; cursor: pointer; border-radius: 6px; font-weight: bold; }
        .btn:disabled { background: #95a5a6; }
        .actions { margin-top: 30px; display: flex; justify-content: center; gap: 20px; border-top: 1px solid #eee; padding-top: 20px; }
        .link-btn { text-decoration: none; font-size: 14px; font-weight: bold; color: #3498db; border: 1px solid #3498db; padding: 8px 15px; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">🧾 小票解析 (保留◎增强版)</h2>
        
        <form id="uploadForm" method="post" enctype="multipart/form-data">
            <input type="file" id="fileInput" name="receipts[]" multiple required style="margin-bottom:15px;"><br>
            <button type="submit" id="submitBtn" class="btn">执行解析</button>
            <p id="status" style="display:none; color:#3498db; font-size:14px; margin-top:10px; text-align:center;">📸 正在处理图片，请稍候...</p>
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
                btn.innerText = `处理中 ${i+1}/${files.length}`;
                const compressedFile = await compressImage(files[i]);
                formData.append('receipts[]', compressedFile, files[i].name);
            }

            fetch('', { method: 'POST', body: formData })
            .then(r => r.text())
            .then(html => {
                const doc = new DOMParser().parseFromString(html, 'text/html');
                document.body.innerHTML = doc.body.innerHTML;
            })
            .catch(err => {
                alert("解析失败");
                btn.disabled = false;
                btn.innerText = "执行解析";
            });
        };

        function compressImage(file) {
            return new Promise((resolve) => {
                const reader = new FileReader();
                reader.readAsDataURL(file);
                reader.onload = (e) => {
                    const img = new Image();
                    img.src = e.target.result;
                    img.onload = () => {
                        const canvas = document.createElement('canvas');
                        let w = img.width; if (w > 1200) w = 1200;
                        canvas.width = w; canvas.height = img.height * (w / img.width);
                        canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                        canvas.toBlob(b => resolve(new File([b], file.name, {type:'image/jpeg'})), 'image/jpeg', 0.8);
                    };
                };
            });
        }
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
                    <div style="color:#666; font-size:14px;">全汇总总计</div>
                    <div class="grand-total-amount">¥<?= number_format($totalAllAmount) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="actions">
            <a href="?action=csv" class="link-btn">📥 下载 CSV</a>
            <a href="?action=log" class="link-btn" style="color:#7f8c8d; border-color:#7f8c8d;">📜 查看日志</a>
        </div>
    </div>
</body>
</html>
