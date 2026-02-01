// --- 4. OCR 核心解析逻辑 ---
$processedIds = []; 
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['receipts'])) {
    file_put_contents($logFile, "\n[" . date('Y-m-d H:i:s') . "] --- 开始識別任務 ---\n", FILE_APPEND);
    
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

        if ($httpCode !== 200) {
            file_put_contents($logFile, "  [ERROR] $fileName 请求失敗: HTTP $httpCode\n", FILE_APPEND);
            continue; 
        }

        $data = json_decode($response, true);
        $lines = $data['readResult']['blocks'][0]['lines'] ?? [];
        $currentItems = [];
        $stopFlag = false;

        for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            // 修改点 1：移除 '◎'，确保关键词判定时包含它
            $pureText = str_replace([' ', '　', '＊', '*', '√', '軽', '轻', '(', ')', '8%', '10%'], '', $text);

            if (preg_match('/合計|合计|内消費税|消費税|対象|支払|残高|再発行/u', $pureText)) {
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            if ($stopFlag) continue;

            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                $nameInLine = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                // 修改点 2：从这里也移除了 '◎'，保证提取出的商品名包含该符号
                $cleanNameInLine = str_replace(['＊', '*', '轻', '軽', '(', ')', '.', '．', ' '], '', $nameInLine);

                if (mb_strlen($cleanNameInLine) < 2 || preg_match('/^[¥￥\d,\s]+$/u', $cleanNameInLine)) {
                    $foundName = "";
                    for ($j = $i - 1; $j >= 0; $j--) {
                        $prev = trim($lines[$j]['text']);
                        // 修改点 3：向上查找商品名时也保留 '◎'
                        $cleanPrev = str_replace(['＊', '*', ' ', '√', '軽', '轻'], '', $prev);
                        if (mb_strlen($cleanPrev) >= 2 && !preg_match('/領|収|証|合|计|計|%|店|电话|電話|¥|￥/u', $cleanPrev)) {
                            $foundName = $cleanPrev; break;
                        }
                    }
                    $finalName = $foundName;
                } else {
                    $finalName = $cleanNameInLine;
                }

                if (!empty($finalName) && !preg_match('/Family|新宿|電話|登録|領収|対象|消費税|合計|内訳/u', $finalName)) {
                    $isDuplicate = false;
                    foreach ($currentItems as $existing) {
                        if ($existing['name'] === $finalName && $existing['price'] === $price) {
                            $isDuplicate = true; break;
                        }
                    }
                    if (!$isDuplicate) {
                        $currentItems[] = ['name' => $finalName, 'price' => $price];
                    }
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
