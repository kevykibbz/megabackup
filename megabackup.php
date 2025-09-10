<?php
/**
 * Plugin Name: MegaFile - Backup and restore
 * Plugin URI: https://megafile.it
 * Description: Backup & Restore evoluto per WordPress: la soluzione sicura, veloce e compatibile con qualsiasi tipo di hosting.
 * Version: 2.0.0
 * Author: MarcoVinci.net
 * License: GPL v2 or later
 * Text Domain: megabackup
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('MEGABACKUP_VERSION', '2.0.0');
define('MEGABACKUP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MEGABACKUP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MEGABACKUP_BACKUPS_DIR', MEGABACKUP_PLUGIN_DIR . 'backups/');

// UNIFIED: Progressive default limits that adapt to hosting environment
@ini_set('max_execution_time', 120);
@ini_set('memory_limit', '512M');
@ini_set('upload_max_filesize', '50G');
@ini_set('post_max_size', '50G');
@ini_set('max_input_time', 120);
@ini_set('max_file_uploads', 2000);

// Include required files
require_once MEGABACKUP_PLUGIN_DIR . 'includes/class-megabackup-core.php';
require_once MEGABACKUP_PLUGIN_DIR . 'includes/class-megabackup-admin.php';
require_once MEGABACKUP_PLUGIN_DIR . 'includes/class-megabackup-backup.php';
require_once MEGABACKUP_PLUGIN_DIR . 'includes/class-megabackup-restore.php';
require_once MEGABACKUP_PLUGIN_DIR . 'includes/class-megabackup-ajax.php';
require_once MEGABACKUP_PLUGIN_DIR . 'includes/class-megabackup-scheduler.php';


// Initialize the plugin
class MegaBackup {

    public function __construct() {
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('wp_loaded', array($this, 'optimize_for_hosting'));
        add_action('admin_init', array($this, 'optimize_for_hosting'));
    }

    public function optimize_for_hosting() {
        if (isset($_POST['action']) && strpos($_POST['action'], 'megabackup_') === 0) {

            $current_memory = ini_get('memory_limit');
            $current_time = ini_get('max_execution_time');

            if ($current_memory && $current_memory !== '-1') {
                $memory_bytes = $this->convert_to_bytes($current_memory);

                if ($memory_bytes < 512 * 1024 * 1024) {
                    @ini_set('memory_limit', '512M');
                } elseif ($memory_bytes < 1024 * 1024 * 1024) {
                    @ini_set('memory_limit', '1024M');
                } else {
                    @ini_set('memory_limit', '2048M');
                }
            } else {
                @ini_set('memory_limit', '1024M');
            }

            if ($current_time && $current_time > 0) {
                if ($current_time < 300) {
                    @ini_set('max_execution_time', 600);
                } elseif ($current_time < 600) {
                    @ini_set('max_execution_time', 1800);
                } else {
                    @ini_set('max_execution_time', 0);
                }
            } else {
                @ini_set('max_execution_time', 0);
            }

            @ini_set('upload_max_filesize', '50G');
            @ini_set('post_max_size', '50G');
            @ini_set('max_input_time', 600);
            @set_time_limit(0);
            @ignore_user_abort(true);

            if (class_exists('MegaBackup_Core')) {
                MegaBackup_Core::log('PHP limits optimized for MegaBackup operations - Memory: ' . ini_get('memory_limit') . ', Time: ' . ini_get('max_execution_time') . 's');
            }
        }
    }

    /**
     * --- START FIX for convert_to_bytes ---
     * The original function had a critical flaw where the switch statement was missing 'break' cases,
     * causing incorrect calculations for 'g' and 'm' units due to fall-through logic.
     * This corrected version uses the proper multiplier for each case.
     */
    private function convert_to_bytes($value) {
        $value = trim($value);
        $last = strtolower(substr($value, -1));
        $num = (int)$value;
        switch ($last) {
            case 'g':
                $num *= 1073741824; // 1024 * 1024 * 1024
                break;
            case 'm':
                $num *= 1048576; // 1024 * 1024
                break;
            case 'k':
                $num *= 1024;
                break;
        }
        return $num;
    }
    // --- END FIX ---

    public function init() {
        // Initialize components
        new MegaBackup_Core();
        new MegaBackup_Admin();
        new MegaBackup_Ajax();
        new MegaBackup_Scheduler();

        // Load text domain
        load_plugin_textdomain('megabackup', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    public function activate() {
        // Create backups directory
        if (!file_exists(MEGABACKUP_BACKUPS_DIR)) {
            wp_mkdir_p(MEGABACKUP_BACKUPS_DIR);
        }

        // Create .htaccess file to protect backups directory
        $htaccess_content = "Order deny,allow\nDeny from all";
        file_put_contents(MEGABACKUP_BACKUPS_DIR . '.htaccess', $htaccess_content);

        // Create logs directory
        $logs_dir = MEGABACKUP_PLUGIN_DIR . 'logs/';
        if (!file_exists($logs_dir)) {
            wp_mkdir_p($logs_dir);
        }

        // Create tmp directory
        $tmp_dir = MEGABACKUP_PLUGIN_DIR . 'tmp/';
        if (!file_exists($tmp_dir)) {
            wp_mkdir_p($tmp_dir);
        }

        // Create chunks directory
        $chunks_dir = MEGABACKUP_PLUGIN_DIR . 'chunks/';
        if (!file_exists($chunks_dir)) {
            wp_mkdir_p($chunks_dir);
        }

        $memory_limit = ini_get('memory_limit');
        $execution_time = ini_get('max_execution_time');

        $chunk_size = '5M';
        $max_execution = 300;

        if ($memory_limit && $memory_limit !== '-1') {
            $memory_bytes = $this->convert_to_bytes($memory_limit);
            if ($memory_bytes < 256 * 1024 * 1024) {
                $chunk_size = '2M';
                $max_execution = 120;
            } elseif ($memory_bytes > 1024 * 1024 * 1024) {
                $chunk_size = '10M';
                $max_execution = 600;
            }
        }

        if ($execution_time && $execution_time > 0 && $execution_time < 120) {
            $chunk_size = '1M';
            $max_execution = $execution_time;
        }

        $adaptive_options = array(
            'excluded_folders' => array('cache', 'tmp', 'temp', 'logs'),
            'excluded_files' => array('.DS_Store', 'Thumbs.db', '*.log', '*.tmp'),
            'compression_level' => 6,
            'max_execution_time' => $max_execution,
            'max_file_size' => '50G',
            'chunked_upload' => true,
            'chunk_size' => $chunk_size,
            'unified_system' => true,
            'adaptive_mode' => true
        );

        add_option('megabackup_settings', $adaptive_options);

        if (class_exists('MegaBackup_Core')) {
            MegaBackup_Core::log('MegaBackup v2.0.0 Activated - UNIFIED system active');
            $env_info = MegaBackup_Core::get_environment_info();
            MegaBackup_Core::log('WordPress Environment - Memory: ' . $env_info['memory_limit'] . ', Time: ' . $env_info['max_execution_time'] . 's, Upload: ' . $env_info['upload_max_filesize']);
            MegaBackup_Core::log('Adaptive Settings – Chunk: ' . $chunk_size . ', Max time: ' . $max_execution . 's');
        }
        
        // Set default schedule settings (disabled by default)
        $default_schedule_options = array(
            'enabled' => false,
            'frequency' => 'daily',
            'time' => '02:00'
        );
        add_option('megabackup_schedule_settings', $default_schedule_options);
    }

    public function deactivate() {
        // Clean up transients
        delete_transient('megabackup_progress');
        delete_transient('megabackup_logs');
        delete_transient('megabackup_restore_progress');
        delete_transient('megabackup_restore_logs');
        delete_transient('megabackup_process_lock');
        delete_transient('megabackup_restore_process_lock');
        delete_transient('megabackup_options');
        delete_transient('megabackup_restore_file');

        $chunks_dir = MEGABACKUP_PLUGIN_DIR . 'chunks/';
        if (file_exists($chunks_dir)) {
            $chunk_dirs = glob($chunks_dir . '*', GLOB_ONLYDIR);
            foreach ($chunk_dirs as $dir) {
                $chunks = glob($dir . '/chunk_*');
                foreach ($chunks as $chunk) {
                    @unlink($chunk);
                }
                @rmdir($dir);
            }
        }

        $tmp_dir = MEGABACKUP_PLUGIN_DIR . 'tmp/';
        if (file_exists($tmp_dir)) {
            $tmp_files = glob($tmp_dir . '*');
            foreach ($tmp_files as $tmp_file) {
                if (filemtime($tmp_file) < (time() - 86400)) { // 24 hours
                    if (is_dir($tmp_file)) {
                        $this->cleanup_directory($tmp_file);
                    } else {
                        @unlink($tmp_file);
                    }
                }
            }
        }

        if (class_exists('MegaBackup_Core')) {
            MegaBackup_Core::log('MegaBackup deactivated');
        }
    }

    private function cleanup_directory($dir) {
        if (!file_exists($dir)) {
            return;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getRealPath());
                } else {
                    @unlink($file->getRealPath());
                }
            }

            @rmdir($dir);
        } catch (Exception $e) {
            // Ignore cleanup errors
        }
    }
}

// Start the plugin
new MegaBackup();