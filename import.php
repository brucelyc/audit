<?php
/**
 * 資料匯入與重整頁面
 * 功能：上傳CSV、Nessus報告、資料清理、匯出
 */

require_once('config.php');
requireAuth();

$link = getDBConnection();

// ==================== 處理 POST 請求 ====================
$message = '';
$start_time = microtime(true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Token 驗證
    if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        die(showAlert("安全驗證失敗，請重新操作", "danger"));
    }

    // 匯出功能
    if (isset($_POST['export1'])) {
        logAction("匯出使用者電腦資料");
        exportComputerData($link);
        exit;
    } elseif (isset($_POST['export2'])) {
        logAction("匯出弱點資料");
        exportNessusData($link);
        exit;
    } elseif (isset($_POST['demo1'])) {
        logAction("下載範例檔");
        exportDemoFile();
        exit;
    }
    
    // 上傳 Nessus 檔案
    elseif (isset($_POST['upload_nessus']) && isset($_FILES['nessusFile'])) {
        $message = uploadAndParseNessus($link);
        logAction("上傳 Nessus 報告", $message);
    }
    
    // 上傳使用者清單
    elseif (isset($_POST['user']) && isset($_FILES['filename'])) {
        $message = importCSVFile($link);
        logAction("上傳使用者清單", $message);
    }
    
    // 資料重整操作
    elseif (isset($_POST['erase0'])) {
        $message = eraseLeadingZeros($link);
        logAction("去除 IP 前置 0", $message);
    } elseif (isset($_POST['erase2'])) {
        $message = executeDelete($link, 
            "DELETE FROM Detail WHERE Name='SSL Certificate Cannot Be Trusted'", 
            "刪除 SSL Certificate 警告");
        logAction("刪除 SSL Certificate 警告", $message);
    } elseif (isset($_POST['erase3'])) {
        $message = executeDelete($link, 
            "DELETE FROM Detail WHERE Name='SSL Self-Signed Certificate'", 
            "刪除 SSL Self-Signed 警告");
        logAction("刪除 SSL Self-Signed 警告", $message);
    } elseif (isset($_POST['erase4'])) {
        $message = eraseNonUserHosts($link);
        logAction("去除使用者列表以外的弱掃資料", $message);
    } elseif (isset($_POST['erase6'])) {
        $message = eraseDuplicateData($link);
        logAction("去除重複資料", $message);
    } elseif (isset($_POST['erase7'])) {
        $message = executeDelete($link, 
            "DELETE FROM Detail WHERE Priority='4'", 
            "刪除 INFO 等級");
        logAction("刪除 INFO 等級", $message);
    }
}

include('header.php');

// 顯示訊息
if (!empty($message)) {
    echo $message;
}

$csrf_token = generateCSRFToken();

// ==================== 頁面內容 ====================
?>

<h1>資料匯入</h1>

<!-- 上傳按鈕區 -->
<div class="action-bar no-print">
    <button type="button" onclick="showUploadSection('csvUploadDiv')" class="btn btn-primary">
        上傳使用者清單
    </button>
    <button type="button" onclick="showUploadSection('nessusUploadDiv')" class="btn btn-primary">
        上傳 Nessus 報告
    </button>
</div>

<!-- 上傳使用者清單區塊 (預設隱藏) -->
<div id="csvUploadDiv" class="filter-box">
    <h3 class="filter-title">上傳使用者清單 (CSV)</h3>
    
    <form action="import.php" method="post" enctype="multipart/form-data" onsubmit="return validateCSVUpload(this);">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        
        <div class="file-upload-group">
            <input type="file" name="filename" id="csvFile" accept=".csv" required class="upload-input">
            <span class="file-hint">僅接受 .csv 檔案，最大 20MB</span>
        </div>
        
        <div class="button-group mt-2">
            <button type="submit" name="user" class="btn btn-primary">上傳使用者清單</button>
            <button type="submit" name="demo1" class="btn btn-secondary">下載範例檔</button>
            <button type="button" onclick="hideUploadSection('csvUploadDiv')" class="btn btn-secondary">取消</button>
        </div>
        
        <div class="format-note">
            <strong>檔案格式說明：</strong><br>
            <code class="code-highlight">IP,單位,姓名,類型</code><br>
            <span class="text-muted text-small">範例：192.168.1.1,資訊室,王小明,桌機</span>
        </div>
    </form>
</div>

<!-- 上傳 Nessus 報告區塊 (預設隱藏) -->
<div id="nessusUploadDiv" class="filter-box">
    <h3 class="filter-title">上傳 Nessus 掃描報告</h3>
    
    <form action="import.php" method="post" enctype="multipart/form-data" onsubmit="return validateNessusUpload(this);">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        
        <div class="file-upload-group">
            <input type="file" name="nessusFile" id="nessusFile" accept=".nessus,.xml" required class="upload-input">
            <span class="file-hint">僅接受 .nessus 或 .xml 檔案，最大 20MB</span>
        </div>
        
        <div class="button-group mt-2">
            <button type="submit" name="upload_nessus" class="btn btn-primary">上傳並匯入</button>
            <button type="button" onclick="hideUploadSection('nessusUploadDiv')" class="btn btn-secondary">取消</button>
        </div>
        
        <div class="warning-note">
            <strong>自動過濾規則：</strong>
            <ul style="margin: 8px 0; padding-left: 20px;">
                <li>Port 為 0 且 Risk Factor 為 None 的項目</li>
                <li>包含 "Nessus SYN scanner" 的項目</li>
            </ul>
        </div>
    </form>
</div>

<hr class="section-divider">

<!-- 資料重整 -->
<h1>資料重整</h1>

<div class="upload-section">
    <form action="import.php" method="post" onsubmit="return confirmCleanAction(event);">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        
        <div class="clean-grid">
            <button type="submit" name="erase0" class="btn btn-primary" data-action="safe">
                去除資料 IP 的前置 0
            </button>
            
            <button type="submit" name="erase2" class="btn btn-primary" data-action="safe">
                去除 SSL Certificate 警告
            </button>
            
            <button type="submit" name="erase3" class="btn btn-primary" data-action="safe">
                去除 SSL Self-Signed 警告
            </button>
            
            <button type="submit" name="erase4" class="btn btn-danger" data-action="danger">
                去除使用者列表以外的弱掃資料
            </button>
            
            <button type="submit" name="erase6" class="btn btn-primary" data-action="safe">
                去除重複資料
            </button>
            
            <button type="submit" name="erase7" class="btn btn-danger" data-action="danger">
                刪除 INFO 等級
            </button>
        </div>
    </form>
</div>

<hr class="section-divider">

<!-- 資料匯出 -->
<h1>資料匯出</h1>

<div class="upload-section">
    <form action="import.php" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        
        <div class="button-group">
            <button type="submit" name="export1" class="btn btn-success">使用者電腦資料匯出</button>
            <button type="submit" name="export2" class="btn btn-success">弱點資料匯出 (Nessus 解析)</button>
        </div>
    </form>
</div>

<?php
// 顯示執行時間
renderExecutionTime($start_time);
?>

<script>
/**
 * 顯示指定的上傳區塊，並隱藏其他上傳區塊
 */
function showUploadSection(sectionId) {
    // 隱藏所有上傳區塊
    const allSections = ['csvUploadDiv', 'nessusUploadDiv'];
    allSections.forEach(function(id) {
        const element = document.getElementById(id);
        if (element) {
            element.style.display = 'none';
        }
    });
    
    // 顯示指定的區塊
    const targetSection = document.getElementById(sectionId);
    if (targetSection) {
        targetSection.style.display = 'block';
    }
}

/**
 * 隱藏指定的上傳區塊
 */
function hideUploadSection(sectionId) {
    const element = document.getElementById(sectionId);
    if (element) {
        element.style.display = 'none';
    }
}

/**
 * 確認資料清理操作
 */
function confirmCleanAction(event) {
    const button = event.submitter;
    const actionType = button.getAttribute('data-action');
    
    if (actionType === 'danger') {
        return confirm('⚠️ 此操作將刪除資料，確定要繼續嗎？\n\n此操作無法復原！');
    }
    
    return confirm('確定要執行此資料重整操作嗎？');
}
</script>

</div>
</body>
</html>

<?php
$link->close();

// ==================== 核心功能函數 ====================

/**
 * 上傳並解析 Nessus 檔案
 */
function uploadAndParseNessus($link) {
    if (!function_exists('simplexml_load_file')) {
        return showAlert("系統錯誤：缺少 <code>php-xml</code> 套件", "danger");
    }
    
    $file = $_FILES['nessusFile'];
    
    $validation = validateFileUpload($file, ['nessus', 'xml'], 20971520);
    if ($validation !== true) {
        return showAlert($validation, "danger");
    }
    
    try {
        // PHP 8.0+ 不再需要 libxml_disable_entity_loader()
        // 外部實體載入預設已停用
        libxml_use_internal_errors(true);
        
        // 確保檔案路徑有效
        $file_path = $file['tmp_name'];
        if (!file_exists($file_path)) {
            return showAlert("上傳的檔案不存在，請重試", "danger");
        }
        
        // 使用 LIBXML_NONET 防止網路存取
        $xml = simplexml_load_file($file_path, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        
        if ($xml === false) {
            $errors = libxml_get_errors();
            if (!empty($errors)) {
                foreach ($errors as $error) {
                    error_log("XML 解析錯誤: " . trim($error->message));
                }
            }
            libxml_clear_errors();
            return showAlert("無法解析 XML 檔案，請確認格式是否正確", "danger");
        }
        
        if (!isset($xml->Report->ReportHost)) {
            return showAlert("Nessus 檔案中沒有掃描結果", "warning");
        }
        
        // 風險等級優先權對應
        $risk_priority = [
            "Critical" => 0, "High" => 1, "Medium" => 2, "Low" => 3, "None" => 4,
            "CRITICAL" => 0, "HIGH" => 1, "MEDIUM" => 2, "LOW" => 3, "NONE" => 4
        ];
        
        $success_count = 0;
        $filtered_count = 0;
        
        $stmt = $link->prepare("INSERT INTO Detail (Risk, Host, Protocol, Port, Name, Priority) VALUES (?, ?, ?, ?, ?, ?)");
        
        foreach ($xml->Report->ReportHost as $host) {
            $ip = sanitizeString((string)$host['name']);
            
            if (!validateIP($ip)) {
                error_log("跳過無效 IP: {$ip}");
                continue;
            }
            
            foreach ($host->ReportItem as $item) {
                $risk_factor = sanitizeString((string)$item->risk_factor);
                $protocol = sanitizeString((string)$item['protocol']);
                $port = sanitizeString((string)$item['port']);
                $plugin_name = sanitizeString((string)$item['pluginName']);
                
                // 過濾規則 1: Port 0 且 Risk None
                if ($port == '0' && $risk_factor == 'None') {
                    $filtered_count++;
                    continue;
                }
                
                // 過濾規則 2: Nessus SYN scanner
                if (strpos($plugin_name, 'Nessus SYN scanner') !== false) {
                    $filtered_count++;
                    continue;
                }
                
                $priority = $risk_priority[$risk_factor] ?? 4;
                
                try {
                    $stmt->bind_param('sssssi', $risk_factor, $ip, $protocol, $port, $plugin_name, $priority);
                    $stmt->execute();
                    $success_count++;
                } catch (Exception $e) {
                    error_log("Nessus 匯入錯誤 [{$ip}:{$port}]: " . $e->getMessage());
                }
            }
        }
        
        $stmt->close();
        
        if ($success_count == 0) {
            return showAlert("檔案解析完成，但沒有資料寫入。過濾了 {$filtered_count} 筆資料。", "warning");
        }
        
        return showAlert("Nessus 檔案匯入完成！<br>成功寫入 <strong>{$success_count}</strong> 筆資料，過濾了 <strong>{$filtered_count}</strong> 筆資料。", "success");
        
    } catch (Exception $e) {
        error_log("Nessus 解析錯誤: " . $e->getMessage());
        return showAlert("解析過程發生錯誤：" . sanitizeString($e->getMessage()), "danger");
    }
}

/**
 * 匯入 CSV 檔案
 */
function importCSVFile($link) {
    $file = $_FILES['filename'];
    $validation = validateFileUpload($file, ['csv'], 20971520);
    if ($validation !== true) {
        return showAlert($validation, "danger");
    }
    
    // 檢查 MIME 類型
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $allowed_mimes = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'];
    if (!in_array($mime, $allowed_mimes)) {
        return showAlert("無效的檔案格式", "danger");
    }
    
    // 確保檔案路徑有效
    $upload_path = $file['tmp_name'];
    if (!file_exists($upload_path)) {
        return showAlert("上傳的檔案不存在，請重試", "danger");
    }
    
    try {
        $csv_handle = fopen($upload_path, 'r');
        if (!$csv_handle) {
            return showAlert("無法開啟檔案", "danger");
        }
        
        $success_count = 0;
        $error_count = 0;
        $line_number = 0;
        
        $stmt = $link->prepare("INSERT INTO Computer (Host, Unit, Name, Property) VALUES (?, ?, ?, ?)");
        
        while (($row = fgetcsv($csv_handle, 1000, ',')) !== false) {
            $line_number++;
            
            // 編碼轉換
            $row = array_map(function($v) {
                return mb_convert_encoding($v, "UTF-8", "BIG5");
            }, $row);
            
            // 跳過空行
            if (empty(array_filter($row))) continue;
            
            // 跳過標題行
            if (trim($row[0]) == "IP") continue;
            
            // 確保有足夠欄位
            if (count($row) < 4) {
                $error_count++;
                continue;
            }
            
            $host = trim($row[0]);
            $unit = trim($row[1]);
            $name = trim($row[2]);
            $property = trim($row[3]);
            
            if (!validateIP($host)) {
                error_log("Line {$line_number}: 無效的 IP 地址: {$host}");
                $error_count++;
                continue;
            }
            
            // 限制欄位長度並使用 sanitizeString
            $unit = mb_substr(sanitizeString($unit), 0, 100, 'UTF-8');
            $name = mb_substr(sanitizeString($name), 0, 50, 'UTF-8');
            $property = mb_substr(sanitizeString($property), 0, 50, 'UTF-8');
            
            try {
                $stmt->bind_param('ssss', $host, $unit, $name, $property);
                $stmt->execute();
                $success_count++;
            } catch (Exception $e) {
                error_log("Line {$line_number} 匯入錯誤: " . $e->getMessage());
                $error_count++;
            }
        }
        
        $stmt->close();
        fclose($csv_handle);
        
        if ($success_count == 0) {
            return showAlert("檔案匯入完成，但沒有成功寫入資料。<br>請檢查檔案格式是否正確。總行數: {$line_number}，錯誤: {$error_count}", "warning");
        }
        
        $msg = "使用者清單匯入完成！<br>成功寫入 <strong>{$success_count}</strong> 筆資料";
        if ($error_count > 0) {
            $msg .= "，<strong>{$error_count}</strong> 筆失敗";
        }
        $msg .= " (共處理 {$line_number} 行)";
        
        return showAlert($msg, "success");
        
    } catch (Exception $e) {
        error_log("CSV 匯入錯誤: " . $e->getMessage());
        return showAlert("匯入過程發生錯誤：" . sanitizeString($e->getMessage()), "danger");
    }
}

/**
 * 匯出使用者電腦資料
 */
function exportComputerData($link) {
    $result = $link->query("SELECT Host, Unit, Name, Property FROM Computer ORDER BY INET_ATON(Host)");
    
    $content = mb_convert_encoding("IP,單位,姓名,類型\r\n", "BIG5", "UTF-8");
    
    while ($row = $result->fetch_assoc()) {
        $csv_line = array_map(function($v) {
            $v = str_replace('"', '""', $v);
            return '"' . mb_convert_encoding($v, "BIG5", "UTF-8") . '"';
        }, [$row['Host'], $row['Unit'], $row['Name'], $row['Property']]);
        
        $content .= implode(",", $csv_line) . "\r\n";
    }
    
    outputCSV("Computer_Export.csv", $content);
}

/**
 * 匯出 Nessus 解析資料
 */
function exportNessusData($link) {
    $result = $link->query("
        SELECT Risk, Host, Protocol, Port, Name 
        FROM Detail 
        ORDER BY Priority, INET_ATON(Host), Name, Port
    ");
    
    $content = mb_convert_encoding("Risk,Host,Protocol,Port,Name\r\n", "BIG5", "UTF-8");
    
    while ($row = $result->fetch_assoc()) {
        $csv_line = array_map(function($v) {
            $v = str_replace('"', '""', $v);
            return '"' . mb_convert_encoding($v, "BIG5", "UTF-8") . '"';
        }, [$row['Risk'], $row['Host'], $row['Protocol'], $row['Port'], $row['Name']]);
        
        $content .= implode(",", $csv_line) . "\r\n";
    }
    
    outputCSV("Nessus_Export.csv", $content);
}

/**
 * 匯出範例檔案
 */
function exportDemoFile() {
    $content = mb_convert_encoding("IP,單位,姓名,類型\r\n", "BIG5", "UTF-8");
    $content .= mb_convert_encoding("192.168.1.1,資訊室,王小明,桌機\r\n", "BIG5", "UTF-8");
    $content .= mb_convert_encoding("192.168.1.2,會計室,李小華,筆電\r\n", "BIG5", "UTF-8");
    $content .= mb_convert_encoding("192.168.1.3,人事室,陳大偉,桌機\r\n", "BIG5", "UTF-8");
    
    outputCSV("Computer_Demo.csv", $content);
}

/**
 * 去除 IP 前置 0
 */
function eraseLeadingZeros($link) {
    $result = $link->query("SELECT ID, Host FROM Computer");
    $updated = 0;
    
    $stmt = $link->prepare("UPDATE Computer SET Host=? WHERE ID=?");
    
    while ($row = $result->fetch_assoc()) {
        $ip_parts = explode(".", $row['Host']);
        $ip_parts = array_map('intval', $ip_parts);
        $clean_ip = implode(".", $ip_parts);
        
        if ($clean_ip !== $row['Host']) {
            $stmt->bind_param('si', $clean_ip, $row['ID']);
            $stmt->execute();
            $updated++;
        }
    }
    
    $stmt->close();
    return showAlert("資料重整完成！共更新 {$updated} 筆資料。", "success");
}

/**
 * 去除使用者列表以外的主機
 */
function eraseNonUserHosts($link) {
    $result = $link->query("SELECT Host FROM Computer");
    $host_arr = [];
    
    while ($row = $result->fetch_assoc()) {
        $host_arr[] = $row['Host'];
    }
    
    if (empty($host_arr)) {
        return showAlert("沒有找到使用者主機資料", "warning");
    }
    
    $placeholders = implode(',', array_fill(0, count($host_arr), '?'));
    $stmt = $link->prepare("DELETE FROM Detail WHERE Host NOT IN ($placeholders)");
    
    $types = str_repeat('s', count($host_arr));
    $stmt->bind_param($types, ...$host_arr);
    $stmt->execute();
    
    $deleted = $stmt->affected_rows;
    $stmt->close();
    
    return showAlert("資料重整完成！刪除了 {$deleted} 筆不在使用者列表中的弱點資料。", "success");
}

/**
 * 去除重複資料
 */
function eraseDuplicateData($link) {
    $result = $link->query("
        SELECT COUNT(*) as n, MIN(ID) as min_id, Risk, Host, Name 
        FROM Detail 
        GROUP BY Risk, Host, Name 
        HAVING COUNT(*) > 1
    ");
    
    $stmt = $link->prepare("DELETE FROM Detail WHERE Risk=? AND Host=? AND Name=? AND ID <> ?");
    $deleted = 0;
    
    while ($row = $result->fetch_assoc()) {
        $stmt->bind_param('sssi', $row['Risk'], $row['Host'], $row['Name'], $row['min_id']);
        $stmt->execute();
        $deleted += $stmt->affected_rows;
    }
    
    $stmt->close();
    
    if ($deleted > 0) {
        return showAlert("資料重整完成！刪除了 {$deleted} 筆重複資料。", "success");
    }
    
    return showAlert("沒有找到重複資料。", "info");
}

/**
 * 執行刪除 SQL
 */
function executeDelete($link, $sql, $description = "資料刪除") {
    $result = $link->query($sql);
    $affected = $link->affected_rows;
    
    if ($affected > 0) {
        return showAlert("{$description}完成！刪除了 {$affected} 筆資料。", "success");
    }
    
    return showAlert("沒有找到符合條件的資料。", "info");
}
?>
