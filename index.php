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
    <title>小票解析</title>
    <style>
        :root {
            --bg: #fcfcfd;
            --accent: #0071e3;
            --text: #1d1d1f;
            --text-sub: #86868b;
            --glass: rgba(255, 255, 255, 0.7);
            --border: rgba(0, 0, 0, 0.05);
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #000000;
                --accent: #0077ed;
                --text: #f5f5f7;
                --text-sub: #a1a1a6;
                --glass: rgba(28, 28, 30, 0.7);
                --border: rgba(255, 255, 255, 0.1);
            }
        }

        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        body {
            margin: 0;
            background-color: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "PingFang SC", sans-serif;
            display: flex;
            justify-content: center;
            padding: 60px 20px;
            min-height: 100vh;
        }

        .container {
            width: 100%;
            max-width: 480px;
            animation: fadeIn 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* 头部 */
        header { text-align: center; margin-bottom: 40px; }
        header h1 { font-size: 34px; font-weight: 700; letter-spacing: -0.5px; margin: 0; }
        header p { color: var(--text-sub); font-size: 16px; margin-top: 8px; font-weight: 400; }

        /* 玻璃容器 */
        .glass-panel {
            background: var(--glass);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border: 1px solid var(--border);
            border-radius: 28px;
            padding: 32px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.04);
        }

        /* 上传交互区 */
        .upload-zone {
            position: relative;
            background: rgba(0,0,0,0.02);
            border-radius: 20px;
            padding: 40px 20px;
            text-align: center;
            transition: all 0.3s ease;
            border: 1px solid transparent;
        }
        .upload-zone:active { transform: scale(0.98); }
        .upload-zone.drag { background: rgba(0, 113, 227, 0.05); border-color: var(--accent); }
        
        .upload-zone i { font-style: normal; font-size: 40px; display: block; margin-bottom: 12px; }
        .upload-zone b { font-size: 17px; font-weight: 600; display: block; color: var(--text); }
        .upload-zone span { font-size: 14px; color: var(--text-sub); margin-top: 4px; display: block; }

        /* 按钮 */
        .btn-primary {
            margin-top: 24px;
            width: 100%;
            padding: 18px;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 16px;
            font-size: 17px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .btn-primary:hover { box-shadow: 0 10px 20px rgba(0, 113, 227, 0.3); opacity: 0.95; }
        .btn-primary:disabled { background: var(--text-sub); opacity: 0.5; transform: none; }

        /* 识别结果明细 */
        .result-list { margin-top: 32px; border-top: 1px solid var(--border); padding-top: 10px; }
        .item-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 0;
            border-bottom: 1px solid var(--border);
            animation: slideIn 0.5s ease forwards;
        }
        @keyframes slideIn { from { opacity: 0; transform: translateX(-10px); } to { opacity: 1; transform: translateX(0); } }

        .item-info .name { display: block; font-size: 16px; font-weight: 500; }
        .item-info .file { font-size: 12px; color: var(--text-sub); }
        .item-price { font-size: 17px; font-weight: 600; font-variant-numeric: tabular-nums; }

        /* 合计 */
        .total-section {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
            align-items: baseline;
        }
        .total-label { font-size: 18px; font-weight: 600; color: var(--text-sub); }
        .total-val { font-size: 48px; font-weight: 700; letter-spacing: -2px; }
        .total-val small { font-size: 24px; margin-right: 4px; font-weight: 500; }

        /* 页脚 */
        footer {
            margin-top: 40px;
            display: flex;
            justify-content: center;
            gap: 20px;
        }
        footer a {
            font-size: 14px;
            text-decoration: none;
            color: var(--accent);
            font-weight: 500;
        }

        #status {
            text-align: center;
            margin-top: 16px;
            font-size: 14px;
            font-weight: 500;
            color: var(--accent);
            min-height: 20px;
        }
    </style>
</head>

<body>
<div class="container">

    <header>
        <h1>小票解析</h1>
        <p>Intelligence in simplicity.</p>
    </header>

    <div class="glass-panel">
        <form id="uploadForm">
            <label class="upload-zone" id="dropArea">
                <input type="file" id="fileInput" name="receipts[]" multiple hidden>
                <i>📄</i>
                <b>选取或拖入小票</b>
                <span>支持多张图片识别</span>
            </label>
            <button class="btn-primary" id="submitBtn">开始智能分析</button>
            <div id="status"></div>
        </form>

        <?php if ($results): ?>
        <div class="result-list">
            <?php foreach ($results as $res): ?>
                <?php foreach ($res['items'] as $it): ?>
                <div class="item-row">
                    <div class="item-info">
                        <span class="name"><?= htmlspecialchars($it['name']) ?></span>
                        <span class="file"><?= htmlspecialchars($res['file']) ?></span>
                    </div>
                    <div class="item-price">¥<?= number_format($it['price']) ?></div>
                </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>

        <div class="total-section">
            <span class="total-label">合计</span>
            <div class="total-val"><small>¥</small><?= number_format($totalAllAmount) ?></div>
        </div>
        <?php endif; ?>
    </div>

    <footer>
        <a href="?action=csv">CSV 导出数据</a>
        <a href="?action=log">开发者日志</a>
    </footer>

</div>

<script>
    const dropArea = document.getElementById('dropArea');
    const fileInput = document.getElementById('fileInput');
    const submitBtn = document.getElementById('submitBtn');
    const status = document.getElementById('status');

    // 交互动画
    ['dragenter', 'dragover'].forEach(name => {
        dropArea.addEventListener(name, (e) => {
            e.preventDefault();
            dropArea.classList.add('drag');
        });
    });

    ['dragleave', 'drop'].forEach(name => {
        dropArea.addEventListener(name, () => dropArea.classList.remove('drag'));
    });

    dropArea.addEventListener('drop', (e) => {
        e.preventDefault();
        fileInput.files = e.dataTransfer.files;
        status.innerText = `已准备 ${fileInput.files.length} 张图片`;
    });

    document.getElementById('uploadForm').onsubmit = async (e) => {
        e.preventDefault();
        if (fileInput.files.length === 0) return alert('请先选择文件');

        submitBtn.disabled = true;
        status.innerText = "正在进行 OCR 云端识别...";

        const fd = new FormData();
        for (let f of fileInput.files) fd.append('receipts[]', f, f.name);

        try {
            const r = await fetch('', { method: 'POST', body: fd });
            const html = await r.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            document.body.innerHTML = doc.body.innerHTML;
            // 重新绑定事件（如果是局部刷新）
        } catch (err) {
            status.innerText = "解析出错，请重试";
            submitBtn.disabled = false;
        }
    };
</script>
</body>
</html>


