<?php
/**
 * 弱點掃描結果詳細頁面
 * 功能：顯示、篩選、刪除、匯出弱點資料
 */

require_once('config.php');
requireAuth();

$start_time = microtime(true);
$link = getDBConnection();

// ==================== 處理匯出功能 ====================
if (isset($_POST['export'])) {
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        die(showAlert("安全驗證失敗", "danger"));
    }
    
    $filter_host = null;
    if (isset($_POST['filterHost']) && !empty($_POST['filterHost'])) {
        $filter_host = trim($_POST['filterHost']);
        if (!validateIP($filter_host)) {
            die(showAlert("無效的 IP 地址", "danger"));
        }
    }
    
    // 查詢資料
    if ($filter_host) {
        $stmt = $link->prepare("
            SELECT Risk, Host, Protocol, Port, Name 
            FROM Detail 
            WHERE Host = ?
            ORDER BY Priority, INET_ATON(Host), Name, Port
        ");
        $stmt->bind_param('s', $filter_host);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $link->query("
            SELECT Risk, Host, Protocol, Port, Name 
            FROM Detail 
            ORDER BY Priority, INET_ATON(Host), Name, Port
        ");
    }
    
    $content = mb_convert_encoding("Risk,Host,Protocol,Port,Name\r\n", "BIG5", "UTF-8");
    
    while ($row = $result->fetch_assoc()) {
        $csv_line = array_map(function($v) {
            $v = str_replace('"', '""', $v);
            return '"' . mb_convert_encoding($v, "BIG5", "UTF-8") . '"';
        }, [$row['Risk'], $row['Host'], $row['Protocol'], $row['Port'], $row['Name']]);
        
        $content .= implode(",", $csv_line) . "\r\n";
    }
    
    $filename = $filter_host ? "Vulns_{$filter_host}.csv" : "Vulns_All.csv";
    
    logAction("匯出弱點資料", $filter_host ? "主機: {$filter_host}" : "全部資料");
    
    outputCSV($filename, $content);
}

// ==================== 取得篩選主機 ====================
$filter_host = null;
if (isset($_GET['host'])) {
    $temp_host = trim($_GET['host']);
    if (validateIP($temp_host)) {
        $filter_host = $temp_host;
    }
} elseif (isset($_POST['filterHost'])) {
    $temp_host = trim($_POST['filterHost']);
    if (validateIP($temp_host)) {
        $filter_host = $temp_host;
    }
}

// ==================== 處理刪除操作 ====================
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['export'])) {
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        die(showAlert("安全驗證失敗，請重新操作", "danger"));
    }

    // 刪除勾選項目
    if (isset($_POST['delCheck']) && isset($_POST['id']) && is_array($_POST['id'])) {
        $deleted_count = 0;
        
        // 刪除同 IP 所有資料
        if (isset($_POST['relation']) && $_POST['relation']) {
            $stmt_select = $link->prepare("SELECT Host FROM Detail WHERE ID = ?");
            $stmt_delete = $link->prepare("DELETE FROM Detail WHERE Host = ?");
            
            $hosts_to_delete = [];
            foreach ($_POST['id'] as $id => $value) {
                $validated_id = validateInt($id, 1);
                if ($validated_id !== false) {
                    $stmt_select->bind_param('i', $validated_id);
                    $stmt_select->execute();
                    $result = $stmt_select->get_result();
                    if ($row = $result->fetch_assoc()) {
                        if (validateIP($row['Host'])) {
                            $hosts_to_delete[$row['Host']] = true;
                        }
                    }
                }
            }
            
            foreach (array_keys($hosts_to_delete) as $host) {
                $stmt_delete->bind_param('s', $host);
                if ($stmt_delete->execute()) {
                    $deleted_count += $stmt_delete->affected_rows;
                }
            }
            
            $stmt_select->close();
            $stmt_delete->close();
            
            $message = showAlert(
                "成功刪除 " . count($hosts_to_delete) . " 個主機的 {$deleted_count} 筆弱點資料", 
                "success"
            );
            logAction("刪除同 IP 弱點資料", count($hosts_to_delete) . " 個主機，{$deleted_count} 筆資料");
        } 
        // 僅刪除勾選項目
        else {
            $deleted_count = batchDeleteByIds($link, 'Detail', $_POST['id']);
            
            $message = showAlert("成功刪除 {$deleted_count} 筆弱點資料", "success");
            logAction("刪除弱點資料", "{$deleted_count} 筆");
        }
    } 
    // 刪除所有資料
    elseif (isset($_POST['delAll'])) {
        $total = truncateTable($link, 'Detail');
        
        if ($total > 0) {
            $message = showAlert("成功刪除所有弱點資料 (共 {$total} 筆)", "success");
            logAction("刪除所有弱點資料", "{$total} 筆");
        } else {
            $message = showAlert("目前沒有資料可刪除", "warning");
        }
    }
}

include('header.php');

// ==================== 顯示訊息 ====================
if ($message) echo $message;

// ==================== 顯示篩選資訊 ====================
echo '<h1>Nessus 掃描結果</h1>';

if ($filter_host) {
    $safe_host = sanitizeString($filter_host);
    echo '<div class="filter-info-box">';
    echo '<strong>🔍 篩選條件:</strong>主機 IP = <code>' . $safe_host . '</code>';
    echo '<a href="detail.php" class="clear-filter-link">清除篩選</a>';
    echo '</div>';
}

// ==================== 生成 CSRF Token ====================
$csrf_token = generateCSRFToken();
$filter_host_value = sanitizeString($filter_host ?? '');
?>

<!-- 主表單 -->
<form method="POST" action="detail.php" id="mainForm" onsubmit="return confirmDelete(this);">
    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
    <input type="hidden" name="filterHost" value="<?php echo $filter_host_value; ?>">
    
    <div class="action-bar no-print">
        <button type="submit" name="delCheck" class="btn btn-danger">刪除勾選</button>
        <button type="submit" name="delAll" class="btn btn-warning">刪除所有</button>
        <label class="relation-checkbox">
            <input type="checkbox" name="relation" value="1">
            <span class="text-danger font-bold">刪除同 IP 所有資料</span>
        </label>
        <button type="submit" name="export" class="btn btn-success">匯出資料</button>
        <span class="selected-count-wrapper">
            <span id="selectedCount">0</span> 筆已勾選
        </span>
    </div>
</form>

<!-- 快速篩選列 -->
<div class="quick-filter-bar">
    <strong>快速篩選:</strong>
    <div class="filter-buttons">
        <button type="button" onclick="filterByRisk('all', this)" class="btn btn-secondary btn-sm filter-btn active">全部</button>
        <button type="button" onclick="filterByRisk('Critical', this)" class="btn btn-sm risk-Critical filter-btn">Critical</button>
        <button type="button" onclick="filterByRisk('High', this)" class="btn btn-sm risk-High filter-btn">High</button>
        <button type="button" onclick="filterByRisk('Medium', this)" class="btn btn-sm risk-Medium filter-btn">Medium</button>
        <button type="button" onclick="filterByRisk('Low', this)" class="btn btn-sm risk-Low filter-btn">Low</button>
        <button type="button" onclick="filterByRisk('None', this)" class="btn btn-sm risk-None filter-btn">None</button>
    </div>
</div>

<!-- 排序列 -->
<div class="quick-filter-bar">
    <strong>排序方式:</strong>
    <div class="filter-buttons">
        <button type="button" onclick="sortTable('risk', this)" class="btn btn-primary btn-sm sort-btn active">風險等級</button>
        <button type="button" onclick="sortTable('ip', this)" class="btn btn-primary btn-sm sort-btn">主機IP</button>
        <button type="button" onclick="sortTable('port', this)" class="btn btn-primary btn-sm sort-btn">協定/埠號</button>
        <button type="button" onclick="sortTable('name', this)" class="btn btn-primary btn-sm sort-btn">弱點名稱</button>
    </div>
</div>

<!-- 資料表格 -->
<table id="dataTable">
    <thead>
        <tr>
            <th class="col-checkbox text-center">
                <input type="checkbox" onclick="toggleAll(this)" title="全選/取消全選">
            </th>
            <th class="col-number text-center">No.</th>
            <th class="col-risk text-center">風險等級</th>
            <th class="col-host">主機IP</th>
            <th class="col-port text-center">協定/埠號</th>
            <th class="col-vuln-name">弱點名稱</th>
        </tr>
    </thead>
    <tbody>
<?php
// ==================== 查詢資料 ====================
if ($filter_host) {
    $stmt = $link->prepare("
        SELECT ID, Risk, Host, Protocol, Port, Name, Priority 
        FROM Detail 
        WHERE Host = ? 
        ORDER BY Priority, INET_ATON(Host), Port, Name
    ");
    $stmt->bind_param('s', $filter_host);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $link->query("
        SELECT ID, Risk, Host, Protocol, Port, Name, Priority 
        FROM Detail 
        ORDER BY Priority, INET_ATON(Host), Port, Name
    ");
}

$row_count = 0;
$has_data = false;
$risk_stats = ['Critical' => 0, 'High' => 0, 'Medium' => 0, 'Low' => 0, 'None' => 0];

while ($row = $result->fetch_assoc()) {
    $has_data = true;
    $row_count++;
    
    $id = validateInt($row['ID'], 1);
    if ($id === false) continue;
    
    $risk = sanitizeString($row['Risk']);
    $host = sanitizeString($row['Host']);
    $protocol = sanitizeString($row['Protocol']);
    $port = sanitizeString($row['Port']);
    $name = sanitizeString($row['Name']);
    
    // 統計風險等級
    if (isset($risk_stats[$row['Risk']])) {
        $risk_stats[$row['Risk']]++;
    }
    
    $risk_badge = getRiskBadge($risk);
    $port_display = $protocol . '/' . $port;
    
    echo '<tr>';
    echo '<td class="text-center">';
    echo '<input type="checkbox" name="id[' . $id . ']" value="1" form="mainForm">';
    echo '</td>';
    echo '<td class="text-center">' . $row_count . '</td>';
    echo '<td class="text-center">' . $risk_badge . '</td>';
    echo '<td><strong>' . $host . '</strong></td>';
    echo '<td class="text-center">' . $port_display . '</td>';
    echo '<td>' . $name . '</td>';
    echo '</tr>';
}

// 無資料時的提示
if (!$has_data) {
    if ($filter_host) {
        renderEmptyState(6, '此主機沒有弱點資料', 'detail.php', '查看所有資料');
    } else {
        renderEmptyState(6, '目前沒有弱點資料', 'import.php', '前往匯入資料');
    }
}
?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="6" class="table-footer">
                顯示: <span id="visibleCount"><?php echo $row_count; ?></span> / <?php echo $row_count; ?> 筆弱點資料
            </td>
        </tr>
    </tfoot>
</table>

<?php
// ==================== 統計資訊 ====================
if ($has_data) {
    $host_count = getAffectedHostCount($link, $filter_host);
    renderRiskStatistics($risk_stats, $host_count, '筆弱點');
}

// ==================== 頁面執行時間 ====================
renderExecutionTime($start_time);

logAction('查看弱點掃描結果', $filter_host ? "主機: {$filter_host}" : "共 {$row_count} 筆資料");
?>

</div>
</body>
</html>

<?php
$link->close();
?>
