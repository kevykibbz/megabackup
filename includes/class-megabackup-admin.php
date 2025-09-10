<?php
/**
 * MegaFile Admin Class - OPTIMIZED: Enhanced with Large File Download Support
 */

if (!defined('ABSPATH')) {
    exit;
}

class MegaBackup_Admin {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'init_settings'));
        add_action('admin_init', array($this, 'handle_download_backup_optimized'));
        add_action('admin_init', array($this, 'handle_schedule_settings'));
    }

    public function add_admin_menu() {
        add_menu_page(
            __('MegaFile - Backup and restore', 'megabackup'),
            __('MegaFile', 'megabackup'),
            'manage_options',
            'megabackup',
            array($this, 'admin_page'),
            'dashicons-database-export',
            30
        );
    }

    public function init_settings() {
        register_setting('megabackup_settings', 'megabackup_settings');
        register_setting('megabackup_schedule_settings', 'megabackup_schedule_settings');
    }

    /**
     * Handle schedule settings save
     */
    public function handle_schedule_settings() {
        if (isset($_POST['megabackup_schedule_submit']) && wp_verify_nonce($_POST['megabackup_schedule_nonce'], 'megabackup_schedule_action')) {
            $schedule_enabled = isset($_POST['schedule_enabled']) ? true : false;
            $schedule_frequency = sanitize_text_field($_POST['schedule_frequency']);
            $schedule_time = sanitize_text_field($_POST['schedule_time']);

            $schedule_settings = array(
                'enabled' => $schedule_enabled,
                'frequency' => $schedule_frequency,
                'time' => $schedule_time,
                'last_run' => get_option('megabackup_last_scheduled_run', ''),
                'next_run' => ''
            );

            // Calculate next run time
            if ($schedule_enabled) {
                $next_run = $this->calculate_next_run($schedule_frequency, $schedule_time);
                $schedule_settings['next_run'] = $next_run;

                // Schedule the event
                $this->schedule_backup_event($next_run);

                MegaBackup_Core::log('SCHEDULE: Backup schedule enabled - Frequency: ' . $schedule_frequency . ', Time: ' . $schedule_time . ', Next run: ' . date('Y-m-d H:i:s', $next_run));
            } else {
                // Unschedule the event
                $this->unschedule_backup_event();
                MegaBackup_Core::log('SCHEDULE: Backup schedule disabled');
            }

            update_option('megabackup_schedule_settings', $schedule_settings);

            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>' . __('Schedule settings saved successfully!', 'megabackup') . '</p></div>';
            });
        }
    }

    /**
     * Calculate next run timestamp
     */
    private function calculate_next_run($frequency, $time) {
        $time_parts = explode(':', $time);
        $hour = intval($time_parts[0]);
        $minute = intval($time_parts[1]);

        $now = current_time('timestamp');
        $today = strtotime(date('Y-m-d', $now));
        $scheduled_time_today = $today + ($hour * 3600) + ($minute * 60);

        switch ($frequency) {
            case 'daily':
                if ($scheduled_time_today > $now) {
                    return $scheduled_time_today;
                } else {
                    return $scheduled_time_today + DAY_IN_SECONDS;
                }
                break;

            case 'weekly':
                $next_week = $scheduled_time_today + (7 * DAY_IN_SECONDS);
                if ($scheduled_time_today > $now) {
                    return $scheduled_time_today;
                } else {
                    return $next_week;
                }
                break;

            case 'monthly':
                $next_month = strtotime('+1 month', $scheduled_time_today);
                if ($scheduled_time_today > $now) {
                    return $scheduled_time_today;
                } else {
                    return $next_month;
                }
                break;

            default:
                return $scheduled_time_today + DAY_IN_SECONDS;
        }
    }

    /**
     * Schedule backup event
     */
    private function schedule_backup_event($timestamp) {
        // Clear any existing scheduled event
        $this->unschedule_backup_event();

        // Schedule new event
        wp_schedule_single_event($timestamp, 'megabackup_scheduled_backup');

        MegaBackup_Core::log('SCHEDULE: Event scheduled for: ' . date('Y-m-d H:i:s', $timestamp));
    }

    /**
     * Unschedule backup event
     */
    private function unschedule_backup_event() {
        $timestamp = wp_next_scheduled('megabackup_scheduled_backup');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'megabackup_scheduled_backup');
            MegaBackup_Core::log('SCHEDULE: Scheduled event removed');
        }
    }

    /**
     * OPTIMIZED: Enhanced download handler for large files with streaming and resume support
     */
    public function handle_download_backup_optimized() {
        if (isset($_GET['megabackup_download']) && isset($_GET['file']) && current_user_can('manage_options')) {
            if (!wp_verify_nonce($_GET['nonce'], 'megabackup_download_' . $_GET['file'])) {
                wp_die('Security check failed');
            }

            $backup_file = sanitize_file_name($_GET['file']);
            $backup_path = MEGABACKUP_BACKUPS_DIR . $backup_file;

            if (!file_exists($backup_path) || strpos(realpath($backup_path), realpath(MEGABACKUP_BACKUPS_DIR)) !== 0) {
                wp_die('Backup file not found or access denied');
            }

            MegaBackup_Core::log('OPTIMIZED-DOWNLOAD: Starting download for: ' . $backup_file);

            // OPTIMIZED: Configure environment for large file downloads
            $this->configure_download_environment();

            // OPTIMIZED: Get file information
            $file_size = filesize($backup_path);
            $file_name = basename($backup_path);

            MegaBackup_Core::log("OPTIMIZED-DOWNLOAD: File size: " . size_format($file_size));

            // OPTIMIZED: Handle range requests for resume capability
            $range_start = 0;
            $range_end = $file_size - 1;
            $partial_content = false;

            if (isset($_SERVER['HTTP_RANGE'])) {
                $partial_content = true;
                list($range_start, $range_end) = $this->parse_range_header($_SERVER['HTTP_RANGE'], $file_size);
                MegaBackup_Core::log("OPTIMIZED-DOWNLOAD: Range request - Start: {$range_start}, End: {$range_end}");
            }

            $content_length = $range_end - $range_start + 1;

            // OPTIMIZED: Set headers for large file download with resume support
            $this->set_download_headers_optimized($file_name, $file_size, $range_start, $range_end, $partial_content);

            // OPTIMIZED: Stream file with chunked reading for memory efficiency
            $this->stream_file_optimized($backup_path, $range_start, $range_end);

            MegaBackup_Core::log('OPTIMIZED-DOWNLOAD: Download completed successfully');
            exit;
        }
    }

    /**
     * OPTIMIZED: Configure environment for large file downloads
     */
    private function configure_download_environment() {
        // OPTIMIZED: Set unlimited execution time for downloads
        @ini_set('max_execution_time', 0);
        @ini_set('max_input_time', 0);
        @set_time_limit(0);
        @ignore_user_abort(false); // Allow abort for downloads

        // OPTIMIZED: Optimize memory for streaming
        @ini_set('memory_limit', '256M'); // Moderate memory for streaming

        // OPTIMIZED: Clear all output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        // OPTIMIZED: Disable compression for large files
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', 'Off');

        MegaBackup_Core::log('OPTIMIZED-DOWNLOAD: Environment configured for large file streaming');
    }

    /**
     * OPTIMIZED: Parse HTTP Range header for resume capability
     */
    private function parse_range_header($range_header, $file_size) {
        $range_start = 0;
        $range_end = $file_size - 1;

        if (preg_match('/bytes=(\d+)-(\d*)/', $range_header, $matches)) {
            $range_start = intval($matches[1]);
            if (!empty($matches[2])) {
                $range_end = intval($matches[2]);
            }
        }

        // Validate range
        if ($range_start < 0) {
            $range_start = 0;
        }
        if ($range_end >= $file_size) {
            $range_end = $file_size - 1;
        }
        if ($range_start > $range_end) {
            $range_start = 0;
            $range_end = $file_size - 1;
        }

        return array($range_start, $range_end);
    }

    /**
     * OPTIMIZED: Set headers for large file download with resume support
     */
    private function set_download_headers_optimized($file_name, $file_size, $range_start, $range_end, $partial_content) {
        $content_length = $range_end - $range_start + 1;

        // OPTIMIZED: Set status code
        if ($partial_content) {
            http_response_code(206); // Partial Content
            MegaBackup_Core::log("OPTIMIZED-DOWNLOAD: Sending partial content (206) - {$content_length} bytes");
        } else {
            http_response_code(200); // OK
            MegaBackup_Core::log("OPTIMIZED-DOWNLOAD: Sending full content (200) - {$content_length} bytes");
        }

        // OPTIMIZED: Essential headers for large file downloads
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Content-Length: ' . $content_length);

        // OPTIMIZED: Range and resume headers
        header('Accept-Ranges: bytes');
        if ($partial_content) {
            header("Content-Range: bytes {$range_start}-{$range_end}/{$file_size}");
        }

        // OPTIMIZED: Cache and connection headers
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Connection: close');

        // OPTIMIZED: Additional headers for better compatibility
        header('Content-Transfer-Encoding: binary');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime(MEGABACKUP_BACKUPS_DIR . $file_name)) . ' GMT');
        header('ETag: "' . md5($file_name . $file_size) . '"');

        MegaBackup_Core::log('OPTIMIZED-DOWNLOAD: Headers set for streaming download');
    }

    /**
     * OPTIMIZED: Stream file with chunked reading for memory efficiency and large file support
     */
    private function stream_file_optimized($file_path, $range_start, $range_end) {
        $handle = fopen($file_path, 'rb');
        if (!$handle) {
            MegaBackup_Core::log('OPTIMIZED-DOWNLOAD: ERROR - Cannot open file for streaming', 'error');
            http_response_code(500);
            echo 'Error: Cannot open file for download';
            return;
        }

        // OPTIMIZED: Seek to start position for range requests
        if ($range_start > 0) {
            fseek($handle, $range_start);
            MegaBackup_Core::log("OPTIMIZED-DOWNLOAD: Seeked to position: {$range_start}");
        }

        // OPTIMIZED: Calculate streaming parameters
        $bytes_remaining = $range_end - $range_start + 1;
        $chunk_size = 1024 * 1024; // 1MB chunks for optimal streaming
        $bytes_sent = 0;
        $last_log_time = time();

        MegaBackup_Core::log("OPTIMIZED-DOWNLOAD: Starting stream - {$bytes_remaining} bytes to send");

        // OPTIMIZED: Stream file in chunks with progress logging
        while (!feof($handle) && $bytes_remaining > 0 && connection_status() === CONNECTION_NORMAL) {
            // OPTIMIZED: Calculate chunk size for this iteration
            $current_chunk_size = min($chunk_size, $bytes_remaining);

            // OPTIMIZED: Read chunk
            $chunk = fread($handle, $current_chunk_size);
            if ($chunk === false) {
                MegaBackup_Core::log('OPTIMIZED-DOWNLOAD: ERROR - Failed to read chunk', 'error');
                break;
            }

            $chunk_length = strlen($chunk);
            if ($chunk_length === 0) {
                break;
            }

            // OPTIMIZED: Send chunk to client
            echo $chunk;

            // OPTIMIZED: Update counters
            $bytes_sent += $chunk_length;
            $bytes_remaining -= $chunk_length;

            // OPTIMIZED: Flush output to client
            if (ob_get_level()) {
                ob_flush();
            }
            flush();

            // OPTIMIZED: Log progress every 10MB or 30 seconds
            $current_time = time();
            if (($bytes_sent % (10 * 1024 * 1024) === 0) || ($current_time - $last_log_time >= 30)) {
                $progress_percent = round(($bytes_sent / ($range_end - $range_start + 1)) * 100, 1);
                MegaBackup_Core::log("OPTIMIZED-DOWNLOAD: Progress - {$progress_percent}% ({$bytes_sent} bytes sent)");
                $last_log_time = $current_time;
            }

            // OPTIMIZED: Brief pause to prevent server overload
            usleep(1000); // 1ms pause
        }

        fclose($handle);

        // OPTIMIZED: Log completion status
        if ($bytes_remaining <= 0) {
            MegaBackup_Core::log("OPTIMIZED-DOWNLOAD: Stream completed successfully - {$bytes_sent} bytes sent");
        } else {
            $connection_status = connection_status();
            if ($connection_status === CONNECTION_ABORTED) {
                MegaBackup_Core::log("OPTIMIZED-DOWNLOAD: Download aborted by client - {$bytes_sent} bytes sent", 'warning');
            } elseif ($connection_status === CONNECTION_TIMEOUT) {
                MegaBackup_Core::log("OPTIMIZED-DOWNLOAD: Download timed out - {$bytes_sent} bytes sent", 'warning');
            } else {
                MegaBackup_Core::log("OPTIMIZED-DOWNLOAD: Download incomplete - {$bytes_sent} bytes sent, {$bytes_remaining} remaining", 'warning');
            }
        }
    }

    public function admin_page() {
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'backup';
        ?>
        <div class="wrap megabackup-admin">
            <h1>
                <?php _e('MegaFile - Backup and restore', 'megabackup'); ?>
                <span class="enterprise-badge">50GB Support</span>
            </h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=megabackup&tab=backup" class="nav-tab <?php echo $active_tab == 'backup' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Create Backup', 'megabackup'); ?>
                </a>
                <a href="?page=megabackup&tab=restore" class="nav-tab <?php echo $active_tab == 'restore' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Restore Backup', 'megabackup'); ?>
                </a>
                <a href="?page=megabackup&tab=schedule" class="nav-tab <?php echo $active_tab == 'schedule' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Schedule', 'megabackup'); ?>
                </a>
                <a href="?page=megabackup&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Settings', 'megabackup'); ?>
                </a>
            </nav>

            <div class="tab-content">
                <?php
                switch($active_tab) {
                    case 'backup':
                        $this->backup_tab();
                        break;
                    case 'restore':
                        $this->restore_tab();
                        break;
                    case 'schedule':
                        $this->schedule_tab();
                        break;
                    case 'settings':
                        $this->settings_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

     private function backup_tab() {
        // Calculate sizes to make them available for the button's data attributes
        $backup_dir_space = $this->get_directory_space(MEGABACKUP_BACKUPS_DIR);
        $wp_size = $this->estimate_wordpress_size();
        $recommended_size = $wp_size * 2;
        ?>
        <div class="megabackup-tab backup-tab">
            <div class="backup-card">
                <h2><?php _e('Create a New Backup', 'megabackup'); ?></h2>
                <p><?php _e('Create a complete backup of your WordPress site, including files, themes, plugins, and database.', 'megabackup'); ?></p>

                <div class="backup-options">
                    <label>
                        <input type="checkbox" id="include-database" checked>
                        <?php _e('Include Database', 'megabackup'); ?>
                    </label>
                    <label>
                        <input type="checkbox" id="include-uploads" checked>
                        <?php _e('Include Uploads Folder', 'megabackup'); ?>
                    </label>
                    <label>
                        <input type="checkbox" id="include-themes" checked>
                        <?php _e('Include Themes Folder', 'megabackup'); ?>
                    </label>
                    <label>
                        <input type="checkbox" id="include-plugins" checked>
                        <?php _e('Include Plugins', 'megabackup'); ?>
                    </label>
                </div>

                <button id="start-backup" class="button button-primary button-hero" 
                        data-available-space="<?php echo esc_attr($backup_dir_space['free']); ?>" 
                        data-wp-size="<?php echo esc_attr($wp_size); ?>"
                        data-recommended-size="<?php echo esc_attr($recommended_size); ?>">
                    <?php _e('Start Backup', 'megabackup'); ?>
                </button>

                <div id="backup-selection-warning" class="notice notice-warning inline" style="display: none; margin-top: 10px;">
                    <p><?php _e('Please select at least one backup option to continue.', 'megabackup'); ?></p>
                </div>

                <div id="backup-progress" class="progress-container" style="display: none;">
                    <div class="progress-bar">
                        <div class="progress-fill"></div>
                    </div>
                    <div class="progress-text">0%</div>
                </div>

                <div id="backup-logs" class="logs-container">
                    <h3>
                        <?php _e('Backup Log', 'megabackup'); ?>
                        <a href="?page=megabackup&tab=backup&megabackup_download_log=1" class="button button-secondary" style="float: right;"><?php _e('Download Log', 'megabackup'); ?></a>
                    </h3>
                    <div class="logs-content"></div>
                </div>

                <div class="system-info-section">
                    <h3><?php _e('System Information', 'megabackup'); ?></h3>
                    <p class="description"><?php _e('This information helps in diagnosing and preventing errors during backup.', 'megabackup'); ?></p>

                    <?php $this->display_system_info($backup_dir_space, $wp_size); ?>
                </div>
            </div>
        </div>
        <div id="low-space-modal" class="megabackup-modal-overlay" style="display: none;">
            <div class="megabackup-modal-content">
                <div class="megabackup-modal-header">
                    <span class="dashicons dashicons-warning"></span>
                    <h3><?php _e('Insufficient Disk Space', 'megabackup'); ?></h3>
                </div>
                <div class="megabackup-modal-body">
                    <p><?php _e('Your available backup space is too low to safely create a new backup. Please free up some space on your server before trying again.', 'megabackup'); ?></p>
                    <div class="space-info">
                        <p><strong><?php _e('Available Space:', 'megabackup'); ?></strong> <span id="available-space"></span></p>
                        <p><strong><?php _e('Estimated Backup Size:', 'megabackup'); ?></strong> <span id="estimated-size"></span></p>
                        <p><strong><?php _e('Recommended Space:', 'megabackup'); ?></strong> <span id="recommended-size"></span></p>
                    </div>
                </div>
                <div class="megabackup-modal-footer">
                    <button id="close-low-space-modal" class="button button-primary"><?php _e('OK, I Understand', 'megabackup'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }


    private function restore_tab() {
        ?>
        <div class="megabackup-tab restore-tab">
            <div class="restore-options">
                <div class="restore-card">
                    <h3><?php _e('Restore from an Existing Backup', 'megabackup'); ?></h3>
                    <p class="description"><?php _e('Select a backup from the list below to restore it.', 'megabackup'); ?></p>
                    <select id="backup-select">
                        <option value=""><?php _e('Select a backup file...', 'megabackup'); ?></option>
                        <?php
                        $backups = $this->get_backup_files();
                        foreach ($backups as $backup) {
                            echo '<option value="' . esc_attr($backup['file']) . '">' . esc_html($backup['name']) . ' (' . esc_html($backup['date']) . ')</option>';
                        }
                        ?>
                    </select>
                    <button id="restore-existing" class="button button-secondary">
                        <?php _e('Restore Selected Backup', 'megabackup'); ?>
                    </button>
                </div>

                <div class="restore-card">
                    <h3><?php _e('Upload Backup File', 'megabackup'); ?></h3>
                    <p class="description"><?php _e('Upload a .megafile to add it to the backup list. Automatic chunked upload for large files.', 'megabackup'); ?></p>

                    <div class="upload-section">
                        <input type="file" id="backup-upload" accept=".megafile" style="margin: 10px 0;">
                        <br>
                        <button id="upload-backup" class="button button-secondary">
                            <?php _e('Upload Backup File', 'megabackup'); ?>
                        </button>
                    </div>

                    <div id="upload-progress" class="progress-container" style="display: none;">
                        <div class="progress-bar">
                            <div class="progress-fill"></div>
                        </div>
                        <div class="progress-text">0%</div>
                    </div>

                    <div id="upload-logs" class="logs-container" style="display: none;">
                        <h3><?php _e('Upload Log', 'megabackup'); ?></h3>
                        <div class="logs-content"></div>
                    </div>
                </div>
            </div>

            <div id="restore-progress" class="progress-container" style="display: none;">
                <div class="progress-bar">
                    <div class="progress-fill"></div>
                </div>
                <div class="progress-text">0%</div>
            </div>

            <div id="restore-logs" class="logs-container">
                <h3>
                    <?php _e('Restore Log', 'megabackup'); ?>
                    <a href="?page=megabackup&tab=restore&megabackup_download_log=1" class="button button-secondary" style="float: right;"><?php _e('Download Log', 'megabackup'); ?></a>
                </h3>
                <div class="logs-content"></div>
            </div>

            <div class="existing-backups">
                <h3><?php _e('Backup Archive', 'megabackup'); ?></h3>
                <div id="backups-list">
                    <?php $this->display_backups_list(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function schedule_tab() {   
        $schedule_settings = get_option('megabackup_schedule_settings', array(
            'enabled' => false,
            'frequency' => 'daily',
            'time' => '02:00',
            'last_run' => '',
            'next_run' => ''
        ));

        // Calculate sizes to make them available for the button's data attributes
        $backup_dir_space = $this->get_directory_space(MEGABACKUP_BACKUPS_DIR);
        $wp_size = $this->estimate_wordpress_size();
        $recommended_size = $wp_size * 2;

        $next_scheduled = wp_next_scheduled('megabackup_scheduled_backup');
        $last_run = get_option('megabackup_last_scheduled_run', '');
        ?>
        <div class="megabackup-tab schedule-tab">
            <div class="schedule-card">
                <h2><?php _e('Automatic Backup Scheduling', 'megabackup'); ?></h2>
                <p><?php _e('Configure automatic backups to protect your site without manual intervention.', 'megabackup'); ?></p>

                <form id="schedule-form" method="post" action="">
                    <?php wp_nonce_field('megabackup_schedule_action', 'megabackup_schedule_nonce'); ?>

                    <div class="schedule-options">
                        <div class="schedule-enable">
                            <label class="schedule-toggle">
                                <input type="checkbox" id="schedule-enabled-checkbox" name="schedule_enabled" value="1" <?php checked(isset($schedule_settings['enabled']) ? $schedule_settings['enabled'] : false, true); ?>>
                                <span class="toggle-slider"></span>
                                <strong><?php _e('Enable Automatic Backups', 'megabackup'); ?></strong>
                            </label>
                            <p class="description"><?php _e('Enable or disable scheduled automatic backups', 'megabackup'); ?></p>
                        </div>

                        <div class="schedule-row" style="display: flex; gap: 10px; align-items: flex-start;">
                            <div class="schedule-frequency" style="flex: 1; max-width: 200px;">
                                <h3><?php _e('Backup Frequency', 'megabackup'); ?></h3>
                                <select name="schedule_frequency" id="schedule-frequency" style="width: 100%; box-sizing: border-box;">
                                    <option value="daily" <?php selected($schedule_settings['frequency'], 'daily'); ?>><?php _e('Daily', 'megabackup'); ?></option>
                                    <option value="weekly" <?php selected($schedule_settings['frequency'], 'weekly'); ?>><?php _e('Weekly', 'megabackup'); ?></option>
                                    <option value="monthly" <?php selected($schedule_settings['frequency'], 'monthly'); ?>><?php _e('Monthly', 'megabackup'); ?></option>
                                </select>
                            </div>

                            <div class="schedule-time" style="flex: 1; max-width: 200px;">
                                <h3><?php _e('Backup Time', 'megabackup'); ?></h3>
                                <input type="time" name="schedule_time" id="schedule-time" value="<?php echo esc_attr($schedule_settings['time']); ?>" style="width: 100%; box-sizing: border-box;">
                                <p class="description"><?php _e('Choose the preferred time for the backup to run (12-hour format)', 'megabackup'); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="schedule-actions">
                        <button type="submit" id="save-schedule-settings" name="megabackup_schedule_submit" class="button button-primary" 
                                data-available-space="<?php echo esc_attr($backup_dir_space['free']); ?>" 
                                data-wp-size="<?php echo esc_attr($wp_size); ?>"
                                data-recommended-size="<?php echo esc_attr($recommended_size); ?>">
                            <?php _e('Save Settings', 'megabackup'); ?>
                        </button>
                    </div>
                </form>

                <div class="schedule-status">
                    <h3><?php _e('Scheduling Status', 'megabackup'); ?></h3>

                    <div class="status-info">
                        <div class="status-item">
                            <span class="status-label"><?php _e('Status:', 'megabackup'); ?></span>
                            <span class="status-value <?php echo $schedule_settings['enabled'] ? 'enabled' : 'disabled'; ?>">
                                <?php echo $schedule_settings['enabled'] ? __('Active', 'megabackup') : __('Inactive', 'megabackup'); ?>
                            </span>
                        </div>

                        <?php if ($schedule_settings['enabled'] && $next_scheduled): ?>
                        <div class="status-item">
                            <span class="status-label"><?php _e('Next Run:', 'megabackup'); ?></span>
                            <span class="status-value next-run">
                                <?php echo date('d/m/Y H:i', $next_scheduled); ?>
                                <small>(<?php echo human_time_diff($next_scheduled, current_time('timestamp')); ?> <?php echo $next_scheduled > current_time('timestamp') ? __('from now', 'megabackup') : __('ago', 'megabackup'); ?>)</small>
                            </span>
                        </div>
                        <?php endif; ?>

                        <?php if ($last_run): ?>
                        <div class="status-item">
                            <span class="status-label"><?php _e('Last Run:', 'megabackup'); ?></span>
                            <span class="status-value last-run">
                                <?php echo date('d/m/Y H:i', strtotime($last_run)); ?>
                                <small>(<?php echo human_time_diff(strtotime($last_run), current_time('timestamp')); ?> <?php _e('ago', 'megabackup'); ?>)</small>
                            </span>
                        </div>
                        <?php endif; ?>

                        <div class="status-item">
                            <span class="status-label"><?php _e('Frequency:', 'megabackup'); ?></span>
                            <span class="status-value frequency">
                                <?php
                                switch($schedule_settings['frequency']) {
                                    case 'daily': echo __('Daily', 'megabackup'); break;
                                    case 'weekly': echo __('Weekly', 'megabackup'); break;
                                    case 'monthly': echo __('Monthly', 'megabackup'); break;
                                    default: echo __('Not set', 'megabackup');
                                }
                                ?>
                                <?php if ($schedule_settings['enabled']): ?>
                                    <?php _e('at', 'megabackup'); ?> <?php echo esc_html($schedule_settings['time']); ?>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="schedule-info">
                    <h3><?php _e('Information about Automatic Backups', 'megabackup'); ?></h3>
                    <ul class="info-list">
                        <li><?php _e('Automatic backups use the same settings as manual backups', 'megabackup'); ?></li>
                        <li><?php _e('Backups are saved in the standard folder with an "auto_" prefix', 'megabackup'); ?></li>
                        <li><?php _e('You can change the settings at any time', 'megabackup'); ?></li>
                        <li><?php _e('In case of an error, you will receive a notification in the admin area', 'megabackup'); ?></li>
                    </ul>
                </div>
            </div>
        </div>

        <style>
        .schedule-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .schedule-options {
            margin: 20px 0;
        }

        .schedule-enable {
            margin-bottom: 25px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
        }

        .schedule-toggle {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-size: 16px;
        }

        .schedule-toggle input[type="checkbox"] {
            display: none;
        }

        .toggle-slider {
            position: relative;
            width: 50px;
            height: 24px;
            background: #ccc;
            border-radius: 24px;
            margin-right: 10px;
            transition: background 0.3s;
        }

        .toggle-slider:before {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: white;
            top: 2px;
            left: 2px;
            transition: transform 0.3s;
        }

        .schedule-toggle input[type="checkbox"]:checked + .toggle-slider {
            background: #0073aa;
        }

        .schedule-toggle input[type="checkbox"]:checked + .toggle-slider:before {
            transform: translateX(26px);
        }

        .schedule-frequency, .schedule-time {
            margin-bottom: 20px;
        }

        .schedule-frequency h3, .schedule-time h3 {
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        .schedule-frequency select, .schedule-time input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .schedule-actions {
            margin: 25px 0;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }

        .schedule-status {
            margin-top: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 4px;
        }

        .status-info {
            margin-top: 15px;
        }

        .status-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }

        .status-item:last-child {
            border-bottom: none;
        }

        .status-label {
            font-weight: 600;
            color: #555;
        }

        .status-value {
            font-weight: 500;
        }

        .status-value.enabled {
            color: #46b450;
        }

        .status-value.disabled {
            color: #dc3232;
        }

        .status-value small {
            color: #666;
            font-weight: normal;
        }

        .schedule-info {
            margin-top: 30px;
            padding: 20px;
            background: #e7f3ff;
            border-left: 4px solid #0073aa;
            border-radius: 4px;
        }

        .info-list {
            margin: 10px 0 0 20px;
        }

        .info-list li {
            margin-bottom: 8px;
            color: #555;
        }
        </style>
        <?php
    }

    private function settings_tab() {
        $settings = get_option('megabackup_settings', array());
        ?>
        <div class="megabackup-tab settings-tab">
            <form method="post" action="options.php">
                <?php settings_fields('megabackup_settings'); ?>

                <div class="settings-card">
                    <h3><?php _e('Advanced Configurations', 'megabackup'); ?></h3>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Maximum File Size', 'megabackup'); ?></th>
                            <td>
                                <select name="megabackup_settings[max_file_size]">
                                    <option value="1G" <?php selected(isset($settings['max_file_size']) ? $settings['max_file_size'] : '50G', '1G'); ?>>1GB</option>
                                    <option value="5G" <?php selected(isset($settings['max_file_size']) ? $settings['max_file_size'] : '50G', '5G'); ?>>5GB</option>
                                    <option value="10G" <?php selected(isset($settings['max_file_size']) ? $settings['max_file_size'] : '50G', '10G'); ?>>10GB</option>
                                    <option value="25G" <?php selected(isset($settings['max_file_size']) ? $settings['max_file_size'] : '50G', '25G'); ?>>25GB</option>
                                    <option value="50G" <?php selected(isset($settings['max_file_size']) ? $settings['max_file_size'] : '50G', '50G'); ?>>50GB (Enterprise)</option>
                                </select>
                                <p class="description"><?php _e('Maximum size of files to upload', 'megabackup'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Chunked Upload', 'megabackup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="megabackup_settings[chunked_upload]" value="1" <?php checked(isset($settings['chunked_upload']) ? $settings['chunked_upload'] : true, true); ?>>
                                    <?php _e('Enable chunked upload for large files', 'megabackup'); ?>
                                </label>
                                <p class="description"><?php _e('Automatically split large files into chunks for reliable upload', 'megabackup'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Disable Disk Space Check', 'megabackup'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="megabackup_settings[disable_disk_space_check]" value="1" <?php checked(isset($settings['disable_disk_space_check']) ? $settings['disable_disk_space_check'] : false, true); ?>>
                                    <?php _e('Skip disk space checking (for unlimited hosting)', 'megabackup'); ?>
                                </label>
                                <p class="description"><?php _e('Enable this if you have unlimited hosting and disk space checks are causing backup failures', 'megabackup'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Chunk Size', 'megabackup'); ?></th>
                            <td>
                                <select name="megabackup_settings[chunk_size]">
                                    <option value="10M" <?php selected(isset($settings['chunk_size']) ? $settings['chunk_size'] : '10M', '10M'); ?>>10MB (Recommended)</option>
                                    <option value="25M" <?php selected(isset($settings['chunk_size']) ? $settings['chunk_size'] : '10M', '25M'); ?>>25MB</option>
                                    <option value="50M" <?php selected(isset($settings['chunk_size']) ? $settings['chunk_size'] : '10M', '50M'); ?>>50MB</option>
                                    <option value="100M" <?php selected(isset($settings['chunk_size']) ? $settings['chunk_size'] : '10M', '100M'); ?>>100MB</option>
                                </select>
                                <p class="description"><?php _e('Size of each chunk for uploading large files', 'megabackup'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="settings-card">
                    <h3><?php _e('Download Optimization', 'megabackup'); ?></h3>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Download Method', 'megabackup'); ?></th>
                            <td>
                                <select name="megabackup_settings[download_method]">
                                    <option value="optimized" <?php selected(isset($settings['download_method']) ? $settings['download_method'] : 'optimized', 'optimized'); ?>>Optimized Streaming (Recommended)</option>
                                    <option value="direct" <?php selected(isset($settings['download_method']) ? $settings['download_method'] : 'optimized', 'direct'); ?>>Direct Download</option>
                                </select>
                                <p class="description"><?php _e('Optimized streaming supports download resume and large files', 'megabackup'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Download Chunk Size', 'megabackup'); ?></th>
                            <td>
                                <select name="megabackup_settings[download_chunk_size]">
                                    <option value="512K" <?php selected(isset($settings['download_chunk_size']) ? $settings['download_chunk_size'] : '1M', '512K'); ?>>512KB</option>
                                    <option value="1M" <?php selected(isset($settings['download_chunk_size']) ? $settings['download_chunk_size'] : '1M', '1M'); ?>>1MB (Recommended)</option>
                                    <option value="2M" <?php selected(isset($settings['download_chunk_size']) ? $settings['download_chunk_size'] : '1M', '2M'); ?>>2MB</option>
                                    <option value="5M" <?php selected(isset($settings['download_chunk_size']) ? $settings['download_chunk_size'] : '1M', '5M'); ?>>5MB</option>
                                </select>
                                <p class="description"><?php _e('Size of chunks for streaming download', 'megabackup'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="settings-card">
                    <h3><?php _e('Exclusion Settings', 'megabackup'); ?></h3>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Excluded Folders', 'megabackup'); ?></th>
                            <td>
                                <textarea name="megabackup_settings[excluded_folders_text]" rows="5" cols="50" class="large-text" placeholder="wp-content/cache
wp-content/uploads/temp
wp-content/logs
node_modules
.git"><?php
                                echo isset($settings['excluded_folders']) ? esc_textarea(implode("\n", $settings['excluded_folders'])) : '';
                                ?></textarea>
                                <p class="description"><?php _e('Enter one folder path per line (relative to WordPress root)', 'megabackup'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Excluded Files', 'megabackup'); ?></th>
                            <td>
                                <textarea name="megabackup_settings[excluded_files_text]" rows="3" cols="50" class="large-text" placeholder="*.log
*.tmp
debug.log
error_log
.htaccess"><?php
                                echo isset($settings['excluded_files']) ? esc_textarea(implode("\n", $settings['excluded_files'])) : '';
                                ?></textarea>
                                <p class="description"><?php _e('Enter one file name per line', 'megabackup'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="settings-card">
                    <h3><?php _e('Advanced Settings', 'megabackup'); ?></h3>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Compression Level', 'megabackup'); ?></th>
                            <td>
                                <select name="megabackup_settings[compression_level]">
                                    <?php for ($i = 1; $i <= 9; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php selected(isset($settings['compression_level']) ? $settings['compression_level'] : 6, $i); ?>>
                                        <?php echo $i; ?> <?php echo $i == 1 ? '(Fast)' : ($i == 9 ? '(Best)' : ''); ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Max Execution Time (seconds)', 'megabackup'); ?></th>
                            <td>
                                <input type="number" name="megabackup_settings[max_execution_time]"
                                       value="<?php echo isset($settings['max_execution_time']) ? esc_attr($settings['max_execution_time']) : 0; ?>"
                                       min="0" max="36000">
                                <p class="description"><?php _e('0 = Unlimited (Enterprise)', 'megabackup'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    private function display_backups_list() {
        $backups = $this->get_backup_files();

        if (empty($backups)) {
            echo '<p>' . __('No backups found.', 'megabackup') . '</p>';
            return;
        }

        echo '<div class="backups-list">';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th scope="col" class="manage-column">' . __('Backup', 'megabackup') . '</th>';
        echo '<th scope="col" class="manage-column">' . __('Creation Date', 'megabackup') . '</th>';
        echo '<th scope="col" class="manage-column">' . __('Size', 'megabackup') . '</th>';
        echo '<th scope="col" class="manage-column">' . __('Actions', 'megabackup') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($backups as $backup) {
            $download_url = wp_nonce_url(
                admin_url('admin.php?page=megabackup&megabackup_download=1&file=' . urlencode($backup['file'])),
                'megabackup_download_' . $backup['file'],
                'nonce'
            );

            echo '<tr>';
            echo '<td data-label="' . __('Backup', 'megabackup') . '"><strong>' . esc_html($backup['name']) . '</strong></td>';
            echo '<td data-label="' . __('Creation Date', 'megabackup') . '">' . esc_html($backup['date']) . '</td>';
            echo '<td data-label="' . __('File Size', 'megabackup') . '">' . esc_html($backup['size']) . '</td>';
            echo '<td data-label="' . __('Actions', 'megabackup') . '" class="backup-actions">';
            echo '<a href="' . esc_url($download_url) . '" class="button download-backup" title="' . __('Download this backup (Optimized for large files)', 'megabackup') . '">' . __('Download', 'megabackup') . '</a> ';
            echo '<button class="button restore-backup" data-file="' . esc_attr($backup['file']) . '" title="' . __('Restore this backup', 'megabackup') . '">' . __('Restore', 'megabackup') . '</button> ';
            echo '<button class="button delete-backup" data-file="' . esc_attr($backup['file']) . '" title="' . __('Delete this backup', 'megabackup') . '"></button>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    private function get_backup_files() {
        $backups = array();

        if (!file_exists(MEGABACKUP_BACKUPS_DIR)) {
            return $backups;
        }

        $files = glob(MEGABACKUP_BACKUPS_DIR . '*.megafile');

        foreach ($files as $file) {
            $backups[] = array(
                'file' => basename($file),
                'name' => basename($file, '.megafile'),
                'date' => date('Y-m-d H:i:s', filemtime($file)),
                'size' => size_format(filesize($file))
            );
        }

        // Sort by date (newest first)
        usort($backups, function($a, $b) {
            return strcmp($b['date'], $a['date']);
        });

        return $backups;
    }

    /**
     * Display system information for diagnostics
     */
    private function display_system_info($backup_dir_space, $wp_size) {
        $env_info = MegaBackup_Core::get_environment_info();
        $requirements_check = MegaBackup_Core::check_requirements();
        $optimal_chunk_size = MegaBackup_Core::get_optimal_chunk_size();
        $can_handle_large = MegaBackup_Core::can_handle_large_files();

        ?>
        <div class="system-info-grid">
            <div class="info-card">
                <h4><span class="dashicons dashicons-admin-tools"></span> <?php _e('PHP Environment', 'megabackup'); ?></h4>
                <div class="info-items">
                    <div class="info-item">
                        <span class="label"><?php _e('PHP Version:', 'megabackup'); ?></span>
                        <span class="value <?php echo version_compare($env_info['php_version'], '7.4', '>=') ? 'good' : 'warning'; ?>">
                            <?php echo esc_html($env_info['php_version']); ?>
                            <?php if (version_compare($env_info['php_version'], '7.4', '<')): ?>
                                <small>(<?php _e('Recommended: 7.4+', 'megabackup'); ?>)</small>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="label"><?php _e('Memory Limit:', 'megabackup'); ?></span>
                        <span class="value <?php echo $this->get_memory_status_class($env_info['memory_limit']); ?>">
                            <?php echo esc_html($env_info['memory_limit']); ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="label"><?php _e('Execution Time:', 'megabackup'); ?></span>
                        <span class="value <?php echo $this->get_execution_time_status_class($env_info['max_execution_time']); ?>">
                            <?php echo esc_html($env_info['max_execution_time']); ?>s
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="label"><?php _e('Max Upload:', 'megabackup'); ?></span>
                        <span class="value">
                            <?php echo esc_html($env_info['upload_max_filesize']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="info-card">
                <h4><span class="dashicons dashicons-performance"></span> <?php _e('System Capabilities', 'megabackup'); ?></h4>
                <div class="info-items">
                    <div class="info-item">
                        <span class="label"><?php _e('ZipArchive:', 'megabackup'); ?></span>
                        <span class="value <?php echo $env_info['ziparchive_available'] === 'yes' ? 'good' : 'error'; ?>">
                            <?php echo $env_info['ziparchive_available'] === 'yes' ? __('Available', 'megabackup') : __('Not Available', 'megabackup'); ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="label"><?php _e('Large Files:', 'megabackup'); ?></span>
                        <span class="value <?php echo $can_handle_large ? 'good' : 'warning'; ?>">
                            <?php echo $can_handle_large ? __('Supported', 'megabackup') : __('Limited', 'megabackup'); ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="label"><?php _e('Optimal Chunk:', 'megabackup'); ?></span>
                        <span class="value">
                            <?php echo size_format($optimal_chunk_size); ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="label"><?php _e('Server:', 'megabackup'); ?></span>
                        <span class="value">
                            <?php echo esc_html($env_info['server_software']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="info-card">
                <h4><span class="dashicons dashicons-chart-pie"></span> <?php _e('Space and Sizes', 'megabackup'); ?></h4>
                <div class="info-items">
                    <div class="info-item">
                        <span class="label"><?php _e('Estimated WP Size:', 'megabackup'); ?></span>
                        <span class="value">
                            <?php echo size_format($wp_size); ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="label"><?php _e('Available Backup Space:', 'megabackup'); ?></span>
                        <span class="value <?php 
                            $is_unlimited = $backup_dir_space['free'] >= PHP_INT_MAX || $backup_dir_space['free'] > (50 * 1024 * 1024 * 1024 * 1024);
                            if ($is_unlimited) {
                                echo 'good';
                            } elseif ($backup_dir_space['free'] > ($wp_size * 2)) {
                                echo 'good'; 
                            } else {
                                echo 'warning';
                            }
                        ?>">
                            <?php 
                            if ($is_unlimited) {
                                echo __('Unlimited', 'megabackup'); 
                            } else {
                                echo size_format($backup_dir_space['free']);
                                if ($backup_dir_space['free'] <= ($wp_size * 2)) {
                                    echo '<small>(' . __('May be insufficient', 'megabackup') . ')</small>';
                                }
                            }
                            ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="label"><?php _e('Existing Backups:', 'megabackup'); ?></span>
                        <span class="value">
                            <?php
                            $existing_backups = $this->get_backup_files();
                            echo count($existing_backups);
                            if (count($existing_backups) > 0) {
                                $total_size = 0;
                                foreach ($existing_backups as $backup) {
                                    $backup_path = MEGABACKUP_BACKUPS_DIR . $backup['file'];
                                    if (file_exists($backup_path)) {
                                        $total_size += filesize($backup_path);
                                    }
                                }
                                echo ' (' . size_format($total_size) . ')';
                            }
                            ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="label"><?php _e('WordPress:', 'megabackup'); ?></span>
                        <span class="value">
                            <?php echo esc_html($env_info['wordpress_version']); ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="info-card">
                <h4><span class="dashicons dashicons-yes-alt"></span> <?php _e('Requirements Status', 'megabackup'); ?></h4>
                <div class="info-items">
                    <?php if (empty($requirements_check)): ?>
                        <div class="info-item">
                            <span class="status-icon good">✓</span>
                            <span class="value good"><?php _e('All requirements are met', 'megabackup'); ?></span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($requirements_check as $error): ?>
                        <div class="info-item">
                            <span class="status-icon error">✗</span>
                            <span class="value error"><?php echo esc_html($error); ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <div class="info-item">
                        <span class="label"><?php _e('Backup Directory:', 'megabackup'); ?></span>
                        <span class="value <?php echo is_writable(MEGABACKUP_BACKUPS_DIR) ? 'good' : 'error'; ?>">
                            <?php echo is_writable(MEGABACKUP_BACKUPS_DIR) ? __('Writable', 'megabackup') : __('Not Writable', 'megabackup'); ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <span class="label"><?php _e('UNIFIED System:', 'megabackup'); ?></span>
                        <span class="value good">
                            <?php echo $env_info['unified_system'] ? __('Active', 'megabackup') : __('Standard', 'megabackup'); ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Recommendations section moved into grid, taking two columns -->
            <div class="info-card recommendations-card" style="grid-column: span 2; padding: 0;">
                <?php 
                // Get recommendations using the original method logic
                $recommendations = array();
                
                // Check memory
                if ($env_info['memory_limit'] !== 'unlimited' && $env_info['memory_limit'] !== '-1') {
                    $memory_bytes = MegaBackup_Core::convert_to_bytes($env_info['memory_limit']);
                    if ($memory_bytes < 256 * 1024 * 1024) {
                        $recommendations[] = __('Increase the PHP memory limit to at least 256MB for more reliable backups.', 'megabackup');
                    }
                }

                // Check execution time
                if ($env_info['max_execution_time'] !== 'unlimited' && $env_info['max_execution_time'] !== '0') {
                    $time = intval($env_info['max_execution_time']);
                    if ($time < 300) {
                        $recommendations[] = __('Increase the maximum execution time to at least 300 seconds for large site backups.', 'megabackup');
                    }
                }

                // Check ZipArchive
                if ($env_info['ziparchive_available'] !== 'yes') {
                    $recommendations[] = __('Install the PHP ZipArchive extension for full backup support.', 'megabackup');
                }

                // Check space - FIX: Don't recommend more space if unlimited
                $is_unlimited_space = $backup_dir_space['free'] >= PHP_INT_MAX || $backup_dir_space['free'] > (50 * 1024 * 1024 * 1024 * 1024);
                if (!$is_unlimited_space && $backup_dir_space['free'] <= ($wp_size * 2)) {
                    $recommendations[] = __('Free up disk space: at least twice the size of the site is recommended for backups.', 'megabackup');
                }

                // Check large files
                if (!$can_handle_large) {
                    $recommendations[] = __('The system may have difficulty with very large files. Consider using chunked upload.', 'megabackup');
                }

                // Check PHP version
                if (version_compare($env_info['php_version'], '7.4', '<')) {
                    $recommendations[] = __('Update PHP to version 7.4 or higher for better performance and security.', 'megabackup');
                }

                if (!empty($recommendations)): ?>
                    <div class="recommendations" style="margin: 0; padding: 20px; height: 100%;">
                        <h4 style="margin-top: 0;"><?php _e('💡 Recommendations to Optimize Backups', 'megabackup'); ?></h4>
                        <ul style="margin-bottom: 0;">
                            <?php foreach ($recommendations as $recommendation): ?>
                                <li><?php echo esc_html($recommendation); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <div class="recommendations" style="background: #e8f5e8; border-left-color: #46b450; margin: 0; padding: 20px; height: 100%;">
                        <h4 style="color: #46b450; margin-top: 0;"><?php _e('✅ Optimized System', 'megabackup'); ?></h4>
                        <p style="margin: 0; color: #555;"><?php _e('Your system is correctly configured to perform reliable and fast backups.', 'megabackup'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <style>
        .system-info-section {
            margin-top: 30px;
            padding: 20px;
            background: #f8f9fa;
            border: 1px solid #e1e5e9;
            border-radius: 8px;
        }

        .system-info-section h3 {
            margin-top: 0;
            color: #1d2327;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .system-info-section h3:before {
            content: "🔧";
            font-size: 18px;
        }

        .system-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }

        .info-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .info-card h4 {
            margin: 0 0 12px 0;
            color: #1d2327;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .info-card h4 .dashicons {
            font-size: 16px;
            width: 16px;
            height: 16px;
        }

        .info-items {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-item .label {
            font-weight: 500;
            color: #555;
            font-size: 13px;
        }

        .info-item .value {
            font-weight: 600;
            font-size: 13px;
            text-align: right;
        }

        .info-item .value.good {
            color: #46b450;
        }

        .info-item .value.warning {
            color: #ffb900;
        }

        .info-item .value.error {
            color: #dc3232;
        }

        .info-item .value small {
            display: block;
            font-weight: normal;
            font-size: 11px;
            opacity: 0.8;
        }

        .status-icon {
            font-weight: bold;
            margin-right: 8px;
        }

        .status-icon.good {
            color: #46b450;
        }

        .status-icon.error {
            color: #dc3232;
        }

        .recommendations {
            margin-top: 20px;
            padding: 16px;
            background: #e7f3ff;
            border-left: 4px solid #0073aa;
            border-radius: 4px;
        }

        .recommendations h4 {
            margin: 0 0 10px 0;
            color: #0073aa;
        }

        .recommendations ul {
            margin: 0;
            padding-left: 20px;
        }

        .recommendations li {
            margin-bottom: 6px;
            color: #555;
        }

        @media (max-width: 768px) {
            .system-info-grid {
                grid-template-columns: 1fr;
            }

            .info-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
            }

            .info-item .value {
                text-align: left;
            }
        }
        </style>
        <?php
    }
    /**
     * Get CSS class for memory status
     */
    private function get_memory_status_class($memory_limit) {
        if ($memory_limit === 'unlimited' || $memory_limit === '-1') {
            return 'good';
        }

        $memory_bytes = MegaBackup_Core::convert_to_bytes($memory_limit);
        if ($memory_bytes >= 512 * 1024 * 1024) { // 512MB+
            return 'good';
        } elseif ($memory_bytes >= 256 * 1024 * 1024) { // 256MB+
            return 'warning';
        } else {
            return 'error';
        }
    }

    /**
     * Get CSS class for execution time status
     */
    private function get_execution_time_status_class($execution_time) {
        if ($execution_time === 'unlimited' || $execution_time === '0') {
            return 'good';
        }

        $time = intval($execution_time);
        if ($time >= 300) { // 5+ minutes
            return 'good';
        } elseif ($time >= 120) { // 2+ minutes
            return 'warning';
        } else {
            return 'error';
        }
    }

    /**
     * Get directory space information
     */
    private function get_directory_space($directory) {
        $free_bytes = disk_free_space($directory);
        $total_bytes = disk_total_space($directory);

        // FIX: Handle unlimited space scenarios (common on some hosting providers)
        // If disk_free_space returns false or a very large number, assume unlimited space
        if ($free_bytes === false || $free_bytes > (100 * 1024 * 1024 * 1024 * 1024)) { // 100TB threshold
            $free_bytes = PHP_INT_MAX; // Set to maximum value to indicate unlimited space
        }
        
        if ($total_bytes === false || $total_bytes > (100 * 1024 * 1024 * 1024 * 1024)) { // 100TB threshold
            $total_bytes = PHP_INT_MAX;
        }

        return array(
            'free' => $free_bytes ?: PHP_INT_MAX,
            'total' => $total_bytes ?: PHP_INT_MAX,
            'used' => ($total_bytes !== PHP_INT_MAX && $free_bytes !== PHP_INT_MAX) ? ($total_bytes - $free_bytes) : 0
        );
    }

    /**
     * Estimate WordPress size
     */
    private function estimate_wordpress_size() {
        $total_size = 0;

        // Estimate database size
        global $wpdb;
        $db_size = 0;
        $tables = $wpdb->get_results("SHOW TABLE STATUS", ARRAY_A);
        if ($tables) {
            foreach ($tables as $table) {
                $db_size += $table['Data_length'] + $table['Index_length'];
            }
        }
        $total_size += $db_size;

        // Estimate file size (sampling)
        $directories = array(
            ABSPATH . 'wp-content/uploads/',
            ABSPATH . 'wp-content/themes/',
            ABSPATH . 'wp-content/plugins/',
            ABSPATH . 'wp-admin/',
            ABSPATH . 'wp-includes/'
        );

        foreach ($directories as $dir) {
            if (is_dir($dir)) {
                $total_size += $this->get_directory_size_estimate($dir);
            }
        }

        return $total_size;
    }

    /**
     * Estimate directory size (sampling for performance)
     */
    private function get_directory_size_estimate($directory) {
        $size = 0;
        $count = 0;
        $max_files = 100; // Limit sampling for performance

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($count >= $max_files) {
                    // Extrapolate total size from sample
                    $total_files = iterator_count(new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
                    ));
                    return ($size / $count) * $total_files;
                }

                if ($file->isFile()) {
                    $size += $file->getSize();
                    $count++;
                }
            }
        } catch (Exception $e) {
            // In case of error, return a conservative estimate
            return 100 * 1024 * 1024; // 100MB
        }

        return $size;
    }

    /**
     * Display system recommendations
     */
    private function display_system_recommendations($env_info, $can_handle_large, $backup_dir_space, $wp_size) {
        $recommendations = array();

        // Check memory
        if ($env_info['memory_limit'] !== 'unlimited' && $env_info['memory_limit'] !== '-1') {
            $memory_bytes = MegaBackup_Core::convert_to_bytes($env_info['memory_limit']);
            if ($memory_bytes < 256 * 1024 * 1024) {
                $recommendations[] = __('Increase the PHP memory limit to at least 256MB for more reliable backups.', 'megabackup');
            }
        }

        // Check execution time
        if ($env_info['max_execution_time'] !== 'unlimited' && $env_info['max_execution_time'] !== '0') {
            $time = intval($env_info['max_execution_time']);
            if ($time < 300) {
                $recommendations[] = __('Increase the maximum execution time to at least 300 seconds for large site backups.', 'megabackup');
            }
        }

        // Check ZipArchive
        if ($env_info['ziparchive_available'] !== 'yes') {
            $recommendations[] = __('Install the PHP ZipArchive extension for full backup support.', 'megabackup');
        }

        // Check space - FIX: Don't recommend more space if unlimited
        $is_unlimited_space = $backup_dir_space['free'] >= PHP_INT_MAX || $backup_dir_space['free'] > (50 * 1024 * 1024 * 1024 * 1024);
        if (!$is_unlimited_space && $backup_dir_space['free'] <= ($wp_size * 2)) {
            $recommendations[] = __('Free up disk space: at least twice the size of the site is recommended for backups.', 'megabackup');
        }

        // Check large files
        if (!$can_handle_large) {
            $recommendations[] = __('The system may have difficulty with very large files. Consider using chunked upload.', 'megabackup');
        }

        // Check PHP version
        if (version_compare($env_info['php_version'], '7.4', '<')) {
            $recommendations[] = __('Update PHP to version 7.4 or higher for better performance and security.', 'megabackup');
        }

        if (!empty($recommendations)) {
            ?>
            <div class="recommendations">
                <h4><?php _e('💡 Recommendations to Optimize Backups', 'megabackup'); ?></h4>
                <ul>
                    <?php foreach ($recommendations as $recommendation): ?>
                        <li><?php echo esc_html($recommendation); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php
        } else {
            ?>
            <div class="recommendations" style="background: #e8f5e8; border-left-color: #46b450;">
                <h4 style="color: #46b450;"><?php _e('✅ Optimized System', 'megabackup'); ?></h4>
                <p style="margin: 0; color: #555;"><?php _e('Your system is correctly configured to perform reliable and fast backups.', 'megabackup'); ?></p>
            </div>
            <?php
        }
    }
}
