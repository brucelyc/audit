<?php
/**
 * 系統資訊與檢查頁面
 * 功能：系統需求檢查、PHP 環境資訊、安裝指引
 */

require_once('config.php');
requireAuth();

$start_time = microtime(true);

include('header.php');

// ==================== 系統需求定義 ====================
$requirements = [
    [
        'name' => 'PHP MySQL 擴充套件 (mysqli)',
        'check' => extension_loaded('mysqli'),
        'description' => '用於資料庫連線操作',
        'critical' => true
    ],
    [
        'name' => 'PHP 多位元組字串處理 (mbstring)',
        'check' => extension_loaded('mbstring'),
        'description' => '用於中文編碼轉換',
        'critical' => true
    ],
    [
        'name' => 'PHP XML 擴充套件',
        'check' => extension_loaded('xml') && function_exists('simplexml_load_file'),
        'description' => '用於解析 Nessus .nessus 檔案',
        'critical' => true
    ],
    [
        'name' => 'PHP 檔案上傳功能',
        'check' => ini_get('file_uploads') == 1,
        'description' => '允許上傳 CSV 和 Nessus 檔案',
        'critical' => true
    ],
    [
        'name' => 'PHP OpenSSL 擴充套件',
        'check' => extension_loaded('openssl'),
        'description' => '用於安全連線和加密功能',
        'critical' => false
    ]
];

// ==================== 取得系統資訊 ====================
$php_version = sanitizeString(phpversion());
$upload_max = sanitizeString(ini_get('upload_max_filesize'));
$post_max = sanitizeString(ini_get('post_max_size'));
$max_execution = sanitizeString(ini_get('max_execution_time'));
$memory_limit = sanitizeString(ini_get('memory_limit'));

// ==================== 檢查上傳大小 ====================
$upload_size_mb = (int)$upload_max;
$post_size_mb = (int)$post_max;
$upload_warning = '';

if ($upload_size_mb < 20 || $post_size_mb < 20) {
    $upload_warning = showAlert(
        '<strong>⚠️ 建議調整：</strong>您的 PHP 上傳檔案大小限制可能不足。<br>' .
        '建議將 upload_max_filesize 和 post_max_size 調整為至少 20M，以確保能順利上傳較大的 Nessus 掃描報告。<br>' .
        '<a href="#upload-config" style="color: #856404; text-decoration: underline;">查看調整說明 ↓</a>',
        'warning'
    );
}

// ==================== PHP 環境資訊 ====================
echo '<h1>系統資訊</h1>';

echo '<div class="info-card">';
echo '<h3 class="section-subtitle">PHP 環境</h3>';
echo '<table class="info-table">';

$env_info = [
    'PHP 版本' => $php_version,
    '最大上傳檔案大小' => $upload_max,
    'POST 最大容量' => $post_max,
    '記憶體限制' => $memory_limit,
    '最大執行時間' => $max_execution . ' 秒'
];

foreach ($env_info as $label => $value) {
    echo '<tr>';
    echo '<td class="info-label"><strong>' . sanitizeString($label) . '</strong></td>';
    echo '<td>' . $value . '</td>';
    echo '</tr>';
}

echo '</table>';
echo '</div>';

// 顯示上傳警告
if ($upload_warning) {
    echo $upload_warning;
}

// ==================== 系統需求檢查 ====================
echo '<h1>系統需求檢查</h1>';
echo '<table>';
echo '<thead>';
echo '<tr>';
echo '<th class="text-center" style="width: 10%;">狀態</th>';
echo '<th style="width: 35%;">模組名稱</th>';
echo '<th style="width: 45%;">說明</th>';
echo '<th class="text-center" style="width: 10%;">必要性</th>';
echo '</tr>';
echo '</thead>';
echo '<tbody>';

$all_critical_passed = true;

foreach ($requirements as $req) {
    $name = sanitizeString($req['name']);
    $description = sanitizeString($req['description']);
    
    if ($req['check']) {
        $status = '<span class="status-icon-success">✓</span>';
        $status_text = getRiskBadge('None') . ' 已安裝';
    } else {
        $status = '<span class="status-icon-error">✗</span>';
        $status_text = getRiskBadge('Critical') . ' 未安裝';
        if ($req['critical']) {
            $all_critical_passed = false;
        }
    }
    
    $critical_badge = $req['critical'] 
        ? getRiskBadge('Critical') . ' 必要'
        : '<span class="badge badge-secondary">建議</span>';
    
    echo '<tr>';
    echo '<td class="text-center">' . $status . '</td>';
    echo '<td><strong>' . $name . '</strong><br>' . $status_text . '</td>';
    echo '<td class="text-muted">' . $description . '</td>';
    echo '<td class="text-center">' . $critical_badge . '</td>';
    echo '</tr>';
}

echo '</tbody>';
echo '</table>';

// 顯示檢查結果
if (!$all_critical_passed) {
    echo showAlert(
        '<strong>⚠️ 注意：</strong>部分必要模組尚未安裝，系統功能可能受限。<br>' .
        '請聯絡系統管理員安裝缺少的 PHP 擴充套件。',
        'warning'
    );
} else {
    echo showAlert(
        '<strong>✓ 系統就緒：</strong>所有必要模組已正確安裝，系統可正常運作。',
        'success'
    );
}

// ==================== PHP 上傳設定說明 ====================
echo '<h1 id="upload-config">PHP 上傳檔案大小調整 (建議 20M)</h1>';

echo '<div class="config-section">';
echo '<h3 class="config-title">步驟 1: 修改 php.ini 設定檔</h3>';
echo '<p class="text-muted">找到並編輯 php.ini 檔案（通常位於 <code>/etc/php/8.x/apache2/php.ini</code> 或 <code>/etc/php.ini</code>）</p>';

echo '<pre class="code-block">找到以下參數並修改：
upload_max_filesize = 20M
post_max_size = 25M
max_execution_time = 300
max_input_time = 300
memory_limit = 256M</pre>';

echo '<div class="tip-box">';
echo '<strong>💡 提示：</strong>';
echo '<ul>';
echo '<li><code>post_max_size</code> 應該略大於 <code>upload_max_filesize</code></li>';
echo '<li><code>memory_limit</code> 應該大於 <code>post_max_size</code></li>';
echo '<li>增加 <code>max_execution_time</code> 以處理大檔案</li>';
echo '</ul>';
echo '</div>';
echo '</div>';

// ==================== 重啟伺服器說明 ====================
echo '<div class="config-section">';
echo '<h3 class="config-title">步驟 2: 重新啟動網頁伺服器</h3>';

echo '<h4 class="subsection-title">Apache 伺服器</h4>';
echo '<pre class="code-block"># Ubuntu/Debian
sudo systemctl restart apache2

# CentOS/RHEL
sudo systemctl restart httpd</pre>';

echo '<h4 class="subsection-title mt-2">Nginx + PHP-FPM 伺服器</h4>';
echo '<p class="text-muted">Nginx 本身也需要調整上傳大小限制：</p>';

echo '<p class="text-muted mt-1"><strong>2.1 修改 Nginx 設定</strong></p>';
echo '<p class="text-muted">編輯 <code>/etc/nginx/nginx.conf</code> 或網站設定檔 <code>/etc/nginx/sites-available/your-site</code></p>';

echo '<pre class="code-block">http {
    # 在 http 區塊中加入：
    client_max_body_size 20M;
    
    # 或在 server 區塊中加入：
    server {
        client_max_body_size 20M;
        ...
    }
}</pre>';

echo '<p class="text-muted mt-1"><strong>2.2 修改 PHP-FPM 設定</strong></p>';
echo '<p class="text-muted">編輯 <code>/etc/php/8.x/fpm/php.ini</code></p>';

echo '<pre class="code-block">upload_max_filesize = 20M
post_max_size = 25M
max_execution_time = 300
max_input_time = 300
memory_limit = 256M</pre>';

echo '<p class="text-muted mt-1"><strong>2.3 重新啟動服務</strong></p>';
echo '<pre class="code-block"># 重新載入 Nginx 設定
sudo nginx -t  # 先測試設定檔語法
sudo systemctl reload nginx

# 重新啟動 PHP-FPM
sudo systemctl restart php8.1-fpm  # 根據您的 PHP 版本調整</pre>';

echo '<div class="warning-box">';
echo '<strong>⚠️ 注意：</strong>PHP 版本號碼請依照您的實際環境調整（如 7.4, 8.0, 8.1, 8.2 等）';
echo '</div>';
echo '</div>';

// ==================== 驗證設定 ====================
echo '<div class="config-section config-verify">';
echo '<h3 class="config-title">步驟 3: 驗證設定</h3>';
echo '<p>完成設定後，請重新整理本頁面檢查「最大上傳檔案大小」是否已更新為 20M。</p>';
echo '<button onclick="location.reload()" class="btn btn-primary">';
echo '🔄 重新整理頁面';
echo '</button>';
echo '</div>';

// ==================== 安裝指引 ====================
echo '<h1>安裝指引</h1>';

echo '<div class="info-card">';
echo '<h3 class="section-subtitle">Ubuntu/Debian 系統</h3>';
echo '<pre class="code-block">sudo apt update
sudo apt install php-mysql php-mbstring php-xml php-curl
sudo systemctl restart apache2

# 如果使用 Nginx + PHP-FPM
sudo systemctl restart php8.1-fpm nginx</pre>';

echo '<h3 class="section-subtitle mt-2">CentOS/RHEL 系統</h3>';
echo '<pre class="code-block">sudo yum install php-mysql php-mbstring php-xml php-curl
sudo systemctl restart httpd

# 如果使用 Nginx + PHP-FPM
sudo systemctl restart php-fpm nginx</pre>';

echo '<h3 class="section-subtitle mt-2">查找 php.ini 位置</h3>';
echo '<pre class="code-block"># 方法 1: 使用 PHP 命令
php --ini

# 方法 2: 查看 phpinfo
php -r "phpinfo();" | grep "Loaded Configuration File"

# 方法 3: 建立 phpinfo 檔案
echo "&lt;?php phpinfo(); ?&gt;" | sudo tee /var/www/html/info.php
# 然後瀏覽 http://your-server/info.php (使用完請刪除此檔案！)</pre>';
echo '</div>';

// ==================== 關於系統 ====================
echo '<h1>關於系統</h1>';

echo '<div class="info-card">';
echo '<h3 class="section-subtitle">Aduit 檢測管理系統系統</h3>';
echo '<table class="info-table">';

$system_info = [
    '版本' => 'v2.0',
    '最後更新' => '2025年12月',
    '主要功能' => '• 使用者電腦清單管理<br>' .
                  '• Nessus 報告解析與匯入<br>' .
                  '• 弱點資料彙整與統計<br>' .
                  '• 多維度風險分析報表'
];

foreach ($system_info as $label => $value) {
    echo '<tr>';
    echo '<td class="info-label"><strong>' . sanitizeString($label) . '</strong></td>';
    echo '<td>' . $value . '</td>';
    echo '</tr>';
}

echo '</table>';
echo '</div>';

// ==================== 安全建議 ====================
echo '<h1>安全建議</h1>';

$security_tips = [
    '修改預設密碼' => '請務必在 <code>config.php</code> 中設定強密碼',
    '啟用 HTTPS' => '建議使用 SSL/TLS 加密連線保護資料傳輸',
    '限制存取權限' => '設定防火牆規則，僅允許授權 IP 存取',
    '定期備份' => '定期備份資料庫，防止資料遺失',
    '保持更新' => '定期更新 PHP、MySQL 和系統套件',
    '刪除 phpinfo' => '如果建立了 info.php 測試檔案，請務必刪除',
    '檢查日誌' => '定期檢查系統和應用程式日誌，監控異常活動'
];

echo showAlert(
    '<h3 style="margin-top: 0;">重要安全提示</h3>' .
    '<ul>' .
    implode('', array_map(function($key, $value) {
        return '<li><strong>' . sanitizeString($key) . '：</strong>' . $value . '</li>';
    }, array_keys($security_tips), $security_tips)) .
    '</ul>',
    'danger'
);

// ==================== 更新日誌 ====================
echo '<h1>更新日誌</h1>';

echo '<div class="info-card">';
echo '<h4 class="text-primary">v2.0 (2025-12)</h4>';
echo '<ul class="text-muted">';
$v20_features = [
    '新增 CSRF 防護機制',
    '強化 XSS 防護和輸出編碼',
    '改用 Prepared Statement 防止 SQL 注入',
    '強化安全性，所有輸出都經過 sanitizeString 處理',
    '新增輸入驗證白名單機制',
    '增強錯誤處理和日誌記錄',
    '優化視覺介面與風險標籤顯示',
    '改進資料聚合與排序演算法',
    '新增直接上傳 .nessus 檔案功能',
    '優化檔案上傳驗證機制',
    '新增 Critical 風險等級統計',
    '新增系統資訊檢查頁面',
    '重構共用函式，提升程式碼可維護性',
    '統一各頁面顯示風格'
];
foreach ($v20_features as $feature) {
    echo '<li>' . sanitizeString($feature) . '</li>';
}
echo '</ul>';

echo '<h4 class="text-muted mt-2">v1.3 (舊版)</h4>';
echo '<ul class="text-muted">';
echo '<li>基礎弱點掃描資料管理</li>';
echo '<li>CSV 檔案匯入匯出</li>';
echo '<li>簡易統計報表</li>';
echo '</ul>';
echo '</div>';

// ==================== 技術支援 ====================
echo '<h1>技術支援</h1>';

$support_items = [
    '確認所有必要模組已安裝',
    '檢查 PHP 錯誤日誌：<code>/var/log/php_errors.log</code>',
    '檢查網頁伺服器日誌：<code>/var/log/apache2/error.log</code> 或 <code>/var/log/nginx/error.log</code>',
    '確認資料庫連線設定正確',
    '驗證檔案和目錄權限設定'
];

echo showAlert(
    '<h3 style="margin-top: 0;">需要協助？</h3>' .
    '<p>如果您在安裝或使用過程中遇到問題，請檢查以下資源：</p>' .
    '<ul>' .
    implode('', array_map(function($item) {
        return '<li>' . $item . '</li>';
    }, $support_items)) .
    '</ul>',
    'info'
);

// ==================== 頁面執行時間 ====================
renderExecutionTime($start_time);

logAction('查看系統資訊頁面');
?>

</div>
</body>
</html>
