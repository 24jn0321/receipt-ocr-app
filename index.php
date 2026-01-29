/* =====================
    3. メイン処理
   ===================== */
$displayItems = [];
$totalAmount = 0;

if (!empty($_FILES['images']['tmp_name'][0])) {
    file_put_contents("ocr.log", ""); 

    foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
        $name = basename($_FILES['images']['name'][$i]);
        $path = $uploadDir . $name;
        
        if (move_uploaded_file($tmp, $path)) {
            $opUrl = analyzeImage($path, $endpoint, $key);
            $ocr = getResult($opUrl, $key);
            
            file_put_contents("ocr.log", "--- FILE: $name ---\n" . json_encode($ocr, JSON_UNESCAPED_UNICODE) . "\n\n", FILE_APPEND);

            if ($ocr && isset($ocr['analyzeResult']['readResults'])) {
                foreach ($ocr['analyzeResult']['readResults'] as $page) {
                    foreach ($page['lines'] as $line) {
                        $text = $line['text'];

                        // --- 核心优化：改进正则表达式 ---
                        // 匹配：商品名 + (可选空格) + ¥(可选) + 数字 + (可选"轻"字或符号)
                        if (preg_match('/^(.+?)[ \t　]*[¥￥]?([0-9,]{2,7})[ \t　]*(軽|轻|.*)?$/u', $text, $m)) {
                            
                            $pName = trim($m[1]);
                            // 清理前缀符号
                            $pName = preg_replace('/^[◎*＊]\s*/u', '', $pName); 
                            $price = (int)str_replace(',', '', $m[2]);

                            // 过滤不需要显示的行
                            $exclude = [
                                '合計', '合計', '小計', '対象', '預り', 'お釣', '現金', 
                                '消費税', '再発行', '残高', '番号', 'No.', 'レジ'
                            ];
                            
                            $isSkip = false;
                            foreach ($exclude as $w) { 
                                if (mb_strpos($pName, $w) !== false) {
                                    $isSkip = true;
                                    break;
                                }
                            }

                            if (!$isSkip && $price > 0) {
                                $displayItems[] = ['name' => $pName, 'price' => $price];
                                $totalAmount += $price;
                                
                                // 写入数据库
                                try {
                                    $stmt = $conn->prepare("INSERT INTO receipts (image_name, product_name, price) VALUES (?, ?, ?)");
                                    $stmt->execute([$name, $pName, $price]);
                                } catch (Exception $e) {
                                    // 数据库记录失败不影响显示
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    // ... 保存 CSV 的逻辑保持不变 ...
}
