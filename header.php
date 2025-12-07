<?php
/**
 * 頁面標頭檔案
 * 包含導航列和當前頁面高亮顯示
 */

// 取得當前頁面名稱
$current_page = basename($_SERVER['PHP_SELF']);

// 取得登入使用者
$current_user = getCurrentUser();

// 定義導航選單
$nav_items = [
    'index.php' => '使用者列表',
    'import.php' => '匯入與重整',
    'detail.php' => '弱掃結果',
    'sum.php' => '資料彙總',
    'check.php' => '關於'
];

echo <<<HTML
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Aduit 檢測管理系統</title>

<!-- 引用外部樣式表 -->
<link rel="stylesheet" href="style.css">

<!-- 引用外部 JavaScript -->
<script src="common.js"></script>
</head>

<body>
<div class="container">
<table border="0" class="nav-table no-print">
<tr>
HTML;

// 輸出導航選單項目
foreach ($nav_items as $page => $label) {
    $is_active = ($current_page === $page) ? ' nav-active' : '';
    echo '<th class="' . $is_active . '"><a href="' . $page . '">' . $label . '</a></th>';
}

// 登出連結（顯示使用者名稱）
$logout_text = '登出';
if ($current_user) {
    $logout_text .= ' (' . htmlspecialchars($current_user, ENT_QUOTES, 'UTF-8') . ')';
}
$is_active = ($current_page === 'logout.php') ? ' nav-active' : '';
echo '<th class="' . $is_active . '"><a href="logout.php">' . $logout_text . '</a></th>';

echo <<<HTML
</tr>
</table>
<hr class="no-print">
HTML;
?>
