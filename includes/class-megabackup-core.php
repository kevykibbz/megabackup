<?php
/**
 * MegaBackup Core Class - UNIFIED: Complete Revision for Robust System
 */

if (!defined('ABSPATH')) {
    exit;
}

class MegaBackup_Core {

    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_init', array($this, 'download_log_file'));
    }

    public function enqueue_scripts() {
        // Frontend scripts if needed
    }

    public function enqueue_admin_scripts($hook) {
        // Only load on our admin pages
        if (strpos($hook, 'megabackup') === false) {
            return;
        }

        // FIX: Use filemtime for cache-busting the CSS file
        $css_version = file_exists(MEGABACKUP_PLUGIN_DIR . 'assets/css/admin.css') ? filemtime(MEGABACKUP_PLUGIN_DIR . 'assets/css/admin.css') : MEGABACKUP_VERSION;
        wp_enqueue_style(
            'megabackup-admin',
            MEGABACKUP_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            $css_version
        );

        // FIX: Use filemtime for cache-busting the JS file
        $js_version = file_exists(MEGABACKUP_PLUGIN_DIR . 'assets/js/admin.js') ? filemtime(MEGABACKUP_PLUGIN_DIR . 'assets/js/admin.js') : MEGABACKUP_VERSION;
        wp_enqueue_script(
            'megabackup-admin',
            MEGABACKUP_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            $js_version,
            true
        );

        wp_localize_script('megabackup-admin', 'megabackup_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('megabackup_nonce'),
            'messages' => array(
                'backup_started' => __('UNIFIED MEGAFILE backup started...', 'megabackup'),
                'backup_completed' => __('UNIFIED MEGAFILE backup completed successfully!', 'megabackup'),
                'backup_failed' => __('UNIFIED MEGAFILE backup failed. Please check the logs.', 'megabackup'),
                'restore_started' => __('UNIFIED MEGAFILE restore started...', 'megabackup'),
                'restore_completed' => __('UNIFIED MEGAFILE restore completed successfully!', 'megabackup'),
                'restore_failed' => __('UNIFIED MEGAFILE restore failed. Please check the logs.', 'megabackup'),
                'upload_started' => __('UNIFIED MEGAFILE upload started...', 'megabackup'),
                'upload_completed' => __('UNIFIED MEGAFILE upload completed successfully!', 'megabackup'),
                'upload_failed' => __('UNIFIED MEGAFILE upload failed. Please check the logs.', 'megabackup')
            )
        ));
    }

    public static function log($message, $type = 'info') {
        // Create logs directory if it doesn't exist
        $logs_dir = MEGABACKUP_PLUGIN_DIR . 'logs/';
        if (!file_exists($logs_dir)) {
            wp_mkdir_p($logs_dir);
        }

        $log_file = $logs_dir . 'megabackup.log';
        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = "[$timestamp] [UNIFIED] [$type] $message" . PHP_EOL;

        // Write to file
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);

        // Also write to WordPress debug log if enabled
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log("UNIFIED MegaBackup [$type]: $message");
        }
    }

    /**
     * UNIFIED: Generate MEGAFILE filename with proper extension
     */
    public static function generate_backup_filename() {
        $site_name = sanitize_title(get_bloginfo('name'));
        if (empty($site_name)) {
            $site_name = 'wordpress';
        }

        $timestamp = current_time('Y-m-d_H-i-s');
        $unique_id = sprintf('%03d', wp_rand(100, 999));

        // UNIFIED: Always use .megafile extension for consistency
        return "{$site_name}_{$timestamp}_{$unique_id}.megafile";
    }

    /**
     * UNIFIED: Get excluded paths for MEGAFILE backup
     */
    public static function get_excluded_paths() {
        $settings = get_option('megabackup_settings', array());
        $excluded = array();

        // Always exclude these from UNIFIED MEGAFILE backup
        $excluded[] = MEGABACKUP_BACKUPS_DIR;
        $excluded[] = MEGABACKUP_PLUGIN_DIR . 'logs/';
        $excluded[] = MEGABACKUP_PLUGIN_DIR . 'tmp/';
        $excluded[] = MEGABACKUP_PLUGIN_DIR . 'chunks/';

        // UNIFIED: Exclude common cache and temporary directories
        $excluded[] = ABSPATH . 'wp-content/cache/';
        $excluded[] = ABSPATH . 'wp-content/uploads/cache/';
        $excluded[] = ABSPATH . 'wp-content/w3tc-cache/';
        $excluded[] = ABSPATH . 'wp-content/wp-rocket-cache/';
        $excluded[] = ABSPATH . 'wp-content/litespeed-cache/';
        $excluded[] = ABSPATH . 'wp-content/et-cache/';
        $excluded[] = ABSPATH . 'wp-content/wp-fastest-cache/';
        $excluded[] = ABSPATH . 'wp-content/wp-super-cache/';

        // Add user-defined exclusions
        if (!empty($settings['excluded_folders'])) {
            foreach ($settings['excluded_folders'] as $folder) {
                $excluded[] = ABSPATH . ltrim($folder, '/');
            }
        }

        return $excluded;
    }

    /**
     * UNIFIED: Flexible system requirements check for all hosting types
     */
    public static function check_requirements() {
        $errors = array();
        $warnings = array();

        // Check if ZipArchive is available (primary method for UNIFIED MEGAFILE)
        if (!class_exists('ZipArchive')) {
            $errors[] = 'ZipArchive class not available. Please install php-zip extension for UNIFIED MEGAFILE support.';
        }

        // Check if backups directory is writable
        if (!is_writable(MEGABACKUP_BACKUPS_DIR)) {
            if (!wp_mkdir_p(MEGABACKUP_BACKUPS_DIR)) {
                $errors[] = 'Cannot create or write to UNIFIED MEGAFILE backups directory: ' . MEGABACKUP_BACKUPS_DIR;
            }
        }

        // Check if tmp directory can be created
        $tmp_test = MEGABACKUP_PLUGIN_DIR . 'tmp/test_' . uniqid();
        if (!wp_mkdir_p($tmp_test)) {
            $errors[] = 'Cannot create temporary directories for UNIFIED MEGAFILE processing in: ' . MEGABACKUP_PLUGIN_DIR . 'tmp/';
        } else {
            rmdir($tmp_test);
        }

        // Check if chunks directory can be created (for large uploads)
        $chunks_test = MEGABACKUP_PLUGIN_DIR . 'chunks/test_' . uniqid();
        if (!wp_mkdir_p($chunks_test)) {
            $errors[] = 'Cannot create chunks directories for UNIFIED MEGAFILE uploads in: ' . MEGABACKUP_PLUGIN_DIR . 'chunks/';
        } else {
            rmdir($chunks_test);
        }

        // UNIFIED: Very flexible memory limit check (warnings only)
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit && $memory_limit !== '-1') {
            $memory_bytes = self::convert_to_bytes($memory_limit);
            $recommended_bytes = 256 * 1024 * 1024; // 256MB minimum

            if ($memory_bytes < $recommended_bytes) {
                $warnings[] = 'Memory limit (' . $memory_limit . ') is low. UNIFIED MEGAFILE will work but may be slower. Recommended: 256M or higher.';
                self::log('UNIFIED: Low memory limit detected: ' . $memory_limit . ' - will work with reduced performance', 'warning');
            }
        }

        // UNIFIED: Very flexible execution time check (warnings only)
        $max_execution_time = ini_get('max_execution_time');
        if ($max_execution_time && $max_execution_time > 0 && $max_execution_time < 60) {
            $warnings[] = 'Max execution time (' . $max_execution_time . 's) is very low. UNIFIED MEGAFILE will use chunked processing to work around this limitation.';
            self::log('UNIFIED: Very low execution time detected: ' . $max_execution_time . 's - will use chunked processing', 'warning');
        } elseif ($max_execution_time && $max_execution_time > 0 && $max_execution_time < 300) {
            $warnings[] = 'Max execution time (' . $max_execution_time . 's) is low but workable. UNIFIED MEGAFILE will adapt processing accordingly.';
            self::log('UNIFIED: Low execution time detected: ' . $max_execution_time . 's - will adapt processing', 'info');
        }

        // UNIFIED: Check upload limits (warnings only)
        $upload_max_filesize = ini_get('upload_max_filesize');
        $post_max_size = ini_get('post_max_size');

        if ($upload_max_filesize) {
            $upload_bytes = self::convert_to_bytes($upload_max_filesize);
            $recommended_upload = 100 * 1024 * 1024; // 100MB

            if ($upload_bytes < $recommended_upload) {
                $warnings[] = 'Upload max filesize (' . $upload_max_filesize . ') is low. UNIFIED MEGAFILE chunked upload will handle large files automatically.';
                self::log('UNIFIED: Low upload limit detected: ' . $upload_max_filesize . ' - chunked upload will compensate', 'info');
            }
        }

        // UNIFIED: Log the environment for debugging
        self::log('UNIFIED: Environment check completed');
        self::log('UNIFIED: Memory limit: ' . ($memory_limit ?: 'unlimited'));
        self::log('UNIFIED: Max execution time: ' . ($max_execution_time ?: 'unlimited'));
        self::log('UNIFIED: Upload max filesize: ' . ($upload_max_filesize ?: 'unknown'));
        self::log('UNIFIED: Post max size: ' . ($post_max_size ?: 'unknown'));
        
        // FIX: Add detailed disk space logging for Altervista testing
        $backup_dir = MEGABACKUP_BACKUPS_DIR;
        $free_space = disk_free_space($backup_dir);
        $total_space = disk_total_space($backup_dir);
        self::log('DISK SPACE DEBUG: Free space raw value: ' . var_export($free_space, true));
        self::log('DISK SPACE DEBUG: Total space raw value: ' . var_export($total_space, true));
        self::log('DISK SPACE DEBUG: Free space formatted: ' . ($free_space && is_numeric($free_space) && $free_space > 0 ? size_format($free_space) : 'N/A'));
        self::log('DISK SPACE DEBUG: Server software: ' . (isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown'));

        // UNIFIED: Only return critical errors, not warnings
        if (!empty($warnings)) {
            self::log('UNIFIED: Warnings detected but proceeding: ' . implode(', ', $warnings), 'warning');
        }

        return $errors; // Only return blocking errors, not warnings
    }

    /**
     * UNIFIED: Convert memory limit string to bytes
     */
    public static function convert_to_bytes($value) {
        $value = trim($value);
        $last = strtolower($value[strlen($value)-1]);
        $value = (int) $value;

        switch($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * UNIFIED: Get current environment limits
     */
    public static function get_environment_info() {
        return array(
            'memory_limit' => ini_get('memory_limit') ?: 'unlimited',
            'max_execution_time' => ini_get('max_execution_time') ?: 'unlimited',
            'upload_max_filesize' => ini_get('upload_max_filesize') ?: 'unknown',
            'post_max_size' => ini_get('post_max_size') ?: 'unknown',
            'max_input_time' => ini_get('max_input_time') ?: 'unlimited',
            'max_file_uploads' => ini_get('max_file_uploads') ?: 'unlimited',
            'php_version' => PHP_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'ziparchive_available' => class_exists('ZipArchive') ? 'yes' : 'no',
            'server_software' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'unknown',
            'unified_system' => true
        );
    }

    /**
     * UNIFIED: Validate MEGAFILE format
     */
    public static function is_valid_megafile($file_path) {
        if (!file_exists($file_path)) {
            return false;
        }

        // Check file extension
        if (pathinfo($file_path, PATHINFO_EXTENSION) !== 'megafile') {
            return false;
        }

        // Check file size (must be at least 1KB)
        if (filesize($file_path) < 1024) {
            return false;
        }

        // Check file signature
        $handle = fopen($file_path, 'rb');
        if (!$handle) {
            return false;
        }

        $header = fread($handle, 1024);
        fclose($handle);

        if (strlen($header) < 4) {
            return false;
        }

        $signature_hex = bin2hex(substr($header, 0, 4));

        // Valid UNIFIED MEGAFILE signatures
        $valid_signatures = array(
            '504b0304', // ZIP local file header (most common)
            '504b0506', // ZIP end of central directory
            '504b0708', // ZIP data descriptor
            '1f8b0800', // GZIP header
            '425a6839', // BZIP2 header
            '377abcaf', // 7-Zip header
            '52617221'  // RAR header
        );

        // Also check if it contains ZIP signature anywhere in first 1KB
        $contains_zip = (strpos($header, "PK\x03\x04") !== false ||
                        strpos($header, "PK\x05\x06") !== false ||
                        strpos($header, "PK\x07\x08") !== false);

        return in_array($signature_hex, $valid_signatures) || $contains_zip;
    }

    /**
     * UNIFIED: Get MEGAFILE info
     */
    public static function get_megafile_info($file_path) {
        if (!self::is_valid_megafile($file_path)) {
            return false;
        }

        $info = array(
            'filename' => basename($file_path),
            'size' => filesize($file_path),
            'size_formatted' => size_format(filesize($file_path)),
            'created' => date('Y-m-d H:i:s', filemtime($file_path)),
            'format' => 'unified_megafile',
            'valid' => true,
            'unified_system' => true
        );

        // Try to extract metadata if it's a ZIP-based UNIFIED MEGAFILE
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($file_path, ZipArchive::RDONLY) === TRUE) {
                $metadata_content = $zip->getFromName('megabackup.json');
                if ($metadata_content) {
                    $metadata = json_decode($metadata_content, true);
                    if ($metadata) {
                        $info['metadata'] = $metadata;
                        $info['original_site'] = isset($metadata['site_url']) ? $metadata['site_url'] : 'Unknown';
                        $info['wp_version'] = isset($metadata['wp_version']) ? $metadata['wp_version'] : 'Unknown';
                        $info['backup_method'] = isset($metadata['backup_method']) ? $metadata['backup_method'] : 'Unknown';
                        $info['unified_system'] = isset($metadata['unified_system']) ? $metadata['unified_system'] : false;
                    }
                }
                $info['files_count'] = $zip->numFiles;
                $zip->close();
            }
        }

        return $info;
    }

    /**
     * UNIFIED: Enhanced file size formatting
     */
    public static function format_file_size($bytes) {
        if ($bytes === 0) return '0 Bytes';

        $k = 1024;
        $sizes = array('Bytes', 'KB', 'MB', 'GB', 'TB');
        $i = floor(log($bytes) / log($k));

        return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
    }

    /**
     * UNIFIED: Check if system can handle large files
     */
    public static function can_handle_large_files() {
        $memory_limit = ini_get('memory_limit');
        $execution_time = ini_get('max_execution_time');

        if ($memory_limit && $memory_limit !== '-1') {
            $memory_bytes = self::convert_to_bytes($memory_limit);
            if ($memory_bytes < 512 * 1024 * 1024) { // Less than 512MB
                return false;
            }
        }

        if ($execution_time && $execution_time > 0 && $execution_time < 300) {
            return false;
        }

        return true;
    }

    /**
     * UNIFIED: Get optimal chunk size based on system capabilities
     */
    public static function get_optimal_chunk_size() {
        $memory_limit = ini_get('memory_limit');
        $upload_limit = ini_get('upload_max_filesize');

        $chunk_size = 5 * 1024 * 1024; // Default 5MB

        if ($memory_limit && $memory_limit !== '-1') {
            $memory_bytes = self::convert_to_bytes($memory_limit);
            if ($memory_bytes < 256 * 1024 * 1024) { // Less than 256MB
                $chunk_size = 2 * 1024 * 1024; // 2MB for low memory
            } elseif ($memory_bytes > 1024 * 1024 * 1024) { // More than 1GB
                $chunk_size = 10 * 1024 * 1024; // 10MB for high memory
            }
        }

        if ($upload_limit) {
            $upload_bytes = self::convert_to_bytes($upload_limit);
            if ($upload_bytes < $chunk_size) {
                $chunk_size = max($upload_bytes / 2, 1024 * 1024); // Half of upload limit, min 1MB
            }
        }

        return $chunk_size;
    }
    
    public function download_log_file() {
        if (isset($_GET['megabackup_download_log']) && current_user_can('manage_options')) {
            $log_file = MEGABACKUP_PLUGIN_DIR . 'logs/megabackup.log';
            if (file_exists($log_file)) {
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="megabackup.log"');
                readfile($log_file);
                exit;
            } else {
                wp_die('Log file not found.');
            }
        }
    }
}
