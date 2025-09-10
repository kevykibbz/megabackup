<?php
/**
 * MegaBackup Test Script for Altervista
 * Upload this file to your WordPress root directory and access it via browser
 * URL: http://yoursite.altervista.org/megabackup-test.php
 */

// Basic WordPress bootstrap
define('WP_USE_THEMES', false);
require_once('./wp-config.php');
require_once('./wp-load.php');

if (!current_user_can('manage_options')) {
    die('Access denied. Please log in as administrator first.');
}

echo "<h1>MegaBackup Altervista Test</h1>";

// Test 1: Disk Space Detection
echo "<h2>Test 1: Disk Space Detection</h2>";
$backup_dir = WP_CONTENT_DIR . '/uploads/megabackup/backups/';
if (!file_exists($backup_dir)) {
    wp_mkdir_p($backup_dir);
}

$free_space = disk_free_space($backup_dir);
$total_space = disk_total_space($backup_dir);

echo "<p><strong>Backup Directory:</strong> $backup_dir</p>";
echo "<p><strong>Free Space (raw):</strong> " . ($free_space === false ? 'FALSE' : $free_space) . "</p>";
echo "<p><strong>Total Space (raw):</strong> " . ($total_space === false ? 'FALSE' : $total_space) . "</p>";
echo "<p><strong>Free Space (formatted):</strong> " . ($free_space ? size_format($free_space) : 'N/A') . "</p>";
echo "<p><strong>Total Space (formatted):</strong> " . ($total_space ? size_format($total_space) : 'N/A') . "</p>";

// Check if it's detected as unlimited
$is_unlimited = $free_space === false || $free_space > (50 * 1024 * 1024 * 1024 * 1024);
echo "<p><strong>Detected as Unlimited:</strong> " . ($is_unlimited ? '✅ YES' : '❌ NO') . "</p>";

// Test 2: WordPress Size Estimation
echo "<h2>Test 2: WordPress Size Estimation</h2>";
global $wpdb;
$db_size = 0;
$tables = $wpdb->get_results("SHOW TABLE STATUS", ARRAY_A);
if ($tables) {
    foreach ($tables as $table) {
        $db_size += $table['Data_length'] + $table['Index_length'];
    }
}
echo "<p><strong>Database Size:</strong> " . size_format($db_size) . "</p>";

$upload_dir = wp_upload_dir();
$upload_size = 0;
if (is_dir($upload_dir['basedir'])) {
    $upload_size = get_directory_size($upload_dir['basedir']);
}

function get_directory_size($directory) {
    $size = 0;
    if (is_dir($directory)) {
        $files = glob($directory . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                $size += filesize($file);
            } elseif (is_dir($file)) {
                $size += get_directory_size($file);
            }
        }
    }
    return $size;
}

echo "<p><strong>Uploads Directory Size:</strong> " . size_format($upload_size) . "</p>";
echo "<p><strong>Total Estimated WP Size:</strong> " . size_format($db_size + $upload_size) . "</p>";

// Test 3: ZipArchive Availability
echo "<h2>Test 3: ZipArchive Test</h2>";
if (class_exists('ZipArchive')) {
    echo "<p>✅ ZipArchive is available</p>";
    
    // Test creating a simple ZIP
    $test_zip = $backup_dir . 'test_' . time() . '.zip';
    $zip = new ZipArchive();
    $result = $zip->open($test_zip, ZipArchive::CREATE);
    
    if ($result === TRUE) {
        $zip->addFromString('test.txt', 'This is a test file for MegaBackup');
        $close_result = $zip->close();
        
        if ($close_result && file_exists($test_zip)) {
            $zip_size = filesize($test_zip);
            echo "<p>✅ Test ZIP created successfully: " . size_format($zip_size) . "</p>";
            unlink($test_zip); // Clean up
        } else {
            echo "<p>❌ Failed to close/save test ZIP</p>";
        }
    } else {
        echo "<p>❌ Failed to create test ZIP. Error code: $result</p>";
    }
} else {
    echo "<p>❌ ZipArchive is not available</p>";
}

// Test 4: Permission Check
echo "<h2>Test 4: Directory Permissions</h2>";
echo "<p><strong>Backup Directory Writable:</strong> " . (is_writable($backup_dir) ? '✅ YES' : '❌ NO') . "</p>";

// Test 5: Server Info
echo "<h2>Test 5: Server Information</h2>";
echo "<p><strong>Server Software:</strong> " . (isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown') . "</p>";
echo "<p><strong>PHP Version:</strong> " . phpversion() . "</p>";
echo "<p><strong>Memory Limit:</strong> " . ini_get('memory_limit') . "</p>";
echo "<p><strong>Max Execution Time:</strong> " . ini_get('max_execution_time') . "s</p>";
echo "<p><strong>Upload Max Filesize:</strong> " . ini_get('upload_max_filesize') . "</p>";

echo "<hr>";
echo "<p><em>Test completed. If all tests show ✅, your MegaBackup should work on Altervista.</em></p>";
echo "<p><strong>Next step:</strong> Try creating a small backup (database only) from the MegaBackup admin interface.</p>";
?>
