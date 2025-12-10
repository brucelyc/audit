<?php
/**
 * 資料彙總頁面
 * 提供多種資料檢視模式：全部、僅中高風險、統計、IP排序、弱點分類
 */

require_once('config.php');
requireAuth();

$start_time = microtime(true);
$link = getDBConnection();

// ==================== 判斷當前模式 ====================
$allowed_modes = ['all', 'onlyHM', 'onlyTotal', 'sortByIP', 'sortByKind'];
$mode = 'all';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        die(showAlert("安全驗證失敗，請重新操作", "danger"));
    }
    
    if (isset($_POST['onlyHM'])) $mode = 'onlyHM';
    elseif (isset($_POST['onlyTotal'])) $mode = 'onlyTotal';
    elseif (isset($_POST['sortByIP'])) $mode = 'sortByIP';
    elseif (isset($_POST['sortByKind'])) $mode = 'sortByKind';
    
    if (!in_array($mode, $allowed_modes)) {
        $mode = 'all';
    }
}

$csrf_token = generateCSRFToken();

include('header.php');
?>

<h1>資料彙總</h1>

<!-- 模式選擇按鈕 -->
<div class="action-bar">
    <form method="POST" action="sum.php" style="display: inline;">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <button type="submit" name="all" class="btn btn-primary <?php echo $mode === 'all' ? 'active' : ''; ?>">列出全部</button>
        <button type="submit" name="onlyHM" class="btn btn-primary <?php echo $mode === 'onlyHM' ? 'active' : ''; ?>">僅列中高</button>
        <button type="submit" name="onlyTotal" class="btn btn-primary <?php echo $mode === 'onlyTotal' ? 'active' : ''; ?>">僅列統計</button>
        <button type="submit" name="sortByIP" class="btn btn-primary <?php echo $mode === 'sortByIP' ? 'active' : ''; ?>">依IP排序統計</button>
        <button type="submit" name="sortByKind" class="btn btn-primary <?php echo $mode === 'sortByKind' ? 'active' : ''; ?>">依弱點統計</button>
    </form>
</div>

<?php
// ==================== 根據模式顯示資料 ====================
switch ($mode) {
    case 'sortByKind':
        displayByCategory($link);
        break;
    case 'onlyTotal':
    case 'sortByIP':
        displaySummary($link, $mode);
        break;
    default:
        displayDetail($link, $mode);
        break;
}

// ==================== 頁面執行時間 ====================
renderExecutionTime($start_time);

logAction('查看資料彙總', "模式: {$mode}");

echo "</div>\n</body>\n</html>";
$link->close();

// ==================== 顯示函數 ====================

/**
 * 依類別統計顯示
 */
function displayByCategory($link) {
    $stmt = $link->prepare("
        SELECT Name, Risk, COUNT(DISTINCT Host) AS Hosts 
        FROM Detail 
        GROUP BY Name, Risk 
        ORDER BY 
            FIELD(Risk, 'Critical', 'High', 'Medium', 'Low', 'None'),
            Hosts DESC
    ");
    
    if (!$stmt) {
        error_log("SQL prepare failed: " . $link->error);
        echo showAlert("查詢失敗，請稍後再試", "danger");
        return;
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $rows = [];
    $risk_stats = ['Critical' => 0, 'High' => 0, 'Medium' => 0, 'Low' => 0, 'None' => 0];
    
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
        if (isset($risk_stats[$row['Risk']])) {
            $risk_stats[$row['Risk']]++;
        }
    }
    $stmt->close();
    
    // 顯示表格
    echo '<table id="dataTable">';
    renderTableHeader([
        ['text' => 'No.', 'width' => 'col-number', 'center' => true],
        ['text' => '弱點名稱', 'width' => 'col-vuln-name'],
        ['text' => '風險等級', 'width' => 'col-risk', 'center' => true],
        ['text' => '影響主機數', 'width' => 'col-number', 'center' => true]
    ]);
    echo '<tbody>';
    
    if (empty($rows)) {
        renderEmptyState(4, '目前沒有弱點資料', 'import.php', '前往匯入資料');
    } else {
        $i = 1;
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td class="text-center">' . $i . '</td>';
//            echo '<td>' . sanitizeString($row['Name']) . '</td>';
            echo '<td>' . sanitizeOutput($row['Name']) . '</td>';
            echo '<td class="text-center">' . getRiskBadge($row['Risk']) . '</td>';
            echo '<td class="text-center"><strong>' . intval($row['Hosts']) . '</strong></td>';
            echo '</tr>';
            $i++;
        }
    }
    
    echo '</tbody>';
    
    if (!empty($rows)) {
        renderTableFooter(4, '總計弱點種類:', count($rows), false);
    }
    
    echo '</table>';
    
    if (!empty($rows)) {
        $total_hosts = getAffectedHostCount($link);
        renderRiskStatistics($risk_stats, $total_hosts, '種弱點');
    }
}

/**
 * 統計模式顯示
 */
function displaySummary($link, $mode) {
    $data = aggregateData($link);
    
    if (empty($data)) {
        echo '<table id="dataTable">';
        renderTableHeader([
            ['text' => 'No.', 'width' => 'col-number', 'center' => true],
            ['text' => '單位', 'width' => 'col-unit'],
            ['text' => '主機IP', 'width' => 'col-ip'],
            ['text' => 'Critical', 'width' => 'col-number', 'center' => true],
            ['text' => 'High', 'width' => 'col-number', 'center' => true],
            ['text' => 'Medium', 'width' => 'col-number', 'center' => true],
            ['text' => '開放埠數', 'width' => 'col-number', 'center' => true]
        ]);
        echo '<tbody>';
        renderEmptyState(7, '目前沒有弱點資料', 'import.php', '前往匯入資料');
        echo '</tbody>';
        echo '</table>';
        return;
    }
    
    // 排序
    if ($mode === 'sortByIP') {
        uksort($data, 'compareIP');
    } else {
        uasort($data, 'compareRiskCount');
    }
    
    $risk_stats = ['Critical' => 0, 'High' => 0, 'Medium' => 0, 'Low' => 0, 'None' => 0];
    $totals = ['critical' => 0, 'high' => 0, 'medium' => 0, 'ports' => 0];
    
    // 顯示表格
    echo '<table id="dataTable">';
    renderTableHeader([
        ['text' => 'No.', 'width' => 'col-number', 'center' => true],
        ['text' => '單位', 'width' => 'col-unit'],
        ['text' => '主機IP', 'width' => 'col-ip'],
        ['text' => 'Critical', 'width' => 'col-number', 'center' => true],
        ['text' => 'High', 'width' => 'col-number', 'center' => true],
        ['text' => 'Medium', 'width' => 'col-number', 'center' => true],
        ['text' => '開放埠數', 'width' => 'col-number', 'center' => true]
    ]);
    echo '<tbody>';
    
    $i = 1;
    foreach ($data as $host => $info) {
        $critical = intval($info['CSUM']);
        $high = intval($info['HSUM']);
        $medium = intval($info['MSUM']);
        $ports = count($info['Port']);
        
        $totals['critical'] += $critical;
        $totals['high'] += $high;
        $totals['medium'] += $medium;
        $totals['ports'] += $ports;
        
        $risk_stats['Critical'] += $critical;
        $risk_stats['High'] += $high;
        $risk_stats['Medium'] += $medium;
        
        echo '<tr>';
        echo '<td class="text-center">' . $i . '</td>';
        echo '<td>' . sanitizeString($info['Unit']) . '</td>';
        echo '<td><a href="detail.php?host=' . urlencode($host) . '" class="action-link">' . sanitizeString($host) . '</a></td>';
        echo '<td class="text-center">' . formatRiskCount($critical, 'danger') . '</td>';
        echo '<td class="text-center">' . formatRiskCount($high, '#f0ad4e') . '</td>';
        echo '<td class="text-center">' . formatRiskCount($medium, '#ffc107') . '</td>';
        echo '<td class="text-center">' . $ports . '</td>';
        echo '</tr>';
        $i++;
    }
    
    echo '</tbody>';
    echo '<tfoot>';
    echo '<tr class="table-footer">';
    echo '<td colspan="3" class="text-right" style="padding-right: 20px;">總計</td>';
    echo '<td class="text-center text-danger">' . $totals['critical'] . '</td>';
    echo '<td class="text-center" style="color: #f0ad4e;">' . $totals['high'] . '</td>';
    echo '<td class="text-center" style="color: #ffc107;">' . $totals['medium'] . '</td>';
    echo '<td class="text-center">' . $totals['ports'] . '</td>';
    echo '</tr>';
    echo '</tfoot>';
    echo '</table>';
    
    renderRiskStatistics($risk_stats, count($data), '筆弱點');
}

/**
 * 詳細模式顯示
 */
function displayDetail($link, $mode) {
    $data = aggregateData($link);
    
    if (empty($data)) {
        echo '<table id="dataTable">';
        renderTableHeader([
            ['text' => 'No.', 'width' => 'col-number', 'center' => true],
            ['text' => '單位', 'width' => 'col-unit'],
            ['text' => '主機IP', 'width' => 'col-ip'],
            ['text' => '風險等級', 'width' => 'col-risk', 'center' => true],
            ['text' => '協定', 'width' => 'col-number', 'center' => true],
            ['text' => '通訊埠', 'width' => 'col-port', 'center' => true],
            ['text' => '弱點名稱', 'width' => 'col-vuln-name']
        ]);
        echo '<tbody>';
        renderEmptyState(7, '目前沒有弱點資料', 'import.php', '前往匯入資料');
        echo '</tbody>';
        echo '</table>';
        return;
    }
    
    $flat_data = flattenData($data, $mode);
    usort($flat_data, 'compareRiskLevel');
    
    $risk_stats = ['Critical' => 0, 'High' => 0, 'Medium' => 0, 'Low' => 0, 'None' => 0];
    
    // 顯示表格
    echo '<table id="dataTable">';
    renderTableHeader([
        ['text' => 'No.', 'width' => 'col-number', 'center' => true],
        ['text' => '單位', 'width' => 'col-unit'],
        ['text' => '主機IP', 'width' => 'col-ip'],
        ['text' => '風險等級', 'width' => 'col-risk', 'center' => true],
        ['text' => '協定', 'width' => 'col-number', 'center' => true],
        ['text' => '通訊埠', 'width' => 'col-port', 'center' => true],
        ['text' => '弱點名稱', 'width' => 'col-vuln-name']
    ]);
    echo '<tbody>';
    
    if (empty($flat_data)) {
        renderEmptyState(7, '目前沒有符合條件的資料');
    } else {
        $i = 1;
        foreach ($flat_data as $item) {
            if (isset($risk_stats[$item['risk']])) {
                $risk_stats[$item['risk']]++;
            }
            
            echo '<tr>';
            echo '<td class="text-center">' . $i . '</td>';
            echo '<td>' . sanitizeString($item['unit']) . '</td>';
            echo '<td><a href="detail.php?host=' . urlencode($item['host']) . '" class="action-link">' . sanitizeString($item['host']) . '</a></td>';
            echo '<td class="text-center">' . getRiskBadge($item['risk']) . '</td>';
            echo '<td class="text-center">' . sanitizeString($item['protocol']) . '</td>';
            echo '<td class="text-center">' . sanitizeString($item['port']) . '</td>';
//            echo '<td>' . sanitizeString($item['name']) . '</td>';
            echo '<td>' . sanitizeOutput($item['name']) . '</td>';
            echo '</tr>';
            $i++;
        }
    }
    
    echo '</tbody>';
    
    if (!empty($flat_data)) {
        renderTableFooter(7, '顯示:', count($flat_data), false);
    }
    
    echo '</table>';
    
    if (!empty($flat_data)) {
        renderRiskStatistics($risk_stats, count($data), '筆弱點');
    }
}

// ==================== 輔助函數 ====================

/**
 * 格式化風險數量顯示
 */
function formatRiskCount($count, $color) {
    if ($count > 0) {
        return '<span style="color: ' . $color . '; font-weight: bold;">' . $count . '</span>';
    }
    return '0';
}

/**
 * 展開資料為平面結構
 */
function flattenData($data, $mode) {
    $flat_data = [];
    foreach ($data as $host => $info) {
        foreach ($info['Risk'] as $risk => $ports) {
            if ($mode === 'onlyHM' && in_array(strtolower($risk), ['none', 'low'])) {
                continue;
            }
            
            foreach ($ports as $port => $names) {
                foreach ($names as $name => $data_item) {
                    $flat_data[] = [
                        'host' => $host,
                        'unit' => $info['Unit'],
                        'risk' => $risk,
                        'port' => $port,
                        'name' => $name,
                        'protocol' => $data_item['Protocol']
                    ];
                }
            }
        }
    }
    return $flat_data;
}

// ==================== 比較函數 ====================

/**
 * IP 地址比較
 */
function compareIP($a, $b) {
    $ip_a = filter_var($a, FILTER_VALIDATE_IP) ? ip2long($a) : 0;
    $ip_b = filter_var($b, FILTER_VALIDATE_IP) ? ip2long($b) : 0;
    return $ip_a - $ip_b;
}

/**
 * 風險數量比較
 */
function compareRiskCount($a, $b) {
    if ($a['CSUM'] != $b['CSUM']) return $b['CSUM'] - $a['CSUM'];
    if ($a['HSUM'] != $b['HSUM']) return $b['HSUM'] - $a['HSUM'];
    return $b['MSUM'] - $a['MSUM'];
}

/**
 * 風險等級比較
 */
function compareRiskLevel($a, $b) {
    $risk_order = ['Critical' => 0, 'High' => 1, 'Medium' => 2, 'Low' => 3, 'None' => 4];
    $order_a = $risk_order[$a['risk']] ?? 999;
    $order_b = $risk_order[$b['risk']] ?? 999;
    return $order_a - $order_b;
}
?>
