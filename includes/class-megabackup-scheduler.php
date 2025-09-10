<?php
/**
 * MegaBackup Scheduler Class - Manages scheduled backups
 */

if (!defined('ABSPATH')) {
    exit;
}

class MegaBackup_Scheduler {

    public function __construct() {
        // Hook for executing the scheduled backup
        add_action('megabackup_scheduled_backup', array($this, 'execute_scheduled_backup'));

        // Hook to update the schedule when settings change
        add_action('update_option_megabackup_schedule_settings', array($this, 'update_schedule'), 10, 2);

        // Hook to check and reschedule if necessary
        add_action('wp_loaded', array($this, 'check_and_reschedule'));

        // Hook to clean up expired events
        add_action('wp_loaded', array($this, 'cleanup_expired_events'));
    }

    /**
     * Executes the scheduled backup using the batch processing system.
     */
    public function execute_scheduled_backup() {
        MegaBackup_Core::log('SCHEDULER: Starting automatic scheduled backup');

        $schedule_settings = get_option('megabackup_schedule_settings', array());
        if (empty($schedule_settings['enabled'])) {
            MegaBackup_Core::log('SCHEDULER: Scheduled backup disabled, aborting execution', 'warning');
            return;
        }

        $this->setup_scheduled_environment();

        try {
            if (!class_exists('MegaBackup_Backup')) {
                MegaBackup_Core::log('SCHEDULER: MegaBackup_Backup class not found', 'error');
                return;
            }

            $backup = new MegaBackup_Backup();
            
            // Generate filename with "auto_" prefix for scheduled backups
            $backup_filename = 'auto_' . MegaBackup_Core::generate_backup_filename();

            $backup_options = array(
                'include_database' => true,
                'include_uploads' => true,
                'include_themes' => true,
                'include_plugins' => true,
                'scheduled' => true,
                'filename' => $backup_filename // Pass the special filename to the job
            );

            MegaBackup_Core::log('SCHEDULER: Creating backup job with options: ' . json_encode($backup_options));
            
            // 1. Create the job (the "to-do list")
            $job = $backup->create_job($backup_options);
            MegaBackup_Core::log("SCHEDULER: Job {$job['job_id']} created for file {$job['backup_filename']}.");

            // 2. Loop through batches until the job is complete
            $max_loops = 1000; // Safety break to prevent infinite loops
            $loop_count = 0;
            while ($job['progress'] < 100 && $loop_count < $max_loops) {
                $job_result = $backup->process_batch($job);
                $job = $job_result['job']; // Update job with the latest state
                MegaBackup_Core::log("SCHEDULER: Batch processed. Progress: {$job['progress']}%. Status: {$job['message']}");
                $loop_count++;
            }

            if ($job['progress'] >= 100) {
                MegaBackup_Core::log('SCHEDULER: Scheduled backup completed successfully: ' . $job['backup_filename']);
                update_option('megabackup_last_scheduled_run', current_time('mysql'));
                $this->send_success_notification($job['backup_filename']);
            } else {
                throw new Exception("Backup process timed out or stalled after {$loop_count} loops.");
            }

        } catch (Exception $e) {
            $error_message = $e->getMessage();
            MegaBackup_Core::log('SCHEDULER: Exception during scheduled backup: ' . $error_message, 'error');
            $this->send_error_notification($error_message);
        } finally {
            // Always schedule the next run, even if the current one failed.
            $this->schedule_next_backup();
            MegaBackup_Core::log('SCHEDULER: Scheduled backup execution finished.');
        }
    }

    /**
     * Configure the environment for the scheduled backup
     */
    private function setup_scheduled_environment() {
        // Increase limits for automatic backup
        @ini_set('max_execution_time', 0);
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);
        @ignore_user_abort(true);

        MegaBackup_Core::log('SCHEDULER: Environment configured for scheduled backup');
    }

    /**
     * Schedule the next backup
     */
    private function schedule_next_backup() {
        $schedule_settings = get_option('megabackup_schedule_settings', array());

        if (empty($schedule_settings['enabled'])) {
            return;
        }

        $frequency = $schedule_settings['frequency'];
        $time = $schedule_settings['time'];

        $next_run = $this->calculate_next_run($frequency, $time);

        // Remove existing events
        $this->clear_scheduled_events();

        // Schedule new event
        wp_schedule_single_event($next_run, 'megabackup_scheduled_backup');

        // Update settings with the new time
        $schedule_settings['next_run'] = $next_run;
        update_option('megabackup_schedule_settings', $schedule_settings);

        MegaBackup_Core::log('SCHEDULER: Next backup scheduled for: ' . date('Y-m-d H:i:s', $next_run));
    }

    /**
     * Calculate the next execution time
     */
    private function calculate_next_run($frequency, $time) {
        $time_parts = explode(':', $time);
        $hour = intval($time_parts[0]);
        $minute = intval($time_parts[1]);

        $now = current_time('timestamp');

        switch ($frequency) {
            case 'daily':
                $next_run = strtotime('tomorrow ' . $time, $now);
                break;

            case 'weekly':
                $next_run = strtotime('next week ' . $time, $now);
                break;

            case 'monthly':
                $next_run = strtotime('first day of next month ' . $time, $now);
                break;

            default:
                $next_run = strtotime('tomorrow ' . $time, $now);
        }

        return $next_run;
    }

    /**
     * Update the schedule when settings change
     */
    public function update_schedule($old_value, $new_value) {
        MegaBackup_Core::log('SCHEDULER: Updating backup schedule');

        // Remove existing events
        $this->clear_scheduled_events();

        // If enabled, schedule a new event
        if (!empty($new_value['enabled'])) {
            $next_run = $this->calculate_next_run($new_value['frequency'], $new_value['time']);
            wp_schedule_single_event($next_run, 'megabackup_scheduled_backup');

            MegaBackup_Core::log('SCHEDULER: New backup scheduled for: ' . date('Y-m-d H:i:s', $next_run));
        } else {
            MegaBackup_Core::log('SCHEDULER: Backup scheduling disabled');
        }
    }

    /**
     * Check and reschedule if necessary
     */
    public function check_and_reschedule() {
        // Run only in the admin and not too often
        if (!is_admin() || wp_doing_ajax()) {
            return;
        }

        // Check only once per hour
        $last_check = get_transient('megabackup_schedule_check');
        if ($last_check) {
            return;
        }
        set_transient('megabackup_schedule_check', time(), HOUR_IN_SECONDS);

        $schedule_settings = get_option('megabackup_schedule_settings', array());

        if (empty($schedule_settings['enabled'])) {
            return;
        }

        // Check if there is a scheduled event
        $next_scheduled = wp_next_scheduled('megabackup_scheduled_backup');

        if (!$next_scheduled) {
            // There is no scheduled event, create one
            MegaBackup_Core::log('SCHEDULER: No scheduled event found, rescheduling in progress');
            $this->schedule_next_backup();
        }
    }

    /**
     * Clean up expired or duplicate events
     */
    public function cleanup_expired_events() {
        // Run only in the admin
        if (!is_admin()) {
            return;
        }

        // Clean up only once a day
        $last_cleanup = get_transient('megabackup_schedule_cleanup');
        if ($last_cleanup) {
            return;
        }
        set_transient('megabackup_schedule_cleanup', time(), DAY_IN_SECONDS);

        // Find all megabackup_scheduled_backup events
        $cron_array = _get_cron_array();
        $cleaned = 0;

        foreach ($cron_array as $timestamp => $cron) {
            if (isset($cron['megabackup_scheduled_backup'])) {
                // If the event is in the past, remove it
                if ($timestamp < time()) {
                    wp_unschedule_event($timestamp, 'megabackup_scheduled_backup');
                    $cleaned++;
                }
            }
        }

        if ($cleaned > 0) {
            MegaBackup_Core::log('SCHEDULER: Removed ' . $cleaned . ' expired events');
        }
    }

    /**
     * Remove all scheduled events
     */
    private function clear_scheduled_events() {
        $cron_array = _get_cron_array();

        foreach ($cron_array as $timestamp => $cron) {
            if (isset($cron['megabackup_scheduled_backup'])) {
                wp_unschedule_event($timestamp, 'megabackup_scheduled_backup');
            }
        }
    }

    /**
     * Send success notification (optional)
     */
    private function send_success_notification($filename) {
        // Implement email notification if necessary
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');

        $subject = sprintf(__('[%s] Automatic backup completed', 'megabackup'), $site_name);
        $message = sprintf(
            __("The automatic backup of the site %s was completed successfully.\n\nFile created: %s\nDate: %s\n\nYou can download the backup from the site's administrative area.", 'megabackup'),
            $site_name,
            $filename,
            current_time('d/m/Y H:i:s')
        );

        // Send email only if enabled in settings
        $settings = get_option('megabackup_settings', array());
        if (!empty($settings['email_notifications'])) {
            wp_mail($admin_email, $subject, $message);
        }

        MegaBackup_Core::log('SCHEDULER: Success notification prepared for: ' . $admin_email);
    }

    /**
     * Send error notification
     */
    private function send_error_notification($error_message) {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');

        $subject = sprintf(__('[%s] Automatic backup error', 'megabackup'), $site_name);
        $message = sprintf(
            __("The automatic backup of the site %s failed.\n\nError: %s\nDate: %s\n\nCheck the logs in the administrative area for more details.", 'megabackup'),
            $site_name,
            $error_message,
            current_time('d/m/Y H:i:s')
        );

        // Always send error notifications
        wp_mail($admin_email, $subject, $message);

        MegaBackup_Core::log('SCHEDULER: Error notification sent to: ' . $admin_email);
    }

    /**
     * Get scheduling information
     */
    public static function get_schedule_info() {
        $schedule_settings = get_option('megabackup_schedule_settings', array());
        $next_scheduled = wp_next_scheduled('megabackup_scheduled_backup');
        $last_run = get_option('megabackup_last_scheduled_run', '');

        return array(
            'enabled' => !empty($schedule_settings['enabled']),
            'frequency' => isset($schedule_settings['frequency']) ? $schedule_settings['frequency'] : 'daily',
            'time' => isset($schedule_settings['time']) ? $schedule_settings['time'] : '02:00',
            'next_run' => $next_scheduled,
            'last_run' => $last_run,
            'next_run_formatted' => $next_scheduled ? date('d/m/Y H:i', $next_scheduled) : '',
            'last_run_formatted' => $last_run ? date('d/m/Y H:i', strtotime($last_run)) : ''
        );
    }
}
