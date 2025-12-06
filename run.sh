#!/bin/bash
###############################################################################
# Aduit 檢測管理系統 - 服務重啟腳本
# 用途: 重啟 Nginx, MySQL, PHP-FPM 服務
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

# 偵測 PHP-FPM 版本
detect_php_version() {
    log_info "偵測 PHP-FPM 版本..."
    
    # 嘗試從已安裝的套件中找到 PHP-FPM
    for ver in 8.4 8.3 8.2 8.1 8.0 7.4 7.3 7.2; do
        if systemctl list-unit-files 2>/dev/null | grep -q "php${ver}-fpm" || \
           service --status-all 2>&1 | grep -q "php${ver}-fpm"; then
            PHP_VERSION=$ver
            log_info "找到 PHP ${ver}-FPM"
            return 0
        fi
    done
    
    # 如果找不到，嘗試通用的 php-fpm
    if systemctl list-unit-files 2>/dev/null | grep -q "php-fpm" || \
       service --status-all 2>&1 | grep -q "php-fpm"; then
        PHP_VERSION=""
        log_info "找到通用 PHP-FPM"
        return 0
    fi
    
    log_warn "未找到 PHP-FPM 服務"
    return 1
}

# 重啟服務
restart_service() {
    local service_name=$1
    local display_name=$2
    
    log_step "重啟 ${display_name}..."
    
    # 嘗試使用 systemctl
    if command -v systemctl &> /dev/null; then
        if $SUDO systemctl restart ${service_name} 2>/dev/null; then
            log_info "${display_name} 重啟成功 (systemctl)"
            
            # 檢查服務狀態
            if $SUDO systemctl is-active --quiet ${service_name}; then
                log_info "${display_name} 運行正常"
            else
                log_warn "${display_name} 狀態異常"
                $SUDO systemctl status ${service_name} --no-pager || true
            fi
            return 0
        fi
    fi
    
    # 備選: 使用 service 命令
    if command -v service &> /dev/null; then
        if $SUDO service ${service_name} restart 2>/dev/null; then
            log_info "${display_name} 重啟成功 (service)"
            
            # 檢查服務狀態
            if $SUDO service ${service_name} status &>/dev/null; then
                log_info "${display_name} 運行正常"
            else
                log_warn "${display_name} 狀態可能異常"
            fi
            return 0
        fi
    fi
    
    log_error "無法重啟 ${display_name}"
    return 1
}

# 重啟 MySQL
restart_mysql() {
    # 嘗試不同的服務名稱
    for service in mysql mariadb mysqld; do
        if systemctl list-unit-files 2>/dev/null | grep -q "^${service}.service" || \
           service --status-all 2>&1 | grep -q "${service}"; then
            restart_service "${service}" "MySQL/MariaDB"
            return $?
        fi
    done
    
    log_error "找不到 MySQL/MariaDB 服務"
    return 1
}

# 重啟 PHP-FPM
restart_php_fpm() {
    if [ -n "$PHP_VERSION" ]; then
        restart_service "php${PHP_VERSION}-fpm" "PHP ${PHP_VERSION}-FPM"
    else
        restart_service "php-fpm" "PHP-FPM"
    fi
}

# 重啟 Nginx
restart_nginx() {
    restart_service "nginx" "Nginx"
}

# 測試 Nginx 設定
test_nginx_config() {
    log_info "測試 Nginx 設定檔..."
    
    if $SUDO nginx -t 2>&1 | grep -q "successful"; then
        log_info "Nginx 設定檔正確"
        return 0
    else
        log_error "Nginx 設定檔有誤"
        $SUDO nginx -t
        return 1
    fi
}

# 顯示服務狀態
show_service_status() {
    echo ""
    log_step "服務狀態檢查"
    echo ""
    
    # 檢查 MySQL
    if systemctl is-active --quiet mysql 2>/dev/null || \
       systemctl is-active --quiet mariadb 2>/dev/null || \
       service mysql status &>/dev/null; then
        echo -e "${GREEN}✓${NC} MySQL/MariaDB: 運行中"
    else
        echo -e "${RED}✗${NC} MySQL/MariaDB: 未運行"
    fi
    
    # 檢查 PHP-FPM
    if [ -n "$PHP_VERSION" ]; then
        if systemctl is-active --quiet php${PHP_VERSION}-fpm 2>/dev/null || \
           service php${PHP_VERSION}-fpm status &>/dev/null; then
            echo -e "${GREEN}✓${NC} PHP ${PHP_VERSION}-FPM: 運行中"
        else
            echo -e "${RED}✗${NC} PHP ${PHP_VERSION}-FPM: 未運行"
        fi
    else
        if systemctl is-active --quiet php-fpm 2>/dev/null || \
           service php-fpm status &>/dev/null; then
            echo -e "${GREEN}✓${NC} PHP-FPM: 運行中"
        else
            echo -e "${RED}✗${NC} PHP-FPM: 未運行"
        fi
    fi
    
    # 檢查 Nginx
    if systemctl is-active --quiet nginx 2>/dev/null || \
       service nginx status &>/dev/null; then
        echo -e "${GREEN}✓${NC} Nginx: 運行中"
    else
        echo -e "${RED}✗${NC} Nginx: 未運行"
    fi
    
    echo ""
}

# 顯示完成資訊
show_completion_info() {
    echo ""
    echo "=========================================="
    echo "  服務重啟完成"
    echo "=========================================="
    echo ""
    echo "您可以使用以下命令檢查詳細狀態:"
    echo ""
    
    if [ -n "$PHP_VERSION" ]; then
        echo "  $SUDO systemctl status mysql"
        echo "  $SUDO systemctl status php${PHP_VERSION}-fpm"
        echo "  $SUDO systemctl status nginx"
    else
        echo "  $SUDO systemctl status mysql"
        echo "  $SUDO systemctl status php-fpm"
        echo "  $SUDO systemctl status nginx"
    fi
    
    echo ""
    echo "查看日誌:"
    echo "  $SUDO journalctl -u nginx -f"
    
    if [ -n "$PHP_VERSION" ]; then
        echo "  $SUDO journalctl -u php${PHP_VERSION}-fpm -f"
    fi
    
    echo "  $SUDO tail -f /var/www/html/logs/system.log"
    echo "=========================================="
}

# 主程序
main() {
    echo "=========================================="
    echo "  Nessus 弱點管理系統 - 服務重啟"
    echo "=========================================="
    echo ""
    
    check_root
    detect_php_version
    
    # 先測試 Nginx 設定
    if ! test_nginx_config; then
        log_error "Nginx 設定檔有誤，請修正後再重啟"
        exit 1
    fi
    
    echo ""
    
    # 重啟服務
    local restart_failed=0
    
    restart_mysql || restart_failed=1
    echo ""
    
    restart_php_fpm || restart_failed=1
    echo ""
    
    restart_nginx || restart_failed=1
    
    # 顯示服務狀態
    show_service_status
    
    # 顯示完成資訊
    if [ $restart_failed -eq 0 ]; then
        show_completion_info
        exit 0
    else
        log_error "部分服務重啟失敗，請檢查錯誤訊息"
        exit 1
    fi
}

# 執行主程序
main "$@"
