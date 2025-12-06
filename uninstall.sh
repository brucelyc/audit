#!/bin/bash
###############################################################################
# Nessus 弱點管理系統 - 解除安裝腳本
# 用途: 移除系統檔案和資料庫 (可選擇保留或移除套件)
###############################################################################

set -e  # 遇到錯誤立即停止

# 顏色定義
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
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

log_step() {
    echo -e "${BLUE}[STEP]${NC} $1"
}

# 檢查是否為 root 或有 sudo 權限
check_root() {
    if [ "$EUID" -eq 0 ]; then
        SUDO=""
    elif command -v sudo &> /dev/null; then
        SUDO="sudo"
    else
        log_error "需要 root 權限或 sudo 命令"
        exit 1
    fi
}

# 確認解除安裝
confirm_uninstall() {
    echo ""
    echo "警告：此操作將移除 Nessus 弱點管理系統"
    echo ""
    echo "將執行以下操作:"
    echo "  1. 移除網站檔案 (/var/www/html/*.php, *.js, *.css)"
    echo "  2. 刪除資料庫 (audit)"
    echo "  3. 移除 Nginx 設定檔"
    echo ""
    echo -n "是否繼續? (yes/no): "
    read -r confirm
    
    if [ "$confirm" != "yes" ]; then
        log_info "取消解除安裝"
        exit 0
    fi
    
    echo ""
}

# 備份資料庫
backup_database() {
    log_step "備份資料庫"
    echo ""
    echo -n "是否要備份資料庫? (y/n): "
    read -r do_backup
    
    if [[ "$do_backup" =~ ^[Yy]$ ]]; then
        local backup_dir="./backup_$(date +%Y%m%d_%H%M%S)"
        mkdir -p "$backup_dir"
        
        echo -n "請輸入 MySQL root 密碼 (若無密碼直接按 Enter): "
        read -rs db_password
        echo ""
        
        log_info "備份資料庫到 ${backup_dir}..."
        
        if [ -z "$db_password" ]; then
            $SUDO mysqldump -uroot audit > "${backup_dir}/audit_backup.sql" 2>/dev/null || true
        else
            $SUDO mysqldump -uroot -p"${db_password}" audit > "${backup_dir}/audit_backup.sql" 2>/dev/null || true
        fi
        
        if [ -f "${backup_dir}/audit_backup.sql" ]; then
            log_info "資料庫備份完成: ${backup_dir}/audit_backup.sql"
        else
            log_warn "資料庫備份失敗或資料庫不存在"
        fi
    else
        log_warn "跳過資料庫備份"
    fi
    
    echo ""
}

# 移除網站檔案
remove_web_files() {
    log_step "移除網站檔案"
    
    local files_to_remove=(
        "/var/www/html/*.php"
        "/var/www/html/*.js"
        "/var/www/html/*.css"
        "/var/www/html/*.csv"
        "/var/www/html/logs"
    )
    
    for pattern in "${files_to_remove[@]}"; do
        if ls $pattern 1> /dev/null 2>&1; then
            log_info "移除: $pattern"
            $SUDO rm -rf $pattern
        fi
    done
    
    log_info "網站檔案移除完成"
}

# 刪除資料庫
remove_database() {
    log_step "刪除資料庫"
    echo ""
    echo -n "請輸入 MySQL root 密碼 (若無密碼直接按 Enter): "
    read -rs db_password
    echo ""
    
    log_info "刪除資料庫 'audit'..."
    
    if [ -z "$db_password" ]; then
        $SUDO mysql -uroot -e "DROP DATABASE IF EXISTS audit;" 2>/dev/null || log_warn "無法刪除資料庫"
    else
        $SUDO mysql -uroot -p"${db_password}" -e "DROP DATABASE IF EXISTS audit;" 2>/dev/null || log_warn "無法刪除資料庫"
    fi
    
    log_info "資料庫刪除完成"
    echo ""
}

# 移除 Nginx 設定
remove_nginx_config() {
    log_step "移除 Nginx 設定"
    
    if [ -f "/etc/nginx/sites-available/default.bak" ]; then
        log_info "還原 Nginx 設定檔..."
        
        # 找到最新的備份檔
        local latest_backup=$(ls -t /etc/nginx/sites-available/default.bak* 2>/dev/null | head -1)
        
        if [ -n "$latest_backup" ]; then
            $SUDO cp "$latest_backup" /etc/nginx/sites-available/default
            log_info "已還原設定檔"
        fi
    else
        log_warn "找不到備份的 Nginx 設定檔"
    fi
    
    # 測試設定
    if $SUDO nginx -t 2>/dev/null; then
        log_info "Nginx 設定檔正確"
        $SUDO systemctl reload nginx || $SUDO service nginx reload
    else
        log_warn "Nginx 設定檔可能有誤，請手動檢查"
    fi
}

# 詢問是否移除套件
remove_packages() {
    echo ""
    log_step "套件管理"
    echo ""
    echo "以下套件可能被其他應用程式使用:"
    echo "  - nginx"
    echo "  - mysql-server"
    echo "  - php-fpm"
    echo ""
    echo -n "是否要移除這些套件? (y/n): "
    read -r remove_pkgs
    
    if [[ "$remove_pkgs" =~ ^[Yy]$ ]]; then
        log_warn "移除套件..."
        
        # 偵測 PHP 版本
        local php_version=""
        for ver in 8.4 8.3 8.2 8.1 8.0 7.4; do
            if dpkg -l | grep -q "php${ver}-fpm"; then
                php_version=$ver
                break
            fi
        done
        
        # 移除套件
        if [ -n "$php_version" ]; then
            $SUDO apt remove -y nginx default-mysql-server php${php_version}-fpm php${php_version}-mysql php${php_version}-mbstring php${php_version}-xml 2>/dev/null || true
        else
            $SUDO apt remove -y nginx default-mysql-server php-fpm php-mysql php-mbstring php-xml 2>/dev/null || true
        fi
        
        # 清理不需要的套件
        $SUDO apt autoremove -y
        
        log_info "套件移除完成"
    else
        log_info "保留已安裝的套件"
    fi
}

# 顯示完成資訊
show_completion_info() {
    echo ""
    echo "=========================================="
    echo "  解除安裝完成"
    echo "=========================================="
    echo ""
    echo "已執行的操作:"
    echo "  ✓ 移除網站檔案"
    echo "  ✓ 刪除資料庫"
    echo "  ✓ 還原 Nginx 設定"
    echo ""
    
    if [ -d "./backup_"* 2>/dev/null ]; then
        echo "備份檔案位置:"
        ls -d ./backup_* 2>/dev/null
        echo ""
    fi
    
    echo "如需重新安裝，請執行:"
    echo "  ./install.sh"
    echo "  ./db.sh"
    echo "=========================================="
}

# 主程序
main() {
    echo "=========================================="
    echo "  Nessus 弱點管理系統 - 解除安裝"
    echo "=========================================="
    
    check_root
    confirm_uninstall
    backup_database
    remove_web_files
    remove_database
    remove_nginx_config
    remove_packages
    show_completion_info
}

# 執行主程序
main "$@"
