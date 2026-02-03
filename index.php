<?php
/**
 * 🧾 レシート解析システム - 最终兼容版
 * 解决：不同电脑扫描结果不一致、商品遗漏、相同商品被过滤的问题。
 */

// --- 1. 設定と環境構成 ---
@set_time_limit(600);
@ini_set('memory_limit', '512M');

$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 
$logFile = 'ocr.log';

// --- 2. Azure SQL 接続設定 ---
$serverName = "tcp:receipt-server-24jn0.database.windows.net,1433"; 
$connectionOptions = array(
    "Database" => "receiptdb",
    "Uid" => "sqladmin",
    "PWD" => "Abc842727925",
    "CharacterSet" => "UTF-8"
);
$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    die("<pre>" . print_r(sqlsrv_errors(), true) . "</pre>");
}

// --- 3. アクション処理 ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_export_'.date('Ymd').'.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ファイル名', '項目', '金額', '日時']);
        $sql = "SELECT r.file_name, r.processed_at, i.item_name, i.price FROM receipts r JOIN receipt_items i ON r.id = i.receipt_id";
        $stmt = sqlsrv_query($conn, $sql);
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            fputcsv($output, [$row['file_name'], $row['item_name'], $row['price'], $row['processed_at']->format('Y-m-d H:i:s')]);
        }
        fclose($output); exit;
    }
    if ($action == 'download_log') {
        if (file_exists($logFile)) {
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="ocr.log"');
            readfile($logFile); exit;
        }
    }
    if ($action == 'clear_view') {
        header("Location: " . strtok($_SERVER["PHP_SELF"], '?')); 
        exit;
    }
}

// --- 4. OCR 解析ロジック ---
$processedIds = []; 
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        if ($key > 0) sleep(1); 

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

        if ($httpCode !== 200) continue;

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        $currentItems = [];
        $stopFlag = false;
        
        $logStore = "不明な店舗";
        $logTotal = 0;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            
            // 店舗推測
            if ($i < 8 && preg_match('/FamilyMart|セブン|ローソン|LAWSON/i', $text, $storeMatch)) {
                $logStore = $storeMatch[0];
            }

            $pureText = str_replace([' ', '　', '＊', '*', '√', '軽', '轻', '(', ')', '8%', '10%'], '', $text);

            // 合计金额识别
            if (preg_match('/合計|合計額/u', $pureText) && preg_match('/[¥￥]([\d,]+)/u', $text, $totalMatch)) {
                $logTotal = (float)str_replace(',', '', $totalMatch[1]);
            }

            // 停止词（防止把找零、支付方式识别成商品）
            if (preg_match('/内消費税|消費税|対象|支払|残高|再発行/u', $pureText)) {
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            if ($stopFlag) continue;

            // 商品和金额解析
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                $nameInLine = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                
                // 清洗名称（保留 ◎）
                $cleanName = str_replace(['＊', '*', '轻', '軽', '(', ')', '.', '．', ' '], '', $nameInLine);

                // 如果当前行名太短，向上找一行
                if (mb_strlen($cleanName) < 2 || preg_match('/^[¥￥\d,\s]+$/u', $cleanName)) {
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prev = str_replace(['＊', '*', ' ', '√', '軽', '轻'], '', trim($lines[$j]['text']));
                        if (mb_strlen($prev) >= 2 && !preg_match('/領|収|証|合|計|%|店|電話|¥|￥/u', $prev)) {
                            $cleanName = $prev; 
                            break;
                        }
                    }
                }

                if (!empty($cleanName) && !preg_match('/Family|新宿|電話|登録|領収|対象|合計|内訳/u', $cleanName)) {
                    // 【关键：移除了重复检查逻辑，确保别人的电脑也能扫出多个相同商品】
                    $currentItems[] = ['name' => $cleanName, 'price' => $price];
                }
            }
        }

        // 写入日志
        $logEntry = sprintf("[%s] FILE:%s STORE:%s TOTAL:%d ITEMS:%d\n", date('Y-m-d H:i:s'), $fileName, $logStore, $logTotal, count($currentItems));
        file_put_contents($logFile, $logEntry, FILE_APPEND);

        // 存入数据库
        if (!empty($currentItems)) {
            $sqlR = "INSERT INTO receipts (file_name) OUTPUT INSERTED.id VALUES (?)";
            $stmtR = sqlsrv_query($conn, $sqlR, array($fileName));
            if ($stmtR && sqlsrv_fetch($stmtR)) {
                $newId = sqlsrv_get_field($stmtR, 0);
                $processedIds[] = $newId; 
                foreach ($currentItems as $it) {
                    $sqlI = "INSERT INTO receipt_items (receipt_id, item_name, price) VALUES (?, ?, ?)";
                    sqlsrv_query($conn, $sqlI, array($newId, $it['name'], $it['price']));
                }
            }
        }
    }
}

// --- 5. 获取结果显示 ---
$results = [];
$totalAllAmount = 0;
if (!empty($processedIds)) {
    $idList = implode(',', array_map('intval', $processedIds));
    $sqlMain = "SELECT id, file_name FROM receipts WHERE id IN ($idList)";
    $resMain = sqlsrv_query($conn, $sqlMain);
    if ($resMain) {
        while ($row = sqlsrv_fetch_array($resMain, SQLSRV_FETCH_ASSOC)) {
            $items = [];
            $sqlSub = "SELECT item_name as name, price FROM receipt_items WHERE receipt_id = ? ORDER BY id ASC";
            $resSub = sqlsrv_query($conn, $sqlSub, array($row['id']));
            while ($it = sqlsrv_fetch_array($resSub, SQLSRV_FETCH_ASSOC)) {
                $items[] = $it;
                $totalAllAmount += $it['price'];
            }
            $results[] = ['file' => $row['file_name'], 'items' => $items];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>レシート解析システム (稳定版)</title>
    <style>
        body { font-family: sans-serif; background: #f4f7f9; padding: 20px; }
        .box { max-width: 600px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .card { border-left: 4px solid #2ecc71; background: #fafafa; padding: 10px; margin-bottom: 10px; }
        .row { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px dashed #ddd; }
        .amount-big { font-size: 28px; font-weight: bold; color: #ff4d4f; text-align: center; }
        .btn-main { width: 100%; padding: 15px; background: #1890ff; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; }
        .nav-bar { margin-top: 20px; display: flex; gap: 10px; justify-content: center; }
        .nav-link { font-size: 12px; color: #666; text-decoration: none; border: 1px solid #ccc; padding: 5px 10px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">📜 レシート解析</h2>
        <form id="uploadForm" method="post" enctype="multipart/form-data">
            <input type="file" id="fileInput" name="receipts[]" multiple required style="width:100%; margin-bottom:15px;">
            <button type="submit" id="submitBtn" class="btn-main">解析を開始</button>
            <div id="status" style="display:none; text-align:center; margin-top:10px; color:#1890ff;">処理中...</div>
        </form>

        <?php if ($results): ?>
            <div style="margin-top:20px;">
                <?php foreach ($results as $res): ?>
                    <div class="card">
                        <small>📄 <?= htmlspecialchars($res['file']) ?></small>
                        <?php foreach ($res['items'] as $it): ?>
                            <div class="row">
                                <span><?= htmlspecialchars($it['name']) ?></span>
                                <span>¥<?= number_format($it['price']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <div class="amount-big">合計 ¥<?= number_format($totalAllAmount) ?></div>
            </div>
        <?php endif; ?>

        <div class="nav-bar">
            <a href="?action=csv" class="nav-link">CSV保存</a>
            <a href="?action=download_log" class="nav-link">ログ</a>
            <a href="?action=clear_view" class="nav-link">リセット</a>
        </div>
    </div>

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
            status.innerText = `处理中 (${i+1}/${files.length})...`;
            const compressed = await compressImg(files[i]);
            formData.append('receipts[]', compressed, files[i].name);
        }

        fetch('', { method: 'POST', body: formData })
        .then(r => r.text())
        .then(html => {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            document.body.innerHTML = doc.body.innerHTML;
        })
        .catch(() => { alert("错误"); btn.disabled = false; });
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
                    // 统一限制 1600px，确保所有电脑传给服务器的清晰度一致
                    if (w > 1600) { h = h * (1600/w); w = 1600; }
                    canvas.width = w; canvas.height = h;
                    const ctx = canvas.getContext('2d');
                    ctx.imageSmoothingEnabled = true;
                    ctx.imageSmoothingQuality = 'high';
                    ctx.drawImage(img, 0, 0, w, h);
                    canvas.toBlob(blob => resolve(new File([blob], file.name, {type:'image/jpeg'})), 'image/jpeg', 0.95);
                };
            };
        });
    }
    </script>
</body>
</html>
