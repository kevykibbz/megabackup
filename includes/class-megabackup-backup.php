<?php
/**
 * MegaFile Backup Class - MODIFIED FOR FULL DATA COLLECTION BEFORE ZIPPING
 */

if (!defined('ABSPATH')) {
    exit;
}

class MegaBackup_Backup {

    private $job;

    /**
     * Creates the initial backup job (the "to-do list").
     */
    public function create_job($options) {
        $job_id = 'megabackup_' . time() . '_' . wp_rand(1000, 9999);
        
        // FIX: Use the filename from options if it exists (for scheduled backups), otherwise generate a new one.
        $filename = isset($options['filename']) && !empty($options['filename'])
                    ? sanitize_file_name($options['filename'])
                    : MegaBackup_Core::generate_backup_filename();

        $this->job = [
            'job_id' => $job_id,
            'options' => $options,
            'backup_filename' => $filename,
            'backup_path' => MEGABACKUP_BACKUPS_DIR . $filename,
            'temp_sql_path' => MEGABACKUP_PLUGIN_DIR . 'tmp/db_' . $job_id . '.sql',
            'status' => 'created',
            'step' => 'database', // Start with the database
            'progress' => 0,
            'uploads_queue' => [],
            'themes_queue' => [],
            'plugins_queue' => [],
            'total_uploads' => 0,
            'total_themes' => 0,
            'total_plugins' => 0,
            'uploads_processed' => 0,
            'themes_processed' => 0,
            'plugins_processed' => 0,
            'db_tables_queue' => [],
            'db_tables_total' => 0,
            'current_db_table' => false,
            'current_db_offset' => 0,
            'zip_files_processed' => 0, // new
            'total_files_to_zip' => 0 // new
        ];

        // Populate database queue if selected
        if (!empty($this->job['options']['include_database'])) {
            global $wpdb;
            $this->job['db_tables_queue'] = $wpdb->get_col("SHOW TABLES");
            $this->job['db_tables_total'] = count($this->job['db_tables_queue']);
            MegaBackup_Core::log("BACKUP: Database backup enabled. Found {$this->job['db_tables_total']} tables.");
        } else {
            MegaBackup_Core::log("BACKUP: Database backup disabled by user.");
            // Find the next enabled step
            $this->job['step'] = $this->get_next_enabled_step('database');
        }

        // Populate file queues separately based on user selections
        if (!empty($this->job['options']['include_uploads'])) {
            $upload_dir = wp_upload_dir();
            if ($upload_dir && !empty($upload_dir['basedir'])) {
                $this->scan_directory_for_queue($upload_dir['basedir'], 'uploads_queue');
                MegaBackup_Core::log("BACKUP: Uploads backup enabled. Found {$this->job['total_uploads']} files.");
            }
        } else {
            MegaBackup_Core::log("BACKUP: Uploads backup disabled by user.");
        }
        
        if (!empty($this->job['options']['include_themes'])) {
            $this->scan_directory_for_queue(get_theme_root(), 'themes_queue');
            MegaBackup_Core::log("BACKUP: Themes backup enabled. Found {$this->job['total_themes']} files.");
        } else {
            MegaBackup_Core::log("BACKUP: Themes backup disabled by user.");
        }
        
        if (!empty($this->job['options']['include_plugins'])) {
            $this->scan_directory_for_queue(WP_PLUGIN_DIR, 'plugins_queue');
            MegaBackup_Core::log("BACKUP: Plugins backup enabled. Found {$this->job['total_plugins']} files.");
        } else {
            MegaBackup_Core::log("BACKUP: Plugins backup disabled by user.");
        }

        $this->job['total_uploads'] = count($this->job['uploads_queue']);
        $this->job['total_themes'] = count($this->job['themes_queue']);
        $this->job['total_plugins'] = count($this->job['plugins_queue']);
        $this->job['total_files_to_zip'] = $this->job['total_uploads'] + $this->job['total_themes'] + $this->job['total_plugins'] + ($this->job['db_tables_total'] > 0 ? 1 : 0);

        MegaBackup_Core::log("BACKUP: Job created. Total files to process: {$this->job['total_files_to_zip']}");
        
        return $this->job;
    }

    /**
     * Helper method to find the next enabled backup step
     */
    private function get_next_enabled_step($current_step) {
        $steps = ['database', 'uploads', 'themes', 'plugins', 'zip_files', 'finalize'];
        $current_index = array_search($current_step, $steps);
        
        // Start checking from the next step
        for ($i = $current_index + 1; $i < count($steps); $i++) {
            $step = $steps[$i];
            
            // Check if this step is enabled
            switch ($step) {
                case 'uploads':
                    if (!empty($this->job['options']['include_uploads'])) return $step;
                    break;
                case 'themes':
                    if (!empty($this->job['options']['include_themes'])) return $step;
                    break;
                case 'plugins':
                    if (!empty($this->job['options']['include_plugins'])) return $step;
                    break;
                case 'zip_files':
                    return $step; // Always need to zip whatever we have
                case 'finalize':
                    return $step; // Always need to finalize
            }
        }
        
        // If no enabled steps found, go to finalize (last step)
        return 'finalize';
    }

    private function scan_directory_for_queue($source_path, $queue_name) {
        if (!is_dir($source_path) || !is_readable($source_path)) return;
        $abs_path_real = realpath(ABSPATH);

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source_path, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if ($file->isDir()) continue;
                $real_path = $file->getRealPath();
                if (!$real_path) continue;

                $zip_path = ltrim(str_replace($abs_path_real, '', $real_path), '/\\');

                $this->job[$queue_name][] = [
                    'source' => $real_path,
                    'zip_path' => $zip_path
                ];
            }
        } catch(Exception $e) {
            MegaBackup_Core::log('Could not scan directory ' . $source_path . ': ' . $e->getMessage(), 'warning');
        }
    }

    /**
     * Processes a single batch of the backup job.
     */
    public function process_batch($job) {
        $this->job = $job;

        switch ($this->job['step']) {
            case 'database':
                $this->process_database_batch();
                break;
            case 'uploads':
                $this->process_uploads_batch();
                break;
            case 'themes':
                $this->process_themes_batch();
                break;
            case 'plugins':
                $this->process_plugins_batch();
                break;
            case 'zip_files':
                $this->process_zip_files_batch();
                break;
            case 'finalize':
                $this->finalize_backup();
                break;
            case 'completed':
                // Backup is already finished, do nothing
                MegaBackup_Core::log("BACKUP: Backup already completed, no further processing needed.");
                return;
        }

        // Calculate overall progress based on enabled steps only
        $enabled_steps = [];
        if (!empty($this->job['options']['include_database'])) $enabled_steps[] = 'database';
        if (!empty($this->job['options']['include_uploads'])) $enabled_steps[] = 'uploads';
        if (!empty($this->job['options']['include_themes'])) $enabled_steps[] = 'themes';
        if (!empty($this->job['options']['include_plugins'])) $enabled_steps[] = 'plugins';
        $enabled_steps[] = 'zip_files'; // Always enabled
        $enabled_steps[] = 'finalize'; // Always enabled
        
        $total_enabled_steps = count($enabled_steps);
        $current_step_index = array_search($this->job['step'], $enabled_steps);
        
        $progress = 0;
        if ($current_step_index !== false) {
            // Base progress for completed steps
            $progress = ($current_step_index / $total_enabled_steps) * 100;
            
            // Add progress within current step
            $step_progress = 0;
            switch ($this->job['step']) {
                case 'database':
                    if ($this->job['db_tables_total'] > 0) {
                        $tables_processed = $this->job['db_tables_total'] - count($this->job['db_tables_queue']);
                        $step_progress = ($tables_processed / $this->job['db_tables_total']);
                    }
                    break;
                case 'uploads':
                    if ($this->job['total_uploads'] > 0) {
                        $step_progress = ($this->job['uploads_processed'] / $this->job['total_uploads']);
                    }
                    break;
                case 'themes':
                    if ($this->job['total_themes'] > 0) {
                        $step_progress = ($this->job['themes_processed'] / $this->job['total_themes']);
                    }
                    break;
                case 'plugins':
                    if ($this->job['total_plugins'] > 0) {
                        $step_progress = ($this->job['plugins_processed'] / $this->job['total_plugins']);
                    }
                    break;
                case 'zip_files':
                    if ($this->job['total_files_to_zip'] > 0) {
                        $step_progress = ($this->job['zip_files_processed'] / $this->job['total_files_to_zip']);
                    }
                    break;
                case 'finalize':
                    $step_progress = 1;
                    break;
                case 'completed':
                    $step_progress = 1;
                    $progress = 100; 
                    break;
            }
            
            // Add the current step progress
            $progress += ($step_progress / $total_enabled_steps) * 100;
        }

        // Only calculate progress if not manually set (e.g., for completed backups)
        if (!isset($this->job['progress']) || $this->job['step'] !== 'completed') {
            $this->job['progress'] = min(100, max(0, floor($progress)));
        }
        
        MegaBackup_Core::log("BACKUP: Progress calculation - Step: {$this->job['step']}, Progress: {$this->job['progress']}%, Enabled steps: " . implode(', ', $enabled_steps));

        return [
            'job' => $this->job,
            'progress' => $this->job['progress'],
            'message' => $this->job['status']
        ];
    }

    private function process_database_batch() {
        if (empty($this->job['db_tables_queue']) && !$this->job['current_db_table']) {
            $this->job['step'] = $this->get_next_enabled_step('database');
            $this->job['status'] = 'Database backup complete. Moving to next step...';
            MegaBackup_Core::log("BACKUP: Database step completed. Next step: " . $this->job['step']);
            return;
        }

        global $wpdb;
        $handle = fopen($this->job['temp_sql_path'], 'a');
        if(!$handle) throw new Exception('Cannot open temporary SQL file for writing.');

        if (!$this->job['current_db_table']) {
            $this->job['current_db_table'] = array_shift($this->job['db_tables_queue']);
            $this->job['current_db_offset'] = 0;

            $create_table_row = $wpdb->get_row("SHOW CREATE TABLE `{$this->job['current_db_table']}`", ARRAY_N);
            if($create_table_row) {
                $create_table_sql = $create_table_row[1];
                fwrite($handle, "\n-- Table structure for table `{$this->job['current_db_table']}`\n");
                fwrite($handle, "DROP TABLE IF EXISTS `{$this->job['current_db_table']}`;\n");
                fwrite($handle, $create_table_sql . ";\n\n");
            }
        }

        $table = $this->job['current_db_table'];
        $offset = $this->job['current_db_offset'];
        $batch_size = 200;

        $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT {$offset}, {$batch_size}", ARRAY_A);

        if (!empty($rows)) {
            fwrite($handle, "INSERT INTO `{$table}` VALUES \n");
            $values = [];
            foreach ($rows as $row) {
                $row_values = [];
                foreach ($row as $value) {
                    if (is_null($value)) {
                        $row_values[] = 'NULL';
                    } else {
                        $row_values[] = "'" . $wpdb->_real_escape($value) . "'";
                    }
                }
                $values[] = "(" . implode(",", $row_values) . ")";
            }
            fwrite($handle, implode(",\n", $values) . ";\n");
            $this->job['current_db_offset'] += count($rows);
        }

        if (count($rows) < $batch_size) {
            $this->job['current_db_table'] = false;
        }

        $this->job['status'] = "Backing up table '{$table}'...";
        fclose($handle);
    }

    private function process_uploads_batch() {
        // If uploads not selected or empty, skip to next step
        if (empty($this->job['options']['include_uploads']) || $this->job['total_uploads'] == 0) {
            $this->job['step'] = $this->get_next_enabled_step('uploads');
            $this->job['status'] = 'Uploads step skipped. Moving to next step...';
            MegaBackup_Core::log("BACKUP: Uploads step skipped (not selected or empty). Next step: " . $this->job['step']);
            return;
        }
        
        if ($this->job['uploads_processed'] >= $this->job['total_uploads']) {
            $this->job['step'] = $this->get_next_enabled_step('uploads');
            $this->job['status'] = 'Uploads folder backup complete. Moving to next step...';
            MegaBackup_Core::log("BACKUP: Uploads step completed. Next step: " . $this->job['step']);
            return;
        }

        $batch_size = 50;
        $files_in_batch = array_slice($this->job['uploads_queue'], $this->job['uploads_processed'], $batch_size);
        $this->job['uploads_processed'] += count($files_in_batch);
        $this->job['status'] = "Gathering Uploads folder files... (" . number_format($this->job['uploads_processed']) . " / " . number_format($this->job['total_uploads']) . ")";
        MegaBackup_Core::log("BACKUP: Processing uploads batch. Progress: {$this->job['uploads_processed']}/{$this->job['total_uploads']}");
    }

    private function process_themes_batch() {
        // If themes not selected or empty, skip to next step
        if (empty($this->job['options']['include_themes']) || $this->job['total_themes'] == 0) {
            $this->job['step'] = $this->get_next_enabled_step('themes');
            $this->job['status'] = 'Themes step skipped. Moving to next step...';
            MegaBackup_Core::log("BACKUP: Themes step skipped (not selected or empty). Next step: " . $this->job['step']);
            return;
        }
        
        if ($this->job['themes_processed'] >= $this->job['total_themes']) {
            $this->job['step'] = $this->get_next_enabled_step('themes');
            $this->job['status'] = 'Themes folder backup complete. Moving to next step...';
            MegaBackup_Core::log("BACKUP: Themes step completed. Next step: " . $this->job['step']);
            return;
        }

        $batch_size = 50;
        $files_in_batch = array_slice($this->job['themes_queue'], $this->job['themes_processed'], $batch_size);
        $this->job['themes_processed'] += count($files_in_batch);
        $this->job['status'] = "Gathering Themes folder files... (" . number_format($this->job['themes_processed']) . " / " . number_format($this->job['total_themes']) . ")";
        MegaBackup_Core::log("BACKUP: Processing themes batch. Progress: {$this->job['themes_processed']}/{$this->job['total_themes']}");
    }

    private function process_plugins_batch() {
        // If plugins not selected or empty, skip to next step
        if (empty($this->job['options']['include_plugins']) || $this->job['total_plugins'] == 0) {
            $this->job['step'] = 'zip_files';
            $this->job['status'] = 'Plugins step skipped. Starting to create ZIP archive...';
            MegaBackup_Core::log("BACKUP: Plugins step skipped (not selected or empty). Moving to zip_files step.");
            return;
        }
        
        if ($this->job['plugins_processed'] >= $this->job['total_plugins']) {
            $this->job['step'] = 'zip_files';
            $this->job['status'] = 'Plugins folder backup complete. Starting to create ZIP archive...';
            MegaBackup_Core::log("BACKUP: Plugins step completed. Moving to zip_files step.");
            return;
        }

        $batch_size = 50;
        $files_in_batch = array_slice($this->job['plugins_queue'], $this->job['plugins_processed'], $batch_size);
        $this->job['plugins_processed'] += count($files_in_batch);
        $this->job['status'] = "Gathering Plugins folder files... (" . number_format($this->job['plugins_processed']) . " / " . number_format($this->job['total_plugins']) . ")";
    }

    /**
     * Dedicated function to handle the zipping process after all data is prepared.
     */
    private function process_zip_files_batch() {
        $this->job['status'] = 'Creating ZIP archive and adding all files...';
        $zip = new ZipArchive();
        $zip_file = $this->job['backup_path'];
        $open_flag = file_exists($zip_file) ? ZipArchive::CHECKCONS : ZipArchive::CREATE;

        $open_result = $zip->open($zip_file, $open_flag);
        if ($open_result !== TRUE) {
            // FIX: Better error handling for ZIP creation failures
            $error_messages = [
                ZipArchive::ER_OK => 'No error',
                ZipArchive::ER_MULTIDISK => 'Multi-disk zip archives not supported',
                ZipArchive::ER_RENAME => 'Renaming temporary file failed',
                ZipArchive::ER_CLOSE => 'Closing zip archive failed',
                ZipArchive::ER_SEEK => 'Seek error',
                ZipArchive::ER_READ => 'Read error',
                ZipArchive::ER_WRITE => 'Write error - this often indicates insufficient disk space',
                ZipArchive::ER_CRC => 'CRC error',
                ZipArchive::ER_ZIPCLOSED => 'Containing zip archive was closed',
                ZipArchive::ER_NOENT => 'No such file',
                ZipArchive::ER_EXISTS => 'File already exists',
                ZipArchive::ER_OPEN => 'Can not open file - check permissions and disk space',
                ZipArchive::ER_TMPOPEN => 'Failure to create temporary file',
                ZipArchive::ER_ZLIB => 'Zlib error',
                ZipArchive::ER_MEMORY => 'Memory allocation failure',
                ZipArchive::ER_CHANGED => 'Entry has been changed',
                ZipArchive::ER_COMPNOTSUPP => 'Compression method not supported',
                ZipArchive::ER_EOF => 'Premature EOF',
                ZipArchive::ER_INVAL => 'Invalid argument',
                ZipArchive::ER_NOZIP => 'Not a zip archive',
                ZipArchive::ER_INTERNAL => 'Internal error',
                ZipArchive::ER_INCONS => 'Zip archive inconsistent',
                ZipArchive::ER_REMOVE => 'Can not remove file',
                ZipArchive::ER_DELETED => 'Entry has been deleted'
            ];
            
            $error_msg = isset($error_messages[$open_result]) ? $error_messages[$open_result] : "Unknown ZIP error code: $open_result";
            throw new Exception("Could not create or open ZIP file: $error_msg. This may indicate insufficient disk space or permission issues.");
        }

        if ($this->job['zip_files_processed'] === 0) {
            // Add metadata and database file on the first run
            $metadata = ['created' => time(), 'site_url' => get_site_url(), 'options' => $this->job['options']];
            $zip->addFromString('megabackup.json', json_encode($metadata));

            if (!empty($this->job['options']['include_database']) && file_exists($this->job['temp_sql_path'])) {
                if (!$zip->addFile($this->job['temp_sql_path'], 'database.sql')) {
                    $zip->close();
                    throw new Exception("Failed to add database file to ZIP. This may indicate insufficient disk space.");
                }
                $this->job['zip_files_processed']++;
            }
        }

        $all_files = array_merge($this->job['uploads_queue'], $this->job['themes_queue'], $this->job['plugins_queue']);
        
        // FIX: Adaptive batch sizing - start with smaller batches if we've had errors
        $batch_size = isset($this->job['zip_errors']) && $this->job['zip_errors'] > 0 ? 25 : 100;
        
        // Initialize error counter if not set
        if (!isset($this->job['zip_errors'])) {
            $this->job['zip_errors'] = 0;
        }

        // --- START FIX for INFINITE LOOP ---
        // The main counter ($this->job['zip_files_processed']) includes the database file if it exists,
        // but the $all_files array does not. We must adjust the starting offset for array_slice
        // to prevent processing the same files repeatedly or skipping files.
        $db_file_offset = (!empty($this->job['options']['include_database']) && file_exists($this->job['temp_sql_path'])) ? 1 : 0;
        
        // The correct offset into the $all_files array is the number of files we've already zipped,
        // minus the database file which isn't in this array.
        $files_array_offset = $this->job['zip_files_processed'] - $db_file_offset;

        // Slice the array using the corrected file offset to get the current batch.
        $files_to_process_now = array_slice($all_files, $files_array_offset, $batch_size);
        // --- END FIX ---

        // FIX: Improved disk space checking for unlimited hosting
        $backup_dir = dirname($this->job['backup_path']);
        
        // Check if disk space checking is disabled in settings
        $settings = get_option('megabackup_settings', array());
        $disable_disk_space_check = isset($settings['disable_disk_space_check']) && $settings['disable_disk_space_check'];
        
        if ($disable_disk_space_check) {
            MegaBackup_Core::log("BACKUP: Disk space checking disabled in settings. Proceeding with normal batch size.", 'info');
        } else {
            $available_space = disk_free_space($backup_dir);
            
            // Handle the case where disk_free_space returns false, null, 0, or empty string (common on shared hosting)
            if ($available_space === false || $available_space === null || $available_space === 0 || $available_space === '' || !is_numeric($available_space)) {
                MegaBackup_Core::log("BACKUP: Disk space check unavailable (disk_free_space returned: " . var_export($available_space, true) . "). Assuming unlimited hosting.", 'info');
                MegaBackup_Core::log("BACKUP: Disk space OK or unlimited hosting detected. Proceeding with normal batch size.", 'info');
            } else {
                MegaBackup_Core::log("BACKUP: Checking disk space. Available: " . size_format($available_space));
                
                // Only check space if disk_free_space returns a reasonable value and it's not unlimited hosting
                // Many unlimited hosting providers return very large numbers
                $is_unlimited = ($available_space > (100 * 1024 * 1024 * 1024 * 1024)); // More than 100TB = unlimited
                
                if (!$is_unlimited && $available_space < (100 * 1024 * 1024)) { // Only worry if less than 100MB
                    MegaBackup_Core::log("BACKUP: Low disk space detected: " . size_format($available_space), 'warning');
                    
                    // Reduce batch size for low space, but don't stop the backup
                    $batch_size = max(1, intval($batch_size / 2));
                    MegaBackup_Core::log("BACKUP: Reducing batch size to {$batch_size} files due to low space.", 'warning');
                    
                    // Only fail if extremely low space (less than 10MB)
                    if ($available_space < (10 * 1024 * 1024)) {
                        throw new Exception("Critically low disk space: " . size_format($available_space) . ". Cannot continue backup safely.");
                    }
                } else {
                    MegaBackup_Core::log("BACKUP: Disk space OK or unlimited hosting detected. Proceeding with normal batch size.", 'info');
                }
            }
        }

        MegaBackup_Core::log("BACKUP: Processing batch of {$batch_size} files starting from offset {$files_array_offset}");
        
        $files_added = 0;
        $files_skipped = 0;
        
        foreach ($files_to_process_now as $file_info) {
            if (!file_exists($file_info['source'])) {
                MegaBackup_Core::log("BACKUP: File not found, skipping: " . $file_info['source'], 'warning');
                $files_skipped++;
                continue;
            }
            
            if (!is_readable($file_info['source'])) {
                MegaBackup_Core::log("BACKUP: File not readable, skipping: " . $file_info['source'], 'warning');
                $files_skipped++;
                continue;
            }
            
            // Add detailed logging for large files
            $file_size = filesize($file_info['source']);
            if ($file_size > (10 * 1024 * 1024)) { // Log files larger than 10MB
                MegaBackup_Core::log("BACKUP: Adding large file (" . size_format($file_size) . "): " . $file_info['zip_path']);
            }
            
            // FIX: Add error handling for individual file additions with retry mechanism
            $add_success = false;
            $retry_count = 0;
            $max_retries = 2;
            
            while (!$add_success && $retry_count <= $max_retries) {
                if ($zip->addFile($file_info['source'], $file_info['zip_path'])) {
                    $add_success = true;
                    $files_added++;
                } else {
                    $retry_count++;
                    if ($retry_count <= $max_retries) {
                        MegaBackup_Core::log("BACKUP: Failed to add file (retry {$retry_count}/{$max_retries}): " . $file_info['source'], 'warning');
                        // Brief pause before retry
                        usleep(100000); // 100ms
                    } else {
                        MegaBackup_Core::log("BACKUP: Failed to add file after {$max_retries} retries, skipping: " . $file_info['source'], 'error');
                        $files_skipped++;
                    }
                }
            }
        }
        
        MegaBackup_Core::log("BACKUP: Batch completed. Files added: {$files_added}, Files skipped: {$files_skipped}");

        $processed_count = count($files_to_process_now);
        $this->job['zip_files_processed'] += $processed_count;
        $this->job['status'] = "Adding files to zip... (" . $this->job['zip_files_processed'] . " / " . $this->job['total_files_to_zip'] . ")";

        if ($this->job['zip_files_processed'] >= $this->job['total_files_to_zip']) {
            // FIX: Check if ZIP close operation was successful
            if (!$zip->close()) {
                throw new Exception("Failed to finalize ZIP file. This may indicate insufficient disk space or the backup file is corrupted.");
            }
            $this->job['step'] = 'finalize';
            $this->job['status'] = 'ZIP archive created.';
        } else {
            // FIX: Check if ZIP close operation was successful for partial saves
            if (!$zip->close()) {
                throw new Exception("Failed to save ZIP file progress. This may indicate insufficient disk space.");
            }
        }
    }

    private function finalize_backup() {
        MegaBackup_Core::log("BACKUP: Starting finalization process...");
        
        // Mark the job as complete so the UI updates successfully
        $this->job['status'] = 'Backup complete: ' . $this->job['backup_filename'];
        $this->job['progress'] = 100;
        $this->job['step'] = 'completed'; // Important: change step to prevent endless loop
        
        MegaBackup_Core::log("BACKUP: Backup finalized successfully. File: " . $this->job['backup_filename']);
        
        // Clean up the temporary database file (it's okay if this fails)
        if (file_exists($this->job['temp_sql_path'])) {
            $cleanup_success = @unlink($this->job['temp_sql_path']);
            if ($cleanup_success) {
                MegaBackup_Core::log("BACKUP: Temporary database file cleaned up successfully.");
            } else {
                MegaBackup_Core::log("BACKUP: Warning: Could not delete temporary database file: " . $this->job['temp_sql_path'], 'warning');
            }
        }
        
        // Verify the backup file exists and log its size
        if (file_exists($this->job['backup_path'])) {
            $backup_size = filesize($this->job['backup_path']);
            MegaBackup_Core::log("BACKUP: Final backup file size: " . size_format($backup_size));
        } else {
            MegaBackup_Core::log("BACKUP: Warning: Final backup file not found at: " . $this->job['backup_path'], 'warning');
        }
    }
}
