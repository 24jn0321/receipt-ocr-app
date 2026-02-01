<?php
/**
 * 🧾 小票解析系统 - 视觉增强版
 * 保持原有 OCR 逻辑，全面升级前端设计
 */

@set_time_limit(600);
@ini_set('memory_limit', '512M');

$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 

$results = [];
$totalAllAmount = 0; 
$storageFile = 'ocr_data.json';
$logFile = 'ocr.log';

// --- 接口逻辑 (保留) ---
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
                foreach ($res['items'] as $it) {
                    fputcsv($output, [$res['file'], $it['name'], $it['price']]);
                }
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

// --- OCR 核心逻辑 (保留) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    file_put_contents($logFile, "--- NEW SCAN SESSION " . date('Y-m-d H:i:s') . " ---\n");
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $response = curl_exec($ch); 
        curl_close($ch);
        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        $currentItems = [];
        $stopFlag = false;
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
                        if ($item['name'] === $finalName && $item['price'] === $price) {
                            $isDuplicate = true; break;
                        }
                    }
                    if (!$isDuplicate) {
                        $currentItems[] = ['name' => $finalName, 'price' => $price];
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
    <title>小票智能解析系统</title>
    <style>
        :root {
            --primary: #2563eb;
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --text-sub: #64748b;
            --accent: #f43f5e;
        }
        body { 
            font-family: 'PingFang SC', 'Microsoft YaHei', sans-serif; 
            background: var(--bg); 
            color: var(--text-main);
            margin: 0; padding: 20px; 
            line-height: 1.6;
        }
        .container { max-width: 600px; margin: 0 auto; }
        
        /* 头部样式 */
        header { text-align: center; margin-bottom: 30px; }
        header h1 { font-size: 24px; margin-bottom: 5px; color: var(--primary); }
        header p { font-size: 14px; color: var(--text-sub); }

        /* 上传区域 */
        .upload-box { 
            background: var(--card-bg); 
            padding: 30px; 
            border-radius: 16px; 
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            text-align: center;
            border: 2px dashed #e2e8f0;
            transition: all 0.3s;
        }
        .upload-box:hover { border-color: var(--primary); }
        
        .file-input-wrapper { margin-bottom: 20px; }
        input[type="file"] { font-size: 14px; color: var(--text-sub); }

        .btn-primary { 
            width: 100%; padding: 12px; 
            background: var(--primary); color: white; 
            border: none; cursor: pointer; border-radius: 8px; 
            font-weight: 600; font-size: 16px; 
            transition: opacity 0.2s;
        }
        .btn-primary:hover { opacity: 0.9; }
        .btn-primary:disabled { background: #cbd5e1; cursor: not-allowed; }

        /* 状态提示 */
        #status { 
            background: #eff6ff; color: var(--primary); 
            padding: 10px; border-radius: 8px; 
            margin-top: 15px; font-size: 13px; display: none; 
        }

        /* 结果列表 */
        .result-card { 
            background: var(--card-bg); 
            margin-top: 20px; border-radius: 12px; 
            overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .card-header { 
            background: #f1f5f9; padding: 10px 15px; 
            font-size: 12px; color: var(--text-sub);
            border-bottom: 1px solid #e2e8f0;
            display: flex; justify-content: space-between;
        }
        .item-row { 
            display: flex; justify-content: space-between; 
            padding: 12px 15px; border-bottom: 1px solid #f8fafc;
            font-size: 15px;
        }
        .item-row:last-child { border-bottom: none; }
        .price { font-weight: 600; color: var(--text-main); }

        /* 合计区域 - 重点美化 */
        .total-section { 
            margin-top: 30px; 
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            color: white; padding: 25px; border-radius: 16px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.2);
        }
        .total-label { font-size: 16px; opacity: 0.8; font-weight: 300; }
        .total-amount { font-size: 32px; font-weight: 700; letter-spacing: -1px; }
        .total-amount span { font-size: 18px; margin-right: 4px; }

        /* 底部动作 */
        .footer-actions { 
            margin-top: 25px; display: flex; gap: 10px; justify-content: center; 
        }
        .btn-outline { 
            text-decoration: none; font-size: 13px; 
            color: var(--text-sub); border: 1px solid #e2e8f0; 
            padding: 8px 16px; border-radius: 6px; transition: all 0.2s;
        }
        .btn-outline:hover { background: white; border-color: var(--text-sub); color: var(--text-main); }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🧾 小票识别汇总</h1>
            <p>智能提取项目名称与金额 (支持多图上传)</p>
        </header>
        
        <div class="upload-box">
            <form id="uploadForm" method="post" enctype="multipart/form-data">
                <div class="file-input-wrapper">
                    <input type="file" id="fileInput" name="receipts[]" multiple required>
                </div>
                <button type="submit" id="submitBtn" class="btn-primary">开始解析</button>
                <div id="status">📸 正在压缩图片并请求 API，请稍候...</div>
            </form>
        </div>

        <?php if ($results): ?>
            <div class="results-container">
                <?php foreach ($results as $res): ?>
                    <div class="result-card">
                        <div class="card-header">
                            <span>📄 <?= htmlspecialchars($res['file']) ?></span>
                            <span><?= count($res['items']) ?> 项</span>
                        </div>
                        <?php foreach ($res['items'] as $it): ?>
                            <div class="item-row">
                                <span class="name"><?= htmlspecialchars($it['name']) ?></span>
                                <span class="price">¥<?= number_format($it['price']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <div class="total-section">
                    <div class="total-label">合计 (Total)</div>
                    <div class="total-amount"><span>¥</span><?= number_format($totalAllAmount) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="footer-actions">
            <a href="?action=csv" class="btn-outline">📥 下载数据 (CSV)</a>
            <a href="?action=log" class="btn-outline">📜 调试日志</a>
        </div>
    </div>

    <script>
    // 保持压缩和上传逻辑
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
            status.innerText = `正在压缩处理第 ${i+1}/${files.length} 张图片...`;
            const compressedFile = await compressImage(files[i]);
            formData.append('receipts[]', compressedFile, files[i].name);
        }

        status.innerText = "🔍 云端识别中，请稍后 (每张约需2秒)...";
        fetch('', { method: 'POST', body: formData })
        .then(r => r.text())
        .then(html => {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            document.body.innerHTML = doc.body.innerHTML;
        })
        .catch(err => {
            alert("网络请求失败，请检查网络或减少单次上传数量。");
            btn.disabled = false;
            status.style.display = "none";
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
</body>
</html>
