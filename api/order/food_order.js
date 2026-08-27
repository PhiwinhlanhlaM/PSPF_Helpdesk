// food_order.js - REAL-TIME SUBMISSION (NO DELAY)
// Orders are submitted with current timestamp automatically

let activeOrders = {};
let currentOutlet = null;
const FEE_LIMIT = 70.00;
const MAX_CASH_AMOUNT = 70.00;

/* ============================
   INITIALIZATION & UTILITIES
============================ */

document.addEventListener('DOMContentLoaded', () => {
    initializePage();
});

function initializePage() {
    // Set up real-time display
    updateRealTimeDisplays();
    setInterval(updateRealTimeDisplays, 60000); // Update every minute
    
    // Initialize cash input
    const cashInput = document.getElementById('cashAmount');
    if (cashInput) {
        cashInput.addEventListener('input', validateCashAmount);
        cashInput.addEventListener('blur', formatCashAmount);
    }
    
    // Set form datetime to current time (read-only)
    setCurrentDateTime();
    
    // Add keyboard shortcuts
    setupKeyboardShortcuts();
}

function updateRealTimeDisplays() {
    // Update main time display if exists
    const timeDisplay = document.getElementById('currentTimeDisplay');
    if (timeDisplay) {
        timeDisplay.textContent = new Date().toLocaleString('en-US', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: 'numeric',
            minute: 'numeric',
            hour12: true,
            timeZone: 'Africa/Mbabane'
        });
    }
    
    // Update order time display
    updateOrderTimeDisplay();
}

function updateOrderTimeDisplay() {
    const datetimeInput = document.getElementById('orderDateTime');
    if (!datetimeInput) return;
    
    const now = new Date();
    const localDateTime = new Date(now.getTime() - (now.getTimezoneOffset() * 60000))
        .toISOString()
        .slice(0, 16);
    
    // Always set to current time (real-time)
    datetimeInput.value = localDateTime;
}

function setCurrentDateTime() {
    const now = new Date();
    const localDateTime = new Date(now.getTime() - (now.getTimezoneOffset() * 60000))
        .toISOString()
        .slice(0, 16);
    
    // Make datetime input read-only since we always use current time
    const datetimeInput = document.getElementById('orderDateTime');
    if (datetimeInput) {
        datetimeInput.value = localDateTime;
        datetimeInput.readOnly = true;
        datetimeInput.style.backgroundColor = '#f8f9fa';
        datetimeInput.style.cursor = 'not-allowed';
    }
}

function setupKeyboardShortcuts() {
    document.addEventListener('keydown', (e) => {
        // Ctrl/Cmd + Enter to submit
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            const submitBtn = document.querySelector('#completeOrderForm button[type="submit"]');
            if (submitBtn && !submitBtn.disabled) {
                e.preventDefault();
                document.getElementById('completeOrderForm').dispatchEvent(new Event('submit'));
            }
        }
        
        // Escape to cancel
        if (e.key === 'Escape') {
            const orderForm = document.getElementById('orderFormCard');
            if (!orderForm.classList.contains('hidden')) {
                cancelCurrentOutlet();
            }
        }
        
        // Alt + C to focus cash input
        if (e.altKey && e.key === 'c') {
            const cashInput = document.getElementById('cashAmount');
            if (cashInput) {
                e.preventDefault();
                cashInput.focus();
                cashInput.select();
            }
        }
    });
}

/* ============================
   CASH REQUEST HANDLING
============================ */

function validateCashAmount() {
    const input = document.getElementById('cashAmount');
    if (!input) return;
    
    let value = parseFloat(input.value);
    
    if (isNaN(value) || value < 0) {
        input.value = '';
        return;
    }
    
    if (value > MAX_CASH_AMOUNT) {
        input.value = MAX_CASH_AMOUNT.toFixed(2);
        showAlert(`Maximum cash amount is E${MAX_CASH_AMOUNT.toFixed(2)}`, 'warning');
    }
}

function formatCashAmount() {
    const input = document.getElementById('cashAmount');
    if (!input || input.value === '') return;
    
    const value = parseFloat(input.value);
    if (!isNaN(value) && value >= 0) {
        input.value = value.toFixed(2);
    }
}

function addCashRequestFromCard() {
    const cashInput = document.getElementById('cashAmount');
    if (!cashInput) {
        showAlert('Cash input field not found', 'danger');
        return;
    }
    
    let amount = parseFloat(cashInput.value);
    
    if (isNaN(amount) || amount <= 0) {
        showAlert('Please enter a valid amount (greater than 0)', 'warning');
        cashInput.focus();
        return;
    }
    
    if (amount > MAX_CASH_AMOUNT) {
        showAlert(`Maximum cash amount is E${MAX_CASH_AMOUNT.toFixed(2)}`, 'warning');
        cashInput.value = MAX_CASH_AMOUNT.toFixed(2);
        amount = MAX_CASH_AMOUNT;
    }
    
    // Deselect all cards first
    deselectAllCards();
    
    // Select the cash card
    const cashCard = document.querySelector('.card-1:first-child');
    if (cashCard) {
        cashCard.classList.add('selected');
    }
    
    // Add cash request
    addCashRequest(amount);
    
    // Reset input
    cashInput.value = '';
    cashInput.placeholder = '0.00';
    
    // Show success
    showAlert(`Cash request of E${amount.toFixed(2)} added successfully`, 'success');
}

function addCashRequest(amount) {
    const key = 'cash_request';
    
    activeOrders[key] = {
        name: 'Cash Request',
        type: 'cash',
        amount: amount,
        items: [{ description: 'Cash Request', price: amount }],
        deliveryFee: 0
    };
    
    // Show the complete order form
    showCompleteOrderForm();
    updateActiveOrdersDisplay();
}

/* ============================
   OUTLET SELECTION
============================ */

function deselectAllCards() {
    document.querySelectorAll('.outlet-card-horizontal, .cash-card, .card-1')
        .forEach(c => c.classList.remove('selected'));
}

function selectOutlet(id, name, el) {
    deselectAllCards();
    if (el) el.classList.add('selected');

    currentOutlet = { id, name, type: 'food' };

    document.getElementById('outletId').value = id;
    document.getElementById('currentOutletName').textContent = name;
    document.getElementById('selectedOutlet').textContent = name;

    resetItemForm();
    showOrderForm();
}

function selectCashRequest(el) {
    deselectAllCards();
    if (el) el.classList.add('selected');

    currentOutlet = { id: 'cash', name: 'Cash Request', type: 'cash' };

    document.getElementById('currentOutletName').textContent = 'Cash Request';
    document.getElementById('selectedOutlet').textContent = 'Cash Request';

    resetItemForm();
    showOrderForm();
}

/* ============================
   FORM MANAGEMENT
============================ */

function showOrderForm() {
    const card = document.getElementById('orderFormCard');
    card.classList.remove('hidden');
    card.scrollIntoView({ behavior: 'smooth' });
}

function showCompleteOrderForm() {
    const card = document.getElementById('completeOrderCard');
    card.classList.remove('hidden');
    card.scrollIntoView({ behavior: 'smooth' });
    
    // Update order time to current time
    setCurrentDateTime();
}

function resetItemForm() {
    const descInput = document.getElementById('itemDescription');
    const priceInput = document.getElementById('itemPrice');
    const feeInput = document.getElementById('deliveryFee');
    
    if (descInput) descInput.value = '';
    if (priceInput) priceInput.value = '';
    if (feeInput) feeInput.value = '';
}

function cancelCurrentOutlet() {
    currentOutlet = null;
    document.getElementById('orderFormCard').classList.add('hidden');
    deselectAllCards();
    resetItemForm();
}

/* ============================
   ORDER ITEM MANAGEMENT
============================ */

function addToCurrentOutlet() {
    if (!currentOutlet) {
        showAlert('Please select an outlet first', 'warning');
        return;
    }

    const desc = document.getElementById('itemDescription')?.value.trim();
    const price = parseFloat(document.getElementById('itemPrice')?.value);
    const fee = parseFloat(document.getElementById('deliveryFee')?.value || 0);

    if (!desc || isNaN(price) || price <= 0) {
        showAlert('Please enter a valid item description and price', 'warning');
        return;
    }

    const key = currentOutlet.id;

    if (!activeOrders[key]) {
        activeOrders[key] = {
            name: currentOutlet.name,
            type: currentOutlet.type,
            items: [],
            deliveryFee: fee
        };
    }

    activeOrders[key].items.push({ 
        description: desc, 
        price: price 
    });

    resetItemForm();
    showCompleteOrderForm();
    updateActiveOrdersDisplay();
    
    // Auto-scroll to new item
    setTimeout(() => {
        document.getElementById('activeOrdersContainer')?.scrollIntoView({ 
            behavior: 'smooth',
            block: 'end'
        });
    }, 100);
}

function updateActiveOrdersDisplay() {
    const container = document.getElementById('activeOrdersContainer');
    const completeCard = document.getElementById('completeOrderCard');

    if (!Object.keys(activeOrders).length) {
        if (container) container.innerHTML = '';
        if (completeCard) completeCard.classList.add('hidden');
        return;
    }

    let html = '';
    let grandTotal = 0;
    let extraTotal = 0;
    let orderCount = 0;

    Object.entries(activeOrders).forEach(([key, order]) => {
        const subtotal = order.items.reduce((sum, item) => sum + item.price, 0);
        const total = subtotal + (order.deliveryFee || 0);
        const extra = Math.max(0, total - FEE_LIMIT);

        grandTotal += total;
        extraTotal += extra;
        orderCount++;

        html += `
            <div class="card mb-3 active-order-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${order.name}</strong>
                        ${order.type === 'cash' ? 
                            '<span class="badge bg-success ms-2">Cash</span>' : 
                            '<span class="badge bg-primary ms-2">Food</span>'
                        }
                    </div>
                    <button class="btn btn-sm btn-danger" 
                            onclick="removeOutletOrder('${key}')"
                            title="Remove this order">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
                <div class="card-body">
                    ${order.items.map((item, idx) => `
                        <div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-light rounded">
                            <div>
                                <strong>${item.description}</strong>
                                <br><small class="text-muted">Price: E${item.price.toFixed(2)}</small>
                            </div>
                            <button class="btn btn-sm btn-warning" 
                                    onclick="removeOrderItem('${key}', ${idx})"
                                    title="Remove this item">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                    `).join('')}
                    
                    ${order.deliveryFee > 0 ? `
                        <div class="d-flex justify-content-between mt-2">
                            <span>Delivery Fee:</span>
                            <span>E${order.deliveryFee.toFixed(2)}</span>
                        </div>
                    ` : ''}
                    
                    <div class="d-flex justify-content-between mt-3 pt-2 border-top">
                        <strong>Subtotal:</strong>
                        <strong>E${total.toFixed(2)}</strong>
                    </div>
                    
                    ${extra > 0 ? `
                        <div class="d-flex justify-content-between mt-1 text-danger">
                            <span><i class="bi bi-exclamation-triangle"></i> Extra Fee:</span>
                            <span>E${extra.toFixed(2)}</span>
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    });

    if (container) {
        container.innerHTML = html;
    }

    // Update summary
    const grandTotalEl = document.getElementById('grandTotal');
    const extraFeeAmountEl = document.getElementById('finalExtraFeeAmount');
    const extraFeeWarningEl = document.getElementById('finalExtraFeeWarning');
    const finalSummaryEl = document.getElementById('finalOrderSummary');

    if (grandTotalEl) grandTotalEl.textContent = grandTotal.toFixed(2);
    if (extraFeeAmountEl) extraFeeAmountEl.textContent = extraTotal.toFixed(2);
    
    if (extraFeeWarningEl) {
        extraFeeWarningEl.classList.toggle('hidden', extraTotal === 0);
    }
    
    // Update final summary display
    if (finalSummaryEl) {
        const now = new Date();
        const timeString = now.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: true
        });
        
        const dateString = now.toLocaleDateString('en-US', {
            weekday: 'short',
            month: 'short',
            day: 'numeric'
        });
        
        finalSummaryEl.innerHTML = `
            <div class="alert alert-info">
                <i class="bi bi-clock"></i>
                <strong>Orders will be submitted with current timestamp:</strong><br>
                ${timeString} on ${dateString}
            </div>
            <p><strong>Total Orders:</strong> ${orderCount}</p>
            <p><strong>Items Count:</strong> ${Object.values(activeOrders).reduce((sum, order) => sum + order.items.length, 0)}</p>
        `;
    }
}

function removeOutletOrder(key) {
    if (!confirm('Remove this entire order?')) return;
    
    delete activeOrders[key];
    
    // Deselect corresponding card
    if (key === 'cash_request') {
        const cashCard = document.querySelector('.card-1:first-child');
        if (cashCard) cashCard.classList.remove('selected');
    }
    
    updateActiveOrdersDisplay();
    showAlert('Order removed successfully', 'info');
}

function removeOrderItem(key, idx) {
    const order = activeOrders[key];
    if (!order) return;

    if (!confirm('Remove this item?')) return;
    
    order.items.splice(idx, 1);
    
    if (!order.items.length) {
        delete activeOrders[key];
        // Deselect card if removed
        if (key === 'cash_request') {
            const cashCard = document.querySelector('.card-1:first-child');
            if (cashCard) cashCard.classList.remove('selected');
        }
    }
    
    updateActiveOrdersDisplay();
    showAlert('Item removed successfully', 'info');
}

/* ============================
   ORDER SUBMISSION (REAL-TIME)
============================ */

document.getElementById('completeOrderForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    console.log('=== SUBMITTING ORDERS WITH REAL-TIME ===');
    
    if (!Object.keys(activeOrders).length) {
        showAlert('No orders to submit', 'warning');
        return;
    }

    // Always use current time (real-time submission)
    setCurrentDateTime();
    
    const payload = new FormData();
    payload.append('csrf_token', document.getElementById('csrfToken').value);
    payload.append('outlet_orders', JSON.stringify(activeOrders));
    // Note: We're NOT sending order_date - backend will use current time
    payload.append('order_notes', document.getElementById('orderNotes').value || '');

    // Show loading state
    const submitBtn = e.target.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    const originalState = submitBtn.disabled;
    
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting...';
    submitBtn.disabled = true;
    
    // Disable all form inputs during submission
    const formInputs = this.querySelectorAll('input, textarea, button');
    formInputs.forEach(input => {
        if (input !== submitBtn) input.disabled = true;
    });

    try {
        const response = await fetch('process_food_order.php', {
            method: 'POST',
            body: payload,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (!response.ok) {
            throw new Error(`Server responded with ${response.status}`);
        }
        
        const result = await response.json();
        console.log('Submission result:', result);
        
        if (!result.success) {
            throw new Error(result.message || 'Submission failed');
        }

        // Show success message with timestamp
        const now = new Date();
        const timeString = now.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: true
        });
        
        showAlert(`✓ ${result.message} at ${timeString}`, 'success');
        
        // Reset everything
        resetAllOrders(true); // silent reset
        
    } catch (error) {
        console.error('Submission error:', error);
        showAlert(`Submission failed: ${error.message}`, 'danger');
    } finally {
        // Restore button state
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = originalState;
        
        // Re-enable form inputs
        formInputs.forEach(input => {
            if (input !== submitBtn) input.disabled = false;
        });
    }
});

/* ============================
   ORDER RESET
============================ */

function resetAllOrders(silent = false) {
    if (!silent && !confirm('Are you sure you want to cancel all orders?')) {
        return;
    }
    
    activeOrders = {};
    currentOutlet = null;
    
    // Deselect all cards
    deselectAllCards();
    
    // Reset cash input
    const cashInput = document.getElementById('cashAmount');
    if (cashInput) {
        cashInput.value = '';
        cashInput.placeholder = '0.00';
    }
    
    // Hide forms
    document.getElementById('orderFormCard')?.classList.add('hidden');
    document.getElementById('completeOrderCard')?.classList.add('hidden');
    
    // Clear display
    updateActiveOrdersDisplay();
    
    // Reset form
    document.getElementById('foodOrderForm')?.reset();
    document.getElementById('completeOrderForm')?.reset();
    
    // Reset to current time
    setCurrentDateTime();
    
    if (!silent) {
        showAlert('All orders have been cancelled', 'info');
    }
}

/* ============================
   ALERT SYSTEM
============================ */

function showAlert(message, type = 'info') {
    // Remove any existing alerts
    const existingAlerts = document.querySelectorAll('.custom-alert');
    existingAlerts.forEach(alert => alert.remove());
    
    // Create alert element
    const alertDiv = document.createElement('div');
    alertDiv.className = `custom-alert alert alert-${type} alert-dismissible fade show`;
    alertDiv.style.cssText = `
        position: fixed;
        top: 80px;
        right: 20px;
        z-index: 1050;
        min-width: 300px;
        max-width: 400px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        animation: slideIn 0.3s ease;
    `;
    
    // Add animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    `;
    document.head.appendChild(style);
    
    // Set icon based on type
    let icon = 'bi-info-circle';
    switch(type) {
        case 'success': icon = 'bi-check-circle'; break;
        case 'warning': icon = 'bi-exclamation-triangle'; break;
        case 'danger': icon = 'bi-x-circle'; break;
        case 'info': icon = 'bi-info-circle'; break;
    }
    
    alertDiv.innerHTML = `
        <div class="d-flex align-items-center">
            <i class="bi ${icon} me-2"></i>
            <div>${message}</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
    
    document.body.appendChild(alertDiv);
    
    // Auto-dismiss after appropriate time
    let dismissTime = 5000;
    if (type === 'success') dismissTime = 3000;
    if (type === 'danger') dismissTime = 7000;
    
    setTimeout(() => {
        if (alertDiv.parentNode) {
            const bsAlert = new bootstrap.Alert(alertDiv);
            bsAlert.close();
        }
    }, dismissTime);
}

/* ============================
   INPUT VALIDATION HELPERS
============================ */

function validateAmount(input) {
    if (!input || input.value === '') return;
    
    const value = parseFloat(input.value);
    const max = parseFloat(input.max) || 1000;
    
    if (isNaN(value) || value < 0) {
        input.value = '0.00';
    } else if (value > max) {
        input.value = max.toFixed(2);
        showAlert(`Maximum amount is E${max.toFixed(2)}`, 'warning');
    }
}

/* ==============================
    EDIT ORDER
    ============================= */

function editOrder(orderId) {
    fetch(`./get_order.php?id=${orderId}`)
        .then(res => res.json())
        .then(order => {
            if (!order) return alert("Order not found");

            // Fill the form with order data
            document.getElementById('outletId').value = order.outlet_id;
            document.getElementById('selectedOutlet').textContent = order.outlet_name;
            document.getElementById('currentOutletName').textContent = order.outlet_name;
            document.getElementById('itemDescription').value = order.order_items;
            document.getElementById('itemPrice').value = order.total_amount;
            document.getElementById('deliveryFee').value = order.delivery_fee ?? 0;

            // Show the order form
            document.getElementById('orderFormCard').classList.remove('hidden');
            document.getElementById('completeOrderCard').classList.remove('hidden');

            // Store order ID in hidden field for update
            if (!document.getElementById('orderIdHidden')) {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.id = 'orderIdHidden';
                hiddenInput.value = order.id;
                document.getElementById('foodOrderForm').appendChild(hiddenInput);
            } else {
                document.getElementById('orderIdHidden').value = order.id;
            }

            window.scrollTo({ top: 0, behavior: 'smooth' });
        })
        .catch(err => console.error(err));
}


// Expose necessary functions to global scope
window.validateAmount = validateAmount;
window.resetAllOrders = resetAllOrders;
window.cancelCurrentOutlet = cancelCurrentOutlet;
window.addCashRequestFromCard = addCashRequestFromCard;
window.selectOutlet = selectOutlet;
window.selectCashRequest = selectCashRequest;
window.addToCurrentOutlet = addToCurrentOutlet;
window.removeOutletOrder = removeOutletOrder;
window.removeOrderItem = removeOrderItem;