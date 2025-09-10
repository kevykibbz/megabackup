jQuery(document).ready(function($) {

    let backupInProgress = false;
    let restoreInProgress = false;

    /**
     * Check if at least one backup option is selected and update button state
     */
    function updateBackupButtonState() {
        const hasSelection = $('#include-database').is(':checked') || 
                           $('#include-uploads').is(':checked') || 
                           $('#include-themes').is(':checked') || 
                           $('#include-plugins').is(':checked');
        
        const $button = $('#start-backup');
        const $warning = $('#backup-selection-warning');
        
        if (hasSelection) {
            $button.prop('disabled', false).removeClass('disabled');
            $warning.hide();
        } else {
            $button.prop('disabled', true).addClass('disabled');
            $warning.show();
        }
    }

    // Initialize button state on page load
    updateBackupButtonState();

    // Monitor checkbox changes
    $('#include-database, #include-uploads, #include-themes, #include-plugins').on('change', updateBackupButtonState);

    /**
     * FIX: Add a helper function to format file sizes from bytes into a readable format.
     */
    function formatBytes(bytes, decimals = 2) {
        if (!+bytes) return '0 Bytes';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
    }

    // --- BACKUP TRIGGER ---
    $('#start-backup').on('click', function() {
        const $button = $(this);
        
        // Check if button is disabled
        if ($button.prop('disabled') || $button.hasClass('disabled')) {
            return false;
        }
        
        const availableSpace = parseFloat($button.data('available-space'));
        const wpSize = parseFloat($button.data('wp-size'));
        const recommendedSize = parseFloat($button.data('recommended-size'));

        // FIX: More flexible space checking for unlimited space scenarios
        // Only show warning if available space is significantly low AND not unlimited
        const isUnlimitedSpace = availableSpace > (50 * 1024 * 1024 * 1024 * 1024); // 50TB threshold for unlimited
        const hasInsufficientSpace = !isUnlimitedSpace && availableSpace < (wpSize * 0.5); // Only warn if less than 50% of site size
        
        if (hasInsufficientSpace) {
            // FIX: Populate the modal with formatted values before showing it
            $('#low-space-modal #available-space').text(formatBytes(availableSpace));
            $('#low-space-modal #estimated-size').text(formatBytes(wpSize));
            $('#low-space-modal #recommended-size').text(formatBytes(recommendedSize));
            $('#low-space-modal').show();
            return;
        }
        
        if (backupInProgress || restoreInProgress) return;

        const options = {
            include_database: $('#include-database').is(':checked') ? 'true' : 'false',
            include_uploads: $('#include-uploads').is(':checked') ? 'true' : 'false',
            include_themes: $('#include-themes').is(':checked') ? 'true' : 'false',
            include_plugins: $('#include-plugins').is(':checked') ? 'true' : 'false'
        };
        startBackupPlanner(options);
    });

    // --- SCHEDULE SAVE TRIGGER (Final Version) ---
    $('#schedule-form').on('submit', function(e) {
        const isEnabling = $('#schedule-enabled-checkbox').is(':checked');

        if (isEnabling) {
            const $button = $('#save-schedule-settings');
            const availableSpace = parseFloat($button.data('available-space'));
            const wpSize = parseFloat($button.data('wp-size'));
            const recommendedSize = parseFloat($button.data('recommended-size'));

            // FIX: More flexible space checking for unlimited space scenarios
            const isUnlimitedSpace = availableSpace > (50 * 1024 * 1024 * 1024 * 1024); // 50TB threshold for unlimited
            const hasInsufficientSpace = !isUnlimitedSpace && availableSpace < (wpSize * 0.5); // Only warn if less than 50% of site size

            if (hasInsufficientSpace) {
                e.preventDefault(); // This stops the form from submitting
                
                // FIX: Populate the modal with formatted values before showing it
                $('#low-space-modal #available-space').text(formatBytes(availableSpace));
                $('#low-space-modal #estimated-size').text(formatBytes(wpSize));
                $('#low-space-modal #recommended-size').text(formatBytes(recommendedSize));
                $('#low-space-modal').show();
            }
            // If space is sufficient, the event is not prevented, and the form submits normally.
        }
    });

    // --- MODAL CLOSE TRIGGER ---
    $('#close-low-space-modal').on('click', function() {
        $('#low-space-modal').hide();
    });

    // --- RESTORE TRIGGER ---
    $(document).on('click', '.restore-backup', function() {
        console.log('Restore button clicked'); // Debug log
        
        if (restoreInProgress || backupInProgress) {
            console.log('Restore or backup already in progress');
            return;
        }
        
        const backupFile = $(this).data('file');
        console.log('Backup file:', backupFile); // Debug log
        
        if (!backupFile) {
            showError('No backup file specified for restore.');
            return;
        }
        
        if (!confirm('Are you sure you want to restore this backup? This will overwrite your current site.')) {
            return;
        }
        
        console.log('Starting restore for file:', backupFile); // Debug log
        startRestorePlanner(backupFile);
    });

    // --- RESTORE SELECTED BACKUP TRIGGER ---
    $('#restore-existing').on('click', function() {
        console.log('Restore Selected Backup button clicked'); // Debug log
        
        if (restoreInProgress || backupInProgress) {
            console.log('Restore or backup already in progress');
            return;
        }
        
        const selectedBackup = $('#backup-select').val();
        console.log('Selected backup:', selectedBackup); // Debug log
        
        if (!selectedBackup) {
            showError('Please select a backup file to restore.');
            return;
        }
        
        if (!confirm('Are you sure you want to restore this backup? This will overwrite your current site.')) {
            return;
        }
        
        console.log('Starting restore for selected backup:', selectedBackup); // Debug log
        startRestorePlanner(selectedBackup);
    });

    // --- Unchanged Handlers from here ---

    function startBackupPlanner(options) {
        backupInProgress = true;
        updateUIForStart('backup');
        addLog('#backup-logs .logs-content', 'Creating backup job and scanning files...', 'info');

        $.ajax({
            url: megabackup_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'megabackup_start_backup',
                nonce: megabackup_ajax.nonce,
                ...options
            },
            success: function(response) {
                if (response.success) {
                    addLog('#backup-logs .logs-content', 'Job created successfully. Starting batch processing.', 'info');
                    processBackupBatch(response.data.job_id);
                } else {
                    showError('Could not start backup: ' + (response.data || 'Unknown error'));
                    resetUI('backup');
                }
            },
            error: function(xhr) {
                showError('Could not start backup: ' + (xhr.responseJSON ? xhr.responseJSON.data : xhr.responseText));
                resetUI('backup');
            }
        });
    }

    function processBackupBatch(job_id, retryCount = 0) {
        if (!backupInProgress) return;
        const maxRetries = 3;

        $.ajax({
            url: megabackup_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'megabackup_process_backup_batch',
                nonce: megabackup_ajax.nonce,
                job_id: job_id
            },
            success: function(response) {
                if (response.success) {
                    updateProgress('#backup-progress', response.data.progress, response.data.message);
                    if (response.data.progress < 100) {
                        setTimeout(() => processBackupBatch(job_id, 0), 1500);
                    } else {
                        showSuccess('Backup completed successfully!');
                        resetUI('backup');
                        refreshBackupsList();
                    }
                } else {
                    showError('A batch failed: ' + (response.data || 'Unknown error'));
                    resetUI('backup');
                }
            },
            error: function(xhr) {
                if (retryCount < maxRetries) {
                    const newRetryCount = retryCount + 1;
                    const waitTime = newRetryCount * 3000;
                    addLog('#backup-logs .logs-content', `A batch failed. Retrying in ${waitTime / 1000}s... (Attempt ${newRetryCount}/${maxRetries})`, 'warning');
                    setTimeout(() => processBackupBatch(job_id, newRetryCount), waitTime);
                } else {
                    showError('Backup failed after multiple retries. Error: ' + (xhr.responseJSON ? xhr.responseJSON.data : 'Server timeout or connection error.'));
                    resetUI('backup');
                }
            }
        });
    }

    function startRestorePlanner(backupFile) {
        console.log('startRestorePlanner called with:', backupFile); // Debug log
        
        restoreInProgress = true;
        updateUIForStart('restore');
        addLog('#restore-logs .logs-content', 'Creating restore job...', 'info');

        $.ajax({
            url: megabackup_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'megabackup_start_restore',
                nonce: megabackup_ajax.nonce,
                backup_file: backupFile
            },
            success: function(response) {
                console.log('Restore AJAX success:', response); // Debug log
                if (response.success) {
                    addLog('#restore-logs .logs-content', 'Restore job created. Starting restore process.', 'info');
                    processRestoreBatch(response.data.job_id);
                } else {
                    showError('Could not start restore: ' + (response.data || 'Unknown error'));
                    resetUI('restore');
                }
            },
            error: function(xhr) {
                console.log('Restore AJAX error:', xhr); // Debug log
                showError('Could not start restore: ' + (xhr.responseJSON ? xhr.responseJSON.data : xhr.responseText));
                resetUI('restore');
            }
        });
    }

    function processRestoreBatch(job_id, retryCount = 0) {
        if (!restoreInProgress) return;
        const maxRetries = 3;

        $.ajax({
            url: megabackup_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'megabackup_process_restore_batch',
                nonce: megabackup_ajax.nonce,
                job_id: job_id
            },
            success: function(response) {
                if (response.success) {
                    updateProgress('#restore-progress', response.data.progress, response.data.message);
                    if (response.data.progress < 100) {
                        setTimeout(() => processRestoreBatch(job_id, 0), 300);
                    } else {
                        showSuccess('Restore completed successfully! Reloading page...');
                        setTimeout(() => window.location.reload(), 3000);
                    }
                } else {
                    showError('A restore batch failed: ' + (response.data || 'Unknown error'));
                    resetUI('restore');
                }
            },
            error: function(xhr) {
                 if (retryCount < maxRetries) {
                    const newRetryCount = retryCount + 1;
                    const waitTime = newRetryCount * 3000;
                    addLog('#restore-logs .logs-content', `A batch failed. Retrying in ${waitTime / 1000}s... (Attempt ${newRetryCount}/${maxRetries})`, 'warning');
                    setTimeout(() => processRestoreBatch(job_id, newRetryCount), waitTime);
                } else {
                    showError('Restore failed after multiple retries. Error: ' + (xhr.responseJSON ? xhr.responseJSON.data : 'Server timeout or connection error.'));
                    resetUI('restore');
                }
            }
        });
    }

    function updateUIForStart(type) {
        const logsSelector = `#${type}-logs .logs-content`;
        const progressSelector = `#${type}-progress`;
        $(progressSelector).show();
        clearLogs(logsSelector);

        if (type === 'backup') {
            $('#start-backup').prop('disabled', true).addClass('loading').html('<span class="spinner"></span>Backing up...');
        } else {
            $('.restore-backup, .delete-backup').prop('disabled', true);
            addLog('#restore-logs .logs-content', 'Restore process started. Please do not close this page.', 'warning');
        }
    }

    function resetUI(type) {
        if (type === 'backup') {
            backupInProgress = false;
            $('#start-backup').prop('disabled', false).removeClass('loading').text('Create Backup');
        } else {
            restoreInProgress = false;
            $('.restore-backup, .delete-backup').prop('disabled', false);
        }
    }

    function updateProgress(selector, progress, message) {
        const $container = $(selector);
        progress = Math.min(100, Math.max(0, parseInt(progress, 10)));
        $container.find('.progress-fill').css('width', progress + '%');
        $container.find('.progress-text').text(progress + '%' + (message ? ' - ' + message : ''));
        const logSelector = selector.replace('-progress', '-logs') + ' .logs-content';
        const lastLog = $(logSelector).children().last().text();
        if (message && !lastLog.includes(message)) {
             addLog(logSelector, message, 'info');
        }
    }

    function addLog(selector, message, type) {
        const $container = $(selector);
        const time = new Date().toLocaleTimeString();
        const logEntry = `<div class="log-entry ${type}"><span class="log-time">${time}</span><span class="log-message">${escapeHtml(message)}</span></div>`;
        $container.append(logEntry).scrollTop($container[0].scrollHeight);
    }

    function clearLogs(selector) { $(selector).empty(); }
    function showSuccess(message) { showNotice(message, 'success'); }
    function showError(message) { showNotice(message, 'error'); }

    function showNotice(message, type) {
        $('.megabackup-admin .notice').remove();
        const $notice = $(`<div class="notice notice-${type} is-dismissible"><p>${escapeHtml(message)}</p></div>`);
        $('.megabackup-admin h1').after($notice);
        setTimeout(() => $notice.fadeOut(), 6000);
    }

    function escapeHtml(text) {
        if (typeof text !== 'string') return '';
        return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    function refreshBackupsList() {
        $('#backups-list').load(window.location.href + ' #backups-list > *');
    }

    $('#upload-backup').on('click', function() { /* Upload logic is already chunked and can remain */ });
    $(document).on('click', '.delete-backup', function() {
        const backupFile = $(this).data('file');
        if (!confirm('Are you sure you want to delete this backup?')) return;
        $.ajax({
            url: megabackup_ajax.ajax_url,
            type: 'POST',
            data: { action: 'megabackup_delete_backup', nonce: megabackup_ajax.nonce, backup_file: backupFile },
            success: (res) => {
                if(res.success) {
                    showSuccess(res.data);
                    refreshBackupsList();
                } else {
                    showError(res.data);
                }
            }
        });
    });
});
