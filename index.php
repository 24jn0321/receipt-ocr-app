<?php
/**
 * 🧾 小票解析系统 - 终极稳定去重版
 * 功能：多图上传、自动压缩、追加保存、智能去重、保留◎
 */

// --- 1. 配置与环境设置 ---
@set_time_limit(600);
@ini_set('memory_limit', '512M');

$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 

$storageFile = 'ocr_data.json';
$logFile = 'ocr.log';

// --- 2. 接口处理 (CSV下载/日志/清空) ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
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
    if ($action == 'log' && file_exists($logFile)) {
        header('Content-Type: text/plain; charset=utf-8');
        readfile($logFile); exit;
    }
    if ($action == 'clear') {
        if (file_exists($storageFile)) unlink($storageFile);
        header("Location: " . strtok($_SERVER["REQUEST_URI"], '?')); exit;
    }
}

// --- 3. OCR 核心解析逻辑 ---
$results = file_exists($storageFile) ? json_decode(file_get_contents($storageFile), true) : [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    file_put_contents($logFile, "\n[" . date('Y-m-d H:i:s') . "] --- 开始识别任务 ---\n", FILE_APPEND);
    
    $newResults = [];
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        if ($key > 0) sleep(2); // 避免触发 API 频率限制

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
            file_put_contents($logFile, "  [ERROR] $fileName 请求失败: HTTP $httpCode\n", FILE_APPEND);
            continue; 
        }

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        $currentItems = [];
        $stopFlag = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            // 过滤无用字符但保留 ◎
            $pureText = str_replace([' ', '　', '＊', '*', '√', '軽', '轻', '(', ')', '8%', '10%'], '', $text);

            // 遇到合计/税额等关键词停止解析后续条目
            if (preg_match('/合計|合计|内消費税|消費税|対象|支払|残高|再発行/u', $pureText)) {
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            if ($stopFlag) continue;

            // 匹配金额行
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                $nameInLine = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                $cleanNameInLine = str_replace(['＊', '*', '轻', '軽', '(', ')', '.', '．', ' '], '', $nameInLine);

                // 名字提取逻辑 (如果本行名字太短则向上回溯)
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

                // --- 智能去重逻辑 ---
                if (!empty($finalName) && !preg_match('/Family|新宿|電話|登録|領収|対象|消費税|合計|内訳/u', $finalName)) {
                    $isDuplicate = false;
                    foreach ($currentItems as $existingItem) {
                        if ($existingItem['name'] === $finalName && $existingItem['price'] === $price) {
                            $isDuplicate = true;
                            break;
                        }
                    }
                    if (!$isDuplicate) {
                        $currentItems[] = ['name' => $finalName, 'price' => $price];
                    }
                }
            }
        }
        $newResults[] = ['file' => $fileName, 'items' => $currentItems];
        file_put_contents($logFile, "  [SUCCESS] $fileName 处理成功\n", FILE_APPEND);
    }
    
    // 合并新旧数据并保存
    $results = array_merge($results, $newResults);
    file_put_contents($storageFile, json_encode($results, JSON_UNESCAPED_UNICODE));
}

// 计算累计金额
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
    <title>小票解析汇总系统</title>
    <style>
        body { font-family: 'PingFang SC', sans-serif; background: #f4f7f9; padding: 20px; color: #333; }
        .box { max-width: 600px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.05); }
        .card { border-left: 4px solid #2ecc71; background: #fafafa; padding: 15px; margin-bottom: 15px; border-radius: 6px; position: relative; }
        .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #eee; font-size: 14px; }
        .grand-total { margin-top: 25px; padding: 20px; background: #fff5f5; border: 1px solid #ffccc7; border-radius: 10px; text-align: center; }
        .amount-big { font-size: 32px; font-weight: bold; color: #ff4d4f; }
        .btn-main { width: 100%; padding: 15px; background: #1890ff; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; transition: 0.3s; }
        .btn-main:disabled { background: #d9d9d9; cursor: not-allowed; }
        .nav-bar { margin-top: 25px; display: flex; justify-content: space-around; border-top: 1px solid #eee; padding-top: 15px; }
        .nav-link { font-size: 13px; color: #666; text-decoration: none; padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px; }
        #status { color: #1890ff; text-align: center; margin-top: 10px; font-size: 13px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center; margin-bottom:20px;">📜 小票解析汇总</h2>
        
        <form id="uploadForm" method="post" enctype="multipart/form-data">
            <input type="file" id="fileInput" name="receipts[]" multiple required style="margin-bottom:20px; width: 100%;">
            <button type="submit" id="submitBtn" class="btn-main">开始解析并汇总</button>
            <div id="status" style="display:none;">准备处理中...</div>
        </form>

        <?php if ($results): ?>
            <div style="margin-top:30px;">
                <p style="font-weight:bold; color:#888;">扫描明细：</p>
                <?php foreach ($results as $res): ?>
                    <div class="card">
                        <small style="color:#aaa; font-size:11px;">📄 <?= htmlspecialchars($res['file']) ?></small>
                        <?php if (empty($res['items'])): ?>
                            <div class="row"><span style="color:#ccc;">未识别到有效商品</span></div>
                        <?php else: ?>
                            <?php foreach ($res['items'] as $it): ?>
                                <div class="row">
                                    <span><?= htmlspecialchars($it['name']) ?></span>
                                    <span>¥<?= number_format($it['price']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <div class="grand-total">
                    <div style="font-size:14px; color:#666;">所有已上传小票累计金额</div>
                    <div class="amount-big">¥<?= number_format($totalAllAmount) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="nav-bar">
            <a href="?action=csv" class="nav-link">📥 导出 CSV</a>
            <a href="?action=log" class="nav-link" target="_blank">📜 查看日志</a>
            <a href="?action=clear" class="nav-link" style="color:#ff4d4f; border-color:#ffccc7;" onclick="return confirm('确定要清空所有记录吗？')">🗑️ 清空重置</a>
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
        status.style.display = "block";

        const formData = new FormData();
        for (let i = 0; i < files.length; i++) {
            status.innerText = `正在压缩图片 (${i+1}/${files.length})...`;
            const compressed = await compressImg(files[i]);
            formData.append('receipts[]', compressed, files[i].name);
        }

        status.innerText = "正在解析中，图片较多时请耐心等待...";
        fetch('', { method: 'POST', body: formData })
        .then(r => r.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            document.body.innerHTML = doc.body.innerHTML;
        })
        .catch(err => {
            alert("解析超时，请尝试单次上传更少的图片。");
            btn.disabled = false;
        });
    };

    function compressImg(file) {
        return new Promise(resolve => {
            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onload = (e) => {
                const img = new Image();
                img.src = e.target.result;
                img.onload = () => {
                    const canvas = document.createElement('canvas');
                    let w = img.width, h = img.height;
                    if (w > 1200) { h = h * (1200/w); w = 1200; }
                    canvas.width = w; canvas.height = h;
                    canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                    canvas.toBlob(blob => resolve(new File([blob], file.name, {type:'image/jpeg'})), 'image/jpeg', 0.8);
                };
            };
        });
    }
    </script>
</body>
</html>
