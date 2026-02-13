// Modal functions
function openModal(modalId) {
    document.getElementById(modalId).style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

// Stock Management - Move to Retail
var movePiecesPerPackage = 0;
var moveAvailablePieces = 0;
var moveAvailablePackages = 0;

function openMoveModal(productId, productName, availablePieces, piecesPerPackage, availablePackages) {
    movePiecesPerPackage = piecesPerPackage;
    moveAvailablePieces = availablePieces;
    moveAvailablePackages = availablePackages;

    document.getElementById('move_product_id').value = productId;
    document.getElementById('move_pieces_per_package').value = piecesPerPackage;
    document.getElementById('move_product_name').innerHTML = productName;
    document.getElementById('available_stock_info').innerHTML =
        availablePackages + ' packages (' + piecesPerPackage + ' pcs/pkg) = ' + availablePieces + ' total pieces';

    // Reset form
    document.getElementById('move_type_packages').checked = true;
    document.getElementById('packages_to_move').value = '';
    document.getElementById('pieces_to_move').value = '';
    document.getElementById('move_summary').style.display = 'none';
    toggleMoveType();

    openModal('moveStockModal');
}

function toggleMoveType() {
    var isPackages = document.getElementById('move_type_packages').checked;
    var pkgGroup = document.getElementById('packages_input_group');
    var pcsGroup = document.getElementById('pieces_input_group');
    var pkgInput = document.getElementById('packages_to_move');
    var pcsInput = document.getElementById('pieces_to_move');

    if (isPackages) {
        pkgGroup.style.display = 'block';
        pcsGroup.style.display = 'none';
        pkgInput.required = true;
        pkgInput.max = moveAvailablePackages;
        pcsInput.required = false;
        pcsInput.value = '';
    } else {
        pkgGroup.style.display = 'none';
        pcsGroup.style.display = 'block';
        pcsInput.required = true;
        pcsInput.max = moveAvailablePieces;
        pkgInput.required = false;
        pkgInput.value = '';
    }
    document.getElementById('move_summary').style.display = 'none';
}

function calculateFromPackages() {
    var pkgs = parseInt(document.getElementById('packages_to_move').value) || 0;
    var summary = document.getElementById('move_summary');
    var calc = document.getElementById('move_calculation');

    if (pkgs > 0) {
        var totalPieces = pkgs * movePiecesPerPackage;
        calc.innerHTML = pkgs + ' package(s) &times; ' + movePiecesPerPackage + ' pcs/pkg = <strong>' + totalPieces + ' pieces</strong> will be moved to retail';
        summary.style.display = 'block';

        if (pkgs > moveAvailablePackages) {
            calc.innerHTML += '<br><span style="color:red;">Exceeds available packages (' + moveAvailablePackages + ')</span>';
        }
    } else {
        summary.style.display = 'none';
    }
}

function calculateFromPieces() {
    var pcs = parseInt(document.getElementById('pieces_to_move').value) || 0;
    var summary = document.getElementById('move_summary');
    var calc = document.getElementById('move_calculation');

    if (pcs > 0) {
        var pkgsNeeded = Math.ceil(pcs / movePiecesPerPackage);
        calc.innerHTML = pcs + ' pieces (will deduct ' + pkgsNeeded + ' package(s) from warehouse)';
        summary.style.display = 'block';

        if (pcs > moveAvailablePieces) {
            calc.innerHTML += '<br><span style="color:red;">Exceeds available pieces (' + moveAvailablePieces + ')</span>';
        }
    } else {
        summary.style.display = 'none';
    }
}

// Sales - Calculate total amount
document.addEventListener('DOMContentLoaded', function() {
     // Check if chart already exists

    // Bulk sale total calculation
    const bulkProductSelect = document.getElementById('bulk_product_id');
    const bulkQuantity = document.getElementById('bulk_quantity');
    const bulkTotal = document.getElementById('bulk_total');
    
    if (bulkProductSelect && bulkQuantity && bulkTotal) {
        function calculateBulkTotal() {
            const selectedOption = bulkProductSelect.options[bulkProductSelect.selectedIndex];
            const price = parseFloat(selectedOption.dataset.price) || 0;
            const quantity = parseFloat(bulkQuantity.value) || 0;
            const total = price * quantity;
            bulkTotal.innerHTML = 'RWF ' + total.toLocaleString();
        }
        
        bulkProductSelect.addEventListener('change', calculateBulkTotal);
        bulkQuantity.addEventListener('input', calculateBulkTotal);
        
    }
    
    // Retail sale total calculation
    const retailProductSelect = document.getElementById('retail_product_id');
    const piecesSold = document.getElementById('pieces_sold');
    const retailTotal = document.getElementById('retail_total');
    
    if (retailProductSelect && piecesSold && retailTotal) {
        function calculateRetailTotal() {
            const selectedOption = retailProductSelect.options[retailProductSelect.selectedIndex];
            const price = parseFloat(selectedOption.dataset.price) || 0;
            const pieces = parseFloat(piecesSold.value) || 0;
            const total = price * pieces;
            retailTotal.innerHTML = 'RWF ' + total.toLocaleString();
        }
        
        retailProductSelect.addEventListener('change', calculateRetailTotal);
        piecesSold.addEventListener('input', calculateRetailTotal);
    }
});

// Form validation
function validateForm(formId) {
    const form = document.getElementById(formId);
    if (!form) return true;
    
    const inputs = form.querySelectorAll('input[required], select[required]');
    for (let input of inputs) {
        if (!input.value) {
            alert('Please fill in all required fields');
            input.focus();
            return false;
        }
    }
    return true;
}

// Confirmation dialogs
function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

// Search functionality for tables
function searchTable(inputId, tableId) {
    const input = document.getElementById(inputId);
    const filter = input.value.toUpperCase();
    const table = document.getElementById(tableId);
    const rows = table.getElementsByTagName('tr');
    
    for (let i = 1; i < rows.length; i++) {
        const cells = rows[i].getElementsByTagName('td');
        let found = false;
        
        for (let j = 0; j < cells.length; j++) {
            const cell = cells[j];
            if (cell) {
                const textValue = cell.textContent || cell.innerText;
                if (textValue.toUpperCase().indexOf(filter) > -1) {
                    found = true;
                    break;
                }
            }
        }
        
        rows[i].style.display = found ? '' : 'none';
    }
}

// Date formatting
function formatDate(date) {
    const d = new Date(date);
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

// Auto-hide alerts
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            alert.style.opacity = '0';
            setTimeout(function() {
                alert.style.display = 'none';
            }, 300);
        }, 5000);
    });
});

// Print functionality
function printReport(elementId) {
    const printContent = document.getElementById(elementId).innerHTML;
    const originalContent = document.body.innerHTML;
    
    document.body.innerHTML = `
        <div style="padding: 20px;">
            <h1>Stock Management Report</h1>
            <p>Generated on: ${new Date().toLocaleString()}</p>
            ${printContent}
        </div>
    `;
    
    window.print();
    document.body.innerHTML = originalContent;
    location.reload();
}

// Export to CSV
function exportToCSV(tableId, filename) {
    const table = document.getElementById(tableId);
    const rows = table.querySelectorAll('tr');
    const csv = [];
    
    for (let row of rows) {
        const cells = row.querySelectorAll('td, th');
        const rowData = [];
        
        for (let cell of cells) {
            let cellData = cell.innerText.replace(/,/g, ' ');
            rowData.push(cellData);
        }
        
        csv.push(rowData.join(','));
    }
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    
    a.href = url;
    a.download = `${filename}_${formatDate(new Date())}.csv`;
    a.click();
    
    window.URL.revokeObjectURL(url);
}

// Low stock alert
function checkLowStock() {
    const stockRows = document.querySelectorAll('.stock-row');
    stockRows.forEach(row => {
        const quantity = parseInt(row.dataset.quantity);
        const reorderLevel = parseInt(row.dataset.reorderLevel);
        
        if (quantity <= reorderLevel) {
            row.style.backgroundColor = '#fff3cd';
            row.classList.add('low-stock');
        }
    });
}

// Form submit loading state
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('form').forEach(function(form) {
        form.addEventListener('submit', function() {
            var btn = form.querySelector('button[type="submit"]');
            if (btn && !btn.classList.contains('btn-loading')) {
                btn.classList.add('btn-loading');
                btn.dataset.originalText = btn.innerHTML;
                btn.innerHTML = '<span class="btn-spinner"></span> Processing...';
            }
        });
    });
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    checkLowStock();
});
/**
 * Multi-Column Search with Column Selection
 * @param {string} inputId - Search input ID
 * @param {string} tableId - Table ID
 * @param {Array} columns - Columns to search {index: 0, name: 'Product'}
 */
function createAdvancedTableSearch(inputId, tableId, columns) {
    const container = document.createElement('div');
    container.className = 'advanced-table-search';
    container.style.cssText = `
        display: flex;
        gap: 12px;
        align-items: center;
        margin-bottom: 20px;
        flex-wrap: wrap;
    `;
    
    // Search input wrapper
    const searchWrapper = document.createElement('div');
    searchWrapper.style.cssText = `
        flex: 1;
        position: relative;
        min-width: 250px;
    `;
    
    // Search icon
    const searchIcon = document.createElement('span');
    searchIcon.innerHTML = '🔍';
    searchIcon.style.cssText = `
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #64748b;
    `;
    
    // Search input
    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.id = inputId;
    searchInput.placeholder = 'Search...';
    searchInput.style.cssText = `
        width: 100%;
        padding: 12px 12px 12px 40px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.2s;
    `;
    
    searchWrapper.appendChild(searchIcon);
    searchWrapper.appendChild(searchInput);
    container.appendChild(searchWrapper);
    
    // Column selector dropdown
    const columnSelect = document.createElement('select');
    columnSelect.id = `${inputId}_column`;
    columnSelect.style.cssText = `
        padding: 12px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 14px;
        background: white;
        min-width: 150px;
    `;
    
    // Add "All Columns" option
    const allOption = document.createElement('option');
    allOption.value = 'all';
    allOption.textContent = 'All Columns';
    columnSelect.appendChild(allOption);
    
    // Add column options
    columns.forEach(col => {
        const option = document.createElement('option');
        option.value = col.index;
        option.textContent = col.name;
        columnSelect.appendChild(option);
    });
    
    container.appendChild(columnSelect);
    
    // Clear button
    const clearBtn = document.createElement('button');
    clearBtn.type = 'button';
    clearBtn.innerHTML = '✕ Clear';
    clearBtn.style.cssText = `
        padding: 12px 20px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: white;
        color: #64748b;
        font-size: 14px;
        cursor: pointer;
        transition: all 0.2s;
    `;
    clearBtn.onmouseover = () => {
        clearBtn.style.background = '#f1f5f9';
        clearBtn.style.borderColor = '#94a3b8';
    };
    clearBtn.onmouseout = () => {
        clearBtn.style.background = 'white';
        clearBtn.style.borderColor = '#e2e8f0';
    };
    container.appendChild(clearBtn);
    
    // Insert before table
    const table = document.getElementById(tableId);
    table.parentNode.insertBefore(container, table);
    
    // Search function with column filtering
    function searchTableAdvanced() {
        const searchTerm = searchInput.value.toLowerCase();
        const selectedColumn = columnSelect.value;
        
        const rows = table.querySelectorAll('tbody tr');
        let visibleCount = 0;
        
        rows.forEach(row => {
            let matches = false;
            const cells = row.querySelectorAll('td');
            
            if (selectedColumn === 'all') {
                // Search all columns
                cells.forEach(cell => {
                    if (cell.textContent.toLowerCase().includes(searchTerm)) {
                        matches = true;
                    }
                });
            } else {
                // Search specific column
                const cell = cells[parseInt(selectedColumn)];
                if (cell && cell.textContent.toLowerCase().includes(searchTerm)) {
                    matches = true;
                }
            }
            
            row.style.display = matches ? '' : 'none';
            if (matches) visibleCount++;
        });
        
        // Update result count
        const resultCount = document.getElementById(`${tableId}_result_count`) || 
            (() => {
                const span = document.createElement('span');
                span.id = `${tableId}_result_count`;
                span.style.cssText = `
                    font-size: 13px;
                    color: #64748b;
                    margin-left: 12px;
                `;
                container.appendChild(span);
                return span;
            })();
        
        resultCount.innerHTML = `${visibleCount} results`;
    }
    
    // Event listeners
    searchInput.addEventListener('input', searchTableAdvanced);
    columnSelect.addEventListener('change', searchTableAdvanced);
    clearBtn.addEventListener('click', () => {
        searchInput.value = '';
        columnSelect.value = 'all';
        searchTableAdvanced();
    });
    
    return {
        search: searchTableAdvanced,
        clear: () => clearBtn.click()
    };
}