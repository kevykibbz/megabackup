# Testing MegaBackup on Altervista

## Pre-Testing Setup

### 1. Upload Modified Files
Upload these modified files to your Altervista WordPress installation:
- `includes/class-megabackup-admin.php`
- `assets/js/admin.js` 
- `includes/class-megabackup-backup.php`
- `includes/class-megabackup-ajax.php`

### 2. Enable WordPress Debug Logging
Add to your `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

## Testing Phase 1: Space Detection

### Test 1: Check System Info
1. Go to WordPress Admin → MegaBackup → System Info tab
2. Look for "Available Backup Space" - it should show "Unlimited" instead of a confusing large number
3. Check that there are no warnings about insufficient space

**Expected Result**: Space should show as "Unlimited" with green status

### Test 2: JavaScript Space Check
1. Open browser developer tools (F12)
2. Go to MegaBackup backup page
3. Click "Start Backup" button
4. Check console for any JavaScript errors related to space checking

**Expected Result**: No space-related blocking should occur

## Testing Phase 2: Small Backup Test

### Test 3: Database-Only Backup
1. Uncheck: Uploads, Themes, Plugins
2. Check only: Database
3. Click "Start Backup"
4. Monitor progress and logs

**Expected Result**: Should complete successfully without space errors

### Test 4: Small File Backup
1. Check: Database + Themes (usually smaller than uploads)
2. Start backup
3. Watch for progress updates

**Expected Result**: Should process files in batches without issues

## Testing Phase 3: Full Backup Test

### Test 5: Complete Site Backup
1. Check all options: Database, Uploads, Themes, Plugins
2. Start backup
3. Monitor closely for any errors

**Expected Result**: Should complete even for GB-sized sites

## Testing Phase 4: Scheduled Backup

### Test 6: Schedule Setup
1. Go to Schedule tab
2. Enable scheduled backups
3. Set to run in 5 minutes for testing
4. Save settings

**Expected Result**: Should save without space warnings

### Test 7: Scheduled Execution
1. Wait for scheduled time or trigger manually if possible
2. Check backup logs
3. Verify backup file was created

## Monitoring and Debugging

### Check WordPress Error Logs
Location: `/wp-content/debug.log`
Look for: Any MegaBackup related errors

### Check MegaBackup Logs
1. Go to MegaBackup admin page
2. Check the logs section during backup
3. Look for any warning messages about space or batch size adjustments

### Monitor Server Response
1. Use browser dev tools Network tab
2. Watch AJAX requests during backup
3. Check for any 500 errors or timeouts

### File System Check
1. Check if backup files are actually created in the backups directory
2. Verify file sizes are reasonable
3. Test downloading a backup file

## Altervista-Specific Considerations

### 1. Resource Limits
- Altervista may have CPU time limits
- Monitor for any execution timeout errors
- The adaptive batch sizing should help with this

### 2. File Permissions
- Ensure backup directory is writable
- Check that temporary files can be created

### 3. Space Quotas
- Even with "unlimited" space, there may be soft limits
- Monitor for any hosting-level space warnings

## Success Criteria

✅ **Space Detection**: Shows "Unlimited" instead of blocking backups
✅ **Small Backups**: Database and theme backups complete successfully  
✅ **Large Backups**: Full site backups complete without space errors
✅ **Scheduled Backups**: Automatic backups work without intervention
✅ **Error Recovery**: If errors occur, system provides helpful messages
✅ **Adaptive Behavior**: System adjusts batch sizes based on conditions

## If Issues Persist

### Additional Debugging Steps:
1. Check PHP error logs in Altervista control panel
2. Test with even smaller batch sizes (modify the code to use batch_size = 10)
3. Monitor actual disk usage during backup process
4. Contact Altervista support to confirm any hidden quotas

### Fallback Options:
- Exclude large directories temporarily
- Use database-only backups if file backups fail
- Split backups into multiple smaller jobs
