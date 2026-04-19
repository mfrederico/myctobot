/**
 * Main JavaScript file for PHP Framework Starter
 */

document.addEventListener('DOMContentLoaded', function() {
    
    // Initialize Bootstrap tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Initialize Bootstrap popovers
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });

    // Alerts are now manually dismissible only (no auto-hide to prevent layout shift)
    // Users can close alerts using the X button

    // Confirm delete actions
    document.querySelectorAll('.confirm-delete').forEach(function(element) {
        element.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete this item?')) {
                e.preventDefault();
            }
        });
    });
    
    // AJAX form submission
    document.querySelectorAll('.ajax-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            var formData = new FormData(form);
            var submitBtn = form.querySelector('[type="submit"]');
            var originalText = submitBtn.innerHTML;
            
            // Show loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Loading...';
            
            fetch(form.action, {
                method: form.method || 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('success', data.message || 'Success!');
                    if (data.redirect) {
                        setTimeout(() => {
                            window.location.href = data.redirect;
                        }, 1000);
                    }
                } else {
                    showToast('error', data.message || 'An error occurred');
                }
            })
            .catch(error => {
                showToast('error', 'Network error. Please try again.');
                console.error('Error:', error);
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });
    });
    
    // Password visibility toggle
    document.querySelectorAll('.password-toggle').forEach(function(toggle) {
        toggle.addEventListener('click', function() {
            var input = document.querySelector(toggle.dataset.target);
            var icon = toggle.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        });
    });
    
    // Character counter for textareas
    document.querySelectorAll('textarea[maxlength]').forEach(function(textarea) {
        var maxLength = textarea.getAttribute('maxlength');
        var counter = document.createElement('small');
        counter.className = 'text-muted';
        counter.textContent = '0 / ' + maxLength;
        textarea.parentNode.appendChild(counter);
        
        textarea.addEventListener('input', function() {
            var currentLength = textarea.value.length;
            counter.textContent = currentLength + ' / ' + maxLength;
            
            if (currentLength > maxLength * 0.9) {
                counter.classList.add('text-danger');
            } else {
                counter.classList.remove('text-danger');
            }
        });
    });
    
});

// Global toast notification function
function showToast(type, message) {
    var toastEl = document.getElementById('liveToast');
    if (!toastEl) return;

    // Disable autohide to prevent layout shift - user must manually dismiss
    var toast = new bootstrap.Toast(toastEl, {
        autohide: false
    });
    var toastBody = toastEl.querySelector('.toast-body');
    var toastHeader = toastEl.querySelector('.toast-header');

    // Set message
    toastBody.textContent = message;

    // Reset classes
    toastHeader.className = 'toast-header';

    // Set type styling
    if (type === 'success') {
        toastHeader.classList.add('bg-success', 'text-white');
        toastHeader.querySelector('i').className = 'bi bi-check-circle me-2';
    } else if (type === 'error') {
        toastHeader.classList.add('bg-danger', 'text-white');
        toastHeader.querySelector('i').className = 'bi bi-x-circle me-2';
    } else if (type === 'warning') {
        toastHeader.classList.add('bg-warning');
        toastHeader.querySelector('i').className = 'bi bi-exclamation-triangle me-2';
    } else {
        toastHeader.classList.add('bg-info', 'text-white');
        toastHeader.querySelector('i').className = 'bi bi-info-circle me-2';
    }

    toast.show();
}

// AJAX helper function
function ajaxRequest(url, method, data, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open(method, url, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    
    if (method === 'POST' && !(data instanceof FormData)) {
        xhr.setRequestHeader('Content-Type', 'application/json');
        data = JSON.stringify(data);
    }
    
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var response = JSON.parse(xhr.responseText);
                callback(null, response);
            } catch (e) {
                callback(e, null);
            }
        } else {
            callback(new Error('Request failed: ' + xhr.status), null);
        }
    };
    
    xhr.onerror = function() {
        callback(new Error('Network error'), null);
    };
    
    xhr.send(data);
}