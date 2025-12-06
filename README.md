# Aduit 檢測管理系統 - 安裝指南

## 系統需求

### 支援的作業系統
- Debian 10/11/12
- Ubuntu 20.04/22.04/24.04
- Kali Linux 2023.x/2024.x

### 最低硬體需求
- CPU: 1 核心
- RAM: 1GB
- 硬碟空間: 5GB

### 必要軟體
本安裝腳本會自動安裝以下軟體：
- Nginx (Web 伺服器)
- MySQL/MariaDB (資料庫)
- PHP 7.4+ (含 php-fpm, php-mysql, php-mbstring, php-xml)

---

## 快速安裝

### 步驟 1: 下載系統檔案
```bash
# 將所有系統檔案放在同一目錄
cd /path/to/nessus-system
ls
# 應該看到: install.sh, db.sh, run.sh, *.php, *.js, *.css, audit.sql, default
```

### 步驟 2: 賦予執行權限
```bash
chmod +x install.sh db.sh run.sh uninstall.sh
```

### 步驟 3: 執行安裝腳本
```bash
sudo ./install.sh
```

安裝腳本會自動：
- 更新套件列表
- 安裝 Nginx, MySQL, PHP
- 設定 Nginx 虛擬主機
- 部署系統檔案到 `/var/www/html`
- 建立 logs 目錄並設定權限
- 啟動所有必要服務

### 步驟 4: 設定資料庫
```bash
sudo ./db.sh
```

資料庫設定腳本會：
- 檢查 MySQL 服務狀態
- 設定或驗證 root 密碼
- 建立 `audit` 資料庫
- 匯入資料表結構（Computer, Detail）
- 產生 `config.php` 設定檔

### 步驟 5: 訪問系統
開啟瀏覽器，訪問：
```
http://localhost
或
http://your-server-ip
```

預設登入帳號：
- **帳號**: `admin`
- **密碼**: `admin`

**重要**: 登入後請立即修改 `/var/www/html/config.php` 中的預設密碼！

---

## 進階操作

### 重啟服務
當修改設定檔後，使用此腳本重啟所有服務：
```bash
sudo ./run.sh
```

### 手動重啟單一服務
```bash
# 重啟 Nginx
sudo systemctl restart nginx

# 重啟 MySQL
sudo systemctl restart mysql

# 重啟 PHP-FPM (版本號請根據實際情況調整)
sudo systemctl restart php8.4-fpm
```

### 查看服務狀態
```bash
sudo systemctl status nginx
sudo systemctl status mysql
sudo systemctl status php8.4-fpm
```

### 查看系統日誌
```bash
# Nginx 錯誤日誌
sudo tail -f /var/log/nginx/error.log

# PHP-FPM 日誌
sudo journalctl -u php8.4-fpm -f

# 系統應用程式日誌
sudo tail -f /var/www/html/logs/system.log
sudo tail -f /var/www/html/logs/error.log
```

---

## 解除安裝

如需完全移除系統：
```bash
sudo ./uninstall.sh
```

解除安裝腳本會：
- 提示是否備份資料庫
- 移除所有網站檔案
- 刪除 `audit` 資料庫
- 還原 Nginx 設定檔
- 詢問是否移除已安裝的套件

---

## 目錄結構

```
/var/www/html/
├── config.php          # 系統設定檔（資料庫、認證）
├── login.php           # 登入頁面
├── logout.php          # 登出頁面
├── index.php           # 使用者電腦列表
├── import.php          # 資料匯入與重整
├── detail.php          # 弱點掃描結果
├── sum.php             # 資料彙總
├── check.php           # 系統資訊
├── header.php          # 頁面標頭
├── common.js           # JavaScript 共用函數
├── style.css           # CSS 樣式表
└── logs/               # 系統日誌目錄
    ├── system.log      # 操作日誌
    └── error.log       # PHP 錯誤日誌
```

---

## 安全性設定

### 1. 修改預設密碼
編輯 `/var/www/html/config.php`:
```php
// 認證設定
$AP_USER = 'your_username';  // 修改使用者名稱
$AP_PASS = 'your_password';  // 修改強密碼
```

### 2. 資料庫安全
```bash
# 執行 MySQL 安全設定
sudo mysql_secure_installation
```

建議設定：
- 設定 root 密碼
- 移除匿名使用者
- 禁止 root 遠端登入
- 移除測試資料庫

### 3. 防火牆設定
```bash
# 使用 UFW (Ubuntu/Debian)
sudo ufw allow 80/tcp      # HTTP
sudo ufw allow 443/tcp     # HTTPS (如果使用 SSL)
sudo ufw enable

# 使用 iptables
sudo iptables -A INPUT -p tcp --dport 80 -j ACCEPT
sudo iptables -A INPUT -p tcp --dport 443 -j ACCEPT
```

### 4. HTTPS 設定 (建議)
```bash
# 安裝 Certbot
sudo apt install certbot python3-certbot-nginx

# 取得 SSL 憑證
sudo certbot --nginx -d your-domain.com

# 自動更新憑證
sudo certbot renew --dry-run
```

### 5. 檔案權限檢查
```bash
# 檢查重要檔案權限
ls -la /var/www/html/config.php    # 應該是 640
ls -la /var/www/html/logs/         # 應該是 755
```

---

## 疑難排解

### 問題 1: 無法連線到資料庫
**症狀**: 登入後顯示資料庫連線錯誤

**解決方法**:
```bash
# 檢查 MySQL 服務
sudo systemctl status mysql

# 驗證資料庫設定
sudo mysql -uroot -p
> SHOW DATABASES;
> USE audit;
> SHOW TABLES;

# 檢查 config.php 中的密碼是否正確
sudo cat /var/www/html/config.php | grep DB_PASS
```

### 問題 2: PHP 檔案顯示為純文字
**症狀**: 訪問網頁時直接下載 PHP 檔案或顯示原始碼

**解決方法**:
```bash
# 檢查 PHP-FPM 是否運行
sudo systemctl status php*-fpm

# 檢查 Nginx 設定檔
sudo nginx -t
sudo cat /etc/nginx/sites-available/default | grep "\.php"

# 重啟服務
sudo ./run.sh
```

### 問題 3: 403 Forbidden 錯誤
**症狀**: 訪問網頁時顯示 403 錯誤

**解決方法**:
```bash
# 檢查檔案權限
ls -la /var/www/html/

# 修正權限
sudo chown -R www-data:www-data /var/www/html
sudo chmod -R 755 /var/www/html
sudo chmod 644 /var/www/html/*.php
```

### 問題 4: Session 相關錯誤
**症狀**: 無法登入或經常被登出

**解決方法**:
```bash
# 檢查 PHP session 目錄
ls -la /var/lib/php/sessions/

# 確保目錄存在且權限正確
sudo mkdir -p /var/lib/php/sessions
sudo chown www-data:www-data /var/lib/php/sessions
sudo chmod 1733 /var/lib/php/sessions
```

### 問題 5: 檔案上傳失敗
**症狀**: 上傳 Nessus 報告或 CSV 檔案時失敗

**解決方法**:
```bash
# 檢查 PHP 上傳設定
sudo nano /etc/php/8.3/fpm/php.ini

# 確認以下設定值
upload_max_filesize = 20M
post_max_size = 20M
max_execution_time = 300

# 重啟 PHP-FPM
sudo systemctl restart php8.3-fpm
```

---

## 系統維護

### 定期備份
```bash
# 備份資料庫
sudo mysqldump -uroot -p audit > audit_backup_$(date +%Y%m%d).sql

# 備份網站檔案
sudo tar -czf nessus_backup_$(date +%Y%m%d).tar.gz /var/www/html

# 自動備份腳本 (加入 crontab)
0 2 * * * /usr/bin/mysqldump -uroot -pYOUR_PASSWORD audit > /backup/audit_$(date +\%Y\%m\%d).sql
```

### 日誌管理
```bash
# 清理舊日誌 (保留最近 30 天)
find /var/www/html/logs/ -name "*.log" -mtime +30 -delete

# 設定 logrotate
sudo nano /etc/logrotate.d/nessus
```

logrotate 設定範例:
```
/var/www/html/logs/*.log {
    daily
    rotate 30
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
    sharedscripts
}
```

### 更新系統
```bash
# 更新套件
sudo apt update && sudo apt upgrade -y

# 重啟服務
sudo ./run.sh
```

---

## 技術支援

### 檢查系統資訊
訪問系統內建的檢查頁面：
```
http://your-server-ip/check.php
```

此頁面會顯示：
- PHP 版本與模組
- MySQL 連線狀態
- 資料表資訊
- 檔案權限狀態
- 系統設定

### 啟用除錯模式
編輯 `config.php`:
```php
// 錯誤處理設定
error_reporting(E_ALL);
ini_set('display_errors', 1);  // 開發時設為 1，生產環境設為 0
```

### 收集系統資訊
```bash
# 產生系統診斷報告
{
    echo "=== System Info ==="
    uname -a
    echo ""
    
    echo "=== PHP Version ==="
    php -v
    echo ""
    
    echo "=== MySQL Version ==="
    mysql --version
    echo ""
    
    echo "=== Nginx Version ==="
    nginx -v
    echo ""
    
    echo "=== Service Status ==="
    systemctl status nginx --no-pager
    systemctl status mysql --no-pager
    systemctl status php*-fpm --no-pager
    echo ""
    
    echo "=== File Permissions ==="
    ls -la /var/www/html/
    
} > system_diagnostic_$(date +%Y%m%d_%H%M%S).txt
```

---

## 版本資訊

**目前版本**: v2.0  
**最後更新**: 2025-12-06  
**相容性**: PHP 7.4+, MySQL 5.7+/MariaDB 10.3+

---

## 授權

MIT

請遵守您組織的資安政策和相關法規使用本系統。
