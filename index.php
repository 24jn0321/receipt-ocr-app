for ($i = 0; $i < count($lines); $i++) {
            $text = trim($lines[$i]['text']);
            $noSpace = str_replace([' ', '　', '=', '-', '_', '＊', '*', '◎'], '', $text);

            // 1. 核心防御：进入结算区立即停止（防止抓到最后的卡余额）
            if (preg_match('/合计|合計|支付|支払|残高|番号|カード/u', $noSpace)) {
                if (!empty($currentItems)) $stopFlag = true; 
                continue; 
            }
            if ($stopFlag) continue;

            // 2. 识别带 ¥ 的金额行
            if (preg_match('/[¥￥]([\d,]+)/u', $text, $matches)) {
                $price = (int)str_replace(',', '', $matches[1]);
                
                // 排除单纯的税费行（如：8%对象 ¥198 中的“对象”行）
                if (preg_match('/对象|対象|消費税/u', $noSpace)) continue;

                // 3. 提取项目名称
                // 先看本行 ¥ 前面有没有名字
                $name = trim(preg_replace('/[\.．…]+|[¥￥].*$/u', '', $text));
                
                // 【关键修复】：如果本行没名字，或者名字是“对象/税”，则循环向上找
                if (mb_strlen($name) < 2 || preg_match('/对象|対象/u', $name)) {
                    for ($back = 1; $back <= 3; $back++) { // 最多向上找3行
                        if (isset($lines[$i - $back])) {
                            $prevText = trim($lines[$i - $back]['text']);
                            // 如果这一行不是“对象”、“税”或者“领收证”，那它就是商品名
                            if (!preg_match('/对象|対象|消費税|領収|领收|Family|新宿|电话/u', $prevText) && mb_strlen($prevText) > 1) {
                                $name = $prevText;
                                break;
                            }
                        }
                    }
                }

                // 清洗名字并存入
                $cleanName = str_replace(['＊', '*', '轻', '軽', '◎', '(', '（', ')', '）', '.', '．', '…'], '', $name);
                
                if (mb_strlen($cleanName) >= 2) {
                    $currentItems[] = ['name' => $cleanName, 'price' => $price];
                    $sumAmount += $price;
                }
            }
        }
