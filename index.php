<?php
/**
 * 🧾 小票解析系统 - 稳定修复版
 */

// --- 1. 配置与环境 ---
@set_time_limit(600);
@ini_set('memory_limit', '512M');

$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 

$storageFile = 'ocr_data.json';
$logFile = 'ocr.log';

// --- 2. 功能接口 (CSV/LOG/CLEAR) ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    // 导出 CSV
    if ($action == 'csv' && file_exists($storageFile)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_export_'.date('Ymd').'.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['文件名', '项目', '金额']);
        $data = json_decode(file_get_contents($storageFile), true);
        foreach ($data as $res) {
            foreach ($res['items'] as $it) {
                fputcsv($output, [$res['file'], $it['name'], $it['price']]);
            }
        }
        fclose($output); exit;
    }
    // 查看日志
    if ($action == 'log' && file_exists($logFile)) {
        header('Content-Type: text/plain; charset=utf-8');
        readfile($logFile); exit;
    }
    // 清空数据
    if ($action == 'clear') {
        if (file_exists($storageFile)) unlink($storageFile);
        header("Location: index.php"); exit;
    }
}

// --- 3. OCR 核心解析逻辑 ---
$results = file_exists($storageFile) ? json_decode(file_get_contents($storageFile), true) : [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    file_put_contents($logFile, "\n[" . date('Y-m-d H:i:s') . "] 开始新扫描任务...\n", FILE_APPEND);
    
    $newResults = [];
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        if ($key > 0) sleep(2); // 免费版 API 必须间隔

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
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            file_put_contents($logFile, "  [ERROR] $fileName 接口请求失败: HTTP $httpCode - $response\n", FILE_APPEND);
            continue; 
        }

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
                    $currentItems[] = ['name' => $finalName, 'price' => $price];
                }
            }
        }
        $newResults[] = ['file' => $fileName, 'items' => $currentItems];
        file_put_contents($logFile, "  [SUCCESS] $fileName 解析完成，提取条目: " . count($currentItems) . "\n", FILE_APPEND);
    }
    
    // 合并并保存 (关键：不覆盖之前的历史数据)
    $results = array_merge($results, $newResults);
    file_put_contents($storageFile, json_encode($results, JSON_UNESCAPED_UNICODE));
}

// 计算总额
$totalAllAmount = 0;
foreach ($results as $res) {
    foreach ($res['items'] as $it) { $totalAllAmount += $it['price']; }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>小票汇总系统 v2.0</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 15px; }
        .box { max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 15px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .card { border-left: 4px solid #27ae60; background: #f9f9f9; padding: 12px; margin-bottom: 10px; border-radius: 5px; }
        .row { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px dashed #eee; font-size: 14px; }
        .total-box { margin-top: 20px; padding: 20px; background: #fff1f0; border: 1px solid #ffa39e; border-radius: 8px; text-align: center; }
        .total-amount { font-size: 32px; font-weight: bold; color: #cf1322; }
        .btn { width: 100%; padding: 15px; background: #1890ff; color: white; border: none; border-radius: 8px; font-size: 16px; cursor: pointer; }
        .btn:disabled { background: #bfbfbf; }
        .nav-links { margin-top: 20px; display: flex; justify-content: space-between; }
        .link { font-size: 13px; color: #666; text-decoration: none; padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">🧾 小票解析汇总</h2>
        
        <form id="uploadForm" method="post" enctype="multipart/form-data">
            <input type="file" id="fileInput" name="receipts[]" multiple required style="margin-bottom:15px; width:100%;">
            <button type="submit" id="submitBtn" class="btn">开始识别并汇总</button>
            <p id="status" style="display:none; color:#1890ff; text-align:center; font-size:13px; margin-top:10px;">处理中，请稍候...</p>
        </form>

        <script>
        document.getElementById('uploadForm').onsubmit = async function(e) {
            e.preventDefault();
            const btn = document.getElementById('submitBtn');
            const status = document.getElementById('status');
            const files = document.getElementById('fileInput').files;

            btn.disabled = true;
            status.style.display = "block";

            const formData = new FormData();
            for (let i = 0; i < files.length; i++) {
                status.innerText = `压缩进度: ${i+1}/${files.length}...`;
                const compressed = await compressImg(files[i]);
                formData.append('receipts[]', compressed, files[i].name);
            }

            status.innerText = "正在请求 Azure API 解析，请勿刷新...";
            fetch('', { method: 'POST', body: formData })
            .then(r => r.text())
            .then(html => {
                const doc = new DOMParser().parseFromString(html, 'text/html');
                document.body.innerHTML = doc.body.innerHTML;
            })
            .catch(err => {
                alert("请求超时，请检查网络或减少单次上传数量");
                btn.disabled = false;
            });
        };

        function compressImg(file) {
            return new Promise(res => {
                const reader = new FileReader();
                reader.readAsDataURL(file);
                reader.onload = e => {
                    const img = new Image();
                    img.src = e.target.result;
                    img.onload = () => {
                        const canvas = document.createElement('canvas');
                        const max = 1200;
                        let w = img.width, h = img.height;
                        if (w > max) { h = h * (max/w); w = max; }
                        canvas.width = w; canvas.height = h;
                        canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                        canvas.toBlob(b => res(new File([b], file.name, {type:'image/jpeg'})), 'image/jpeg', 0.8);
                    };
                };
            });
        }
        </script>

        <?php if ($results): ?>
            <div style="margin-top:20px;">
                <h4 style="margin-bottom:10px; color:#555;">扫描明细：</h4>
                <?php foreach ($results as $res): ?>
                    <div class="card">
                        <div style="font-size:11px; color:#999; margin-bottom:5px;">📄 <?= htmlspecialchars($res['file']) ?></div>
                        <?php foreach ($results[0]['items'] === [] && count($res['items']) === 0 ? [['name'=>'未识别到项目','price'=>0]] : $res['items'] as $it): ?>
                            <div class="row">
                                <span><?= htmlspecialchars($it['name']) ?></span>
                                <span>¥<?= number_format($it['price']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <div class="total-box">
                    <div style="font-size:14px; color:#888;">所有已扫描小票总计 (累计)</div>
                    <div class="total-amount">¥<?= number_format($totalAllAmount) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="nav-links">
            <a href="?action=csv" class="link">📥 导出 CSV</a>
            <a href="?action=log" class="link" target="_blank">📜 调试日志</a>
            <a href="?action=clear" class="link" style="color:#ff4d4f;" onclick="return confirm('确认清空所有已扫描的数据吗？')">🗑️ 清空重置</a>
        </div>
    </div>
</body>
</html>
