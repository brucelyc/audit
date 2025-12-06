<?php
/**
 * 系統登出頁面
 * 功能：清除 Session、記錄日誌、重導向到登入頁
 */

require_once('config.php');

// 記錄登出動作
if (isAuthenticated()) {
    $username = getCurrentUser();
    logAction('使用者登出', "使用者: {$username}");
}

// 執行登出
logout();
?>
