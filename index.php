<?php
// 请确保你的 Endpoint 对应 Document Intelligence 的地址
$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey   = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37";

$results = [];

// CSV 下载逻辑
if (isset($_GET['dl']) && file_exists('data.json')) {
    $data = json_decode(file_get_contents('data.json'), true);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename=receipt.csv');
    echo "\xEF\xBB\xBF"; 
    $f = fopen('php://output', 'w');
    fputcsv($f, ['文件', '商品', '金额']);
    foreach($data as $r) {
        foreach($r['items'] as $it) fputcsv($f, [$r['file'], $it['name'], $it['price']]);
        fputcsv($f, [$r['file'], '合计', $r['total']]);
    }
    fclose($f); exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        
        // 注意：API 路径变更为 documentintelligence
        $apiUrl = rtrim($endpoint, '/') . "/documentintelligence/documentModels/prebuilt-receipt:analyze?api-version=2023-10-31-preview";

        $imgData = file_get_contents($tmpName);
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/octet-stream',
            'Ocp-Apim-Subscription-Key: ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true); // 需要获取 Operation-Location
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $headerSize);
        curl_close($ch);

        // 获取异步查询地址
        if (preg_match('/Operation-Location: (.*)/i', $headers, $matches)) {
            $resultUrl = trim($matches[1]);
            // 轮询等待结果 (简单起见，这里循环几次)
            for ($i = 0; $i < 5; $i++) {
                sleep(1);
                $ch = curl_init($resultUrl);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Ocp-Apim-Subscription-Key: ' . $apiKey]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $resJson = curl_exec($ch);
                curl_close($ch);
                $data = json_decode($resJson, true);
                if ($data['status'] == 'succeeded') break;
            }

            // 解析 DI 返回的结构化数据
            $receipt = $data['analyzeResult']['documents'][0]['fields'] ?? [];
            $items = [];
            if (isset($receipt['Items']['valueArray'])) {
                foreach ($receipt['Items']['valueArray'] as $val) {
                    $item = $val['valueObject'];
                    $items[] = [
                        'name'  => $item['Description']['valueString'] ?? '未知商品',
                        'price' => $item['TotalPrice']['valueCurrency']['amount'] ?? 0
                    ];
                }
            }
            $total = $receipt['Total']['valueCurrency']['amount'] ?? 0;
            $results[] = ['file' => $_FILES['receipts']['name'][$key], 'items' => $items, 'total' => $total];
        }
    }
    file_put_contents('data.json', json_encode($results));
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <title>DI 高级解析版</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 500px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        .card { border-left: 5px solid #007bff; background: #f8f9fa; padding: 15px; margin-top: 15px; border-radius: 4px; }
        .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #ccc; }
        .total { text-align: right; color: #d32f2f; font-size: 24px; font-weight: bold; margin-top: 10px; }
        .btn { width: 100%; padding: 12px; background: #007bff; color: white; border: none; border-radius: 6px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="container">
        <h2 style="text-align:center;">🧾 Document Intelligence 版</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="receipts[]" multiple><br><br>
            <button type="submit" class="btn">开始智能识别</button>
        </form>

        <?php foreach ($results as $res): ?>
            <div class="card">
                <div style="font-size:12px; color:#666;"><?= htmlspecialchars($res['file']) ?></div>
                <?php foreach ($res['items'] as $it): ?>
                    <div class="row">
                        <span><?= htmlspecialchars($it['name']) ?></span>
                        <span>¥<?= number_format($it['price']) ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="total">合计 ¥<?= number_format($res['total']) ?></div>
            </div>
        <?php endforeach; ?>

        <?php if($results): ?>
            <p style="text-align:center; margin-top:20px;"><a href="?dl=csv">📥 下载 CSV 报表</a></p>
        <?php endif; ?>
    </div>
</body>
</html>
