<?php
/**
 * 使用者電腦列表管理頁面
 * 功能：顯示、篩選、刪除使用者電腦資料
 */

require_once('config.php');
requireAuth();

$start_time = microtime(true);
$link = getDBConnection();

// ==================== 處理 IP 篩選 ====================
$keepIPs = [];
$ipListValue = '';

if (isset($_POST['filterIPs']) && !empty($_POST['ipList'])) {
    $ipListValue = trim($_POST['ipList']);
    $lines = array_filter(array_map('trim', explode("\n", $ipListValue)));
    
    foreach ($lines as $ip) {
        if (validateIP($ip)) {
            $keepIPs[] = $ip;
        }
    }
}

// ==================== 處理刪除操作 ====================
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        die(showAlert("安全驗證失敗，請重新操作", "danger"));
    }

    // 刪除勾選項目
    if (isset($_POST['delCheck']) && isset($_POST['id']) && is_array($_POST['id'])) {
        $deleted_count = batchDeleteByIds($link, 'Computer', $_POST['id']);
        
        if ($deleted_count > 0) {
            $message = showAlert("成功刪除 {$deleted_count} 筆資料", "success");
            logAction("刪除使用者資料", "刪除 {$deleted_count} 筆");
        } else {
            $message = showAlert("沒有資料被刪除", "warning");
        }
    } 
    // 刪除所有資料
    elseif (isset($_POST['delAll'])) {
        $total = truncateTable($link, 'Computer');
        
        if ($total > 0) {
            $message = showAlert("成功刪除所有資料 (共 {$total} 筆)", "success");
            logAction("刪除所有使用者資料", "刪除 {$total} 筆");
        } else {
            $message = showAlert("目前沒有資料可刪除", "warning");
        }
    }
}

// ==================== 檢查資料數量 ====================
$hasData = hasTableData($link, 'Computer');

include('header.php');

// ==================== 顯示訊息和標題 ====================
if ($message) echo $message;

echo '<h1>使用者電腦列表</h1>';

// ==================== 生成 CSRF Token ====================
$csrf_token = generateCSRFToken();
$ipListValueEscaped = sanitizeString($ipListValue);
?>

<!-- IP 篩選區塊 -->
<div id="ipFilterDiv" class="filter-box">
    <h3 class="filter-title">篩選要保留的 IP 地址</h3>
    <p class="filter-description">
        貼入要<strong>保留</strong>的 IP 列表，系統會自動勾選不在列表中的項目以便刪除
    </p>
    
    <form method="POST" action="index.php">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <textarea name="ipList" rows="10" class="filter-textarea" 
            placeholder="請貼入要保留的 IP 地址，每行一個&#10;&#10;例如：&#10;192.168.1.1&#10;192.168.1.2"><?php echo $ipListValueEscaped; ?></textarea>
        
        <div class="button-group mt-2">
            <button type="submit" name="filterIPs" class="btn btn-primary">篩選並選取要刪除的項目</button>
            <button type="button" onclick="toggleIPFilter()" class="btn btn-secondary">取消</button>
        </div>
    </form>
</div>

<!-- 主表單 -->
<form method="POST" action="index.php" onsubmit="return confirmDelete(this);">
    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
    
    <?php
    // 保留 IP 列表狀態
    if (!empty($keepIPs)) {
        echo '<input type="hidden" name="ipList" value="' . $ipListValueEscaped . '">';
        echo '<input type="hidden" name="filterIPs" value="1">';
        echo '<script>onDOMReady(function() { document.getElementById("ipFilterDiv").style.display = "block"; });</script>';
    }
    ?>
    
    <!-- 操作按鈕列 -->
    <div class="action-bar no-print">
        <button type="button" onclick="toggleIPFilter()" class="btn btn-primary" <?php echo !$hasData ? 'disabled' : ''; ?>>
            貼入保留 IP
        </button>
        <button type="submit" name="delCheck" class="btn btn-danger" <?php echo !$hasData ? 'disabled' : ''; ?>>
            刪除勾選
        </button>
        <button type="submit" name="delAll" class="btn btn-warning" <?php echo !$hasData ? 'disabled' : ''; ?>>
            刪除所有
        </button>
        <span class="selected-count-wrapper">
            已勾選: <span id="selectedCount" class="selected-count">0</span> 筆
        </span>
        <?php if (!$hasData): ?>
            <span class="no-data-hint">目前無資料，無法使用操作功能</span>
        <?php endif; ?>
    </div>

    <?php
    // 顯示篩選結果提示
    if (!empty($keepIPs)) {
        echo showAlert(
            '<strong>篩選結果:</strong> 要保留的 IP 數量: <strong>' . count($keepIPs) . '</strong> 個，已自動勾選不在列表中的項目以便刪除',
            'info'
        );
    }
    ?>

    <!-- 資料表格 -->
    <table id="dataTable">
        <thead>
            <tr>
                <th class="col-checkbox text-center">
                    <input type="checkbox" onclick="toggleAll(this)" title="全選/取消全選">
                </th>
                <th class="col-number text-center">No.</th>
                <th class="col-ip">主機 IP</th>
                <th class="col-name">姓名</th>
                <th class="col-unit">單位</th>
                <th class="col-type">類型</th>
                <th class="col-action text-center">操作</th>
            </tr>
        </thead>
        <tbody>
<?php
// ==================== 查詢並顯示資料 ====================
$result = $link->query("SELECT * FROM Computer ORDER BY INET_ATON(Host)");
$row_count = 0;
$has_data = false;

while ($row = $result->fetch_assoc()) {
    $has_data = true;
    $row_count++;
    
    $id = validateInt($row['ID'], 1);
    if ($id === false) continue;
    
    $host = sanitizeString($row['Host']);
    $name = sanitizeString($row['Name']);
    $unit = sanitizeString($row['Unit']);
    $property = sanitizeString($row['Property']);
    
    // 判斷是否自動勾選（不在保留列表中的項目）
    $shouldCheck = (!empty($keepIPs) && !in_array($row['Host'], $keepIPs));
    $checkedAttr = $shouldCheck ? ' checked' : '';
    
    echo '<tr>';
    echo '<td class="text-center">';
    echo '<input type="checkbox" name="id[' . $id . ']" value="1"' . $checkedAttr . '>';
    echo '</td>';
    echo '<td class="text-center">' . $row_count . '</td>';
    echo '<td><strong>' . $host . '</strong></td>';
    echo '<td>' . $name . '</td>';
    echo '<td>' . $unit . '</td>';
    echo '<td>' . $property . '</td>';
    echo '<td class="text-center">';
    echo '<a href="detail.php?host=' . urlencode($host) . '" class="action-link">🔍 查看弱點</a>';
    echo '</td>';
    echo '</tr>';
}

// 無資料時的提示
if (!$has_data) {
    renderEmptyState(7, '目前沒有使用者資料', 'import.php', '前往匯入資料');
}
?>
        </tbody>
        <?php
        if ($has_data) {
            renderTableFooter(7, '總計:', $row_count, false);
        }
        ?>
    </table>
</form>

<?php
// ==================== 單位統計 ====================
if ($has_data) {
    $unit_stats = $link->query("
        SELECT Unit, COUNT(*) as count 
        FROM Computer 
        GROUP BY Unit 
        ORDER BY count DESC
    ");
    
    echo '<div class="stats-card mt-3">';
    echo '<h3 class="section-title">單位統計</h3>';
    echo '<table class="stats-table">';
    echo '<thead>';
    echo '<tr>';
    echo '<th class="text-center col-rank">排名</th>';
    echo '<th class="col-unit-name">單位名稱</th>';
    echo '<th class="text-center col-count">電腦數量</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    $rank = 1;
    $totalComputers = 0;
    
    while ($stat = $unit_stats->fetch_assoc()) {
        $unit_name = sanitizeString($stat['Unit']);
        $count = validateInt($stat['count'], 0) ?: 0;
        $totalComputers += $count;
        
        echo '<tr>';
        echo '<td class="text-center rank-cell">' . $rank . '</td>';
        echo '<td class="unit-name-cell">' . $unit_name . '</td>';
        echo '<td class="text-center count-cell">';
        echo '<strong class="count-number">' . $count . '</strong>';
        echo '</td>';
        echo '</tr>';
        $rank++;
    }
    
    echo '</tbody>';
    echo '<tfoot>';
    echo '<tr>';
    echo '<td colspan="2" class="text-right stats-footer-label">總計</td>';
    echo '<td class="text-center stats-footer-total">';
    echo '<strong class="total-number">' . $totalComputers . '</strong>';
    echo '</td>';
    echo '</tr>';
    echo '</tfoot>';
    echo '</table>';
    echo '</div>';
}

// ==================== 頁面執行時間 ====================
renderExecutionTime($start_time);

logAction('查看使用者電腦列表', "共 {$row_count} 筆資料");
?>

</div>
</body>
</html>

<?php
$link->close();
?>
