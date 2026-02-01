<?php
// ... (保留您原有的 OCR 处理逻辑、API 密钥和数据读取部分，代码省略以节省篇幅) ...
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>小票解析</title>

<style>
:root {
    --bg:#f8fafc;
    --card:#fff;
    --text:#0f172a;
    --sub:#64748b;
    --border:#e5e7eb;
}
body {
    margin:0;
    padding:48px 16px;
    font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;
    background:var(--bg);
    color:var(--text);
}
.app {
    max-width:520px;
    margin:auto;
    background:var(--card);
    border-radius:16px;
    padding:40px 32px 48px;
    box-shadow:0 10px 30px rgba(0,0,0,.06);
}
header h1 {
    font-size:26px;
    font-weight:600;
    margin:0 0 36px;
}

/* --- 核心修复部分 --- */
.upload {
    display: block;           /* 改为块级元素，使其撑满宽度 */
    box-sizing: border-box;    /* 确保 padding 不撑破宽度 */
    width: 100%;              /* 宽度与按钮一致 */
    border: 1.5px dashed var(--border);
    border-radius: 14px;
    padding: 32px 20px;
    text-align: center;
    cursor: pointer;
    transition: .25s;
    margin-bottom: 8px;       /* 调整与按钮的间距 */
}
/* ------------------- */

.upload:hover {
    background:#f8fafc;
    border-color:#c7d2fe;
}
.upload-main {
    font-size:15px;
    font-weight:500;
}
.upload-sub {
    margin-top:6px;
    font-size:13px;
    color:var(--sub);
}

.btn {
    width:100%;
    margin-top:14px;
    padding:15px;
    border-radius:10px;
    border:none;
    background:#0f172a;
    color:#fff;
    font-size:15px;
    font-weight: 500;
    cursor:pointer;
}
#status {
    margin-top:14px;
    text-align:center;
    font-size:13px;
    color:#2563eb;
    height: 18px;
}

/* Items */
.item {
    display:flex;
    justify-content:space-between;
    padding:16px 0;
    border-bottom:1px solid #f1f5f9;
}
.price { font-family:monospace; font-weight: 500; }

/* Summary */
.total {
    margin-top:40px;
    padding-top:22px;
    border-top:1px solid var(--border);
    display:flex;
    justify-content:space-between;
    align-items: center;
}
.total strong {
    font-size:30px;
    font-weight:600;
}

/* Footer */
.footer {
    margin-top:44px;
    text-align:center;
}
.footer a {
    font-size:13px;
    color:var(--sub);
    margin:0 12px;
    text-decoration:none;
}
</style>
</head>

<body>
<div class="app">
    <header>
        <h1>小票解析</h1>
    </header>

    <form id="f" method="post" enctype="multipart/form-data">
        <label class="upload">
            <input type="file" id="i" name="receipts[]" multiple hidden>
            <div class="upload-main">选择小票图片</div>
            <div class="upload-sub">点击选择或拖拽上传</div>
        </label>
        <button class="btn">开始解析</button>
        <div id="status"></div>
    </form>

    <?php if ($results): ?>
    <div style="margin-top: 20px;">
        <?php foreach ($results as $r): ?>
            <?php foreach ($r['items'] as $i): ?>
            <div class="item">
                <span><?= htmlspecialchars($i['name']) ?></span>
                <span class="price">¥<?= number_format($i['price']) ?></span>
            </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>

    <div class="total">
        <span>合计</span>
        <strong>¥<?= number_format($totalAllAmount) ?></strong>
    </div>
    <?php endif; ?>

    <div class="footer">
        <a href="?action=csv">CSV 导出</a>
        <a href="?action=log">运行日志</a>
    </div>
</div>

<script>
// ... (保留您原有的异步提交脚本) ...
</script>
</body>
</html>
