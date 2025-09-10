<?php
/**
 * Uninstall script for MegaBackup plugin
 */

// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove plugin options
delete_option('megabackup_settings');

// Remove transients
delete_transient('megabackup_progress');
delete_transient('megabackup_logs');
delete_transient('megabackup_restore_progress');
delete_transient('megabackup_restore_logs');

// Clean up scheduled events
wp_clear_scheduled_hook('megabackup_do_backup');
wp_clear_scheduled_hook('megabackup_do_restore');

// Optionally remove backup files (user choice)
// Note: In a production plugin, you might want to ask the user
// whether they want to keep backup files when uninstalling

/*
$backups_dir = plugin_dir_path(__FILE__) . 'backups/';
if (file_exists($backups_dir)) {
    $files = glob($backups_dir . '*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($backups_dir);
}
*/

// Clean up logs
$logs_dir = plugin_dir_path(__FILE__) . 'logs/';
if (file_exists($logs_dir)) {
    $files = glob($logs_dir . '*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($logs_dir);
}

// Clean up temporary files
$tmp_dir = plugin_dir_path(__FILE__) . 'tmp/';
if (file_exists($tmp_dir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }
    
    rmdir($tmp_dir);
}