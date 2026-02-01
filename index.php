<?php
/**
 * 🧾 小票解析系统 - 最终修复版
 * 修改说明：
 * 1. 保留名字前的 ◎ 符号。
 * 2. 增加“彻底重置数据库”功能（重置 ID 为 1）。
 * 3. 修复外键约束导致的无法重置问题。
 */

// --- 1. 配置与環境設置 ---
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

// --- 3. 动作处理 ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_export_'.date('Ymd').'.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', '文件名', '项目', '金额', '日期']);
        $sql = "SELECT r.id, r.file_name, r.processed_at, i.item_name, i.price FROM receipts r JOIN receipt_items i ON r.id = i.receipt_id";
        $stmt = sqlsrv_query($conn, $sql);
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            fputcsv($output, [$row['id'], $row['file_name'], $row['item_name'], $row['price'], $row['processed_at']->format('Y-m-d H:i:s')]);
        }
        fclose($output); exit;
    }

    if ($action == 'clear_view') {
        header("Location: " . strtok($_SERVER["PHP_SELF"], '?')); 
        exit;
    }

    // --- 彻底清空并重置 ID 为 1 ---
    if ($action == 'db_reset') {
        sqlsrv_query($conn, "DELETE FROM receipt_items");
        sqlsrv_query($conn, "DELETE FROM receipts");
        sqlsrv_query($conn, "DBCC CHECKIDENT ('receipts', RESEED, 0)");
        sqlsrv_query($conn, "DBCC CHECKIDENT ('receipt_items', RESEED, 0)");
        header("Location: " . strtok($_SERVER["PHP_SELF"], '?')); 
        exit;
    }
}

// --- 4. OCR 核心解析逻辑 ---
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
        curl_close($ch);

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        $currentItems = [];
        $stopFlag = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            // 【修改点】此处不再删除 ◎，以便进行行匹配
            $pureText = str_replace([' ', '　', '＊', '*', '√', '軽', '轻', '(', ')', '8%', '10%'], '', $text);

            if (preg_match('/合計|合计|内消費税|消費税|対象|支払|残高|再発行/u', $pureText)) {
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            if ($stopFlag) continue;

            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                $nameInLine = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                // 【修改点】cleanNameInLine 不再剔除 ◎，保留在商品名中
                $cleanNameInLine = str_replace(['＊', '*', '轻', '軽', '(', ')', '.', '．', ' '], '', $nameInLine);

                if (mb_strlen($cleanNameInLine) < 2 || preg_match('/^[¥￥\d,\s◎]+$/u', $cleanNameInLine)) {
                    $foundName = "";
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prev = trim($lines[$j]['text']);
                        $cleanPrev = str_replace(['＊', '*', ' ', '√', '軽', '轻'], '', $prev); // 这里也不删 ◎
                        if (mb_strlen($cleanPrev) >= 2 && !preg_match('/領|収|証|合|计|計|%|店|电话|電話|¥|￥/u', $cleanPrev)) {
                            $foundName = $cleanPrev; break;
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
    $idList = implode(',', $processedIds);
    $resMain = sqlsrv_query($conn, "SELECT id, file_name FROM receipts WHERE id IN ($idList)");
    while ($row = sqlsrv_fetch_array($resMain, SQLSRV_FETCH_ASSOC)) {
        $items = [];
        $resSub = sqlsrv_query($conn, "SELECT item_name as name, price FROM receipt_items WHERE receipt_id = ?", array($row['id']));
        while ($it = sqlsrv_fetch_array($resSub, SQLSRV_FETCH_ASSOC)) {
            $items[] = $it;
            $totalAllAmount += $it['price'];
        }
        $results[] = ['id' => $row['id'], 'file' => $row['file_name'], 'items' => $items];
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>小票解析系统</title>
    <style>
        body { font-family: 'PingFang SC', sans-serif; background: #f4f7f9; padding: 20px; }
        .box { max-width: 600px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .card { border-left: 4px solid #2ecc71; background: #fafafa; padding: 12px; margin-bottom: 10px; }
        .row { display: flex; justify-content: space-between; font-size: 14px; padding: 4px 0; border-bottom: 1px dashed #ddd; }
        .grand-total { margin-top: 20px; padding: 15px; background: #fff5f5; text-align: center; border-radius: 8px; }
        .btn-main { width: 100%; padding: 12px; background: #1890ff; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .nav-bar { margin-top: 20px; display: flex; justify-content: space-between; font-size: 12px; }
        .nav-link { text-decoration: none; color: #666; padding: 5px 8px; border: 1px solid #ccc; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">📜 小票解析汇总</h2>
        <form id="uploadForm" method="post" enctype="multipart/form-data">
            <input type="file" id="fileInput" name="receipts[]" multiple required style="width:100%; margin-bottom:15px;">
            <button type="submit" id="submitBtn" class="btn-main">开始解析并存入DB</button>
            <div id="status" style="display:none; text-align:center; color:#1890ff; margin-top:10px;">处理中...</div>
        </form>

        <?php if ($results): ?>
            <div style="margin-top:20px;">
                <?php foreach ($results as $res): ?>
                    <div class="card">
                        <div style="font-weight:bold; color:#888; margin-bottom:5px;">ID: <?= $res['id'] ?> | 📄 <?= htmlspecialchars($res['file']) ?></div>
                        <?php foreach ($res['items'] as $it): ?>
                            <div class="row">
                                <span><?= htmlspecialchars($it['name']) ?></span>
                                <span>¥<?= number_format($it['price']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <div class="grand-total">
                    <div style="color:#666;">本次解析总额</div>
                    <div style="font-size:28px; color:#ff4d4f; font-weight:bold;">¥<?= number_format($totalAllAmount) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="nav-bar">
            <a href="?action=csv" class="nav-link">📥 导出 CSV</a>
            <a href="?action=clear_view" class="nav-link">🔄 清空页面</a>
            <a href="?action=db_reset" class="nav-link" style="color:red;" onclick="return confirm('警告：这将删除数据库所有数据并将 ID 重置为 1！')">🗑️ 彻底重置 DB</a>
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
            status.innerText = `正在处理 (${i+1}/${files.length})...`;
            formData.append('receipts[]', files[i]); // 这里简化了，如果需要压缩可保留原来的压缩逻辑
        }
        fetch('', { method: 'POST', body: formData })
        .then(r => r.text())
        .then(html => {
            document.body.innerHTML = new DOMParser().parseFromString(html, 'text/html').body.innerHTML;
        });
    };
    </script>
</body>
</html>
