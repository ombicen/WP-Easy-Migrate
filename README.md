# WP Easy Migrate

A powerful and user-friendly WordPress migration plugin that simplifies the process of moving WordPress sites between different environments.

## Features

- **Full Site Export**: Export complete WordPress sites including database, files, themes, and plugins
- **High-Performance Database Export**: Ultra-optimized database export with multiple performance modes
- **Chunked Processing**: Handle large sites without memory issues
- **Progress Tracking**: Real-time progress updates during export/import
- **Split Archives**: Automatically split large exports for easier handling
- **Selective Export**: Choose which components to include in your export
- **Resume Capability**: Resume interrupted exports automatically

## Database Export Performance Optimizations

This plugin includes several advanced optimizations for database export performance:

### Performance Modes
- **Standard Mode**: Basic chunked export (compatible with all environments)
- **Optimized Mode**: Enhanced with bulk INSERT statements and memory optimization
- **Ultra Mode**: Maximum performance with native mysqldump support when available

### Performance Features
- **Adaptive Table Batching**: Groups multiple small tables together in single export steps for dramatic speed improvement
- **Dynamic Batch Sizing**: Automatically adjusts batch sizes based on table characteristics and available memory
- **Empty Table Skipping**: Automatically skips empty tables to save time
- **Optimized Table Order**: Processes smaller tables first for faster initial progress
- **Bulk INSERT Statements**: Uses efficient bulk INSERT syntax (up to 5,000 rows per statement)
- **Native mysqldump Support**: Falls back to native mysqldump when available for maximum speed
- **Memory Management**: Uses up to 40% of available memory for optimal performance
- **SQL Optimizations**: Disables foreign key checks, unique checks, and autocommit during export

### Default Performance Settings
- **Rows per step**: 15,000 (increased from 5,000)
- **Files per step**: 100 (increased from 50)  
- **Bulk INSERT size**: 5,000 rows per statement
- **Memory usage**: Up to 40% of PHP memory limit
- **Max batch size**: Up to 100,000 rows for very large tables
- **Small table batching**: Up to 5 tables per batch (< 1,000 rows and < 100KB each)
- **Batch row limit**: Maximum 5,000 total rows per small table batch

### Performance Tips
1. **Increase PHP Memory**: Set `memory_limit = 512M` or higher in php.ini
2. **Use Ultra Mode**: Enable ultra-optimized mode for maximum performance
3. **Enable mysqldump**: Ensure mysqldump is available for fastest exports
4. **Optimize MySQL**: Use InnoDB storage engine for better performance
5. **Large Disk I/O**: Ensure sufficient disk space and fast I/O for temporary files

## Installation

1. Download the plugin files
2. Upload to your WordPress `/wp-content/plugins/` directory
3. Activate the plugin through the WordPress admin panel
4. Navigate to `Tools > WP Easy Migrate` to begin

## Usage

### Exporting a Site

1. Go to `Tools > WP Easy Migrate`
2. Select export options (database, files, themes, plugins)
3. Choose performance mode (Standard/Optimized/Ultra)
4. Click "Start Export"
5. Monitor progress in real-time
6. Download the completed export archive

### Import Options

- Manual import via WordPress admin
- Command-line import tools
- Direct database restoration

## System Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- MySQL 5.6 or higher
- Sufficient disk space for temporary files
- ZipArchive PHP extension

## Advanced Configuration

### Database Export Modes

```php
// Set export mode in wp-config.php
define('WP_EASY_MIGRATE_DB_MODE', 'ultra'); // standard, optimized, ultra

// Customize batch sizes
define('WP_EASY_MIGRATE_DB_ROWS', 20000);
define('WP_EASY_MIGRATE_FILES_BATCH', 150);
```

### Performance Tuning

For very large databases (>1GB), consider:
- Using dedicated database server
- Increasing `innodb_buffer_pool_size`
- Setting `innodb_flush_log_at_trx_commit = 0` during export
- Using SSD storage for temporary files

## Troubleshooting

### Performance Issues
- Check PHP memory limit and increase if needed
- Verify disk space availability
- Enable ultra mode if mysqldump is available
- Monitor MySQL performance during export

### Common Solutions
- **Timeout errors**: Increase `max_execution_time` in php.ini
- **Memory errors**: Increase `memory_limit` and reduce batch sizes
- **Large files**: Enable archive splitting for files >100MB

## Support

For support and feature requests, please contact the development team.

## License

This plugin is licensed under the GPL v2 or later. 