<?php
/**
 * 🧾 小票解析系统（产品级 · 克制版）
 * - OCR 逻辑保持不变
 * - UI 精修：无 slogan、无夸张数字
 */

@set_time_limit(600);
@ini_set('memory_limit', '512M');

$endpoint = "https://24jn0321.cognitiveservices.azure.com/";
$apiKey   = "你的 KEY"; // ← 注意别外泄

$results = [];
$totalAllAmount = 0;
$storageFile = 'ocr_data.json';
$logFile = 'ocr.log';

/* ===== 导出接口 ===== */
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'csv' && file_exists($storageFile)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_export.csv');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['文件名', '项目', '金额']);
        $data = json_decode(file_get_contents($storageFile), true);
        foreach ($data as $r) {
            foreach ($r['items'] as $i) {
                fputcsv($out, [$r['file'], $i['name'], $i['price']]);
            }
        }
        fclose($out); exit;
    }

    if ($_GET['action'] === 'log' && file_exists($logFile)) {
        header('Content-Type: text/plain; charset=utf-8');
        readfile($logFile); exit;
    }
}

/* ===== OCR 核心 ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['receipts'])) {
    file_put_contents($logFile, "=== SCAN " . date('Y-m-d H:i:s') . " ===\n");

    foreach ($_FILES['receipts']['tmp_name'] as $k => $tmp) {
        if (!$tmp) continue;
        if ($k > 0) sleep(2);

        $fileName = $_FILES['receipts']['name'][$k];
        $imgData = file_get_contents($tmp);

        $url = rtrim($endpoint, '/') .
            "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/octet-stream',
                'Ocp-Apim-Subscription-Key: ' . $apiKey
            ],
            CURLOPT_POSTFIELDS => $imgData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 60
        ]);
        $res = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($res, true);
        $lines = $json['readResult']['blocks'][0]['lines'] ?? [];

        $items = [];
        $stop = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            $clean = str_replace([' ', '＊', '*'], '', $text);

            if (preg_match('/合计|消費税|支払|残高/u', $clean)) {
                if ($items) $stop = true;
                continue;
            }
            if ($stop) continue;

            if (preg_match('/[¥￥]([\d,]+)/u', $text, $m)) {
                $price = (int)str_replace(',', '', $m[1]);
                $name = preg_replace('/[¥￥].*/u', '', $text);
                $name = trim(str_replace(['＊','*',' '], '', $name));

                if (mb_strlen($name) < 2) {
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $p = trim($lines[$j]['text']);
                        if (mb_strlen($p) >= 2) { $name = $p; break; }
                    }
                }

                if ($name) {
                    $items[] = ['name' => $name, 'price' => $price];
                }
            }
        }

        $results[] = ['file' => $fileName, 'items' => $items];
    }

    file_put_contents($storageFile, json_encode($results, JSON_UNESCAPED_UNICODE));
}
elseif (file_exists($storageFile)) {
    $results = json_decode(file_get_contents($storageFile), true);
}

if ($results) {
    foreach ($results as $r)
        foreach ($r['items'] as $i)
            $totalAllAmount += $i['price'];
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
    --bg:#f8fafc; --card:#fff;
    --text:#0f172a; --sub:#64748b;
    --border:#e5e7eb;
}
body {
    margin:0; padding:40px 16px;
    font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;
    background:var(--bg); color:var(--text);
}
.app {
    max-width:520px; margin:auto;
    background:var(--card);
    border-radius:14px;
    padding:36px 32px 44px;
    box-shadow:0 10px 30px rgba(0,0,0,.06);
}
header h1 {
    font-size:24px;
    font-weight:600;
    margin:0 0 28px;
}

/* Upload */
.upload {
    border:1.5px dashed var(--border);
    border-radius:12px;
    padding:22px;
    text-align:center;
    cursor:pointer;
}
.upload span { font-size:13px; color:var(--sub); }
.btn {
    width:100%; margin-top:20px;
    padding:14px; border-radius:10px;
    background:#0f172a; color:#fff;
    border:none; cursor:pointer;
}
#status { margin-top:12px; font-size:13px; text-align:center; color:#2563eb; }

/* Items */
.item {
    display:flex; justify-content:space-between;
    padding:12px 0; border-bottom:1px solid #f1f5f9;
}
.price { font-family:monospace; }

/* Summary */
.total {
    margin-top:48px;
    padding-top:20px;
    border-top:1px solid var(--border);
    display:flex; justify-content:space-between;
}
.total strong {
    font-size:28px;
    font-weight:600;
}

/* Footer */
.footer {
    margin-top:40px; text-align:center;
}
.footer a {
    font-size:13px; color:var(--sub);
    margin:0 10px; text-decoration:none;
}
</style>
</head>

<body>
<div class="app">
<header><h1>小票解析</h1></header>

<form id="f" method="post" enctype="multipart/form-data">
<label class="upload">
    <input type="file" name="receipts[]" id="i" multiple hidden>
    <span>点击选择或拖拽上传小票</span>
</label>
<button class="btn">开始解析</button>
<div id="status"></div>
</form>

<?php if ($results): ?>
<?php foreach ($results as $r): ?>
<?php foreach ($r['items'] as $i): ?>
<div class="item">
    <span><?= htmlspecialchars($i['name']) ?></span>
    <span class="price">¥<?= number_format($i['price']) ?></span>
</div>
<?php endforeach; endforeach; ?>

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
const f=document.getElementById('f'),
i=document.getElementById('i'),
s=document.getElementById('status');
f.onsubmit=e=>{
    e.preventDefault();
    s.innerText='处理中…';
    fetch('',{method:'POST',body:new FormData(f)})
        .then(r=>r.text())
        .then(h=>document.body.innerHTML=
            new DOMParser().parseFromString(h,'text/html').body.innerHTML);
};
</script>
</body>
</html>
