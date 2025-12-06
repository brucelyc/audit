#!/bin/bash
###############################################################################
# Nessus 弱點管理系統 - 安裝腳本
# 用途: 安裝 Nginx, MySQL, PHP 及系統檔案
# 適用: Debian/Ubuntu/Kali Linux
###############################################################################

set -e  # 遇到錯誤立即停止

# 顏色定義
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 日誌函數
log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# 檢查是否為 root 或有 sudo 權限
check_root() {
    if [ "$EUID" -eq 0 ]; then
        SUDO=""
    elif command -v sudo &> /dev/null; then
        SUDO="sudo"
        log_info "使用 sudo 執行命令"
    else
        log_error "需要 root 權限或 sudo 命令"
        exit 1
    fi
}

# 檢查必要檔案
check_files() {
    log_info "檢查必要檔案..."
    
    local missing_files=()
    
    if [ ! -f "default" ]; then
        missing_files+=("default (Nginx 設定檔)")
    fi
    
    if [ ! -f "config.php" ]; then
        log_warn "config.php 不存在，將在資料庫設定後產生"
    fi
    
    # 檢查 PHP 檔案
    local php_files=(*.php)
    if [ ! -e "${php_files[0]}" ]; then
        missing_files+=("PHP 檔案")
    fi
    
    if [ ${#missing_files[@]} -gt 0 ]; then
        log_error "缺少必要檔案:"
        for file in "${missing_files[@]}"; do
            echo "  - $file"
        done
        exit 1
    fi
    
    log_info "檔案檢查完成"
}

# 安裝套件
install_packages() {
    log_info "開始安裝系統套件..."
    
    # 清理並更新套件列表
    log_info "清理套件快取..."
    $SUDO apt clean
    
    log_info "更新套件列表..."
    $SUDO apt update
    
    # 偵測 PHP 版本
    local php_version=""
    for ver in 8.4 8.3 8.2 8.1 8.0 7.4; do
        if apt-cache show php${ver}-fpm &> /dev/null; then
            php_version=$ver
            break
        fi
    done
    
    if [ -z "$php_version" ]; then
        log_error "無法找到可用的 PHP 版本"
        exit 1
    fi
    
    log_info "偵測到 PHP 版本: $php_version"
    
    # 安裝套件
    log_info "安裝 Nginx, MySQL, PHP..."
    $SUDO apt install -y \
        nginx \
        default-mysql-server \
        php${php_version}-fpm \
        php${php_version}-mysql \
        php${php_version}-mbstring \
        php${php_version}-xml
    
    log_info "套件安裝完成"
    
    # 儲存 PHP 版本供後續使用
    echo "$php_version" > .php_version
}

# 設定 Nginx
configure_nginx() {
    log_info "設定 Nginx..."
    
    # 備份原始設定檔
    if [ -f "/etc/nginx/sites-available/default" ]; then
        log_info "備份原始 Nginx 設定檔..."
        $SUDO mv /etc/nginx/sites-available/default \
                 /etc/nginx/sites-available/default.bak.$(date +%Y%m%d_%H%M%S)
    fi
    
    # 複製新設定檔
    if [ -f "default" ]; then
        log_info "複製 Nginx 設定檔..."
        $SUDO cp ./default /etc/nginx/sites-available/
        
        # 確保符號連結存在
        if [ ! -L "/etc/nginx/sites-enabled/default" ]; then
            $SUDO ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default
        fi
        
        # 測試 Nginx 設定
        if $SUDO nginx -t; then
            log_info "Nginx 設定檔驗證成功"
        else
            log_error "Nginx 設定檔驗證失敗"
            exit 1
        fi
    else
        log_error "找不到 Nginx 設定檔 'default'"
        exit 1
    fi
}

# 部署應用程式檔案
deploy_files() {
    log_info "部署應用程式檔案..."
    
    # 清理預設首頁
    $SUDO rm -f /var/www/html/index.html
    $SUDO rm -f /var/www/html/index.nginx-debian.html
    
    # 複製 PHP 檔案
    if ls *.php 1> /dev/null 2>&1; then
        log_info "複製 PHP 檔案..."
        $SUDO cp *.php /var/www/html/
    else
        log_warn "沒有找到 PHP 檔案"
    fi
    
    # 複製 JavaScript 檔案
    if ls *.js 1> /dev/null 2>&1; then
        log_info "複製 JavaScript 檔案..."
        $SUDO cp *.js /var/www/html/
    fi
    
    # 複製 CSS 檔案
    if ls *.css 1> /dev/null 2>&1; then
        log_info "複製 CSS 檔案..."
        $SUDO cp *.css /var/www/html/
    fi
    
    # 複製 CSV 範例檔案 (如果存在)
    if ls *.csv 1> /dev/null 2>&1; then
        log_info "複製 CSV 範例檔案..."
        $SUDO cp *.csv /var/www/html/
    fi
    
    # 設定目錄權限
    log_info "設定檔案權限..."
    $SUDO chown -R www-data:www-data /var/www/html
    $SUDO chmod -R 755 /var/www/html
    $SUDO chmod 644 /var/www/html/*.php 2>/dev/null || true
    $SUDO chmod 644 /var/www/html/*.js 2>/dev/null || true
    $SUDO chmod 644 /var/www/html/*.css 2>/dev/null || true
    
    # 建立 logs 目錄
    if [ ! -d "/var/www/html/logs" ]; then
        log_info "建立 logs 目錄..."
        $SUDO mkdir -p /var/www/html/logs
        $SUDO chown www-data:www-data /var/www/html/logs
        $SUDO chmod 755 /var/www/html/logs
    fi
}

# 啟動服務
start_services() {
    log_info "啟動服務..."
    
    # 取得 PHP 版本
    local php_version=""
    if [ -f ".php_version" ]; then
        php_version=$(cat .php_version)
    else
        # 嘗試偵測已安裝的 PHP-FPM
        for ver in 8.4 8.3 8.2 8.1 8.0 7.4; do
            if systemctl list-unit-files | grep -q "php${ver}-fpm"; then
                php_version=$ver
                break
            fi
        done
    fi
    
    # 啟動 MySQL
    log_info "啟動 MySQL..."
    $SUDO systemctl restart mysql || $SUDO service mysql restart
    $SUDO systemctl enable mysql 2>/dev/null || true
    
    # 啟動 PHP-FPM
    if [ -n "$php_version" ]; then
        log_info "啟動 PHP ${php_version}-FPM..."
        $SUDO systemctl restart php${php_version}-fpm || $SUDO service php${php_version}-fpm restart
        $SUDO systemctl enable php${php_version}-fpm 2>/dev/null || true
    fi
    
    # 啟動 Nginx
    log_info "啟動 Nginx..."
    $SUDO systemctl restart nginx || $SUDO service nginx restart
    $SUDO systemctl enable nginx 2>/dev/null || true
}

# 顯示安裝資訊
show_info() {
    log_info "安裝完成！"
    echo ""
    echo "=========================================="
    echo "  Nessus 弱點管理系統安裝完成"
    echo "=========================================="
    echo ""
    echo "下一步操作:"
    echo "  1. 執行 ./db.sh 設定資料庫"
    echo "  2. 訪問 http://localhost 或 http://your-server-ip"
    echo ""
    echo "預設帳號密碼 (請在 config.php 中修改):"
    echo "  帳號: admin"
    echo "  密碼: admin"
    echo ""
    echo "重要提示:"
    echo "  - 請立即修改預設密碼"
    echo "  - 檢查 /var/www/html/logs 目錄權限"
    echo "  - 建議設定防火牆規則"
    echo "=========================================="
}

# 主程序
main() {
    echo "=========================================="
    echo "  Nessus 弱點管理系統 - 安裝程式"
    echo "=========================================="
    echo ""
    
    check_root
    check_files
    install_packages
    configure_nginx
    deploy_files
    start_services
    show_info
}

# 執行主程序
main "$@"
