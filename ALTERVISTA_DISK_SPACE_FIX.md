# Fix for Altervista Disk Space Issues

## Problem
The MegaBackup plugin was failing on Altervista hosting with the error:
```
Critically low disk space: . Cannot continue backup safely.
```

Even though Altervista offers "unlimited space", the PHP `disk_free_space()` function returns invalid values (empty string, null, or false) on their servers, causing the plugin to incorrectly detect low disk space.

## Root Cause Analysis
From the logs, we can see that on Altervista:
- Local development: `disk_free_space()` returns proper values like `224511307776` (209 GB)  
- Altervista hosting: `disk_free_space()` returns empty string `""` or `null`

This causes the backup to fail when it reaches the zip_files step and checks disk space.

## Solution Implemented

### 1. Enhanced Detection of Invalid Disk Space Values
The plugin now handles all these invalid return values from `disk_free_space()`:
- `false` (function failed)
- `null` (no data returned)
- `0` (zero bytes reported)
- `""` (empty string - Altervista case)
- Non-numeric values

### 2. Improved Logging and Debugging
- Better debug logging using `var_export()` to show exact return values
- Clear messages explaining what's happening when disk space can't be detected

### 3. New Setting: "Disable Disk Space Check"
- Added a new checkbox setting in the plugin's Advanced Settings
- **Location**: WordPress Admin > MegaBackup > Settings > Advanced Settings
- **Setting**: "Skip disk space checking (for unlimited hosting)"
- **Description**: "Enable this if you have unlimited hosting and disk space checks are causing backup failures"

## How to Use

### Option 1: Automatic (Recommended)
The plugin will now automatically detect when `disk_free_space()` returns invalid values and handle it gracefully. No action required.

### Option 2: Manual Override (If needed)
1. Go to WordPress Admin > MegaBackup > Settings
2. Scroll down to "Advanced Settings"
3. Check the box "Skip disk space checking (for unlimited hosting)"
4. Save settings
5. Try your backup again

## Specifically for Altervista Users
This fix addresses the exact issue where Altervista's `disk_free_space()` function returns an empty string instead of the actual available space, which was being interpreted as "0 bytes available" and causing backup failures.

## What Changed in the Code
1. **class-megabackup-backup.php**: Enhanced disk space detection logic to handle all invalid return values
2. **class-megabackup-admin.php**: Added new setting option for manual override
3. **class-megabackup-core.php**: Improved debug logging to better identify hosting-specific issues

The plugin will now work properly on Altervista and similar hosting environments where disk space functions don't work as expected.
