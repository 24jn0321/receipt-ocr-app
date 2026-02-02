<?php
/**
 * 🧾 レシート解析システム - 完整デバッグ版
 * 目的：日志只有一句话时，通过详细记录定位问题。
 */

// --- 1. 設定と環境設定 ---
@set_time_limit(600);
@ini_set('memory_limit', '512M');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 
$logFile = 'ocr.log';

// 自定义日志函数，方便调试
function writeLog($msg) {
    global $logFile;
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n", FILE_APPEND);
}

// --- 2. Azure SQL 接続設定 ---
$serverName = "tcp:receipt-server-24jn0.database.windows.net,1433"; 
$connectionOptions = array(
    "Database" => "receiptdb",
    "Uid" => "sqladmin",
    "PWD" => "Abc842727925",
    "CharacterSet" => "UTF-8"
);

writeLog("--- システム起動 ---");
$conn = sqlsrv_connect($serverName, $connectionOptions);

if ($conn === false) {
    $errors = print_r(sqlsrv_errors(), true);
    writeLog("[FATAL ERROR] DB接続失敗: " . $errors);
    die("DB接続エラー。ログを確認してください。");
}

// --- 3. アクション処理 ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt_export_'.date('Ymd').'.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ファイル名', '品目', '金額', '処理日時']);
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
            header('Content-Disposition: attachment; filename="ocr_debug.log"');
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
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    writeLog("POSTリクエスト受信。FILES数: " . (isset($_FILES['receipts']) ? count($_FILES['receipts']['tmp_name']) : 0));

    if (isset($_FILES['receipts'])) {
        foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
            $fileName = $_FILES['receipts']['name'][$key];
            
            if (empty($tmpName)) {
                writeLog("[WARN] ファイル '$fileName' のテンポラリパスが空です。アップロード制限の可能性があります。");
                continue;
            }

            writeLog("解析開始: $fileName (Size: " . filesize($tmpName) . " bytes)");

            $imgData = file_get_contents($tmpName);
            $apiUrl = rtrim($endpoint, '/') . "/computervision/imageanalysis:analyze?api-version=2023-10-01&features=read";
            
            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/octet-stream', 
                'Ocp-Apim-Subscription-Key: ' . $apiKey
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode !== 200) {
                writeLog("[ERROR] Azure API失敗: HTTP $httpCode, Error: $curlError, Response: $response");
                continue; 
            }

            writeLog("Azure API 成功: $fileName");

            $data = json_decode($response, true);
            $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
            $currentItems = [];
            $stopFlag = false;

            for ($i = 0; $i < count($lines); $i++) {
                $text = trim($lines[$i]['text']);
                $pureText = str_replace([' ', '　', '＊', '*', '√', '軽', '轻', '(', ')', '8%', '10%'], '', $text);

                if (preg_match('/合計|合計金額|内消費税|消費税|対象|支払|残高|再発行/u', $pureText)) {
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
                            if (mb_strlen($cleanPrev) >= 2 && !preg_match('/領|収|証|合|計|%|店|電話|¥|￥/u', $cleanPrev)) {
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
                writeLog("DB保存中... アイテム数: " . count($currentItems));
                $sqlR = "INSERT INTO receipts (file_name) OUTPUT INSERTED.id VALUES (?)";
                $stmtR = sqlsrv_query($conn, $sqlR, array($fileName));
                if ($stmtR && sqlsrv_fetch($stmtR)) {
                    $newId = sqlsrv_get_field($stmtR, 0);
                    $processedIds[] = $newId; 
                    foreach ($currentItems as $it) {
                        $sqlI = "INSERT INTO receipt_items (receipt_id, item_name, price) VALUES (?, ?, ?)";
                        sqlsrv_query($conn, $sqlI, array($newId, $it['name'], $it['price']));
                    }
                } else {
                    writeLog("[ERROR] DB Insert失敗: " . print_r(sqlsrv_errors(), true));
                }
            } else {
                writeLog("[INFO] $fileName から商品が検出されませんでした。");
            }
        }
    }
}

// --- 5. 表示データの取得 ---
$results = [];
$totalAllAmount = 0;
if (!empty($processedIds)) {
    $idList = implode(',', $processedIds);
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
    <title>レシート解析システム (Debug Mode)</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 20px; }
        .box { max-width: 600px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .card { border-left: 4px solid #4caf50; background: #f9f9f9; padding: 15px; margin-bottom: 15px; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #ddd; }
        .btn-main { width: 100%; padding: 15px; background: #007bff; color: white; border: none; border-radius: 8px; cursor: pointer; }
        .nav-bar { margin-top: 25px; display: flex; justify-content: space-around; }
        .nav-link { font-size: 13px; color: #555; text-decoration: none; padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="text-align:center;">📜 レシート解析</h2>
        <form id="uploadForm" method="post" enctype="multipart/form-data">
            <input type="file" id="fileInput" name="receipts[]" multiple required style="margin-bottom:20px; width: 100%;">
            <button type="submit" id="submitBtn" class="btn-main">解析開始</button>
            <div id="status" style="display:none; text-align:center; margin-top:10px;">処理中...</div>
        </form>

        <?php if ($results): ?>
            <div style="margin-top:30px;">
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
                <div style="text-align:center; font-weight:bold; font-size:24px; color:red;">
                    合計: ¥<?= number_format($totalAllAmount) ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="nav-bar">
            <a href="?action=csv" class="nav-link">CSV出力</a>
            <a href="?action=download_log" class="nav-link">ログ(ocr.log)を見る</a>
            <a href="?action=clear_view" class="nav-link">表示をクリア</a>
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
            status.innerText = `圧縮中 (${i+1}/${files.length})...`;
            const compressed = await compressImg(files[i]);
            formData.append('receipts[]', compressed, files[i].name);
        }

        status.innerText = "解析中... ログを確認してください。";
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
                    if (w > 1200) { h = h * (1200/w); w = 1200; }
                    canvas.width = w; canvas.height = h;
                    canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                    canvas.toBlob(blob => resolve(new File([blob], file.name, {type:'image/jpeg'})), 'image/jpeg', 0.85);
                };
            };
        });
    }
    </script>
</body>
</html>
