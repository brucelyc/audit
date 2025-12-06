#!/bin/bash
###############################################################################
# Nessus 弱點管理系統 - 資料庫設定腳本
# 用途: 設定 MySQL 資料庫、建立資料表、產生 config.php
###############################################################################

set -e  # 遇到錯誤立即停止

# 顏色定義
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 設定檔案
DB_NAME="audit"
DB_USER="root"
SQL_FILE="audit.sql"
CONFIG_TEMPLATE="config.php"

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

# 檢查必要檔案
check_files() {
    log_info "檢查必要檔案..."
    
    if [ ! -f "$SQL_FILE" ]; then
        log_error "找不到 SQL 檔案: $SQL_FILE"
        exit 1
    fi
    
    log_info "檔案檢查完成"
}

# 檢查 MySQL 服務
check_mysql_service() {
    log_info "檢查 MySQL 服務狀態..."
    
    if systemctl is-active --quiet mysql || service mysql status &>/dev/null; then
        log_info "MySQL 服務運行中"
    else
        log_warn "MySQL 服務未運行，嘗試啟動..."
        $SUDO systemctl start mysql || $SUDO service mysql start
        sleep 2
        
        if systemctl is-active --quiet mysql || service mysql status &>/dev/null; then
            log_info "MySQL 服務啟動成功"
        else
            log_error "無法啟動 MySQL 服務"
            exit 1
        fi
    fi
}

# 測試資料庫連線
test_connection() {
    local password=$1
    
    if [ -z "$password" ]; then
        # 無密碼連線測試
        if $SUDO mysql -u${DB_USER} -e "SELECT 1" &>/dev/null; then
            return 0
        fi
    else
        # 有密碼連線測試
        if $SUDO mysql -u${DB_USER} -p"${password}" -e "SELECT 1" &>/dev/null; then
            return 0
        fi
    fi
    
    return 1
}

# 設定 root 密碼
setup_root_password() {
    log_step "MySQL Root 密碼設定"
    echo ""
    
    # 先測試無密碼連線
    if test_connection ""; then
        log_info "偵測到 MySQL root 無密碼"
        echo ""
        echo -n "是否要設定 root 密碼? (y/n): "
        read -r set_password
        
        if [[ "$set_password" =~ ^[Yy]$ ]]; then
            while true; do
                echo -n "請輸入新密碼: "
                read -rs NEW_PASSWORD
                echo ""
                
                if [ -z "$NEW_PASSWORD" ]; then
                    log_warn "密碼不能為空，請重新輸入"
                    continue
                fi
                
                echo -n "請再次輸入密碼: "
                read -rs PASSWORD_CONFIRM
                echo ""
                
                if [ "$NEW_PASSWORD" = "$PASSWORD_CONFIRM" ]; then
                    break
                else
                    log_warn "兩次密碼不一致，請重新輸入"
                fi
            done
            
            # 設定密碼
            log_info "設定 root 密碼..."
            $SUDO mysql -u${DB_USER} -e "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${NEW_PASSWORD}';" || \
            $SUDO mysql -u${DB_USER} -e "SET PASSWORD FOR '${DB_USER}'@'localhost' = PASSWORD('${NEW_PASSWORD}');" || \
            $SUDO mysql -u${DB_USER} -e "GRANT ALL PRIVILEGES ON *.* TO '${DB_USER}'@'localhost' IDENTIFIED BY '${NEW_PASSWORD}';"
            
            $SUDO mysql -u${DB_USER} -p"${NEW_PASSWORD}" -e "FLUSH PRIVILEGES;"
            DB_PASSWORD="$NEW_PASSWORD"
            log_info "密碼設定完成"
        else
            log_warn "跳過密碼設定，使用空密碼"
            DB_PASSWORD=""
        fi
    else
        # 需要輸入現有密碼
        log_info "MySQL root 已設定密碼"
        echo ""
        
        while true; do
            echo -n "請輸入 root 密碼: "
            read -rs EXISTING_PASSWORD
            echo ""
            
            if test_connection "$EXISTING_PASSWORD"; then
                DB_PASSWORD="$EXISTING_PASSWORD"
                log_info "密碼驗證成功"
                break
            else
                log_error "密碼錯誤，請重試"
            fi
        done
    fi
    
    echo ""
}

# 建立資料庫
create_database() {
    log_step "建立資料庫: $DB_NAME"
    
    if [ -z "$DB_PASSWORD" ]; then
        $SUDO mysql -u${DB_USER} -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
    else
        $SUDO mysql -u${DB_USER} -p"${DB_PASSWORD}" -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
    fi
    
    log_info "資料庫建立完成"
}

# 匯入資料表結構
import_tables() {
    log_step "匯入資料表結構"
    
    if [ -z "$DB_PASSWORD" ]; then
        $SUDO mysql -u${DB_USER} ${DB_NAME} < ${SQL_FILE}
    else
        $SUDO mysql -u${DB_USER} -p"${DB_PASSWORD}" ${DB_NAME} < ${SQL_FILE}
    fi
    
    log_info "資料表建立完成"
    
    # 顯示建立的資料表
    echo ""
    log_info "已建立的資料表:"
    if [ -z "$DB_PASSWORD" ]; then
        $SUDO mysql -u${DB_USER} ${DB_NAME} -e "SHOW TABLES;"
    else
        $SUDO mysql -u${DB_USER} -p"${DB_PASSWORD}" ${DB_NAME} -e "SHOW TABLES;"
    fi
    echo ""
}

# 產生 config.php
generate_config() {
    log_step "產生設定檔: config.php"
    
    # 讀取現有的 config.php 內容
    if [ -f "$CONFIG_TEMPLATE" ]; then
        log_info "從現有 config.php 讀取設定..."
        
        # 建立臨時設定檔
        local temp_config=$(mktemp)
        
        # 複製現有內容
        cp "$CONFIG_TEMPLATE" "$temp_config"
        
        # 更新資料庫密碼
        sed -i "s/^define('DB_PASS',.*$/define('DB_PASS', '${DB_PASSWORD}');/" "$temp_config" || \
        sed -i "s/^\$DB_PASS = .*$/\$DB_PASS = '${DB_PASSWORD}';/" "$temp_config"
        
        # 複製到網站目錄
        $SUDO cp "$temp_config" /var/www/html/config.php
        rm -f "$temp_config"
    else
        log_warn "找不到 config.php 模板，建立基本設定檔..."
        
        # 建立基本 config.php
        cat > /tmp/config.php << EOF
<?php
/**
 * Nessus 弱點管理系統 - 資料庫設定
 * 此檔案由 db.sh 自動產生
 */

// 資料庫連線設定
define('DB_HOST', 'localhost');
define('DB_USER', '${DB_USER}');
define('DB_PASS', '${DB_PASSWORD}');
define('DB_NAME', '${DB_NAME}');
define('DB_CHARSET', 'utf8mb4');

// 認證設定 (請修改預設密碼)
\$AP_USER = 'admin';
\$AP_PASS = 'admin';

// 時區設定
date_default_timezone_set('Asia/Taipei');
?>
EOF
        $SUDO mv /tmp/config.php /var/www/html/config.php
    fi
    
    # 設定權限
    $SUDO chown www-data:www-data /var/www/html/config.php
    $SUDO chmod 640 /var/www/html/config.php
    
    log_info "設定檔產生完成"
}

# 驗證資料庫設定
verify_setup() {
    log_step "驗證資料庫設定"
    
    # 檢查資料庫是否存在
    if [ -z "$DB_PASSWORD" ]; then
        local db_exists=$($SUDO mysql -u${DB_USER} -e "SHOW DATABASES LIKE '${DB_NAME}';" | grep -c "${DB_NAME}")
    else
        local db_exists=$($SUDO mysql -u${DB_USER} -p"${DB_PASSWORD}" -e "SHOW DATABASES LIKE '${DB_NAME}';" | grep -c "${DB_NAME}")
    fi
    
    if [ "$db_exists" -eq 1 ]; then
        log_info "資料庫 '${DB_NAME}' 存在"
    else
        log_error "資料庫 '${DB_NAME}' 不存在"
        return 1
    fi
    
    # 檢查資料表
    if [ -z "$DB_PASSWORD" ]; then
        local table_count=$($SUDO mysql -u${DB_USER} ${DB_NAME} -e "SHOW TABLES;" | wc -l)
    else
        local table_count=$($SUDO mysql -u${DB_USER} -p"${DB_PASSWORD}" ${DB_NAME} -e "SHOW TABLES;" | wc -l)
    fi
    
    # 減去標題行
    table_count=$((table_count - 1))
    
    if [ "$table_count" -ge 2 ]; then
        log_info "資料表建立成功 (共 ${table_count} 個)"
    else
        log_warn "資料表數量異常"
        return 1
    fi
    
    # 檢查 config.php
    if [ -f "/var/www/html/config.php" ]; then
        log_info "✓ 設定檔 config.php 存在"
    else
        log_error "✗ 設定檔 config.php 不存在"
        return 1
    fi
    
    echo ""
    log_info "資料庫設定驗證完成！"
}

# 顯示完成資訊
show_completion_info() {
    echo ""
    echo "=========================================="
    echo "  資料庫設定完成"
    echo "=========================================="
    echo ""
    echo "資料庫資訊:"
    echo "  資料庫名稱: ${DB_NAME}"
    echo "  使用者: ${DB_USER}"
    echo "  密碼: $([ -z "$DB_PASSWORD" ] && echo "(空密碼)" || echo "[已設定]")"
    echo ""
    echo "下一步操作:"
    echo "  1. 訪問 http://localhost 或 http://your-server-ip"
    echo "  2. 使用預設帳號登入:"
    echo "     帳號: admin"
    echo "     密碼: admin"
    echo ""
    echo "重要安全提醒:"
    echo "  - 請立即修改 config.php 中的預設帳號密碼"
    echo "  - 建議定期備份資料庫"
    echo "  - 確保 MySQL 只監聽 localhost"
    echo "=========================================="
}

# 主程序
main() {
    echo "=========================================="
    echo "  Nessus 弱點管理系統 - 資料庫設定"
    echo "=========================================="
    echo ""
    
    check_root
    check_files
    check_mysql_service
    setup_root_password
    create_database
    import_tables
    generate_config
    verify_setup
    show_completion_info
}

# 執行主程序
main "$@"
