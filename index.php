<?php

/* =====================

   1. 配置

   ===================== */

$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 

$key      = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 

$uploadDir = "uploads/";



if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);



// 数据库连接

$serverName = "receipt-server-24jn0.database.windows.net";

$database   = "receiptdb";

$username   = "sqladmin"; 

$password   = "Abc842727925";



try {

    $conn = new PDO("sqlsrv:server=$serverName;Database=$database", $username, $password);

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (Exception $e) {

    die("数据库连接失败: " . $e->getMessage());

}



/* =====================

   2. 功能函数

   ===================== */

function analyzeImage($image, $endpoint, $key) {

    // 使用最新的 v3.2 版本路径

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



    preg_match('/Operation-Location: (.*)/i', $res, $m);

    return isset($m[1]) ? trim($m[1]) : null;

}



function getResult($url, $key) {

    $max_attempts = 10;

    $attempt = 0;

    do {

        sleep(1);

        $ch = curl_init(trim($url));

        curl_setopt_array($ch, [

            CURLOPT_HTTPHEADER => ["Ocp-Apim-Subscription-Key: $key"],

            CURLOPT_RETURNTRANSFER => true

        ]);

        $response = curl_exec($ch);

        $res = json_decode($response, true);

        curl_close($ch);

        $attempt++;

    } while (isset($res['status']) && $res['status'] !== 'succeeded' && $attempt < $max_attempts);



    return $res;

}

?>



<!DOCTYPE html>

<html lang="zh-CN">

<head>

    <meta charset="UTF-8">

    <title>全家收据识别系统</title>

    <style>

        body { font-family: sans-serif; margin: 20px; line-height: 1.6; }

        .result-box { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; border-radius: 8px; margin-top: 20px; }

        .log-box { font-size: 12px; color: #666; background: #eee; padding: 10px; overflow-x: auto; }

    </style>

</head>

<body>

    <h2>FamilyMart 收据识别 (强化版)</h2>

    <form method="post" enctype="multipart/form-data">

        <input type="file" name="images[]" multiple required>

        <button type="submit">开始上传并识别</button>

    </form>



<?php

if (!empty($_FILES['images']['tmp_name'][0])) {

    $items = [];

    $total = 0;



    foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {

        $name = basename($_FILES['images']['name'][$i]);

        $path = $uploadDir . $name;

        

        if (move_uploaded_file($tmp, $path)) {

            $opUrl = analyzeImage($path, $endpoint, $key);

            if (!$opUrl) {

                echo "<p style='color:red;'>API 请求发送失败，请检查 Key 或 Endpoint。</p>";

                continue;

            }

            

            $ocr = getResult($opUrl, $key);

            // 保存原始日志以便排查

            file_put_contents("ocr.log", json_encode($ocr, JSON_UNESCAPED_UNICODE));



            if (isset($ocr['analyzeResult']['readResults'])) {

                foreach ($ocr['analyzeResult']['readResults'] as $page) {

                    foreach ($page['lines'] as $line) {

                        $text = $line['text'];

                        

                        // 强化正则：匹配 [商品名] + [价格数字]

                        // 甚至允许中间有各种奇怪的空格

                        if (preg_match('/^(.+?)[\s　]+[¥￥]?(\d+)/u', $text, $m)) {

                            $pName = trim($m[1]);

                            $price = (int)$m[2];



                            // 排除常见的非商品关键词

                            $exclude = ['合计', '合計', '小計', '小计', '対象', '軽', '再発行', '番号'];

                            $shouldExclude = false;

                            foreach ($exclude as $word) {

                                if (strpos($pName, $word) !== false) { $shouldExclude = true; break; }

                            }



                            if (!$shouldExclude && $price > 0) {

                                $items[] = [$pName, $price];

                                $total += $price;



                                // 插入数据库

                                try {

                                    $stmt = $conn->prepare("INSERT INTO receipts (image_name, product_name, price) VALUES (?, ?, ?)");

                                    $stmt->execute([$name, $pName, $price]);

                                } catch (Exception $dbE) { /* 忽略重复插入错误 */ }

                            }

                        }

                    }

                }

            }

        }

    }



    echo "<div class='result-box'><h3>识别结果</h3>";

    if (empty($items)) {

        echo "<p>未能识别出商品。请确认收据清晰且包含价格。</p>";

    } else {

        foreach ($items as $item) {

            echo htmlspecialchars($item[0]) . " - <strong>¥" . number_format($item[1]) . "</strong><br>";

        }

        echo "<h4>总计金额: ¥" . number_format($total) . "</h4>";

    }

    echo "<hr><a href='ocr.log' target='_blank'>点此查看原始 OCR JSON 日志</a></div>";

}

?>

</body>

</html> 
