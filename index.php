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
            --accent: #007AFF;
            --bg: #F2F2F7;
            --card: #FFFFFF;
            --text: #1C1C1E;
            --text-sub: #8E8E93;
            --border: #E5E5EA;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #000000;
                --card: #1C1C1E;
                --text: #FFFFFF;
                --text-sub: #8E8E93;
                --border: #38383A;
            }
        }

        body {
            margin: 0;
            padding: 40px 20px;
            background-color: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .container {
            max-width: 400px;
            margin: 0 auto;
        }

        /* 紧凑型头部 */
        header {
            margin-bottom: 24px;
        }
        header h1 {
            font-size: 20px;
            font-weight: 600;
            margin: 0;
            letter-spacing: -0.5px;
        }

        /* 结果容器 */
        .glass-card {
            background: var(--card);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        }

        /* 精致上传框 */
        .upload-trigger {
            width: 80px;
            height: 80px;
            background: var(--bg);
            border: 1px dashed var(--border);
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: 0.2s;
            margin-bottom: 20px;
        }
        .upload-trigger:hover { border-color: var(--accent); background: rgba(0,122,255,0.05); }
        .upload-trigger svg { width: 24px; height: 24px; fill: var(--text-sub); margin-bottom: 4px; }
        .upload-trigger span { font-size: 11px; color: var(--text-sub); }

        /* 按钮 */
        .btn-action {
            width: 100%;
            background: var(--accent);
            color: white;
            border: none;
            padding: 12px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
        }
        .btn-action:disabled { opacity: 0.4; }

        /* 明细列表 */
        .list { margin-top: 24px; border-top: 1px solid var(--border); }
        .row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 0.5px solid var(--border);
            font-size: 14px;
        }
        .name { color: var(--text); flex: 1; padding-right: 10px; }
        .price { font-weight: 600; font-variant-numeric: tabular-nums; }

        /* 合计栏 */
        .total-bar {
            margin-top: 24px;
            display: flex;
            justify-content: space-between;
            align-items: baseline;
        }
        .total-label { font-size: 14px; font-weight: 500; color: var(--text-sub); }
        .total-val { font-size: 28px; font-weight: 700; }
        .total-val small { font-size: 16px; margin-right: 2px; }

        footer {
            margin-top: 32px;
            text-align: center;
            font-size: 12px;
        }
        footer a { color: var(--accent); text-decoration: none; margin: 0 8px; }

        #status { font-size: 12px; color: var(--accent); margin-top: 10px; text-align: center; }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1>小票解析</h1>
    </header>

    <div class="glass-card">
        <form id="uploadForm">
            <label class="upload-trigger">
                <input type="file" id="fileInput" name="receipts[]" multiple hidden>
                <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                <span>选照片</span>
            </label>
            
            <button type="submit" class="btn-action" id="subBtn">开始解析</button>
            <div id="status"></div>
        </form>

        <?php if ($results): ?>
        <div class="list">
            <?php foreach ($results as $res): ?>
                <?php foreach ($res['items'] as $it): ?>
                <div class="row">
                    <span class="name"><?= htmlspecialchars($it['name']) ?></span>
                    <span class="price">¥<?= number_format($it['price']) ?></span>
                </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>

        <div class="total-bar">
            <span class="total-label">合计</span>
            <div class="total-val"><small>¥</small><?= number_format($totalAllAmount) ?></div>
        </div>
        <?php endif; ?>
    </div>

    <footer>
        <a href="?action=csv">导出数据</a>
        <a href="?action=log">日志</a>
    </footer>
</div>

<script>
    const input = document.getElementById('fileInput');
    const status = document.getElementById('status');
    const btn = document.getElementById('subBtn');

    input.onchange = () => {
        status.innerText = `已选 ${input.files.length} 张图片`;
    };

    document.getElementById('uploadForm').onsubmit = async e => {
        e.preventDefault();
        if(!input.files.length) return;
        btn.disabled = true;
        status.innerText = "识别中...";
        
        const fd = new FormData();
        for (let f of input.files) fd.append('receipts[]', f, f.name);
        
        const r = await fetch('', { method: 'POST', body: fd });
        const html = await r.text();
        document.body.innerHTML = new DOMParser().parseFromString(html, 'text/html').body.innerHTML;
    };
</script>

</body>
</html>
