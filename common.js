/**
 * 共用 JavaScript 函數庫
 * 用於 Nessus 弱點管理系統
 */

// ==================== 表單確認對話框 ====================

/**
 * 確認刪除操作
 * @param {HTMLFormElement} form - 表單元素
 * @returns {boolean} - 是否繼續提交
 */
function confirmDelete(form) {
    // 刪除所有
    if (form.delAll && form.delAll === document.activeElement) {
        return confirm('確定要刪除所有資料嗎？\n\n此操作無法復原！');
    }
    
    // 刪除勾選
    if (form.delCheck && form.delCheck === document.activeElement) {
        const checked = document.querySelectorAll('input[name^="id["]:checked').length;
        if (checked === 0) {
            alert('請先勾選要刪除的項目');
            return false;
        }
        
        const isRelation = form.relation && form.relation.checked;
        if (isRelation) {
            return confirm('確定要刪除勾選項目的所有同 IP 資料嗎？\n\n這將刪除這些主機的所有弱點記錄！');
        }
        
        return confirm(`確定要刪除 ${checked} 筆資料嗎？\n\n此操作無法復原！`);
    }
    
    return true;
}

/**
 * 確認一般操作
 * @param {string} message - 確認訊息
 * @returns {boolean}
 */
function confirmAction(message) {
    return confirm(message || '確定要執行此操作嗎？');
}

// ==================== 勾選框操作 ====================

/**
 * 更新已勾選數量顯示
 */
function updateSelectedCount() {
    const count = document.querySelectorAll('input[name^="id["]:checked').length;
    const element = document.getElementById('selectedCount');
    if (element) {
        element.textContent = count;
    }
}

/**
 * 全選/取消全選
 * @param {HTMLInputElement} checkbox - 主控勾選框
 */
function toggleAll(checkbox) {
    const checkboxes = document.querySelectorAll('input[name^="id["]');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateSelectedCount();
}

// ==================== 篩選功能 ====================

/**
 * 切換顯示/隱藏元素
 * @param {string} elementId - 元素 ID
 */
function toggleElement(elementId) {
    const element = document.getElementById(elementId);
    if (element) {
        element.style.display = (element.style.display === 'none' || element.style.display === '') 
            ? 'block' 
            : 'none';
    }
}

/**
 * 切換 IP 篩選區塊
 */
function toggleIPFilter() {
    toggleElement('ipFilterDiv');
}

/**
 * 依風險等級篩選表格
 * @param {string} risk - 風險等級 (Critical, High, Medium, Low, None, all)
 * @param {HTMLElement} button - 觸發的按鈕元素
 */
function filterByRisk(risk, button) {
    const rows = document.querySelectorAll('#dataTable tbody tr');
    let visibleCount = 0;
    
    rows.forEach(row => {
        // 跳過空白列或合併列
        if (row.querySelector('td[colspan]')) return;
        
        if (risk === 'all') {
            row.style.display = '';
            visibleCount++;
        } else {
            const riskCell = row.querySelector('td:nth-child(3) .badge');
            const riskText = riskCell ? riskCell.textContent.trim() : '';
            
            if (riskText === risk) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        }
    });
    
    // 更新可見數量
    const visibleCountElement = document.getElementById('visibleCount');
    if (visibleCountElement) {
        visibleCountElement.textContent = visibleCount;
    }
    
    // 更新按鈕狀態
    updateFilterButtons('[onclick*="filterByRisk"]', button);
}

/**
 * 更新篩選按鈕的視覺狀態
 * @param {string} selector - 按鈕選擇器
 * @param {HTMLElement} activeButton - 當前選中的按鈕
 */
function updateFilterButtons(selector, activeButton) {
    const allButtons = document.querySelectorAll(selector + ' button');
    allButtons.forEach(btn => {
        btn.style.opacity = '0.6';
        btn.style.fontWeight = 'normal';
    });
    
    if (activeButton) {
        activeButton.style.opacity = '1';
        activeButton.style.fontWeight = 'bold';
    }
}

// ==================== 表格排序 ====================

/**
 * 表格排序
 * @param {string} sortType - 排序類型 (risk, ip, port, name)
 * @param {HTMLElement} button - 觸發的按鈕元素
 */
function sortTable(sortType, button) {
    const tbody = document.querySelector('#dataTable tbody');
    if (!tbody) return;
    
    const rows = Array.from(tbody.querySelectorAll('tr')).filter(row => 
        !row.querySelector('td[colspan]')
    );
    
    if (rows.length === 0) return;
    
    // 完整的風險等級順序對應表 (數字越小優先級越高)
    const riskOrder = {
        'Critical': 0, 
        'High': 1, 
        'Medium': 2, 
        'Low': 3,
        'Info': 4,
        'None': 5
    };
    
    rows.sort((a, b) => {
        switch(sortType) {
            case 'risk':
                return sortByRisk(a, b, riskOrder);
            case 'ip':
                return sortByIP(a, b);
            case 'port':
                return sortByPort(a, b);
            case 'name':
                return sortByName(a, b);
            default:
                return 0;
        }
    });
    
    // 重新排列並更新序號
    rows.forEach((row, index) => {
        const numberCell = row.querySelector('td:nth-child(2)');
        if (numberCell) {
            numberCell.textContent = index + 1;
        }
        tbody.appendChild(row);
    });
    
    // 更新按鈕狀態
    updateFilterButtons('[onclick*="sortTable"]', button);
}

/**
 * 依風險等級排序
 */
function sortByRisk(a, b, riskOrder) {
    const badgeA = a.querySelector('td:nth-child(3) .badge');
    const badgeB = b.querySelector('td:nth-child(3) .badge');
    const riskA = badgeA ? badgeA.textContent.trim() : 'None';
    const riskB = badgeB ? badgeB.textContent.trim() : 'None';
    
    // 如果風險等級不在對應表中，設定一個很大的數字讓它排在最後
    const orderA = riskOrder.hasOwnProperty(riskA) ? riskOrder[riskA] : 999;
    const orderB = riskOrder.hasOwnProperty(riskB) ? riskOrder[riskB] : 999;
    
    return orderA - orderB;
}

/**
 * 依 IP 地址排序
 */
function sortByIP(a, b) {
    const ipA = a.querySelector('td:nth-child(4)').textContent.trim();
    const ipB = b.querySelector('td:nth-child(4)').textContent.trim();
    
    const parseIP = (ip) => {
        return ip.split('.').reduce((acc, octet) => 
            (acc << 8) + parseInt(octet || 0, 10), 0
        );
    };
    
    return parseIP(ipA) - parseIP(ipB);
}

/**
 * 依埠號排序
 */
function sortByPort(a, b) {
    const textA = a.querySelector('td:nth-child(5)').textContent.trim();
    const textB = b.querySelector('td:nth-child(5)').textContent.trim();
    
    const portA = textA.includes('/') ? parseInt(textA.split('/')[1] || 0, 10) : 0;
    const portB = textB.includes('/') ? parseInt(textB.split('/')[1] || 0, 10) : 0;
    
    return portA - portB;
}

/**
 * 依名稱排序
 */
function sortByName(a, b) {
    const textA = a.querySelector('td:nth-child(6)').textContent.trim();
    const textB = b.querySelector('td:nth-child(6)').textContent.trim();
    return textA.localeCompare(textB, 'zh-TW');
}

// ==================== 檔案上傳驗證 ====================

/**
 * 驗證 CSV 檔案上傳
 * @param {HTMLFormElement} form - 表單元素
 * @returns {boolean}
 */
function validateCSVUpload(form) {
    const fileInput = document.getElementById('csvFile');
    const file = fileInput ? fileInput.files[0] : null;
    
    if (!file) {
        alert('請選擇檔案');
        return false;
    }
    
    // 檢查副檔名
    const fileName = file.name.toLowerCase();
    if (!fileName.endsWith('.csv')) {
        alert('只能上傳 .csv 檔案');
        return false;
    }
    
    // 檢查檔案大小 (20MB)
    if (file.size > 20 * 1024 * 1024) {
        alert('檔案大小不能超過 20MB');
        return false;
    }
    
    return confirm('確定要上傳並匯入此使用者清單嗎？');
}

/**
 * 驗證 Nessus 檔案上傳
 * @param {HTMLFormElement} form - 表單元素
 * @returns {boolean}
 */
function validateNessusUpload(form) {
    const fileInput = document.getElementById('nessusFile');
    const file = fileInput ? fileInput.files[0] : null;
    
    if (!file) {
        alert('請選擇檔案');
        return false;
    }
    
    // 檢查副檔名
    const fileName = file.name.toLowerCase();
    if (!fileName.endsWith('.nessus') && !fileName.endsWith('.xml')) {
        alert('只能上傳 .nessus 或 .xml 檔案');
        return false;
    }
    
    // 檢查檔案大小 (20MB)
    if (file.size > 20 * 1024 * 1024) {
        alert('檔案大小不能超過 20MB');
        return false;
    }
    
    return confirm('確定要上傳並匯入此 Nessus 報告嗎？\n\n這可能需要一些時間處理。');
}

// ==================== 工具函數 ====================

/**
 * 格式化檔案大小
 * @param {number} bytes - 位元組數
 * @returns {string}
 */
function formatFileSize(bytes) {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    bytes = Math.max(bytes, 0);
    const pow = Math.floor((bytes ? Math.log(bytes) : 0) / Math.log(1024));
    const finalPow = Math.min(pow, units.length - 1);
    bytes /= Math.pow(1024, finalPow);
    return bytes.toFixed(2) + ' ' + units[finalPow];
}

/**
 * 顯示載入指示器
 * @param {boolean} show - 是否顯示
 */
function toggleLoading(show) {
    let loader = document.getElementById('loadingIndicator');
    
    if (show && !loader) {
        loader = document.createElement('div');
        loader.id = 'loadingIndicator';
        loader.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        `;
        loader.innerHTML = '<div style="background: white; padding: 20px; border-radius: 8px;">載入中...</div>';
        document.body.appendChild(loader);
    } else if (!show && loader) {
        loader.remove();
    }
}

/**
 * 安全地解析 JSON
 * @param {string} jsonString - JSON 字串
 * @param {*} defaultValue - 預設值
 * @returns {*}
 */
function safeJSONParse(jsonString, defaultValue = null) {
    try {
        return JSON.parse(jsonString);
    } catch (e) {
        console.error('JSON 解析失敗:', e);
        return defaultValue;
    }
}

// ==================== 頁面初始化 ====================

/**
 * 當 DOM 準備完成時執行
 * @param {Function} callback - 回調函數
 */
function onDOMReady(callback) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback);
    } else {
        callback();
    }
}

// ==================== 匯出全域函數 (確保向後相容) ====================

// 確保所有函數在全域範圍內可用
if (typeof window !== 'undefined') {
    window.confirmDelete = confirmDelete;
    window.confirmAction = confirmAction;
    window.updateSelectedCount = updateSelectedCount;
    window.toggleAll = toggleAll;
    window.toggleElement = toggleElement;
    window.toggleIPFilter = toggleIPFilter;
    window.filterByRisk = filterByRisk;
    window.sortTable = sortTable;
    window.validateCSVUpload = validateCSVUpload;
    window.validateNessusUpload = validateNessusUpload;
    window.formatFileSize = formatFileSize;
    window.toggleLoading = toggleLoading;
    window.safeJSONParse = safeJSONParse;
    window.onDOMReady = onDOMReady;
}
