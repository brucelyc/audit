<?php
/**
 * 系統登入頁面
 * 功能:使用者認證、Session 管理、安全防護
 */

require_once('config.php');

// 如果已經登入,重導向到首頁
if (isAuthenticated()) {
    header('Location: index.php');
    exit;
}

$error_message = '';
$show_timeout_message = false;

// 檢查是否因為 Session 過期而被導向
if (isset($_GET['timeout']) && $_GET['timeout'] == '1') {
    $show_timeout_message = true;
}

// 處理登入請求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate Limiting - 防止暴力破解
    if (!checkRateLimit('login', 10, 60)) {
        $error_message = '登入嘗試次數過多，請稍後再試';
        logAction('登入失敗 - Rate Limit', "來自 IP: {$_SERVER['REMOTE_ADDR']}");
        sleep(3);
    }
    // CSRF Token 驗證
    elseif (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error_message = '安全驗證失敗，請重新操作';
        logAction('登入失敗 - CSRF', "來自 IP: {$_SERVER['REMOTE_ADDR']}");
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        // 基本輸入驗證
        if (empty($username) || empty($password)) {
            $error_message = '請輸入帳號和密碼';
        }
        // 檢查是否被鎖定
        elseif (isLoginLocked($username)) {
            $error_message = '帳號已被暫時鎖定，請 15 分鐘後再試';
            logAction('登入失敗 - 帳號鎖定', "帳號: {$username}, IP: {$_SERVER['REMOTE_ADDR']}");
            sleep(3);
        }
        // 驗證帳號密碼
        elseif ($username === $AP_USER && $password === $AP_PASS) {
            // 登入成功
            $_SESSION['authenticated'] = true;
            $_SESSION['username'] = $username;
            $_SESSION['login_time'] = time();
            $_SESSION['last_regeneration'] = time();
            $_SESSION['login_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            
            // 記錄成功登入
            recordLoginAttempt($username, true);
            
            // 重新生成 Session ID 防止 Session Fixation
            session_regenerate_id(true);
            
            // 記錄登入日誌
            logAction('使用者登入成功', "來自 IP: {$_SERVER['REMOTE_ADDR']}");
            
            // 重導向到原本要訪問的頁面,或首頁
            $redirect = $_GET['redirect'] ?? 'index.php';
            // 防止開放重導向漏洞
            if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.php$/', $redirect)) {
                $redirect = 'index.php';
            }
            header('Location: ' . $redirect);
            exit;
        } else {
            // 登入失敗
            recordLoginAttempt($username, false);
            $remaining_attempts = MAX_LOGIN_ATTEMPTS - checkLoginAttempts($username);
            
            if ($remaining_attempts > 0) {
                $error_message = "帳號或密碼錯誤 (剩餘 {$remaining_attempts} 次嘗試機會)";
            } else {
                $error_message = '登入失敗次數過多，帳號已被暫時鎖定 15 分鐘';
            }
            
            error_log("Failed login attempt: {$username} from IP: {$_SERVER['REMOTE_ADDR']}");
            logAction('登入失敗', "帳號: {$username}, IP: {$_SERVER['REMOTE_ADDR']}");
            
            // 延遲響應,防止暴力破解
            sleep(2);
        }
    }
}

$csrf_token = generateCSRFToken();
$redirect_param = isset($_GET['redirect']) ? '?redirect=' . urlencode($_GET['redirect']) : '';
$username_value = isset($_POST['username']) ? sanitizeString($_POST['username']) : '';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系統登入 - Nessus 弱點管理系統</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-header">
            <h1>🔒 系統登入</h1>
            <p>Nessus 弱點管理系統</p>
        </div>
        
        <div class="login-body">
            <?php if ($show_timeout_message): ?>
                <?php echo showAlert('您的登入已逾時，請重新登入', 'warning'); ?>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <?php echo showAlert($error_message, 'danger'); ?>
            <?php endif; ?>
            
            <form method="POST" action="login.php<?php echo $redirect_param; ?>" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                
                <div class="form-group">
                    <label for="username">帳號</label>
                    <div class="input-icon" data-icon="👤">
                        <input 
                            type="text" 
                            id="username" 
                            name="username" 
                            placeholder="請輸入帳號" 
                            required 
                            autocomplete="off"
                            autofocus
                            maxlength="50"
                            value="<?php echo htmlspecialchars($username_value, ENT_QUOTES, 'UTF-8'); ?>"
                        >
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">密碼</label>
                    <div class="input-icon" data-icon="🔑">
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            placeholder="請輸入密碼" 
                            required 
                            autocomplete="off"
                            maxlength="100"
                        >
                    </div>
                </div>
                
                <button type="submit" class="btn btn-login">登入系統</button>
            </form>
        </div>
        
        <div class="login-footer">
            <p>© 2025 Nessus 弱點管理系統 | 請妥善保管您的帳號密碼</p>
        </div>
    </div>
</body>
</html>
