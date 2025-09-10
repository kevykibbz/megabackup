<?php
/**
 * MegaBackup Async Helper - Gestisce le richieste AJAX per il restore asincrono
 * Questo file deve essere incluso nel plugin principale
 */
if (!defined('ABSPATH')) {
    exit;
}

class MegaBackup_Async_Helper {
    
    public function __construct() {
        // Registra hook AJAX solo se siamo nell'admin
        if (is_admin()) {
            add_action('wp_ajax_megabackup_async_restore', array($this, 'handle_async_restore'));
            add_action('wp_ajax_megabackup_get_restore_status', array($this, 'handle_get_status'));
            add_action('wp_ajax_megabackup_cancel_restore', array($this, 'handle_cancel_restore'));
            
            // Enqueue script per modalità asincrona
            add_action('admin_enqueue_scripts', array($this, 'enqueue_async_scripts'));
        }
    }

    /**
     * Gestisce richieste AJAX per restore asincrono
     */
    public function handle_async_restore() {
        // Verifica nonce e permessi
        if (!check_ajax_referer('megabackup_restore', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        try {
            // Ottieni parametri
            $backup_file = sanitize_text_field($_POST['backup_file']);
            $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
            
            if (empty($backup_file)) {
                wp_send_json_error('Backup file not specified');
            }

            // Imposta modalità asincrona
            $_POST['async_mode'] = 'true';
            if (!empty($session_id)) {
                $_POST['session_id'] = $session_id;
            }

            // Avvia restore
            $restore = new MegaBackup_Restore();
            $result = $restore->restore_backup($backup_file);
            
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            wp_send_json_error('Restore failed: ' . $e->getMessage());
        }
    }

    /**
     * Gestisce richieste di stato
     */
    public function handle_get_status() {
        if (!check_ajax_referer('megabackup_restore', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $session_id = sanitize_text_field($_POST['session_id']);
        
        if (empty($session_id)) {
            wp_send_json_error('Session ID not specified');
        }

        try {
            $restore = new MegaBackup_Restore();
            $status = $restore->get_async_restore_status($session_id);
            
            wp_send_json_success($status);
            
        } catch (Exception $e) {
            wp_send_json_error('Status check failed: ' . $e->getMessage());
        }
    }

    /**
     * Gestisce cancellazione restore
     */
    public function handle_cancel_restore() {
        if (!check_ajax_referer('megabackup_restore', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $session_id = sanitize_text_field($_POST['session_id']);
        
        if (empty($session_id)) {
            wp_send_json_error('Session ID not specified');
        }

        try {
            $restore = new MegaBackup_Restore();
            $result = $restore->cancel_async_restore($session_id);
            
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            wp_send_json_error('Cancel failed: ' . $e->getMessage());
        }
    }

    /**
     * Carica script per modalità asincrona
     */
    public function enqueue_async_scripts($hook) {
        // Solo nelle pagine del plugin MegaBackup
        if (strpos($hook, 'megabackup') === false) {
            return;
        }
        
        // Inline JavaScript per gestire modalità asincrona
        $async_script = "
        <script type='text/javascript'>
        jQuery(document).ready(function($) {
            // Variabili globali per restore asincrono
            window.megabackupAsync = {
                sessionId: null,
                isRunning: false,
                statusInterval: null,
                maxRetries: 3,
                currentRetries: 0
            };

            // Intercetta form di restore per modalità asincrona
            $('#megabackup-restore-form').on('submit', function(e) {
                var backupFile = $('#backup-file-select').val();
                var useAsync = $('#use-async-restore').is(':checked');
                
                if (useAsync && backupFile) {
                    e.preventDefault();
                    startAsyncRestore(backupFile);
                }
            });

            // Aggiungi checkbox per modalità asincrona se non esiste
            if ($('#use-async-restore').length === 0) {
                var asyncCheckbox = '<label><input type=\"checkbox\" id=\"use-async-restore\" checked> Usa modalità asincrona (raccomandato per siti grandi)</label>';
                $('#megabackup-restore-form .form-table').append('<tr><th>Modalità Restore</th><td>' + asyncCheckbox + '</td></tr>');
            }

            // Aggiungi area progresso se non esiste
            if ($('#async-progress-area').length === 0) {
                var progressHtml = '<div id=\"async-progress-area\" style=\"display:none; margin-top:20px;\">' +
                    '<h3>Progresso Restore Asincrono</h3>' +
                    '<div id=\"async-progress-bar\" style=\"width:100%; height:20px; background:#f0f0f0; border-radius:10px; overflow:hidden;\">' +
                        '<div id=\"async-progress-fill\" style=\"height:100%; background:#0073aa; width:0%; transition:width 0.3s;\"></div>' +
                    '</div>' +
                    '<div id=\"async-progress-text\" style=\"margin-top:10px; font-weight:bold;\">Inizializzazione...</div>' +
                    '<div id=\"async-logs\" style=\"margin-top:15px; max-height:300px; overflow-y:auto; background:#f9f9f9; padding:10px; border:1px solid #ddd; font-family:monospace; font-size:12px;\"></div>' +
                    '<button type=\"button\" id=\"cancel-async-restore\" class=\"button\" style=\"margin-top:10px; display:none;\">Cancella Restore</button>' +
                '</div>';
                $('#megabackup-restore-form').after(progressHtml);
            }

            // Funzione per avviare restore asincrono
            function startAsyncRestore(backupFile) {
                window.megabackupAsync.isRunning = true;
                window.megabackupAsync.sessionId = 'restore_' + Date.now();
                window.megabackupAsync.currentRetries = 0;
                
                $('#async-progress-area').show();
                $('#cancel-async-restore').show();
                updateProgress(0, 'Avvio restore asincrono...');
                
                performAsyncRequest(backupFile);
            }

            // Esegue richiesta asincrona
            function performAsyncRequest(backupFile) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'megabackup_async_restore',
                        backup_file: backupFile,
                        session_id: window.megabackupAsync.sessionId,
                        nonce: $('#_wpnonce').val()
                    },
                    timeout: 60000, // 60 secondi timeout
                    success: function(response) {
                        if (response.success) {
                            handleAsyncResponse(response.data);
                        } else {
                            handleAsyncError(response.data);
                        }
                    },
                    error: function(xhr, status, error) {
                        handleNetworkError(error);
                    }
                });
            }

            // Gestisce risposta asincrona
            function handleAsyncResponse(data) {
                window.megabackupAsync.currentRetries = 0; // Reset retry counter
                
                if (data.completed) {
                    // Restore completato
                    window.megabackupAsync.isRunning = false;
                    stopStatusPolling();
                    updateProgress(100, 'Restore completato con successo!');
                    
                    if (data.stats) {
                        var statsHtml = '<br><strong>Statistiche:</strong><br>' +
                            'Tempo totale: ' + data.stats.total_time + ' secondi<br>' +
                            'File processati: ' + data.stats.files_processed + '<br>' +
                            'Query processate: ' + data.stats.queries_processed + '<br>' +
                            'Errori: ' + data.stats.errors_count;
                        $('#async-progress-text').append(statsHtml);
                    }
                    
                    $('#cancel-async-restore').hide();
                    
                    // Suggerisci reload
                    setTimeout(function() {
                        if (confirm('Restore completato! Vuoi ricaricare la pagina?')) {
                            window.location.reload();
                        }
                    }, 3000);
                    
                } else if (data.continue) {
                    // Continua con prossimo chunk
                    updateProgress(data.progress, data.message);
                    startStatusPolling();
                    
                    // Continua dopo breve pausa
                    setTimeout(function() {
                        if (window.megabackupAsync.isRunning) {
                            performAsyncRequest(null); // Continua con session esistente
                        }
                    }, 1000);
                } else {
                    // Errore o stato non riconosciuto
                    handleAsyncError(data.message || 'Stato sconosciuto');
                }
            }

            // Gestisce errori asincroni
            function handleAsyncError(error) {
                window.megabackupAsync.currentRetries++;
                
                if (window.megabackupAsync.currentRetries < window.megabackupAsync.maxRetries) {
                    updateProgress(null, 'Errore temporaneo (tentativo ' + window.megabackupAsync.currentRetries + '/' + window.megabackupAsync.maxRetries + '): ' + error);
                    
                    // Riprova dopo 5 secondi
                    setTimeout(function() {
                        if (window.megabackupAsync.isRunning) {
                            performAsyncRequest(null);
                        }
                    }, 5000);
                } else {
                    // Troppi errori, ferma il processo
                    window.megabackupAsync.isRunning = false;
                    stopStatusPolling();
                    updateProgress(null, 'Restore fallito dopo ' + window.megabackupAsync.maxRetries + ' tentativi: ' + error);
                    $('#cancel-async-restore').hide();
                }
            }

            // Gestisce errori di rete
            function handleNetworkError(error) {
                window.megabackupAsync.currentRetries++;
                
                if (window.megabackupAsync.currentRetries < window.megabackupAsync.maxRetries) {
                    updateProgress(null, 'Errore di rete (tentativo ' + window.megabackupAsync.currentRetries + '/' + window.megabackupAsync.maxRetries + ')');
                    
                    // Riprova dopo 10 secondi per errori di rete
                    setTimeout(function() {
                        if (window.megabackupAsync.isRunning) {
                            performAsyncRequest(null);
                        }
                    }, 10000);
                } else {
                    window.megabackupAsync.isRunning = false;
                    stopStatusPolling();
                    updateProgress(null, 'Errore di rete persistente dopo ' + window.megabackupAsync.maxRetries + ' tentativi');
                    $('#cancel-async-restore').hide();
                }
            }

            // Avvia polling dello stato
            function startStatusPolling() {
                stopStatusPolling(); // Ferma polling esistente
                
                window.megabackupAsync.statusInterval = setInterval(function() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'megabackup_get_restore_status',
                            session_id: window.megabackupAsync.sessionId,
                            nonce: $('#_wpnonce').val()
                        },
                        success: function(response) {
                            if (response.success && response.data.progress) {
                                updateProgress(response.data.progress.progress, response.data.progress.message);
                                
                                if (response.data.logs && response.data.logs.length > 0) {
                                    updateLogs(response.data.logs);
                                }
                            }
                        },
                        error: function() {
                            // Ignora errori di polling
                        }
                    });
                }, 3000); // Polling ogni 3 secondi
            }

            // Ferma polling dello stato
            function stopStatusPolling() {
                if (window.megabackupAsync.statusInterval) {
                    clearInterval(window.megabackupAsync.statusInterval);
                    window.megabackupAsync.statusInterval = null;
                }
            }

            // Aggiorna barra di progresso
            function updateProgress(progress, message) {
                if (progress !== null) {
                    $('#async-progress-fill').css('width', progress + '%');
                }
                
                if (message) {
                    $('#async-progress-text').text(message);
                }
            }

            // Aggiorna log
            function updateLogs(logs) {
                var logsHtml = '';
                var recentLogs = logs.slice(-20); // Mostra solo ultimi 20 log
                
                recentLogs.forEach(function(log) {
                    var logClass = 'log-' + log.type;
                    logsHtml += '<div class=\"' + logClass + '\">[' + log.time + '] ' + log.message + '</div>';
                });
                
                $('#async-logs').html(logsHtml);
                $('#async-logs').scrollTop($('#async-logs')[0].scrollHeight);
            }

            // Gestisce cancellazione restore
            $('#cancel-async-restore').on('click', function() {
                if (!confirm('Sei sicuro di voler cancellare il restore?')) {
                    return;
                }
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'megabackup_cancel_restore',
                        session_id: window.megabackupAsync.sessionId,
                        nonce: $('#_wpnonce').val()
                    },
                    success: function(response) {
                        window.megabackupAsync.isRunning = false;
                        stopStatusPolling();
                        $('#async-progress-area').hide();
                        
                        if (response.success) {
                            alert('Restore cancellato con successo');
                        } else {
                            alert('Errore durante la cancellazione: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('Errore di rete durante la cancellazione');
                    }
                });
            });

            // CSS per i log
            $('<style>')
                .prop('type', 'text/css')
                .html(`
                    .log-info { color: #333; }
                    .log-warning { color: #f56500; }
                    .log-error { color: #dc3232; }
                    .log-debug { color: #666; }
                    #async-progress-fill { 
                        background: linear-gradient(90deg, #0073aa 0%, #00a0d2 100%);
                        transition: width 0.3s ease;
                    }
                `)
                .appendTo('head');
        });
        </script>";
        
        echo $async_script;
    }
}

// Inizializza helper asincrono
new MegaBackup_Async_Helper();