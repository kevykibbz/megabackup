# MegaFile - WordPress Backup and Restore Plugin

![Version](https://img.shields.io/badge/version-2.0.0-blue.svg)
![WordPress](https://img.shields.io/badge/wordpress-5.0+-green.svg)
![License](https://img.shields.io/badge/license-GPLv2-blue.svg)
![PHP](https://img.shields.io/badge/php-7.4+-purple.svg)

A comprehensive WordPress backup and restore plugin that creates complete backups of your website including files and database. The plugin uses a custom `.megafile` format for secure and efficient backup storage with enterprise-level support for files up to **50GB**.

## 🚀 Key Features

### Backup Features
- **Complete Site Backup**: Full website backup including files and database
- **Custom .megafile Format**: Secure, compressed backup format
- **50GB File Support**: Enterprise-level support for large websites
- **Chunked Upload**: Automatic chunked upload for reliable large file transfers
- **Selective Backup**: Choose what to include (database, uploads, themes, plugins)
- **Smart Exclusions**: Automatically exclude temporary files and existing backups
- **Real-time Progress**: Live progress bars and detailed logging
- **Background Processing**: Non-blocking operations with adaptive time management

### Restore Features
- **Flexible Restore Options**: Restore from existing backups or upload new ones
- **Large File Upload**: Support for uploading backup files up to 50GB
- **Validation System**: Backup integrity checking before restore
- **Step-by-step Process**: Detailed restore process with progress tracking
- **Safe Restoration**: Comprehensive validation and error handling

### Advanced Features
- **Adaptive Processing**: Automatically adjusts to server limitations
- **Hosting Compatibility**: Optimized for shared hosting environments
- **Detailed Logging**: Comprehensive logging with download functionality
- **Modern Interface**: Intuitive admin interface with real-time updates
- **Security**: Built-in permission checks and secure file handling

## 🔧 Recent Improvements & Fixes

### Altervista Hosting Compatibility
- **Fixed disk space detection issues** on Altervista and similar shared hosting
- **Enhanced error handling** for hosting environments with limited PHP functions
- **Added manual override** option to disable disk space checking
- **Improved logging** for better debugging on shared hosting

### User Experience Enhancements
- **Added download logs button** to restore tab (matching backup tab functionality)
- **Improved tab navigation** with proper URL parameters
- **Enhanced progress reporting** with more detailed status updates
- **Better error messages** with actionable solutions

### Technical Improvements
- **Robust disk space handling** for unlimited hosting environments
- **Enhanced debug logging** with detailed server information
- **Improved chunked upload reliability** for large files
- **Better memory and time limit management**

## 📋 Installation

### Method 1: WordPress Admin
1. Go to your WordPress admin panel
2. Navigate to **Plugins > Add New**
3. Upload the plugin ZIP file
4. Activate the plugin
5. Access via **MegaFile** menu in WordPress admin

### Method 2: Manual Installation
1. Upload plugin files to `/wp-content/plugins/MegaBackupRestore/`
2. Activate through the **Plugins** screen in WordPress
3. Configure via **MegaFile** menu

## 🎯 Usage

### Creating a Backup
1. Go to **MegaFile > Backup** tab
2. Select components to backup:
   - ✅ Database
   - ✅ Uploads (media files)
   - ✅ Themes
   - ✅ Plugins
3. Click **Start Backup**
4. Monitor real-time progress
5. Download completed `.megafile`

### Restoring from Backup
1. Go to **MegaFile > Restore** tab
2. Choose restoration method:
   - **Existing Backup**: Select from available backups
   - **Upload New**: Upload external `.megafile` (up to 50GB)
3. Click **Start Restore**
4. Monitor progress and completion

### Configuration Options
1. Navigate to **MegaFile > Settings**
2. Configure:
   - **File exclusions** (patterns and directories)
   - **Compression levels**
   - **Chunked upload settings**
   - **Disk space checking** (disable for unlimited hosting)
   - **Advanced options**

## 🛠️ System Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 7.4 or higher
- **Memory**: Minimum 128MB (512MB+ recommended)
- **Storage**: Available space for backups
- **Permissions**: Write access to plugin directory

## 🌐 Hosting Compatibility

### Optimized For
- ✅ **Shared Hosting** (cPanel, Plesk)
- ✅ **Altervista** (with special disk space handling)
- ✅ **Unlimited Hosting** providers
- ✅ **VPS/Dedicated** servers
- ✅ **Managed WordPress** hosting

### Hosting-Specific Features
- **Altervista**: Automatic disk space check bypass
- **Shared Hosting**: Adaptive processing and chunked uploads
- **Limited PHP**: Graceful degradation and error handling

## 📁 File Structure

```
MegaBackupRestore/
├── includes/
│   ├── class-megabackup-admin.php      # Admin interface
│   ├── class-megabackup-ajax.php       # AJAX handlers
│   ├── class-megabackup-backup.php     # Backup operations
│   ├── class-megabackup-core.php       # Core functionality
│   ├── class-megabackup-restore.php    # Restore operations
│   └── class-megabackup-scheduler.php  # Scheduling
├── assets/
│   ├── css/admin.css                   # Admin styles
│   └── js/admin.js                     # Admin JavaScript
├── backups/                            # Backup storage
├── logs/                               # Operation logs
├── tmp/                                # Temporary files
├── megabackup.php                      # Main plugin file
└── README.md                           # This file
```

## 🐛 Troubleshooting

### Common Issues & Solutions

#### Backup Fails on Altervista
**Problem**: \"Critically low disk space\" error
**Solution**: 
1. Go to **Settings > Advanced Settings**
2. Enable **\"Skip disk space checking\"**
3. Save settings and retry backup

#### Large File Upload Issues
**Problem**: Upload fails for large backup files
**Solution**:
- Enable **chunked upload** in settings
- Verify server upload limits
- Use smaller chunk sizes if needed

#### Memory or Time Limit Errors
**Problem**: Backup/restore stops due to server limits
**Solution**:
- Plugin automatically adapts to server limits
- Check **System Information** for current limits
- Contact hosting provider for limit increases

### Log Analysis
- Download logs using **\"Download Log\"** button in any tab
- Check `logs/megabackup.log` for detailed operation history
- Look for `[error]` entries for specific issues

## 🔒 Security Features

- **Permission Checks**: Proper WordPress capability verification
- **Secure File Handling**: Safe file operations and validation
- **Data Sanitization**: All inputs properly sanitized
- **Nonce Verification**: CSRF protection for all actions
- **Path Validation**: Prevents directory traversal attacks

## 📊 Performance Optimization

- **Chunked Processing**: Large operations split into manageable chunks
- **Memory Management**: Efficient memory usage patterns
- **Time Limits**: Adaptive processing based on server capabilities
- **Background Operations**: Non-blocking user interface
- **Compression**: Efficient backup file compression

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

### Development Setup
1. Clone the repository
2. Set up local WordPress environment
3. Install plugin in development mode
4. Make changes and test thoroughly

## 📞 Support

For support and questions:
- **Issues**: Create a GitHub issue
- **Documentation**: Check this README
- **Logs**: Use the download logs feature for debugging

## 📝 Changelog

### Version 2.0.0 (Current)
- **New**: 50GB file support with chunked upload
- **New**: Altervista hosting compatibility fixes
- **New**: Download logs button in restore tab
- **Improved**: Disk space detection for unlimited hosting
- **Improved**: Error handling and user feedback
- **Improved**: Admin interface consistency
- **Fixed**: Chunked upload validation issues
- **Fixed**: Disk space false positives on shared hosting

### Version 1.0.0
- Initial release with core backup/restore functionality
- Custom .megafile format
- Real-time progress tracking
- User-friendly admin interface

## 📄 License

This project is licensed under the GPLv2 or later - see the [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html) for details.

## 🙏 Acknowledgments

- WordPress community for best practices and standards
- Hosting providers for compatibility testing
- Users who reported issues and provided feedback

---

**Made with ❤️ for WordPress**
