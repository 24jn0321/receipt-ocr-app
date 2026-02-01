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
    --text-main: #0f172a;
    --text-sub: #64748b;
    --accent: #2563eb;
    --bg: #f8fafc;
    --card-bg: #ffffff;
    --border: #e5e7eb;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 40px 16px;
    font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display",
        "PingFang SC", "Microsoft YaHei", sans-serif;
    background: var(--bg);
    color: var(--text-main);
    -webkit-font-smoothing: antialiased;
}

.app-container {
    max-width: 520px;
    margin: 0 auto;
    background: var(--card-bg);
    border-radius: 14px;
    padding: 40px 32px 48px;
    box-shadow:
        0 10px 30px rgba(0,0,0,.06),
        0 1px 3px rgba(0,0,0,.05);
}

/* Header */
header {
    margin-bottom: 48px;
}
header h1 {
    font-size: 30px;
    font-weight: 700;
    margin: 0;
}
header p {
    margin-top: 6px;
    font-size: 14px;
    color: var(--text-sub);
    letter-spacing: .2px;
}

/* Upload */
.upload-area {
    margin-bottom: 20px;
}

.upload-box {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 20px;
    border: 1.5px dashed var(--border);
    border-radius: 12px;
    cursor: pointer;
    transition: .25s;
}
.upload-box:hover {
    border-color: var(--accent);
    background: #f1f5ff;
}

.upload-icon {
    font-size: 28px;
}

.upload-text strong {
    display: block;
    font-size: 15px;
}
.upload-text span {
    display: block;
    margin-top: 2px;
    font-size: 12px;
    color: var(--text-sub);
}

.btn-submit {
    margin-top: 22px;
    width: 100%;
    padding: 16px;
    background: linear-gradient(180deg, #111827, #020617);
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 500;
    cursor: pointer;
    transition: .2s;
}
.btn-submit:hover { opacity: .9; transform: translateY(-1px); }
.btn-submit:disabled {
    background: #e5e7eb;
    color: #94a3b8;
    cursor: not-allowed;
    transform: none;
}

#status {
    margin-top: 14px;
    font-size: 13px;
    text-align: center;
    color: var(--accent);
    font-variant-numeric: tabular-nums;
}

/* Results */
.receipt-group {
    margin-top: 56px;
}

.file-label {
    display: block;
    margin-top: 36px;
    margin-bottom: 10px;
    font-size: 11px;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: var(--text-sub);
    border-bottom: 1px solid #f1f5f9;
    padding-bottom: 4px;
}

.item-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding: 14px 0;
    border-bottom: 1px solid #fafafa;
}

.item-name {
    flex: 1;
    padding-right: 20px;
    font-size: 15px;
}

.item-price {
    font-size: 15px;
    font-weight: 500;
    font-family: "SF Mono", Consolas, monospace;
}

/* Summary */
.summary-box {
    margin-top: 72px;
    padding-top: 28px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    background: linear-gradient(180deg, #fff, #f8fafc);
}

.total-title {
    font-size: 15px;
    color: var(--text-sub);
}

.total-amount {
    font-size: 46px;
    font-weight: 700;
    letter-spacing: -1px;
}
.total-amount span {
    font-size: 20px;
    margin-right: 4px;
    font-weight: 400;
}

/* Footer */
.footer-links {
    margin-top: 48px;
    display: flex;
    justify-content: center;
    gap: 24px;
}

.link {
    font-size: 13px;
    color: var(--text-sub);
    text-decoration: none;
    border-bottom: 1px solid transparent;
    transition: .2s;
}
.link:hover {
    color: var(--text-main);
    border-bottom-color: var(--text-main);
}
</style>
</head>

<body>
<div class="app-container">

<header>
    <h1>小票解析</h1>
    <p>Intelligence in simplicity.</p>
</header>

<div class="upload-area">
<form id="uploadForm" method="post" enctype="multipart/form-data">
    <label class="upload-box">
        <input type="file" id="fileInput" name="receipts[]" multiple required hidden>
        <div class="upload-icon">📄</div>
        <div class="upload-text">
            <strong>选择小票图片</strong>
            <span>支持多张，自动压缩与识别</span>
        </div>
    </label>

    <button type="submit" id="submitBtn" class="btn-submit">开始解析</button>
    <div id="status"></div>
</form>
</div>

<?php if ($results): ?>
<div class="receipt-group">
<?php foreach ($results as $res): ?>
    <span class="file-label"><?= htmlspecialchars($res['file']) ?></span>
    <?php foreach ($res['items'] as $it): ?>
        <div class="item-row">
            <span class="item-name"><?= htmlspecialchars($it['name']) ?></span>
            <span class="item-price">¥<?= number_format($it['price']) ?></span>
        </div>
    <?php endforeach; ?>
<?php endforeach; ?>

<div class="summary-box">
    <div class="total-title">合计</div>
    <div class="total-amount"><span>¥</span><?= number_format($totalAllAmount) ?></div>
</div>
</div>
<?php endif; ?>

<div class="footer-links">
    <a href="?action=csv" class="link">CSV 导出</a>
    <a href="?action=log" class="link">运行日志</a>
</div>

</div>

<script>
document.getElementById('uploadForm').onsubmit = async function(e) {
    e.preventDefault();
    const btn = document.getElementById('submitBtn');
    const status = document.getElementById('status');
    const files = document.getElementById('fileInput').files;
    if (!files.length) return;

    btn.disabled = true;
    status.innerText = "处理中...";

    const formData = new FormData();
    for (let i = 0; i < files.length; i++) {
        status.innerText = `压缩图像 (${i+1}/${files.length})`;
        const f = await compressImage(files[i]);
        formData.append('receipts[]', f, files[i].name);
    }

    status.innerText = "识别请求中...";
    fetch('', { method: 'POST', body: formData })
        .then(r => r.text())
        .then(html => {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            document.body.innerHTML = doc.body.innerHTML;
        });
};

function compressImage(file) {
    return new Promise(resolve => {
        const reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = e => {
            const img = new Image();
            img.src = e.target.result;
            img.onload = () => {
                const canvas = document.createElement('canvas');
                const w = 1200;
                canvas.width = w;
                canvas.height = img.height * (w / img.width);
                canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                canvas.toBlob(b =>
                    resolve(new File([b], file.name, { type: 'image/jpeg' })),
                    'image/jpeg', 0.85
                );
            };
        };
    });
}
</script>
</body>
</html>
