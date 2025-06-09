=== WP Easy Migrate ===
Contributors: wp-easy-migrate-team
Tags: migration, backup, export, import, clone
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export and import entire WordPress sites including media, plugins, themes, and database. Supports archive splitting and safe re-importing.

== Description ==

WP Easy Migrate is a comprehensive WordPress migration plugin that allows you to easily export and import entire WordPress sites. Whether you're moving to a new host, creating a staging site, or backing up your website, WP Easy Migrate provides a reliable and user-friendly solution.

= Key Features =

* **Complete Site Export**: Export your entire WordPress site including database, media files, plugins, and themes
* **Archive Splitting**: Automatically split large archives into smaller parts for easier handling and upload
* **Safe Import**: Import sites with compatibility checking and automatic URL updates
* **Detailed Logging**: Comprehensive logging system for troubleshooting and monitoring
* **System Compatibility Check**: Verify system requirements before export/import operations
* **User-Friendly Interface**: Clean and intuitive admin interface with progress indicators
* **Secure Operations**: Built with WordPress security best practices and proper sanitization

= What Gets Exported/Imported =

* **Database**: Complete WordPress database with all posts, pages, comments, users, and settings
* **Media Files**: All uploads including images, documents, and other media
* **Plugins**: All installed plugins (active and inactive)
* **Themes**: All installed themes
* **WordPress Core Files**: Essential WordPress files and configurations

= Technical Features =

* **PSR-4 Autoloading**: Modern PHP class structure with namespace support
* **Object-Oriented Design**: Clean, maintainable code following WordPress coding standards
* **Error Handling**: Comprehensive error handling with detailed logging
* **Memory Management**: Optimized for large sites with memory-efficient processing
* **Archive Verification**: Integrity checking for split archives
* **Compatibility Checking**: Automatic system requirement validation

= Use Cases =

* **Site Migration**: Move your WordPress site to a new hosting provider
* **Staging Sites**: Create exact copies of your production site for testing
* **Site Backups**: Create complete backups that can be easily restored
* **Development**: Clone production sites for development purposes
* **Site Duplication**: Create multiple copies of the same site

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/wp-easy-migrate` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to Tools → WP Easy Migrate to access the plugin interface
4. Verify system compatibility in the System Info tab before proceeding

== Frequently Asked Questions ==

= What are the system requirements? =

* WordPress 5.0 or higher
* PHP 7.4 or higher
* MySQL 5.6 or higher
* ZipArchive PHP extension
* Sufficient disk space (at least 2x your site size)
* Adequate memory limit (256MB recommended)

= Can I migrate large sites? =

Yes! WP Easy Migrate includes archive splitting functionality that automatically breaks large exports into smaller, manageable parts. This makes it possible to migrate even very large sites.

= Will my URLs be updated automatically? =

Yes, during import, WP Easy Migrate automatically updates all URLs in your database to match the new site location.

= Is it safe to use on production sites? =

WP Easy Migrate is designed with safety in mind. However, we always recommend creating a backup before performing any import operations. The plugin also creates an automatic backup before importing.

= What file formats are supported? =

WP Easy Migrate creates standard ZIP archives. Split archives use the naming convention: filename.part1.zip, filename.part2.zip, etc.

= Can I exclude certain files from export? =

Currently, the plugin automatically excludes cache files, log files, and temporary files. Custom exclusion patterns may be added in future versions.

== Screenshots ==

1. Export interface with options for customizing what to include
2. Import interface with file upload and progress tracking
3. System compatibility checker showing requirements
4. Detailed logging interface for troubleshooting
5. System information overview

== Changelog ==

= 1.0.0 =
* Initial release
* Complete site export/import functionality
* Archive splitting support
* Compatibility checking
* Comprehensive logging system
* User-friendly admin interface

== Upgrade Notice ==

= 1.0.0 =
Initial release of WP Easy Migrate. Please ensure your system meets the minimum requirements before installation.

== Technical Documentation ==

= Class Structure =

The plugin follows a modern object-oriented architecture:

* `WPEasyMigrate\Exporter` - Handles site export operations
* `WPEasyMigrate\Importer` - Handles site import operations  
* `WPEasyMigrate\Archiver` - Manages archive splitting and combining
* `WPEasyMigrate\Logger` - Comprehensive logging system
* `WPEasyMigrate\CompatibilityChecker` - System requirement validation
* `WPEasyMigrate\Admin\SettingsPage` - Admin interface management

= Hooks and Filters =

The plugin provides several hooks for developers:

* `wp_easy_migrate_before_export` - Fired before export starts
* `wp_easy_migrate_after_export` - Fired after export completes
* `wp_easy_migrate_before_import` - Fired before import starts
* `wp_easy_migrate_after_import` - Fired after import completes
* `wp_easy_migrate_clear_caches` - Fired when caches should be cleared

= File Structure =

```
wp-easy-migrate/
├── includes/
│   ├── Exporter.php
│   ├── Importer.php
│   ├── Archiver.php
│   ├── Logger.php
│   └── CompatibilityChecker.php
├── admin/
│   └── SettingsPage.php
├── wp-easy-migrate.php
└── readme.txt
```

= Security Considerations =

* All file operations use WordPress filesystem API
* Proper nonce verification for all AJAX requests
* Capability checking for admin access
* Sanitization of all user inputs
* Secure file storage in protected directories

== Support ==

For support, feature requests, or bug reports, please visit our support forum or contact us through the plugin's official website.

== Contributing ==

WP Easy Migrate is open source software. Contributions are welcome! Please follow WordPress coding standards and include appropriate tests for any new functionality.

== License ==

This plugin is licensed under the GPL v2 or later.

```
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```