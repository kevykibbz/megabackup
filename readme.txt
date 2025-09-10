=== MegaFile - Backup and restore ===
Contributors: your-username
Tags: backup, restore, database, files, security
Requires at least: 5.0
Tested up to: 6.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete WordPress backup and restore solution with custom .megafile format supporting files up to 50GB.

== Description ==

MegaFile is a comprehensive WordPress backup and restore plugin that creates complete backups of your website including files and database. The plugin uses a custom .megafile format for secure and efficient backup storage with enterprise-level support for files up to 50GB.

### Key Features

* **Complete Site Backup**: Backup files and database in one operation
* **Custom .megafile Format**: Secure, compressed backup format
* **50GB File Support**: Enterprise-level support for large websites
* **Chunked Upload**: Automatic chunked upload for large files
* **Real-time Progress**: Live progress bars and detailed logging
* **Selective Backup**: Choose what to include (database, uploads, themes, plugins)
* **Easy Restore**: Restore from existing backups or upload new ones
* **Smart Exclusions**: Automatically exclude temporary files and backups
* **User-friendly Interface**: Modern, intuitive admin interface
* **Background Processing**: Long-running operations don't block the interface

### Backup Features

* Database backup with complete table structure and data
* File system backup with selective inclusion
* Automatic naming with timestamp and unique ID
* Compression for space efficiency
* Detailed logging of all operations

### Restore Features

* Restore from existing backups in the plugin directory
* Upload and restore external backup files up to 50GB
* Validation of backup integrity before restore
* Step-by-step restore process with progress tracking

### Settings & Configuration

* Exclude specific folders and files from backup
* Configure compression levels
* Set maximum execution time
* Advanced options for power users

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/megafile` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the MegaFile menu item in your WordPress admin to configure and use the plugin

== Frequently Asked Questions ==

= What is a .megafile? =

A .megafile is a custom backup format created by MegaFile. It's essentially a ZIP archive containing your website files, database, and metadata about the backup.

= How long does a backup take? =

Backup time depends on your site size. Small sites may take a few minutes, while large sites with many files could take longer. The plugin provides real-time progress updates.

= Can I upload large backup files? =

Yes! MegaFile supports files up to 50GB with automatic chunked upload for reliable transfer of large files.

= Is it safe to restore a backup? =

Yes, but always test on a staging site first. The restore process will overwrite your current site data.

== Screenshots ==

1. Main backup interface with options and progress bar
2. Restore interface showing existing backups
3. Settings page with exclusion options
4. Real-time backup progress and logging

== Changelog ==

= 2.0.0 =
* Rebranded to MegaFile - Backup and restore
* Added 50GB file support with chunked upload
* Improved upload reliability
* Enhanced user interface
* Fixed chunked upload validation issues

= 1.0.0 =
* Initial release
* Complete backup and restore functionality
* Custom .megafile format
* Real-time progress tracking
* User-friendly admin interface

== Upgrade Notice ==

= 2.0.0 =
Major update with 50GB file support and improved reliability.