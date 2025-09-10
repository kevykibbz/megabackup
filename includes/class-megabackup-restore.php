<?php
/**
 * MegaFile Restore Class - REWRITTEN FOR QUEUE-BASED BATCH PROCESSING
 */
if (!defined('ABSPATH')) {
    exit;
}

class MegaBackup_Restore {

    private $job;

    /**
     * Creates the initial restore job from a backup file.
     */
    public function create_job($backup_file) {
        $job_id = 'megabackup_restore_' . time() . '_' . wp_rand(1000, 9999);
        $backup_path = MEGABACKUP_BACKUPS_DIR . $backup_file;

        if (!file_exists($backup_path) || !is_readable($backup_path)) {
            throw new Exception("Backup file not found or is not readable: " . $backup_file);
        }

        $temp_dir = MEGABACKUP_PLUGIN_DIR . 'tmp/restore_' . $job_id;
        if (!wp_mkdir_p($temp_dir)) {
            throw new Exception("Could not create temporary directory for restore.");
        }

        $this->job = [
            'job_id' => $job_id,
            'backup_path' => $backup_path,
            'temp_dir' => $temp_dir,
            'status' => 'created',
            'step' => 'extracting',
            'progress' => 0,
            'files_queue' => [],
            'total_files' => 0,
            'files_processed' => 0,
            'progress_step' => 0,
        ];

        $zip = new ZipArchive();
        if ($zip->open($this->job['backup_path']) === TRUE) {
            $this->job['total_files'] = $zip->numFiles;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $this->job['files_queue'][] = $zip->getNameIndex($i);
            }
            $zip->close();
        } else {
            throw new Exception('Could not open backup archive.');
        }

        return $this->job;
    }

    /**
     * Processes a single batch of the restore job.
     */
    public function process_batch($job) {
        $this->job = $job;

        // Handle completed restores
        if (isset($this->job['step']) && $this->job['step'] === 'completed') {
            MegaBackup_Core::log("RESTORE: Restore already completed, no further processing needed.");
            return [
                'job' => $this->job,
                'progress' => 100,
                'message' => $this->job['status']
            ];
        }

        switch ($this->job['step']) {
            case 'extracting':
                $this->process_extraction_batch();
                break;
            case 'database':
                $this->process_database_batch();
                break;
            case 'files':
                $this->process_files_batch();
                break;
            case 'finalize':
                $this->finalize_restore();
                break;
        }

        $progress = 0;
        switch($this->job['step']) {
            case 'extracting':
                $progress = ($this->job['total_files'] > 0 ? ($this->job['files_processed'] / $this->job['total_files']) : 1) * 50;
                break;
            case 'database':
                $progress = 50 + ($this->job['progress_step'] * 30);
                break;
            case 'files':
                $progress = 80 + (($this->job['total_files'] > 0 ? $this->job['files_processed'] / $this->job['total_files'] : 1) * 15);
                break;
            case 'finalize':
                $progress = 99;
                break;
            case 'completed':
                $progress = 100;
                break;
        }
        
        // Only calculate progress if not manually set (e.g., for completed restores)
        if (!isset($this->job['progress']) || $this->job['step'] !== 'completed') {
            $this->job['progress'] = floor($progress);
        }

        MegaBackup_Core::log("RESTORE: Progress calculation - Step: {$this->job['step']}, Progress: {$this->job['progress']}%");

        return [
            'job' => $this->job,
            'progress' => $this->job['progress'],
            'message' => $this->job['status']
        ];
    }

    private function process_extraction_batch() {
        if ($this->job['files_processed'] >= $this->job['total_files']) {
            $this->job['step'] = 'database';
            $this->job['status'] = 'Extraction complete. Preparing to restore database...';
            // Do not reset counters, total_files is needed for progress calculation
            return;
        }

        $batch_size = 100; // More conservative batch size
        $zip = new ZipArchive();
        if ($zip->open($this->job['backup_path']) !== TRUE) {
            throw new Exception("Could not open backup archive to extract.");
        }

        $files_to_extract = array_slice($this->job['files_queue'], $this->job['files_processed'], $batch_size);

        if (!empty($files_to_extract)) {
            $zip->extractTo($this->job['temp_dir'], $files_to_extract);
        }

        $this->job['files_processed'] += count($files_to_extract);
        $this->job['status'] = 'Extracting backup files... (' . $this->job['files_processed'] . ' / ' . $this->job['total_files'] . ')';

        $zip->close();
    }

    private function process_database_batch() {
        $sql_file = $this->job['temp_dir'] . '/database.sql';
        if (!file_exists($sql_file)) {
            $this->job['step'] = 'files'; // No database to restore
            $this->job['status'] = 'No database file found. Moving to file restore...';
            $this->prepare_file_restore_step();
            return;
        }

        global $wpdb;

        // This is a simplified, single-batch DB restore.
        // A fully robust version would process the SQL file in chunks.
        $sql_content = file_get_contents($sql_file);
        $queries = preg_split('/;\s*(\r\n|\n|\r)/', $sql_content);

        foreach ($queries as $query) {
            $query = trim($query);
            if ($query) {
                $wpdb->query($query);
            }
        }

        $this->job['progress_step'] = 1; // Mark DB as done
        $this->job['step'] = 'files';
        $this->job['status'] = 'Database restore complete. Restoring files...';

        $this->prepare_file_restore_step();
    }

    private function prepare_file_restore_step() {
        MegaBackup_Core::log("RESTORE: Preparing file restore step...");
        
        // Re-scan temp directory for files to restore, making paths relative to temp dir
        $this->job['files_queue'] = [];
        $temp_dir_real = realpath($this->job['temp_dir']);
        
        if (!$temp_dir_real) {
            MegaBackup_Core::log("RESTORE: Warning - Could not get real path for temp directory: " . $this->job['temp_dir'], 'warning');
            $this->job['total_files'] = 0;
            $this->job['files_processed'] = 0;
            return;
        }
        
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($temp_dir_real, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if ($file->isDir()) continue;
                $real_path = $file->getRealPath();
                if (!$real_path) continue;

                $relative_path = ltrim(str_replace($temp_dir_real, '', $real_path), '/\\');
                $this->job['files_queue'][] = $relative_path;
            }
        } catch(Exception $e) {
             MegaBackup_Core::log('RESTORE: Could not scan temp directory for restore: ' . $e->getMessage(), 'warning');
        }
        $this->job['total_files'] = count($this->job['files_queue']);
        $this->job['files_processed'] = 0;
        
        MegaBackup_Core::log("RESTORE: File restore step prepared. Total files to restore: {$this->job['total_files']}");
        
        // If there are no files to restore, skip directly to finalize
        if ($this->job['total_files'] == 0) {
            MegaBackup_Core::log("RESTORE: No files to restore, skipping to finalize step");
            $this->job['step'] = 'finalize';
            $this->job['status'] = 'No files to restore. Finalizing...';
        }
    }

    private function process_files_batch() {
        MegaBackup_Core::log("RESTORE: Files batch - Processed: {$this->job['files_processed']}, Total: {$this->job['total_files']}");
        
        if ($this->job['files_processed'] >= $this->job['total_files']) {
            $this->job['step'] = 'finalize';
            $this->job['status'] = 'File restore complete. Finalizing...';
            MegaBackup_Core::log("RESTORE: Files step completed. Moving to finalize step.");
            return;
        }

        $batch_size = 75; // More conservative batch size
        $abs_path_real = realpath(ABSPATH);

        $files_in_batch = array_slice($this->job['files_queue'], $this->job['files_processed'], $batch_size);
        MegaBackup_Core::log("RESTORE: Processing " . count($files_in_batch) . " files in this batch");

        $processed_count = 0;
        foreach($files_in_batch as $relative_path) {
            $source = $this->job['temp_dir'] . '/' . $relative_path;
            $destination = $abs_path_real . '/' . $relative_path;

            if(basename($source) === 'database.sql' || basename($source) === 'megabackup.json') {
                MegaBackup_Core::log("RESTORE: Skipping " . basename($source) . " (special file)");
                $processed_count++;
                continue;
            }
            if(!file_exists($source)) {
                MegaBackup_Core::log("RESTORE: Source file not found: " . $source, 'warning');
                $processed_count++;
                continue;
            }

            $dest_dir = dirname($destination);
            if(!is_dir($dest_dir)) {
                wp_mkdir_p($dest_dir);
            }
            if (@copy($source, $destination)) {
                $processed_count++;
            } else {
                MegaBackup_Core::log("RESTORE: Failed to copy file: " . $relative_path, 'warning');
                $processed_count++; // Count it anyway to avoid infinite loop
            }
        }

        $this->job['files_processed'] += $processed_count;
        $this->job['status'] = 'Restoring files... (' . $this->job['files_processed'] . ' / ' . $this->job['total_files'] . ')';
        MegaBackup_Core::log("RESTORE: Files batch completed. New progress: {$this->job['files_processed']}/{$this->job['total_files']}");
    }

    private function finalize_restore() {
        MegaBackup_Core::log("RESTORE: Starting finalization process...");
        
        $this->cleanup_directory($this->job['temp_dir']);
        flush_rewrite_rules(true);
        
        // Mark the restore as complete so the UI updates successfully
        $this->job['status'] = 'Restore complete!';
        $this->job['progress'] = 100;
        $this->job['step'] = 'completed'; // Important: change step to prevent endless loop
        
        MegaBackup_Core::log("RESTORE: Restore finalized successfully.");
    }

    private function cleanup_directory($dir) {
        if (!is_dir($dir)) return;
        try {
            $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
            $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
            foreach($files as $file) {
                if ($file->isDir()){
                    @rmdir($file->getRealPath());
                } else {
                    @unlink($file->getRealPath());
                }
            }
            @rmdir($dir);
        } catch(Exception $e) {
            MegaBackup_Core::log('Could not clean up directory ' . $dir . ': ' . $e->getMessage(), 'warning');
        }
    }
}