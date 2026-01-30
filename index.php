<?php
// 开启错误提示（调试用，正式上线请关闭）
ini_set('display_errors', 1);
error_reporting(E_ALL);

$endpoint = "https://24jn0321.cognitiveservices.azure.com/"; 
$apiKey = "BQGkM056pMBAB5KVI6wmcSLBf2JlF8X2UUiwxw5N17K9QmWljMG3JQQJ99CAACi0881XJ3w3AAAFACOGrT37"; 

$results = [];

// 确保目录可写
$storageFile = 'ocr_data.json';

// 下载处理
if (isset($_GET['action']) && $_GET['action'] == 'csv') {
    if (file_exists($storageFile)) {
        $sessionData = json_decode(file_get_contents($storageFile), true);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=receipt.csv');
        echo "\xEF\xBB\xBF"; 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['文件名', '项目', '金额']);
        foreach ($sessionData as $res) {
            foreach ($res['items'] as $it) fputcsv($output, [$res['file'], $it['name'], $it['price']]);
            fputcsv($output, [$res['file'], '合计', $res['total']]);
        }
        fclose($output); 
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    foreach ($_FILES['receipts']['tmp_name'] as $key => $tmpName) {
        if (empty($tmpName)) continue;
        
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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 跳过SSL验证预防本地环境报错
        
        $response = curl_exec($ch);
        if(curl_errno($ch)) {
            echo 'Curl error: ' . curl_error($ch);
        }
        curl_close($ch);

        $data = json_decode($response, true);
        // ... 后续逻辑保持不变 ...
        // 注意：确保这里的解析逻辑匹配 Azure 返回的 JSON 结构
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        
        // (你的识别逻辑在此处继续)
        // ...
        $results[] = ['file' => $_FILES['receipts']['name'][$key], 'items' => $currentItems ?? [], 'total' => $sumAmount ?? 0];
    }
    file_put_contents($storageFile, json_encode($results));
}
?>
