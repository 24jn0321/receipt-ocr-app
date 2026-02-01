<?php
/**
 * 🧾 小票解析系统 - Azure SQL データベース統合版（クリーン表示仕様）
 */

// --- 1. サーバー制限の解除 ---
@set_time_limit(0);
@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', 0);

// Azure OCR API 設定
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 
$logFile = 'ocr.log';

// --- 2. Azure SQL 接続設定 ---
$serverName = "tcp:receipt-server-24jn0321.database.windows.net,1433"; 
$connectionOptions = array(
    "Database" => "receiptdb",
    "Uid" => "sqladmin",
    "PWD" => "Abc842727925",
    "CharacterSet" => "UTF-8"
);

$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    die("DB接続エラー: " . print_r(sqlsrv_errors(), true));
}

// --- 3. アクション処理 (CSV/全削除) ---
if (isset($_GET['action'])) {
    if ($_GET['action'] == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_export_'.date('Ymd').'.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['文件名', '项目', '金额']);
        $sql = "SELECT r.file_name, i.item_name, i.price FROM receipts r JOIN receipt_items i ON r.id = i.receipt_id";
        $stmt = sqlsrv_query($conn, $sql);
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            fputcsv($output, [$row['file_name'], $row['item_name'], $row['price']]);
        }
        fclose($output); exit;
    }
    if ($_GET['action'] == 'clear') {
        sqlsrv_query($conn, "DELETE FROM receipts");
        header("Location: index.php"); exit;
    }
}

// --- 4. OCR 解析 & DB 保存 ---
$newInsertedIds = []; // 今回アップロードしたIDを保持
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        if ($key > 0) sleep(2); // 連続リクエストによるAPI制限回避

        $fileName = $_FILES['receipts']['name'][$key];
        $imgData = file_get_contents($tmpName);
        $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
        
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
            $currentItems = [];
            $stopFlag = false;

            for ($i = 0; $i < count($lines); $i++) {
                $text = trim($lines[$i]['text']);
                if (preg_match('/合計|合计|消費税/u', $text)) { $stopFlag = true; continue; }
                if ($stopFlag) continue;

                if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                    $price = (int)str_replace(',', '', $matches[1]);
                    $name = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                    if (mb_strlen($name) >= 2) {
                        $currentItems[] = ['name' => $name, 'price' => $price];
                    }
                }
            }

            if (!empty($currentItems)) {
                $sqlR = "INSERT INTO receipts (file_name) OUTPUT INSERTED.id VALUES (?)";
                $stmtR = sqlsrv_query($conn, $sqlR, array($fileName));
                if ($stmtR && sqlsrv_fetch($stmtR)) {
                    $receiptId = sqlsrv_get_field($stmtR, 0);
                    $newInsertedIds[] = $receiptId; // 今回挿入したIDを記録
                    foreach ($currentItems as $it) {
                        $sqlI = "INSERT INTO receipt_items (receipt_id, item_name, price) VALUES (?, ?, ?)";
                        sqlsrv_query($conn, $sqlI, array($receiptId, $it['name'], $it['price']));
                    }
                }
            }
        }
    }
}

// --- 5. 表示データの取得 ---
$results = [];
$totalAllAmount = 0;

// 条件：POST直後（今回の結果）または 履歴表示ボタンが押された時のみ取得
if (!empty($newInsertedIds) || isset($_GET['view'])) {
    // 履歴表示なら全件、POST直後なら今回のIDのみ
    $whereClause = !empty($newInsertedIds) ? "WHERE id IN (" . implode(',', $newInsertedIds) . ")" : "";
    $sqlMain = "SELECT id, file_name FROM receipts $whereClause ORDER BY id DESC";
    
    $resMain = sqlsrv_query($conn, $sqlMain);
    if ($resMain) {
        while ($row = sqlsrv_fetch_array($resMain, SQLSRV_FETCH_ASSOC)) {
            $items = [];
            $resSub = sqlsrv_query($conn, "SELECT item_name as name, price FROM receipt_items WHERE receipt_id = ?", array($row['id']));
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
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>小票解析汇总</title>
    <style>
        body { font-family: 'PingFang SC', sans-serif; background: #f4f7f9; padding: 20px; color: #333; }
        .box { max-width: 600px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.05); }
        .card { border-left: 4px solid #2ecc71; background: #fafafa; padding: 15px; margin-bottom: 15px; border-radius: 6px; }
        .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #eee; font-size: 14px; }
        .grand-total { margin-top: 25px; padding: 20px; background: #fff5f5; border: 1px solid #ffccc7; border-radius: 10px; text-align: center; }
        .amount-big { font-size: 32px; font-weight: bold; color: #ff4d4f; }
        .btn-main { width: 100%; padding: 15px; background: #1890ff; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; }
        .nav-bar { margin-top: 25px; display: flex; justify-content: space-around; border-top: 1px solid #eee; padding-top: 15px; }
        .nav-link { font-size: 12px; color: #666; text-decoration: none; padding: 6px 10px; border: 1px solid #ddd; border-radius: 4px; }
        #status { color: #1890ff; text-align: center; margin: 10px 0; font-size: 13px; font-weight: bold; }
        .empty-state { text-align: center; color: #ccc; margin: 40px 0; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">📜 小票解析汇总</h2>
        
        <form id="uploadForm" method="post" enctype="multipart/form-data">
            <input type="file" id="fileInput" name="receipts[]" multiple required style="margin-bottom:20px; width: 100%;">
            <button type="submit" id="submitBtn" class="btn-main">開始解析 (多枚対応)</button>
            <div id="status" style="display:none;"></div>
        </form>

        <?php if (!empty($results)): ?>
            <div style="margin-top:30px;">
                <h3 style="font-size:16px; color:#1890ff;"><?= isset($_GET['view']) ? '📜 履歴表示' : '✨ 今回の結果' ?></h3>
                <?php foreach ($results as $res): ?>
                    <div class="card">
                        <small style="color:#aaa;">📄 <?= htmlspecialchars($res['file']) ?></small>
                        <?php foreach ($res['items'] as $it): ?>
                            <div class="row">
                                <span><?= htmlspecialchars($it['name']) ?></span>
                                <span>¥<?= number_format($it['price']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <div class="grand-total">
                    <div>合計金額</div>
                    <div class="amount-big">¥<?= number_format($totalAllAmount) ?></div>
                </div>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <p>画像をアップロードしてください。<br>結果がここに表示されます。</p>
            </div>
        <?php endif; ?>

        <div class="nav-bar">
            <a href="?view=1" class="nav-link">📜 履歴を見る</a>
            <a href="?action=csv" class="nav-link">📥 CSV出力</a>
            <a href="?action=clear" class="nav-link" style="color:#ff4d4f; border-color:#ffccc7;" onclick="return confirm('全履歴を消去しますか？')">🗑️ リセット</a>
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
            status.innerText = `画像を圧縮中 (${i+1}/${files.length})...`;
            const compressed = await compressImg(files[i]);
            formData.append('receipts[]', compressed, files[i].name);
        }

        status.innerText = "Azureで解析中... しばらくお待ちください。";
        
        fetch('', { method: 'POST', body: formData })
        .then(r => r.text())
        .then(html => {
            document.body.innerHTML = new DOMParser().parseFromString(html, 'text/html').body.innerHTML;
        })
        .catch(err => {
            alert("エラーが発生しました。");
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
                    if (w > 1200) { h *= 1200/w; w = 1200; }
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
