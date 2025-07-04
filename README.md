# WorldTeam Search & Replace

![WordPress](https://img.shields.io/badge/WordPress-6.7%2B-blue)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple)
![License](https://img.shields.io/badge/License-GPL%20v2%2B-green)
![Version](https://img.shields.io/badge/Version-1.0.0-orange)

A powerful WordPress content search and replace tool with support for Posts, Pages, ACF fields, and PHP template files with batch search and replace functionality.

## ✨ Features

### 🔍 **Comprehensive Search Support**
- **Database Content**: Posts, Pages, Custom Post Types
- **ACF Fields**: Advanced Custom Fields with full support
- **Custom Fields**: WordPress meta fields
- **Comments**: User comments and metadata
- **Theme Files**: PHP files in active and child themes (PHP files only)

### 🛡️ **Safety & Security**
- **Permission Control**: Admin-only access with capability checks
- **Operation Confirmation**: Detailed preview before execution
- **Nonce Verification**: CSRF protection for all operations
- **Operation Logging**: Comprehensive audit trail

### ⚡ **Advanced Search Options**
- **Case Sensitive Search**: Toggle case sensitivity
- **Regular Expression**: Full regex pattern support
- **Whole Word Matching**: Exact word boundary matching
- **Real-time Preview**: See changes before applying

### 🎯 **Performance Optimized**
- **Configurable Limits**: File search limits and database pagination
- **Large File Handling**: Skip files over 2MB for better performance
- **Memory Management**: Efficient processing for large datasets
- **Progress Tracking**: Real-time operation progress

### 💡 **User-Friendly Interface**
- **Intuitive Design**: Clean, WordPress-standard admin interface
- **Batch Operations**: Select and process multiple items at once
- **Detailed Results**: Comprehensive search results with context
- **Responsive Design**: Works on all device sizes

## 📋 Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 7.4 or higher
- **MySQL**: 5.6 or higher
- **Memory**: 128MB recommended (256MB for large sites)
- **Permissions**: File write access for backups

## 🚀 Installation

### Method 1: Download and Upload
1. Download the latest release from [GitHub Releases](../../releases)
2. Upload the plugin folder to `/wp-content/plugins/`
3. Activate the plugin through the WordPress admin panel

### Method 2: Git Clone
```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/gaofeng-arcadedevhouse/wp-search-replace-plus.git
```

### Method 3: WordPress Admin Upload
1. Download the ZIP file from GitHub
2. Go to Plugins → Add New → Upload Plugin
3. Choose the ZIP file and click Install Now
4. Activate the plugin

## 📖 Quick Start

1. **Access the Tool**: Navigate to `Tools → Search & Replace` in WordPress admin
2. **Set Search Text**: Enter the text you want to find
3. **Choose Scope**: Select where to search (database, files, or both)
4. **Configure Options**: Set case sensitivity, regex mode, etc.
5. **Execute Search**: Click "Start Search" to find matches
6. **Review Results**: Examine found items and select what to replace
7. **Replace**: Enter replacement text and confirm the operation

## 📚 Usage Examples

### Example 1: Domain Change
```
Search Text: https://old-domain.com
Replace Text: https://new-domain.com
Scope: ✓ Posts Content, ✓ Pages Content, ✓ ACF Fields
Options: Case Sensitive ✗, Whole Words ✓
```

### Example 2: Contact Information Update
```
Search Text: old-email@company.com
Replace Text: new-email@company.com
Scope: ✓ Posts Content, ✓ Pages Content, ✓ Comments Content
Options: Case Sensitive ✗, Regex Mode ✗
```

### Example 3: Function Name Refactoring
```
Search Text: old_function_name
Replace Text: new_function_name
Scope: ✓ Theme Files
Options: Case Sensitive ✓, Whole Words ✓
```

### Example 4: Regular Expression Replace
```
Search Text: \b\d{3}-\d{3}-\d{4}\b
Replace Text: XXX-XXX-XXXX
Scope: ✓ Posts Content, ✓ Pages Content
Options: Regex Mode ✓
```

## 🏗️ Architecture

### File Structure
```
worldteam-search-replace/
├── worldteam-search-replace.php     # Main plugin file
├── includes/                        # Core PHP classes
│   ├── admin-page.php              # Admin interface
│   ├── class-search-handler.php    # Search coordinator
│   ├── class-database-handler.php  # Database operations
│   ├── class-file-scanner.php      # File system scanning
│   └── class-replace-handler.php   # Replace operations
├── assets/                         # Frontend assets
│   ├── css/
│   │   └── search.css             # Search interface styles
│   └── js/
│       └── search.js              # Search interface JavaScript
├── README.md                       # Documentation
└── LICENSE                        # GPL v2+ License
```

### Core Components

| Component | Description |
|-----------|-------------|
| **Search Handler** | Coordinates search operations across different data sources |
| **Database Handler** | Manages WordPress database searches and updates |
| **File Scanner** | Handles theme and plugin file operations |
| **Replace Handler** | Processes batch replace operations with validation |

## 🔧 Configuration

### Performance Settings

The plugin includes several performance options:

- **Max File Search Limit**: Limit the number of files scanned (100-2000)
- **Database Query Limit**: Results per page for database queries (50-500)
- **Skip Large Files**: Automatically skip files larger than 2MB
- **Enable Search Cache**: Cache results for repeated searches (experimental)

### Search Options

- **Case Sensitive**: Toggle case sensitivity for searches
- **Regular Expression Mode**: Enable regex pattern matching
- **Match Whole Words**: Only match complete words, not partial matches

## 🔒 Security Features

- **Admin-Only Access**: Only users with `manage_options` capability can use the plugin
- **Nonce Verification**: All AJAX requests include CSRF protection
- **File Restrictions**: File operations are limited to WordPress directories
- **Operation Logging**: Detailed logs of all search and replace operations

## 🐛 Troubleshooting

### Common Issues

| Issue | Solution |
|-------|----------|
| No search results | Check search text and scope selection |
| Replace operation fails | Verify file permissions and database connectivity |
| Memory errors | Increase PHP memory limit or reduce batch size |

### Debug Mode

Enable WordPress debug mode to get detailed error information:

```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check `/wp-content/debug.log` for detailed error messages.

## 🤝 Contributing

We welcome contributions from the community! Here's how you can help:

### Ways to Contribute

- 🐛 **Report Bugs**: Open an issue with detailed reproduction steps
- 💡 **Suggest Features**: Share your ideas for new functionality
- 📝 **Improve Documentation**: Help make our docs clearer and more comprehensive
- 💻 **Submit Code**: Fork the repo and submit pull requests

### Development Setup

1. **Fork the Repository**
   ```bash
   git clone https://github.com/your-username/worldteam-search-replace.git
   cd worldteam-search-replace
   ```

2. **Set Up Local WordPress**
   - Install a local WordPress development environment
   - Symlink or copy the plugin to `wp-content/plugins/`

3. **Make Changes**
   - Create a feature branch: `git checkout -b feature/your-feature`
   - Make your changes with proper PHP and JavaScript standards
   - Test thoroughly on a development site

4. **Submit Pull Request**
   - Commit your changes: `git commit -am 'Add new feature'`
   - Push to your fork: `git push origin feature/your-feature`
   - Open a Pull Request with detailed description

### Coding Standards

- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)
- Use meaningful variable and function names
- Add inline comments for complex logic
- Include PHPDoc comments for all functions
- Test on multiple WordPress versions

## 📝 Changelog

### Version 1.1.0 (2025-06-06)

#### ✨ New Features
- Initial release with comprehensive search and replace functionality
- Support for database content (Posts, Pages, Custom Fields, Comments)
- ACF (Advanced Custom Fields) integration
- Theme file searching and replacing (PHP files only)
- Advanced search options (regex, case sensitivity, whole words)
- Performance optimization settings
- Real-time progress tracking
- Detailed operation results and logging

#### 🛡️ Security
- Admin-only access with proper capability checks
- CSRF protection with nonce verification
- Safe file operations with directory restrictions
- Comprehensive input validation and sanitization

#### 🎨 User Interface
- Clean, WordPress-standard admin interface
- Responsive design for all device sizes
- Intuitive workflow with step-by-step guidance
- Detailed confirmation dialogs for destructive operations

## 📄 License

This plugin is licensed under the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).

```
WorldTeam Search & Replace WordPress Plugin
Copyright (C) 2024 WorldTeam

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

## 📞 Support

- 🐛 **Bug Reports**: [Open an issue](../../issues/new?template=bug_report.md)
- 💡 **Feature Requests**: [Open an issue](../../issues/new?template=feature_request.md)
- 📧 **Email Support**: support@worldteam.com
- 📖 **Documentation**: [Wiki](../../wiki)

## 🙏 Acknowledgments

- Thanks to the WordPress community for their excellent documentation
- Special thanks to contributors and beta testers
- Built with ❤️ by the WorldTeam development team

## ⚠️ Important Notice

**Always backup your site before performing search and replace operations on a production website.** While this plugin provides powerful search and replace functionality, we strongly recommend creating an additional full site backup before making significant changes.

Test all operations on a staging environment first, especially when using regular expressions or performing large-scale replacements.

---

*Made with ❤️ for the WordPress community* 