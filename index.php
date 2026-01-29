<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ===== 1. 配置 ===== */
$endpoint = "https://24jn0321.cognitiveservices.azure.com/";
$key      = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT3";
$serverName = "receipt-server-24jn0.database.windows.net";
$database   = "receiptdb";
$username   = "sqladmin";
$password   = "Abc842727925";

$uploadDir = "uploads/";
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

/* ===== DB 连接 ===== */
try {
    $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("数据库连接失败");
}

/* ===== 2. OCR 函数 ===== */
function analyzeImage($image, $endpoint, $key) {
    $url = rtrim($endpoint, '/') . "/vision/v3.2/read/analyze";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Ocp-Apim-Subscription-Key: $key",
            "Content-Type: application/octet-stream"
        ],
        CURLOPT_POSTFIELDS => file_get_contents($image),
        CURLOPT_HEADER => true,
        CURLOPT_RETURNTRANSFER => true
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    preg_match('/Operation-Location:\s*(.*)/i', $res, $m);
    return $m[1] ?? null;
}

function getResult($url, $key) {
    for ($i = 0; $i < 12; $i++) {
        sleep(2);
        $ch = curl_init(trim($url));
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ["Ocp-Apim-Subscription-Key: $key"],
            CURLOPT_RETURNTRANSFER => true
        ]);
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (($res['status'] ?? '') === 'succeeded') {
            return $res;
        }
    }
    return null;
}

/* ===== 3. 业务处理 ===== */
$displayItems = [];
$totalAmount = 0;
$processed = false;

if (!empty($_FILES['images']['tmp_name'][0])) {
    $processed = true;
    file_put_contents("ocr.log", "");

    foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
        $name = basename($_FILES['images']['name'][$i]);
        $path = $uploadDir . $name;

        if (!move_uploaded_file($tmp, $path)) continue;

        $ocr = getResult(analyzeImage($path, $endpoint, $key), $key);
        file_put_contents(
            "ocr.log",
            "FILE: $name\n" . json_encode($ocr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n",
            FILE_APPEND
        );

        if (!$ocr || empty($ocr['analyzeResult']['readResults'])) continue;

        /* ===== 关键修复点：遍历所有 page ===== */
        foreach ($ocr['analyzeResult']['readResults'] as $page) {
            foreach ($page['lines'] as $line) {

                $text = trim($line['text']);

                /* 排除无关行 */
                if (preg_match('/合計|小計|消費税|支払|残高|レジ|番号|責No|対象/u', $text)) {
                    continue;
                }

                /* 去掉杂质字符（轻/税/* 等） */
                $clean = preg_replace('/[軽轻＊\*税込内]/u', '', $text);
                $clean = trim($clean);

                /* 商品名 + 金额（严格） */
                if (preg_match('/^(.+?)\s+[¥￥]?\s*([0-9,]{2,5})$/u', $clean, $m)) {

                    $name  = trim($m[1]);
                    $price = (int)str_replace(',', '', $m[2]);

                    if ($price < 50 || $price > 10000) continue;

                    $displayItems[] = [
                        'name'  => $name,
                        'price' => $price
                    ];
                    $totalAmount += $price;

                    try {
                        $stmt = $conn->prepare(
                            "INSERT INTO receipts (image_name, product_name, price)
                             VALUES (?, ?, ?)"
                        );
                        $stmt->execute([$name, $name, $price]);
                    } catch (Exception $e) {}
                }
            }
        }
    }

    /* ===== CSV ===== */
    if ($displayItems) {
        $h = fopen('result.csv', 'w');
        fprintf($h, chr(0xEF).chr(0xBB).chr(0xBF));
        foreach ($displayItems as $it) {
            fputcsv($h, [$it['name'], $it['price']]);
        }
        fputcsv($h, ['合计', $totalAmount]);
        fclose($h);
    }
}
?>
