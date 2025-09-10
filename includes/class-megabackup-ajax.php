<?php
/**
 * MegaFile AJAX Class - REWRITTEN FOR QUEUE-BASED BACKGROUND PROCESSING
 * This class now manages the creation and processing of backup/restore jobs in small, safe batches.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MegaBackup_Ajax {

    public function __construct() {
        // --- REWRITTEN ACTIONS FOR QUEUE PROCESSING ---
        add_action('wp_ajax_megabackup_start_backup', array($this, 'start_backup'));
        add_action('wp_ajax_megabackup_process_backup_batch', array($this, 'process_backup_batch'));

        add_action('wp_ajax_megabackup_start_restore', array($this, 'start_restore'));
        add_action('wp_ajax_megabackup_process_restore_batch', array($this, 'process_restore_batch'));

        // --- GENERIC ACTIONS (Unchanged) ---
        add_action('wp_ajax_megabackup_get_progress', array($this, 'get_progress'));
        add_action('wp_ajax_megabackup_get_restore_progress', array($this, 'get_restore_progress'));
        add_action('wp_ajax_megabackup_get_logs', array($this, 'get_logs'));
        add_action('wp_ajax_megabackup_delete_backup', array($this, 'delete_backup'));
        add_action('wp_ajax_megabackup_get_restore_logs', array($this, 'get_restore_logs'));
        add_action('wp_ajax_megabackup_upload_only', array($this, 'upload_only'));
        add_action('wp_ajax_megabackup_chunk_upload', array($this, 'chunk_upload'));
        add_action('wp_ajax_megabackup_finalize_chunks', array($this, 'finalize_chunks'));
    }

    /**
     * BACKUP PLANNER: Creates the backup job queue.
     */
    public function start_backup() {
        check_ajax_referer('megabackup_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        MegaBackup_Core::log('AJAX: Backup job initiation started.');

        // Clean up any old job data
        delete_transient('megabackup_job');
        delete_transient('megabackup_progress');
        delete_transient('megabackup_logs');

        $options = array(
            'include_database' => isset($_POST['include_database']) && $_POST['include_database'] === 'true',
            'include_uploads' => isset($_POST['include_uploads']) && $_POST['include_uploads'] === 'true',
            'include_themes' => isset($_POST['include_themes']) && $_POST['include_themes'] === 'true',
            'include_plugins' => isset($_POST['include_plugins']) && $_POST['include_plugins'] === 'true'
        );

        // Validate that at least one option is selected
        if (!$options['include_database'] && !$options['include_uploads'] && !$options['include_themes'] && !$options['include_plugins']) {
            MegaBackup_Core::log('AJAX: No backup options selected. Defaulting to database only.', 'warning');
            $options['include_database'] = true; // Default to database backup if nothing selected
        }

        MegaBackup_Core::log('AJAX: Backup options - DB: ' . ($options['include_database'] ? 'YES' : 'NO') . 
                             ', Uploads: ' . ($options['include_uploads'] ? 'YES' : 'NO') . 
                             ', Themes: ' . ($options['include_themes'] ? 'YES' : 'NO') . 
                             ', Plugins: ' . ($options['include_plugins'] ? 'YES' : 'NO'));

        try {
            $backup = new MegaBackup_Backup();
            $job = $backup->create_job($options); // Create the to-do list

            set_transient('megabackup_job', $job, DAY_IN_SECONDS);

            $progress = array(
                'progress' => 1,
                'message' => 'Job created. Starting backup process...',
                'job_id' => $job['job_id']
            );
            set_transient('megabackup_progress', $progress, DAY_IN_SECONDS);

            MegaBackup_Core::log("AJAX: Backup job {$job['job_id']} created successfully. Total files: {$job['total_files_to_zip']}, DB Tables: " . count($job['db_tables_queue']));

            wp_send_json_success($progress);

        } catch (Exception $e) {
            MegaBackup_Core::log('Failed to create backup job: ' . $e->getMessage(), 'error');
            wp_send_json_error('Failed to create backup job: ' . $e->getMessage());
        }
    }

    /**
     * BACKUP WORKER: Processes one small batch from the job queue.
     */
    public function process_backup_batch() {
        check_ajax_referer('megabackup_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $job = get_transient('megabackup_job');
        if (!$job) {
            // Check if there's a recent progress indicating completion
            $recent_progress = get_transient('megabackup_progress');
            if ($recent_progress && $recent_progress['progress'] >= 100) {
                wp_send_json_success($recent_progress); // Return the final status
            } else {
                wp_send_json_error('Backup job not found or has expired. Please start again.');
            }
        }

        // If the job is already completed, don't process further
        if (isset($job['step']) && $job['step'] === 'completed') {
            $progress = get_transient('megabackup_progress');
            if ($progress) {
                wp_send_json_success($progress);
            } else {
                // Recreate the final progress status
                $final_progress = array(
                    'progress' => 100,
                    'message' => $job['status'],
                    'job_id' => $job['job_id']
                );
                wp_send_json_success($final_progress);
            }
        }

        @set_time_limit(60);

        try {
            $backup = new MegaBackup_Backup();
            $job_result = $backup->process_batch($job); // Process one small batch

            set_transient('megabackup_job', $job_result['job'], DAY_IN_SECONDS);

            $progress = array(
                'progress' => $job_result['progress'],
                'message' => $job_result['message'],
                'job_id' => $job['job_id']
            );
            set_transient('megabackup_progress', $progress, DAY_IN_SECONDS);

            if ($job_result['progress'] >= 100) {
                // Keep the job data for a short time after completion so UI can show final status
                set_transient('megabackup_job', $job_result['job'], 5 * MINUTE_IN_SECONDS); // 5 minutes
                MegaBackup_Core::log("Backup job {$job['job_id']} completed. Job data will be available for 5 more minutes.");
            } else {
                // Update the job data during processing
                set_transient('megabackup_job', $job_result['job'], DAY_IN_SECONDS);
            }

            wp_send_json_success($progress);

        } catch (Exception $e) {
            $error_msg = "Backup job {$job['job_id']} failed during batch processing: " . $e->getMessage();
            
            // FIX: Provide more helpful error messages for common space-related issues
            $original_error = $e->getMessage();
            if (strpos($original_error, 'disk space') !== false || strpos($original_error, 'Write error') !== false) {
                $error_msg .= "\n\nThis appears to be a disk space issue. Even if your hosting shows 'unlimited space', there may be temporary limits or quotas. Try freeing up some space or contact your hosting provider.";
            } elseif (strpos($original_error, 'ZIP') !== false && strpos($original_error, 'open') !== false) {
                $error_msg .= "\n\nThis appears to be a file system issue. Check that the backup directory is writable and try again.";
            }
            
            MegaBackup_Core::log($error_msg, 'error');
            wp_send_json_error($error_msg);
        }
    }

    /**
     * RESTORE PLANNER: Creates the restore job queue.
     */
    public function start_restore() {
        check_ajax_referer('megabackup_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        MegaBackup_Core::log('AJAX: Restore job initiation started.');

        delete_transient('megabackup_restore_job');
        delete_transient('megabackup_restore_progress');
        delete_transient('megabackup_restore_logs');

        $backup_file = sanitize_file_name($_POST['backup_file']);

        try {
            $restore = new MegaBackup_Restore();
            $job = $restore->create_job($backup_file);

            set_transient('megabackup_restore_job', $job, DAY_IN_SECONDS);

            $progress = [
                'progress' => 1,
                'message' => 'Restore job created. Starting extraction...',
                'job_id' => $job['job_id']
            ];
            set_transient('megabackup_restore_progress', $progress, DAY_IN_SECONDS);

            MegaBackup_Core::log("Restore job {$job['job_id']} created for file {$backup_file}.");
            wp_send_json_success($progress);
        } catch (Exception $e) {
            MegaBackup_Core::log('Failed to create restore job: ' . $e->getMessage(), 'error');
            wp_send_json_error('Failed to create restore job: ' . $e->getMessage());
        }
    }

    /**
     * RESTORE WORKER: Processes one small batch from the restore job queue.
     */
    public function process_restore_batch() {
        check_ajax_referer('megabackup_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $job = get_transient('megabackup_restore_job');
        if (!$job) {
            // Check if there's a recent progress indicating completion
            $recent_progress = get_transient('megabackup_restore_progress');
            if ($recent_progress && $recent_progress['progress'] >= 100) {
                wp_send_json_success($recent_progress); // Return the final status
            } else {
                wp_send_json_error('Restore job not found or has expired. Please start again.');
            }
        }

        // If the job is already completed, don't process further
        if (isset($job['step']) && $job['step'] === 'completed') {
            $progress = get_transient('megabackup_restore_progress');
            if ($progress) {
                wp_send_json_success($progress);
            } else {
                // Recreate the final progress status
                $final_progress = array(
                    'progress' => 100,
                    'message' => $job['status'],
                    'job_id' => $job['job_id']
                );
                wp_send_json_success($final_progress);
            }
        }

        @set_time_limit(60);

        try {
            $restore = new MegaBackup_Restore();
            $job_result = $restore->process_batch($job);

            set_transient('megabackup_restore_job', $job_result['job'], DAY_IN_SECONDS);

            $progress = [
                'progress' => $job_result['progress'],
                'message' => $job_result['message'],
                'job_id' => $job['job_id']
            ];
            set_transient('megabackup_restore_progress', $progress, DAY_IN_SECONDS);

            if ($job_result['progress'] >= 100) {
                // Keep the job data for a short time after completion so UI can show final status
                set_transient('megabackup_restore_job', $job_result['job'], 5 * MINUTE_IN_SECONDS); // 5 minutes
                MegaBackup_Core::log("Restore job {$job['job_id']} completed. Job data will be available for 5 more minutes.");
            } else {
                // Update the job data during processing
                set_transient('megabackup_restore_job', $job_result['job'], DAY_IN_SECONDS);
            }

            wp_send_json_success($progress);
        } catch (Exception $e) {
            $error_msg = "Restore job {$job['job_id']} failed: " . $e->getMessage();
            MegaBackup_Core::log($error_msg, 'error');
            wp_send_json_error($error_msg);
        }
    }

    // --- GENERIC HELPER FUNCTIONS ---
    public function get_progress() {
        check_ajax_referer('megabackup_nonce', 'nonce');
        $progress = get_transient('megabackup_progress');
        if (!$progress) {
            $progress = ['progress' => 0, 'message' => 'Waiting to start...'];
        }
        wp_send_json_success($progress);
    }

    public function get_restore_progress() {
        check_ajax_referer('megabackup_nonce', 'nonce');
        $progress = get_transient('megabackup_restore_progress');
        if (!$progress) {
            $progress = ['progress' => 0, 'message' => 'Waiting to start...'];
        }
        wp_send_json_success($progress);
    }

    public function get_logs() {
        check_ajax_referer('megabackup_nonce', 'nonce');
        $logs = get_transient('megabackup_logs') ?: [];
        wp_send_json_success($logs);
    }

    public function get_restore_logs() {
        check_ajax_referer('megabackup_nonce', 'nonce');
        $logs = get_transient('megabackup_restore_logs') ?: [];
        wp_send_json_success($logs);
    }

    public function delete_backup() {
        check_ajax_referer('megabackup_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        $backup_file = sanitize_file_name($_POST['backup_file']);
        $backup_path = MEGABACKUP_BACKUPS_DIR . $backup_file;
        if (file_exists($backup_path) && unlink($backup_path)) {
            wp_send_json_success('Backup deleted.');
        } else {
            wp_send_json_error('Failed to delete backup.');
        }
    }

    // --- CHUNKED UPLOAD FUNCTIONS (Unchanged) ---
    public function upload_only() {
        check_ajax_referer('megabackup_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        if (empty($_FILES['backup_file'])) { wp_send_json_error('No file uploaded.'); }
        $uploaded_file = $_FILES['backup_file'];
        if ($uploaded_file['error'] !== UPLOAD_ERR_OK) { wp_send_json_error('Upload error: ' . $uploaded_file['error']); }
        if (pathinfo($uploaded_file['name'], PATHINFO_EXTENSION) !== 'megafile') { wp_send_json_error('Invalid file type.'); }
        $filename = sanitize_file_name($uploaded_file['name']);
        $backup_path = MEGABACKUP_BACKUPS_DIR . $filename;
        if (!move_uploaded_file($uploaded_file['tmp_name'], $backup_path)) { wp_send_json_error('Could not save uploaded file.');}
        wp_send_json_success(['message' => 'File uploaded successfully', 'filename' => $filename]);
    }

    public function chunk_upload() {
        check_ajax_referer('megabackup_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        $chunk_index = intval($_POST['chunk_index']);
        $file_name = sanitize_file_name($_POST['file_name']);
        $chunk_id = sanitize_text_field($_POST['chunk_id']);
        $chunks_dir = MEGABACKUP_PLUGIN_DIR . 'chunks/' . $chunk_id;
        if (!file_exists($chunks_dir)) {
            wp_mkdir_p($chunks_dir);
        }
        $chunk_path = $chunks_dir . '/chunk_' . $chunk_index;
        if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunk_path)) {
            wp_send_json_error('Failed to save chunk.');
        }
        wp_send_json_success(['message' => "Chunk {$chunk_index} uploaded."]);
    }

    public function finalize_chunks() {
        check_ajax_referer('megabackup_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        $chunk_id = sanitize_text_field($_POST['chunk_id']);
        $file_name = sanitize_file_name($_POST['file_name']);
        $total_chunks = intval($_POST['total_chunks']);
        $chunks_dir = MEGABACKUP_PLUGIN_DIR . 'chunks/' . $chunk_id;
        $backup_path = MEGABACKUP_BACKUPS_DIR . $file_name;

        $out = fopen($backup_path, 'wb');
        if (!$out) { wp_send_json_error('Cannot open final file for writing.'); }

        for ($i = 0; $i < $total_chunks; $i++) {
            $chunk_path = $chunks_dir . '/chunk_' . $i;
            if(!file_exists($chunk_path)) {
                fclose($out);
                wp_send_json_error("Chunk {$i} is missing.");
            }
            $in = fopen($chunk_path, 'rb');
            if (!$in) {
                fclose($out);
                wp_send_json_error("Cannot read chunk {$i}.");
            }
            while ($buff = fread($in, 4096)) {
                fwrite($out, $buff);
            }
            fclose($in);
            unlink($chunk_path);
        }
        fclose($out);
        rmdir($chunks_dir);
        wp_send_json_success(['message' => 'File finalized successfully.', 'filename' => $file_name]);
    }
}