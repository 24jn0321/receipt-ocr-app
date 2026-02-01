<?php
/**
 * 🧾 小票解析系统 - 终极去重汇总版
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

                // 向上回溯找商品名
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

                // --- 重点：加入严格去重逻辑 ---
                if (!empty($finalName) && !preg_match('/Family|新宿|電話|登録|領収|対象|消費税|合計|内訳/u', $finalName)) {
                    $isDuplicate = false;
                    foreach ($currentItems as $item) {
                        // 如果名字高度相似且金额一样，就判定为重复
                        if ($item['name'] === $finalName && $item['price'] === $price) {
                            $isDuplicate = true;
                            break;
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
    <title>小票解析系统</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f6; padding: 20px; }
        .box { max-width: 650px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .card { border-left: 5px solid #2ecc71; background: #fafafa; padding: 10px 15px; margin-top: 10px; border-radius: 4px; }
        .row { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px dashed #eee; font-size: 14px; }
        .grand-total-box { margin-top: 25px; padding: 20px; background: #fff5f5; border: 2px solid #e74c3c; border-radius: 10px; text-align: right; }
        .grand-total-amount { font-size: 28px; font-weight: bold; color: #e74c3c; }
        .btn { width: 100%; padding: 12px; background: #3498db; color: white; border: none; cursor: pointer; border-radius: 6px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">🧾 小票解析 (全汇总版)</h2>
        <form id="uploadForm" method="post" enctype="multipart/form-data">
            <input type="file" id="fileInput" name="receipts[]" multiple required style="margin-bottom:15px;"><br>
            <button type="submit" id="submitBtn" class="btn">执行解析</button>
        </form>

        <script>
        document.getElementById('uploadForm').onsubmit = async function(e) {
            e.preventDefault();
            const btn = document.getElementById('submitBtn');
            btn.disabled = true; btn.innerText = "正在解析...";
            const formData = new FormData();
            const files = document.getElementById('fileInput').files;
            for (let i = 0; i < files.length; i++) {
                const compressed = await new Promise(resolve => {
                    const reader = new FileReader();
                    reader.readAsDataURL(files[i]);
                    reader.onload = (ev) => {
                        const img = new Image(); img.src = ev.target.result;
                        img.onload = () => {
                            const canvas = document.createElement('canvas');
                            let w = img.width; if (w > 1000) w = 1000;
                            canvas.width = w; canvas.height = img.height * (w / img.width);
                            canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                            canvas.toBlob(b => resolve(new File([b], files[i].name, {type:'image/jpeg'})), 'image/jpeg', 0.8);
                        };
                    };
                });
                formData.append('receipts[]', compressed, files[i].name);
            }
            fetch('', { method: 'POST', body: formData }).then(r => r.text()).then(h => {
                document.open(); document.write(h); document.close();
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
                    <div style="color:#666; font-size:14px;">全汇总总计</div>
                    <div class="grand-total-amount">¥<?= number_format($totalAllAmount) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <div style="margin-top:20px; display:flex; gap:10px;">
            <a href="?action=csv" style="flex:1; text-align:center; padding:10px; border:1px solid #3498db; color:#3498db; text-decoration:none; border-radius:5px;">下载 CSV</a>
            <a href="?action=log" style="flex:1; text-align:center; padding:10px; border:1px solid #7f8c8d; color:#7f8c8d; text-decoration:none; border-radius:5px;">查看日志</a>
        </div>
    </div>
</body>
</html>
