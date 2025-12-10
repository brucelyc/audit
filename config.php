<?php
/**
 * Aduit 檢測管理系統 - 設定檔
 * 包含資料庫連線、安全設定、認證功能、共用函數
 */

// ==================== 錯誤處理設定 ====================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// ==================== Session 安全設定 ====================
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 0);  // HTTPS 環境請設為 1
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime', 3600);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_lifetime', 0);

// 防止 Session Fixation - 在 session_start 前設定
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==================== 資料庫設定 ====================
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'audit');
define('DB_CHARSET', 'utf8mb4');

// ==================== 認證設定 ====================
$AP_USER = 'admin';
$AP_PASS = 'admin';

// ==================== 安全常數 ====================
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15分鐘
define('SESSION_LIFETIME', 3600); // 1小時

// ==================== 時區設定 ====================
date_default_timezone_set('Asia/Taipei');

// ==================== 資料庫連線函數 ====================
function getDBConnection() {
    static $link = null;
    
    // 使用單例模式避免重複連線
    if ($link !== null && $link->ping()) {
        return $link;
    }
    
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $link = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $link->set_charset(DB_CHARSET);
        
        // 設定 SQL Mode 提高安全性
        $link->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE'");
        
        return $link;
    } catch (Exception $e) {
        error_log("Database connection failed: " . $e->getMessage());
        die("<div class=\"alert alert-danger\">系統暫時無法使用，請稍後再試</div>");
    }
}

// ==================== CSRF Token 防護 ====================
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    
    // Token 超過 1 小時則重新生成
    if (time() - $_SESSION['csrf_token_time'] > 3600) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
        return false;
    }
    
    // 檢查 Token 是否過期
    if (time() - $_SESSION['csrf_token_time'] > 3600) {
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

// ==================== 登入嘗試限制 ====================
function checkLoginAttempts($username) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'login_attempts_' . md5($ip . $username);
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'time' => time()];
    }
    
    $attempts = &$_SESSION[$key];
    
    // 如果鎖定時間已過，重置計數
    if (time() - $attempts['time'] > LOGIN_LOCKOUT_TIME) {
        $attempts = ['count' => 0, 'time' => time()];
    }
    
    return $attempts['count'];
}

function recordLoginAttempt($username, $success = false) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'login_attempts_' . md5($ip . $username);
    
    if ($success) {
        // 登入成功，清除記錄
        unset($_SESSION[$key]);
    } else {
        // 登入失敗，增加計數
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = ['count' => 0, 'time' => time()];
        }
        $_SESSION[$key]['count']++;
        $_SESSION[$key]['time'] = time();
    }
}

function isLoginLocked($username) {
    return checkLoginAttempts($username) >= MAX_LOGIN_ATTEMPTS;
}

// ==================== 認證功能 ====================
function requireAuth() {
    if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        $current_page = basename($_SERVER['PHP_SELF']);
        header('Location: login.php?redirect=' . urlencode($current_page));
        exit;
    }
    
    // 檢查 Session 是否過期
    if (isset($_SESSION['login_time'])) {
        if (time() - $_SESSION['login_time'] > SESSION_LIFETIME) {
            session_unset();
            session_destroy();
            session_start();
            header('Location: login.php?timeout=1&redirect=' . urlencode(basename($_SERVER['PHP_SELF'])));
            exit;
        }
    }
    
    // 更新最後活動時間
    $_SESSION['last_activity'] = time();
    
    // Session Regeneration - 每 30 分鐘更新一次 Session ID
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

function logout() {
    $_SESSION = array();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

function isAuthenticated() {
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

function getCurrentUser() {
    return $_SESSION['username'] ?? null;
}

// ==================== 輸入驗證函數 ====================
function validateIP($ip) {
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

function validateInt($value, $min = null, $max = null) {
    $int = filter_var($value, FILTER_VALIDATE_INT);
    if ($int === false) return false;
    if ($min !== null && $int < $min) return false;
    if ($max !== null && $int > $max) return false;
    return $int;
}

/*
function sanitizeString($str) {
    return htmlspecialchars(trim($str), ENT_COMPAT, 'UTF-8');
}
*/

// 用於輸入清理（只做 trim）
function sanitizeString($str) {
    return trim($str);
}

// 用於輸出顯示（做 HTML 編碼）
function sanitizeOutput($str) {
    return htmlspecialchars($str, ENT_COMPAT, 'UTF-8');
}

function validateFileUpload($file, $allowed_extensions = ['nessus', 'xml'], $max_size = 52428800) {
    if (!isset($file['error']) || is_array($file['error'])) {
        return '檔案上傳錯誤';
    }
    
    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return '檔案大小超過限制';
        case UPLOAD_ERR_NO_FILE:
            return '未選擇檔案';
        default:
            return '未知的檔案上傳錯誤';
    }
    
    if ($file['size'] > $max_size) {
        return '檔案大小超過 ' . ($max_size / 1024 / 1024) . ' MB';
    }
    
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_extension, $allowed_extensions)) {
        return '不支援的檔案格式，僅支援: ' . implode(', ', $allowed_extensions);
    }
    
    // 額外的檔名安全檢查
    if (preg_match('/[^a-zA-Z0-9_\-\.]/', basename($file['name']))) {
        error_log("Suspicious filename detected: " . $file['name']);
    }
    
    return true;
}

// ==================== 安全標頭 ====================
function setSecurityHeaders() {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\';');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

setSecurityHeaders();

// ==================== Rate Limiting ====================
function checkRateLimit($action, $limit = 10, $window = 60) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'rate_limit_' . $action . '_' . md5($ip);
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'time' => time()];
    }
    
    $rate = &$_SESSION[$key];
    
    // 如果時間窗口已過，重置計數
    if (time() - $rate['time'] > $window) {
        $rate = ['count' => 0, 'time' => time()];
    }
    
    $rate['count']++;
    
    return $rate['count'] <= $limit;
}

// ==================== 日誌函數 ====================
function logAction($action, $details = '') {
    $log_dir = __DIR__ . '/logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $log_file = $log_dir . '/system.log';
    $timestamp = date('Y-m-d H:i:s');
    $user = getCurrentUser() ?? 'guest';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    $log_message = "[{$timestamp}] [{$user}@{$ip}] {$action}";
    if ($details) {
        $log_message .= " - {$details}";
    }
    $log_message .= "\n";
    
    file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
}

// ==================== 工具函數 ====================
function safeRedirect($url) {
    if (preg_match('/^[a-zA-Z0-9_\-\.]+\.php(\?.*)?$/', $url)) {
        header('Location: ' . $url);
        exit;
    }
    header('Location: index.php');
    exit;
}

function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}

function getExecutionTime($start_time) {
    $end_time = microtime(true);
    $execution_time = $end_time - $start_time;
    return number_format($execution_time, 4);
}

// ==================== UI 共用函數 ====================
/**
 * 生成風險等級 Badge HTML
 */
function getRiskBadge($risk) {
    $valid_risks = ['Critical', 'High', 'Medium', 'Low', 'None'];
    if (!in_array($risk, $valid_risks)) {
        $risk = 'None';
    }
    
    $risk_clean = htmlspecialchars($risk, ENT_QUOTES, 'UTF-8');
    $risk_class = 'risk-' . $risk_clean;
    return '<span class="badge ' . $risk_class . '">' . $risk_clean . '</span>';
}

/**
 * 顯示提示訊息
 */
function showAlert($message, $type = "success") {
    $allowed_types = ['success', 'danger', 'warning', 'info', 'primary'];
    $type = in_array($type, $allowed_types) ? $type : 'info';
    $type_class = "alert-{$type}";
    return "<div class=\"alert {$type_class}\">{$message}</div>";
}

/**
 * 輸出 CSV 檔案
 */
function outputCSV($filename, $content) {
    // 清除所有輸出緩衝
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // 檔名安全處理
    $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
    
    header("Content-Type: text/csv; charset=Big5");
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header("Pragma: no-cache");
    header("Expires: 0");
    header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
    
    echo $content;
    exit;
}

/**
 * 聚合資料庫資料（用於 sum.php）
 */
function aggregateData($link) {
    $stmt = $link->prepare("
        SELECT d.*, COALESCE(c.Unit, '未分類') as Unit
        FROM Detail d
        LEFT JOIN Computer c ON d.Host = c.Host
        ORDER BY d.Host, d.Port, d.Name
    ");
    
    if (!$stmt) {
        error_log("SQL prepare failed: " . $link->error);
        return [];
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    $processed = [];
    
    while ($row = $result->fetch_assoc()) {
        $host = $row['Host'];
        $risk = $row['Risk'];
        $port = $row['Port'];
        $name = $row['Name'];
        $protocol = $row['Protocol'] ?? 'tcp';
        
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            error_log("Invalid IP address in database: " . $host);
            continue;
        }
        
        $valid_risks = ['Critical', 'High', 'Medium', 'Low', 'None'];
        if (!in_array($risk, $valid_risks)) {
            error_log("Invalid risk level: " . $risk);
            continue;
        }
        
        $key = "{$host}|{$risk}|{$port}|{$name}";
        if (isset($processed[$key])) continue;
        $processed[$key] = true;
        
        if (!isset($data[$host])) {
            $data[$host] = [
                'Unit' => $row['Unit'],
                'Risk' => [],
                'Port' => [],
                'CSUM' => 0,
                'HSUM' => 0,
                'MSUM' => 0
            ];
        }
        
        if (!isset($data[$host]['Risk'][$risk])) {
            $data[$host]['Risk'][$risk] = [];
        }
        if (!isset($data[$host]['Risk'][$risk][$port])) {
            $data[$host]['Risk'][$risk][$port] = [];
        }
        
        $data[$host]['Risk'][$risk][$port][$name] = [
            'Protocol' => $protocol
        ];
        
        $port_key = $protocol . '/' . $port;
        $data[$host]['Port'][$port_key] = 1;
        
        $risk_lower = strtolower($risk);
        if ($risk_lower === 'critical') {
            $data[$host]['CSUM']++;
        } elseif ($risk_lower === 'high') {
            $data[$host]['HSUM']++;
        } elseif ($risk_lower === 'medium') {
            $data[$host]['MSUM']++;
        }
    }
    
    $stmt->close();
    return $data;
}

// ==================== 資料庫操作共用函數 ====================
/**
 * 取得資料表記錄總數
 */
function getTableCount($link, $table) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $result = $link->query("SELECT COUNT(*) as count FROM {$table}");
    if ($result) {
        $row = $result->fetch_assoc();
        return intval($row['count']);
    }
    return 0;
}

/**
 * 檢查資料表是否有資料
 */
function hasTableData($link, $table) {
    return getTableCount($link, $table) > 0;
}

/**
 * 批次刪除資料（使用 ID 陣列）
 */
function batchDeleteByIds($link, $table, $ids) {
    if (empty($ids)) return 0;
    
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $deleted_count = 0;
    $stmt = $link->prepare("DELETE FROM {$table} WHERE ID = ?");
    
    if (!$stmt) {
        error_log("Failed to prepare statement: " . $link->error);
        return 0;
    }
    
    foreach ($ids as $id => $value) {
        $validated_id = validateInt($id, 1);
        if ($validated_id !== false) {
            $stmt->bind_param('i', $validated_id);
            if ($stmt->execute()) {
                $deleted_count++;
            }
        }
    }
    
    $stmt->close();
    return $deleted_count;
}

/**
 * 刪除資料表所有資料
 */
function truncateTable($link, $table) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $total = getTableCount($link, $table);
    if ($total > 0) {
        $link->query("DELETE FROM {$table}");
        return $total;
    }
    return 0;
}

// ==================== 表格渲染共用函數 ====================
/**
 * 渲染空狀態提示
 */
function renderEmptyState($colspan, $message, $linkUrl = null, $linkText = null) {
    echo '<tr>';
    echo '<td colspan="' . intval($colspan) . '" class="empty-state">';
    echo '<div class="empty-state-content">';
    echo '<p class="text-muted">' . sanitizeString($message) . '</p>';
    if ($linkUrl && $linkText) {
        echo '<a href="' . htmlspecialchars($linkUrl, ENT_QUOTES, 'UTF-8') . '" class="btn btn-primary">' . sanitizeString($linkText) . '</a>';
    }
    echo '</div>';
    echo '</td>';
    echo '</tr>';
}

/**
 * 渲染表格標頭
 */
function renderTableHeader($headers) {
    echo '<thead><tr>';
    foreach ($headers as $header) {
        $width = $header['width'] ?? '';
        $center = $header['center'] ?? false;
        $class = trim($width . ($center ? ' text-center' : ''));
        echo '<th class="' . sanitizeString($class) . '">' . sanitizeString($header['text']) . '</th>';
    }
    echo '</tr></thead>';
}

/**
 * 渲染表格頁尾（總計列）
 */
function renderTableFooter($colspan, $label, $count, $center = true) {
    echo '<tfoot><tr>';
    echo '<td colspan="' . intval($colspan) . '" class="table-footer' . ($center ? ' text-center' : ' text-right') . '">';
    echo sanitizeString($label) . ' <span class="total-count">' . intval($count) . '</span>';
    echo '</td>';
    echo '</tr></tfoot>';
}

// ==================== 統計相關共用函數 ====================
/**
 * 統計風險等級分布
 */
function countRiskLevels($link, $host = null) {
    $risk_stats = ['Critical' => 0, 'High' => 0, 'Medium' => 0, 'Low' => 0, 'None' => 0];
    
    if ($host && validateIP($host)) {
        $stmt = $link->prepare("SELECT Risk, COUNT(*) as count FROM Detail WHERE Host = ? GROUP BY Risk");
        $stmt->bind_param('s', $host);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $link->query("SELECT Risk, COUNT(*) as count FROM Detail GROUP BY Risk");
    }
    
    while ($row = $result->fetch_assoc()) {
        if (isset($risk_stats[$row['Risk']])) {
            $risk_stats[$row['Risk']] = intval($row['count']);
        }
    }
    
    if (isset($stmt)) {
        $stmt->close();
    }
    
    return $risk_stats;
}

/**
 * 取得受影響主機數量
 */
function getAffectedHostCount($link, $host = null) {
    if ($host && validateIP($host)) {
        $stmt = $link->prepare("SELECT COUNT(DISTINCT Host) as count FROM Detail WHERE Host = ?");
        $stmt->bind_param('s', $host);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = intval($result->fetch_assoc()['count']);
        $stmt->close();
        return $count;
    } else {
        $result = $link->query("SELECT COUNT(DISTINCT Host) as count FROM Detail");
        return intval($result->fetch_assoc()['count']);
    }
}

/**
 * 渲染風險統計卡片
 */
function renderRiskStatistics($risk_stats, $host_count, $label = '筆弱點') {
    echo '<div class="stats-card mt-3">';
    echo '<h3 class="section-title">風險統計</h3>';
    echo '<div class="stats-grid">';
    
    foreach ($risk_stats as $risk => $count) {
        if ($count > 0) {
            echo '<div class="stat-box">';
            echo '<div class="stat-badge">' . getRiskBadge($risk) . '</div>';
            echo '<div class="stat-number">' . intval($count) . '</div>';
            echo '<div class="stat-label">' . sanitizeString($label) . '</div>';
            echo '</div>';
        }
    }
    
    echo '</div>';
    echo '<div class="stat-footer">';
    echo '<strong>受影響裝置數:</strong> ';
    echo '<span class="affected-hosts-count">' . intval($host_count) . '</span> 台';
    echo '</div>';
    echo '</div>';
}

/**
 * 渲染頁面執行時間
 */
function renderExecutionTime($start_time) {
    $execution_time = getExecutionTime($start_time);
    echo '<p class="text-muted text-small page-execution-time">頁面執行時間: ' . $execution_time . ' 秒</p>';
}
?>
