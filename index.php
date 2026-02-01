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
    --bg: #f8fafc;
    --card: #ffffff;
    --text-main: #0f172a;
    --text-sub: #64748b;
    --accent: #2563eb;
    --border: #e5e7eb;
}

@media (prefers-color-scheme: dark) {
:root {
    --bg: #020617;
    --card: #020617;
    --text-main: #e5e7eb;
    --text-sub: #94a3b8;
    --accent: #60a5fa;
    --border: #1e293b;
}}

* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 48px 16px;
    background: var(--bg);
    color: var(--text-main);
    font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display",
        "PingFang SC", "Microsoft YaHei", sans-serif;
    -webkit-font-smoothing: antialiased;
}

.app {
    max-width: 540px;
    margin: auto;
    background: var(--card);
    border-radius: 18px;
    padding: 44px 36px 52px;
    box-shadow: 0 30px 80px rgba(0,0,0,.25);
    animation: fadeUp .6s ease;
}

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: none; }
}

/* Header */
header h1 {
    font-size: 32px;
    font-weight: 700;
    margin: 0;
}
header p {
    margin-top: 6px;
    font-size: 14px;
    color: var(--text-sub);
}

/* Upload */
.upload-box {
    margin-top: 36px;
    padding: 26px;
    border-radius: 14px;
    border: 1.5px dashed var(--border);
    text-align: center;
    cursor: pointer;
    transition: .3s;
}
.upload-box.drag {
    background: rgba(96,165,250,.08);
    border-color: var(--accent);
}
.upload-box strong {
    display: block;
    font-size: 16px;
}
.upload-box span {
    font-size: 13px;
    color: var(--text-sub);
}

.btn {
    margin-top: 22px;
    width: 100%;
    padding: 16px;
    font-size: 15px;
    border-radius: 12px;
    border: none;
    background: linear-gradient(180deg, #2563eb, #1e40af);
    color: white;
    cursor: pointer;
    transition: .2s;
}
.btn:hover { transform: translateY(-1px); }
.btn:disabled {
    background: #334155;
    cursor: not-allowed;
}

/* Status */
#status {
    margin-top: 14px;
    font-size: 13px;
    text-align: center;
    color: var(--accent);
}

/* Skeleton */
.skeleton {
    margin-top: 40px;
}
.sk-row {
    height: 16px;
    background: linear-gradient(
        90deg,
        var(--border),
        rgba(255,255,255,.2),
        var(--border)
    );
    background-size: 200% 100%;
    animation: shimmer 1.2s infinite;
    border-radius: 6px;
    margin-bottom: 14px;
}
@keyframes shimmer {
    from { background-position: 200% 0; }
    to { background-position: -200% 0; }
}

/* Result */
.item {
    display: flex;
    justify-content: space-between;
    padding: 14px 0;
    border-bottom: 1px solid rgba(255,255,255,.04);
}
.price {
    font-family: "SF Mono", Consolas, monospace;
}

.total {
    margin-top: 60px;
    padding-top: 24px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
}
.total strong {
    font-size: 44px;
}
.footer {
    margin-top: 48px;
    text-align: center;
}
.footer a {
    color: var(--text-sub);
    text-decoration: none;
    font-size: 13px;
    margin: 0 12px;
}
.footer a:hover { color: var(--text-main); }
</style>
</head>

<body>
<div class="app">

<header>
    <h1>小票解析</h1>
    <p>Intelligence in simplicity.</p>
</header>

<form id="uploadForm" enctype="multipart/form-data">
<label class="upload-box" id="drop">
    <input type="file" id="fileInput" name="receipts[]" multiple hidden>
    <strong>拖拽或点击上传小票</strong>
    <span>自动压缩 · OCR 解析 · 汇总</span>
</label>
<button class="btn" id="btn">开始解析</button>
<div id="status"></div>
</form>

<?php if ($results): ?>
<?php foreach ($results as $res): ?>
<?php foreach ($res['items'] as $it): ?>
<div class="item">
    <span><?= htmlspecialchars($it['name']) ?></span>
    <span class="price">¥<?= number_format($it['price']) ?></span>
</div>
<?php endforeach; ?>
<?php endforeach; ?>

<div class="total">
    <span>合计</span>
    <strong>¥<?= number_format($totalAllAmount) ?></strong>
</div>
<?php endif; ?>

<div class="footer">
    <a href="?action=csv">CSV 导出</a>
    <a href="?action=log">运行日志</a>
</div>

</div>

<script>
const drop = document.getElementById('drop');
const input = document.getElementById('fileInput');
const status = document.getElementById('status');
const btn = document.getElementById('btn');

drop.onclick = () => input.click();

['dragenter','dragover'].forEach(e =>
    drop.addEventListener(e, ev => {
        ev.preventDefault();
        drop.classList.add('drag');
    })
);
['dragleave','drop'].forEach(e =>
    drop.addEventListener(e, () => drop.classList.remove('drag'))
);
drop.ondrop = e => {
    e.preventDefault();
    input.files = e.dataTransfer.files;
};

document.getElementById('uploadForm').onsubmit = async e => {
    e.preventDefault();
    btn.disabled = true;
    status.innerText = "解析中…";
    const fd = new FormData();
    for (let f of input.files) fd.append('receipts[]', f, f.name);
    const r = await fetch('', { method: 'POST', body: fd });
    document.body.innerHTML =
        new DOMParser().parseFromString(await r.text(), 'text/html').body.innerHTML;
};
</script>
</body>
</html>


