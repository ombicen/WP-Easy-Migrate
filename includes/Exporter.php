<?php

namespace WPEasyMigrate;

/**
 * Exporter Class
 * 
 * Handles exporting WordPress sites including database, files, and creating manifests
 */
class Exporter
{

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Archiver instance
     */
    private $archiver;

    /**
     * Export directory
     */
    private $export_dir;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->logger = new Logger();
        $this->archiver = new Archiver();
        $this->export_dir = WP_EASY_MIGRATE_UPLOADS_DIR . 'exports/';
    }

    /**
     * Export entire WordPress site
     * 
     * @param array $options Export options
     * @return string Path to the exported archive
     * @throws Exception
     */
    public function export_site(array $options = []): string
    {
        $this->logger->log('Starting site export', 'info');

        // Set default options
        $options = wp_parse_args($options, [
            'include_uploads' => true,
            'include_plugins' => true,
            'include_themes' => true,
            'include_database' => true,
            'split_size' => 100, // MB
            'exclude_patterns' => [
                '*.log',
                '*/cache/*',
                '*/wp-easy-migrate/*'
            ]
        ]);

        try {
            // Create unique export directory
            $export_id = date('Y-m-d_H-i-s') . '_' . wp_generate_password(8, false);
            $current_export_dir = $this->export_dir . $export_id . '/';
            wp_mkdir_p($current_export_dir);

            $this->logger->log("Export directory created: {$current_export_dir}", 'info');

            // Export database
            if ($options['include_database']) {
                $db_file = $this->export_database($current_export_dir);
                $this->logger->log("Database exported: {$db_file}", 'info');
            }

            // Export files
            $files_exported = [];

            if ($options['include_uploads']) {
                $uploads_file = $this->export_uploads($current_export_dir, $options['exclude_patterns']);
                if ($uploads_file) {
                    $files_exported['uploads'] = $uploads_file;
                    $this->logger->log("Uploads exported: {$uploads_file}", 'info');
                }
            }

            if ($options['include_plugins']) {
                $plugins_file = $this->export_plugins($current_export_dir);
                if ($plugins_file) {
                    $files_exported['plugins'] = $plugins_file;
                    $this->logger->log("Plugins exported: {$plugins_file}", 'info');
                }
            }

            if ($options['include_themes']) {
                $themes_file = $this->export_themes($current_export_dir);
                if ($themes_file) {
                    $files_exported['themes'] = $themes_file;
                    $this->logger->log("Themes exported: {$themes_file}", 'info');
                }
            }

            // Create manifest
            $manifest_data = $this->create_manifest([
                'export_id' => $export_id,
                'site_url' => get_site_url(),
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'mysql_version' => $this->get_mysql_version(),
                'options' => $options,
                'files' => $files_exported,
                'export_date' => current_time('mysql'),
                'file_count' => $this->count_exported_files($current_export_dir),
                'total_size' => $this->get_directory_size($current_export_dir)
            ]);

            $manifest_file = $current_export_dir . 'manifest.json';
            file_put_contents($manifest_file, $manifest_data);
            $this->logger->log("Manifest created: {$manifest_file}", 'info');

            // Create main archive
            $archive_path = $this->export_dir . "wp-export-{$export_id}.zip";
            $this->create_archive($current_export_dir, $archive_path);

            // Split archive if needed
            if ($options['split_size'] > 0) {
                $archive_size_mb = filesize($archive_path) / (1024 * 1024);
                if ($archive_size_mb > $options['split_size']) {
                    $this->logger->log("Archive size ({$archive_size_mb}MB) exceeds limit ({$options['split_size']}MB), splitting...", 'info');
                    $parts = $this->archiver->split_archive($archive_path, $options['split_size']);

                    // Remove original large archive
                    unlink($archive_path);

                    $this->logger->log("Archive split into " . count($parts) . " parts", 'info');
                    return $parts[0]; // Return first part path
                }
            }

            // Clean up temporary directory
            $this->cleanup_directory($current_export_dir);

            $this->logger->log("Export completed successfully: {$archive_path}", 'info');
            return $archive_path;
        } catch (\Exception $e) {
            $this->logger->log("Export failed: " . $e->getMessage(), 'error');
            throw $e;
        }
    }

    /**
     * Export WordPress database
     * 
     * @param string $export_dir Export directory
     * @return string Path to database dump file
     * @throws Exception
     */
    private function export_database(string $export_dir): string
    {
        global $wpdb;

        $db_file = $export_dir . 'database.sql';

        // Get all tables
        $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);

        $sql_content = "-- WordPress Database Export\n";
        $sql_content .= "-- Generated on: " . current_time('mysql') . "\n";
        $sql_content .= "-- WordPress Version: " . get_bloginfo('version') . "\n\n";

        $sql_content .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $sql_content .= "SET time_zone = \"+00:00\";\n\n";

        foreach ($tables as $table) {
            $table_name = $table[0];

            // Get table structure
            $create_table = $wpdb->get_row("SHOW CREATE TABLE `{$table_name}`", ARRAY_N);
            $sql_content .= "\n-- Table structure for table `{$table_name}`\n";
            $sql_content .= "DROP TABLE IF EXISTS `{$table_name}`;\n";
            $sql_content .= $create_table[1] . ";\n\n";

            // Get table data
            $rows = $wpdb->get_results("SELECT * FROM `{$table_name}`", ARRAY_A);

            if (!empty($rows)) {
                $sql_content .= "-- Dumping data for table `{$table_name}`\n";

                foreach ($rows as $row) {
                    $values = [];
                    foreach ($row as $value) {
                        $values[] = self::safe_sql_escape($value);
                    }
                    $sql_content .= "INSERT INTO `{$table_name}` VALUES (" . implode(', ', $values) . ");\n";
                }
                $sql_content .= "\n";
            }
        }

        file_put_contents($db_file, $sql_content);

        if (!file_exists($db_file)) {
            throw new \Exception('Failed to create database dump');
        }

        return $db_file;
    }

    /**
     * Export uploads directory
     * 
     * @param string $export_dir Export directory
     * @param array $exclude_patterns Patterns to exclude
     * @return string|null Path to uploads archive
     */
    private function export_uploads(string $export_dir, array $exclude_patterns = []): ?string
    {
        $uploads_dir = wp_upload_dir()['basedir'];

        if (!is_dir($uploads_dir)) {
            $this->logger->log('Uploads directory not found', 'warning');
            return null;
        }

        $uploads_archive = $export_dir . 'uploads.zip';
        return $this->create_filtered_archive($uploads_dir, $uploads_archive, $exclude_patterns);
    }

    /**
     * Export plugins directory
     * 
     * @param string $export_dir Export directory
     * @return string|null Path to plugins archive
     */
    private function export_plugins(string $export_dir): ?string
    {
        $plugins_dir = WP_PLUGIN_DIR;

        if (!is_dir($plugins_dir)) {
            $this->logger->log('Plugins directory not found', 'warning');
            return null;
        }

        $plugins_archive = $export_dir . 'plugins.zip';
        return $this->create_archive($plugins_dir, $plugins_archive);
    }

    /**
     * Export themes directory
     * 
     * @param string $export_dir Export directory
     * @return string|null Path to themes archive
     */
    private function export_themes(string $export_dir): ?string
    {
        $themes_dir = get_theme_root();

        if (!is_dir($themes_dir)) {
            $this->logger->log('Themes directory not found', 'warning');
            return null;
        }

        $themes_archive = $export_dir . 'themes.zip';
        return $this->create_archive($themes_dir, $themes_archive);
    }

    /**
     * Create archive from directory
     * 
     * @param string $source_dir Source directory
     * @param string $archive_path Archive path
     * @return string Archive path
     * @throws Exception
     */
    private function create_archive(string $source_dir, string $archive_path): string
    {
        if (!class_exists('ZipArchive')) {
            throw new \Exception('ZipArchive class not available');
        }

        $zip = new \ZipArchive();
        $result = $zip->open($archive_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        if ($result !== TRUE) {
            throw new \Exception("Cannot create archive: {$archive_path} (Error: {$result})");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $file_path = $file->getRealPath();
            $relative_path = substr($file_path, strlen($source_dir) + 1);

            if ($file->isDir()) {
                $zip->addEmptyDir($relative_path);
            } elseif ($file->isFile()) {
                $zip->addFile($file_path, $relative_path);
            }
        }

        $zip->close();

        if (!file_exists($archive_path)) {
            throw new \Exception("Failed to create archive: {$archive_path}");
        }

        return $archive_path;
    }

    /**
     * Create filtered archive excluding certain patterns
     * 
     * @param string $source_dir Source directory
     * @param string $archive_path Archive path
     * @param array $exclude_patterns Patterns to exclude
     * @return string Archive path
     */
    private function create_filtered_archive(string $source_dir, string $archive_path, array $exclude_patterns = []): string
    {
        $zip = new \ZipArchive();
        $zip->open($archive_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $file_path = $file->getRealPath();
            $relative_path = substr($file_path, strlen($source_dir) + 1);

            // Check if file should be excluded
            $should_exclude = false;
            foreach ($exclude_patterns as $pattern) {
                if (fnmatch($pattern, $relative_path) || fnmatch($pattern, basename($file_path))) {
                    $should_exclude = true;
                    break;
                }
            }

            if ($should_exclude) {
                continue;
            }

            if ($file->isDir()) {
                $zip->addEmptyDir($relative_path);
            } elseif ($file->isFile()) {
                $zip->addFile($file_path, $relative_path);
            }
        }

        $zip->close();
        return $archive_path;
    }

    /**
     * Create manifest file
     * 
     * @param array $info Site information
     * @return string JSON manifest content
     */
    public function create_manifest(array $info): string
    {
        $manifest = [
            'version' => '1.0',
            'generator' => 'WP Easy Migrate',
            'export_id' => $info['export_id'],
            'site_info' => [
                'url' => $info['site_url'],
                'name' => get_bloginfo('name'),
                'description' => get_bloginfo('description'),
                'wp_version' => $info['wp_version'],
                'php_version' => $info['php_version'],
                'mysql_version' => $info['mysql_version'],
            ],
            'export_info' => [
                'date' => $info['export_date'],
                'options' => $info['options'],
                'files' => $info['files'] ?? [],
                'file_count' => $info['file_count'] ?? 0,
                'total_size' => $info['total_size'] ?? 0,
            ],
            'requirements' => [
                'min_wp_version' => '5.0',
                'min_php_version' => '7.4',
                'required_extensions' => ['zip', 'mysqli'],
            ]
        ];

        return wp_json_encode($manifest, JSON_PRETTY_PRINT);
    }

    /**
     * Get MySQL version
     * 
     * @return string MySQL version
     */
    private function get_mysql_version(): string
    {
        global $wpdb;
        return $wpdb->get_var("SELECT VERSION()");
    }

    /**
     * Count files in directory
     * 
     * @param string $dir Directory path
     * @return int File count
     */
    private function count_exported_files(string $dir): int
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get directory size in bytes
     * 
     * @param string $dir Directory path
     * @return int Size in bytes
     */
    private function get_directory_size(string $dir): int
    {
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Clean up directory
     * 
     * @param string $dir Directory to clean up
     */
    private function cleanup_directory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($dir);
    }

    /**
     * Scan files for archiving
     * 
     * @param array $options Export options
     * @return array Array with 'files' and 'sizes' keys
     */
    public function scan_files(array $options): array
    {
        $files = [];
        $sizes = [];

        $this->logger->log('Starting file scan for archiving', 'info');

        // Scan uploads
        if ($options['include_uploads']) {
            $uploads_dir = wp_upload_dir()['basedir'];
            if (is_dir($uploads_dir)) {
                $upload_files = $this->scan_directory($uploads_dir, $options['exclude_patterns']);
                $files = array_merge($files, $upload_files['files']);
                $sizes = array_merge($sizes, $upload_files['sizes']);
                $this->logger->log('Scanned uploads: ' . count($upload_files['files']) . ' files', 'info');
            }
        }

        // Scan plugins
        if ($options['include_plugins']) {
            $plugins_dir = WP_PLUGIN_DIR;
            if (is_dir($plugins_dir)) {
                $plugin_files = $this->scan_directory($plugins_dir);
                $files = array_merge($files, $plugin_files['files']);
                $sizes = array_merge($sizes, $plugin_files['sizes']);
                $this->logger->log('Scanned plugins: ' . count($plugin_files['files']) . ' files', 'info');
            }
        }

        // Scan themes
        if ($options['include_themes']) {
            $themes_dir = get_theme_root();
            if (is_dir($themes_dir)) {
                $theme_files = $this->scan_directory($themes_dir);
                $files = array_merge($files, $theme_files['files']);
                $sizes = array_merge($sizes, $theme_files['sizes']);
                $this->logger->log('Scanned themes: ' . count($theme_files['files']) . ' files', 'info');
            }
        }

        $total_size = array_sum($sizes);
        $this->logger->log("File scan complete: " . count($files) . " files, " . size_format($total_size), 'info');

        return [
            'files' => $files,
            'sizes' => $sizes
        ];
    }

    /**
     * Scan directory for files
     * 
     * @param string $directory Directory to scan
     * @param array $exclude_patterns Patterns to exclude
     * @return array Array with 'files' and 'sizes' keys
     */
    private function scan_directory(string $directory, array $exclude_patterns = []): array
    {
        $files = [];
        $sizes = [];

        if (!is_dir($directory)) {
            return ['files' => $files, 'sizes' => $sizes];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $file_path = $file->getRealPath();
            $relative_path = substr($file_path, strlen($directory) + 1);

            // Check if file should be excluded
            $should_exclude = false;
            foreach ($exclude_patterns as $pattern) {
                if (fnmatch($pattern, $relative_path) || fnmatch($pattern, basename($file_path))) {
                    $should_exclude = true;
                    break;
                }
            }

            if ($should_exclude) {
                continue;
            }

            $files[] = $file_path;
            $sizes[] = $file->getSize();
        }

        return ['files' => $files, 'sizes' => $sizes];
    }

    /**
     * Archive next file in the queue
     * 
     * @param ExportSession $session Export session
     * @return array Response data
     * @throws Exception
     */
    public static function archive_next_file(ExportSession $session): array
    {
        $start_time = microtime(true);

        // Get current file
        $current_file = $session->get_current_file();
        if (!$current_file) {
            throw new \Exception('No current file to archive');
        }

        if (!file_exists($current_file)) {
            // Skip missing files and move to next
            $session->increment_index(0);
            return [
                'success' => true,
                'step' => 'archive_files',
                'progress' => $session->get_progress(),
                'complete' => $session->is_archiving_complete(),
                'estimated_size' => $session->get_estimated_size_remaining(),
                'estimated_time' => $session->get_file_archiving_time_remaining(),
                'current_file' => $session->get_current_file() ? basename($session->get_current_file()) : null,
                'message' => 'Skipped missing file: ' . basename($current_file)
            ];
        }

        // Get archive path
        $archive_path = $session->get_archive_path();
        if (!$archive_path) {
            throw new \Exception('Archive path not set');
        }

        // Open archive
        $zip = new \ZipArchive();
        $result = $zip->open($archive_path, \ZipArchive::CREATE);

        if ($result !== TRUE) {
            throw new \Exception("Cannot open archive: {$archive_path} (Error: {$result})");
        }

        // Determine relative path for archive
        $relative_path = self::get_relative_archive_path($current_file, $session->get_options());

        // Add file to archive
        if (!$zip->addFile($current_file, $relative_path)) {
            $zip->close();
            throw new \Exception("Failed to add file to archive: {$current_file}");
        }

        $zip->close();

        // Calculate runtime
        $runtime = microtime(true) - $start_time;

        // Update session
        $session->increment_index($runtime);

        // Check if archiving is complete
        $complete = $session->is_archiving_complete();

        return [
            'success' => true,
            'step' => 'archive_files',
            'progress' => $session->get_progress(),
            'complete' => $complete,
            'estimated_size' => $session->get_estimated_size_remaining(),
            'estimated_time' => $session->get_file_archiving_time_remaining(),
            'current_file' => $session->get_current_file() ? basename($session->get_current_file()) : null,
            'total_files' => $session->get_total_files(),
            'current_index' => $session->get_current_index(),
            'message' => $complete ? 'File archiving completed' : 'Archived: ' . basename($current_file)
        ];
    }

    /**
     * Get relative path for file in archive
     * 
     * @param string $file_path Absolute file path
     * @param array $options Export options
     * @return string Relative path for archive
     */
    private static function get_relative_archive_path(string $file_path, array $options): string
    {
        // Determine which directory this file belongs to
        $uploads_dir = wp_upload_dir()['basedir'];
        $plugins_dir = WP_PLUGIN_DIR;
        $themes_dir = get_theme_root();

        if (strpos($file_path, $uploads_dir) === 0) {
            return 'uploads/' . substr($file_path, strlen($uploads_dir) + 1);
        } elseif (strpos($file_path, $plugins_dir) === 0) {
            return 'plugins/' . substr($file_path, strlen($plugins_dir) + 1);
        } elseif (strpos($file_path, $themes_dir) === 0) {
            return 'themes/' . substr($file_path, strlen($themes_dir) + 1);
        }

        // Fallback to filename
        return basename($file_path);
    }

    /**
     * Export database chunk (table-by-table or by row limit)
     * 
     * @param ExportSession $session Export session
     * @return array Response data
     * @throws Exception
     */
    public static function export_database_chunk(ExportSession $session): array
    {
        // Choose between optimized and original method based on session setting
        if ($session->use_optimized_db_export()) {
            return self::export_database_chunk_optimized($session);
        } else {
            return self::export_database_chunk_original($session);
        }
    }

    /**
     * Export database chunk (table-by-table or by row limit) - ORIGINAL VERSION
     * 
     * @param ExportSession $session Export session
     * @return array Response data
     * @throws Exception
     */
    public static function export_database_chunk_original(ExportSession $session): array
    {
        global $wpdb;

        $start_time = microtime(true);

        // Initialize database export if not started
        if (!$session->get_current_table()) {
            $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
            $table_names = array_column($tables, 0);
            $session->init_database_export($table_names);

            // Write SQL header
            $db_path = $session->get_db_export_path();
            $sql_header = "-- WordPress Database Export\n";
            $sql_header .= "-- Generated on: " . current_time('mysql') . "\n";
            $sql_header .= "-- WordPress Version: " . get_bloginfo('version') . "\n\n";
            $sql_header .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
            $sql_header .= "SET time_zone = \"+00:00\";\n\n";

            file_put_contents($db_path, $sql_header);
        }

        $current_table = $session->get_current_table();
        if (!$current_table) {
            return [
                'success' => true,
                'step' => 'export_database',
                'progress' => 100,
                'complete' => true,
                'message' => 'Database export completed'
            ];
        }

        $table_offset = $session->get_table_offset();
        $rows_per_step = $session->get_db_rows_per_step();
        $db_path = $session->get_db_export_path();

        $sql_content = '';

        // Export table structure on first chunk
        if ($table_offset === 0) {
            $escaped_table = '`' . str_replace('`', '``', $current_table) . '`';
            $create_table = $wpdb->get_row("SHOW CREATE TABLE {$escaped_table}", ARRAY_N);
            $sql_content .= "\n-- Table structure for table {$escaped_table}\n";
            $sql_content .= "DROP TABLE IF EXISTS {$escaped_table};\n";
            $sql_content .= $create_table[1] . ";\n\n";
            $sql_content .= "-- Dumping data for table `{$current_table}`\n";
        }

        // Get table data in chunks
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$current_table}` LIMIT %d OFFSET %d",
                $rows_per_step,
                $table_offset
            ),
            ARRAY_A
        );

        $rows_processed = count($rows);

        if ($rows_processed > 0) {
            foreach ($rows as $row) {
                $values = [];
                foreach ($row as $value) {
                    $values[] = self::safe_sql_escape($value);
                }
                $sql_content .= "INSERT INTO `{$current_table}` VALUES (" . implode(', ', $values) . ");\n";
            }
        }

        // Append to database file
        if (!empty($sql_content)) {
            $result = file_put_contents($db_path, $sql_content, FILE_APPEND | LOCK_EX);
            if ($result === false) {
                throw new \Exception('Failed to write to database export file: ' . $db_path);
            }
        }

        // Update progress
        if ($rows_processed < $rows_per_step) {
            // Table complete, move to next
            $sql_content = "\n";
            $result = file_put_contents($db_path, $sql_content, FILE_APPEND | LOCK_EX);
            if ($result === false) {
                throw new \Exception('Failed to write to database export file: ' . $db_path);
            }
            $session->next_table();
            $message = "Completed table: {$current_table}";
        } else {
            // More rows in this table
            $session->update_table_offset($table_offset + $rows_per_step);
            $message = "Exported {$rows_processed} rows from {$current_table}";
        }

        $runtime = microtime(true) - $start_time;
        $progress = $session->get_database_export_progress();
        $complete = $session->is_database_export_complete();

        return [
            'success' => true,
            'step' => 'export_database',
            'progress' => $progress,
            'complete' => $complete,
            'message' => $message,
            'current_table' => $session->get_current_table(),
            'table_progress' => $progress
        ];
    }

    /**
     * Export database chunk (table-by-table or by row limit) - OPTIMIZED VERSION
     * 
     * @param ExportSession $session Export session
     * @return array Response data
     * @throws Exception
     */
    public static function export_database_chunk_optimized(ExportSession $session): array
    {
        global $wpdb;

        $start_time = microtime(true);

        // Initialize database export if not started
        if (!$session->get_current_table()) {
            $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
            $table_names = array_column($tables, 0);
            $session->init_database_export($table_names);

            // Write SQL header with optimizations
            $db_path = $session->get_db_export_path();
            $sql_header = "-- WordPress Database Export (OPTIMIZED)\n";
            $sql_header .= "-- Generated on: " . current_time('mysql') . "\n";
            $sql_header .= "-- WordPress Version: " . get_bloginfo('version') . "\n\n";
            $sql_header .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
            $sql_header .= "SET time_zone = \"+00:00\";\n";
            $sql_header .= "SET FOREIGN_KEY_CHECKS = 0;\n";
            $sql_header .= "SET UNIQUE_CHECKS = 0;\n";
            $sql_header .= "SET AUTOCOMMIT = 0;\n\n";

            file_put_contents($db_path, $sql_header);
        }

        $current_table = $session->get_current_table();
        if (!$current_table) {
            // Finalize with optimizations cleanup
            $db_path = $session->get_db_export_path();
            $sql_footer = "\nSET FOREIGN_KEY_CHECKS = 1;\n";
            $sql_footer .= "SET UNIQUE_CHECKS = 1;\n";
            $sql_footer .= "COMMIT;\n";
            file_put_contents($db_path, $sql_footer, FILE_APPEND | LOCK_EX);

            return [
                'success' => true,
                'step' => 'export_database',
                'progress' => 100,
                'complete' => true,
                'message' => 'Database export completed'
            ];
        }

        $table_offset = $session->get_table_offset();

        // Dynamic rows per step based on table size and memory
        $rows_per_step = self::get_optimized_rows_per_step($current_table, $session->get_db_rows_per_step());

        $db_path = $session->get_db_export_path();
        $sql_content = '';

        // Export table structure on first chunk
        if ($table_offset === 0) {
            $escaped_table = '`' . str_replace('`', '``', $current_table) . '`';
            $create_table = $wpdb->get_row("SHOW CREATE TABLE {$escaped_table}", ARRAY_N);
            $sql_content .= "\n-- Table structure for table {$escaped_table}\n";
            $sql_content .= "DROP TABLE IF EXISTS {$escaped_table};\n";
            $sql_content .= $create_table[1] . ";\n\n";
            $sql_content .= "-- Dumping data for table {$escaped_table}\n";
            $sql_content .= "LOCK TABLES {$escaped_table} WRITE;\n";
        }

        // Get table data in chunks with optimized query
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$current_table}` LIMIT %d OFFSET %d",
                $rows_per_step,
                $table_offset
            ),
            ARRAY_A
        );

        $rows_processed = count($rows);

        if ($rows_processed > 0) {
            // Generate SQL with validation
            $insert_sql = self::generate_bulk_insert_sql($current_table, $rows);

            // Validate the generated SQL before adding to content
            if (!empty($insert_sql)) {
                // Basic SQL validation - check for obvious syntax issues
                if (self::validate_sql_syntax($insert_sql)) {
                    $sql_content .= $insert_sql;
                } else {
                    // If bulk SQL fails validation, fall back to individual INSERT statements
                    $logger = new Logger();
                    $logger->log("Bulk SQL validation failed for table {$current_table}, using fallback method", 'warning');

                    foreach ($rows as $row) {
                        $values = [];
                        foreach ($row as $value) {
                            $values[] = self::safe_sql_escape($value);
                        }
                        $sql_content .= "INSERT INTO `{$current_table}` VALUES (" . implode(', ', $values) . ");\n";
                    }
                }
            }
        }

        // Check if table is complete
        $table_complete = $rows_processed < $rows_per_step;

        if ($table_complete) {
            $sql_content .= "UNLOCK TABLES;\n\n";
        }

        // Efficient file writing with buffering
        if (!empty($sql_content)) {
            $result = file_put_contents($db_path, $sql_content, FILE_APPEND | LOCK_EX);
            if ($result === false) {
                throw new \Exception('Failed to write to database export file: ' . $db_path);
            }
        }

        // Update progress
        if ($table_complete) {
            $session->next_table();
            $message = "Completed table: {$current_table} ({$rows_processed} rows)";
        } else {
            $session->update_table_offset($table_offset + $rows_per_step);
            $message = "Exported {$rows_processed} rows from {$current_table} (batch)";
        }

        $runtime = microtime(true) - $start_time;
        $progress = $session->get_database_export_progress();
        $complete = $session->is_database_export_complete();

        return [
            'success' => true,
            'step' => 'export_database',
            'progress' => $progress,
            'complete' => $complete,
            'message' => $message,
            'current_table' => $session->get_current_table(),
            'table_progress' => $progress,
            'rows_per_step' => $rows_per_step,
            'runtime' => $runtime
        ];
    }

    /**
     * Calculate optimized rows per step based on table characteristics
     * 
     * @param string $table_name Table name
     * @param int $base_rows_per_step Base rows per step
     * @return int Optimized rows per step
     */
    private static function get_optimized_rows_per_step(string $table_name, int $base_rows_per_step): int
    {
        global $wpdb;

        // Get table info to optimize batch size
        $table_info = $wpdb->get_row($wpdb->prepare(
            "SELECT table_rows, avg_row_length, data_length 
             FROM information_schema.tables 
             WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table_name
        ));

        if (!$table_info) {
            return $base_rows_per_step;
        }

        $table_rows = (int) $table_info->table_rows;
        $avg_row_length = (int) $table_info->avg_row_length;
        $data_length = (int) $table_info->data_length;

        // Memory-based optimization
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $available_memory = $memory_limit * 0.3; // Use 30% of memory limit for DB operations

        // Calculate optimal batch size based on row size and available memory
        if ($avg_row_length > 0) {
            $memory_based_rows = min(50000, max(1000, floor($available_memory / ($avg_row_length * 2))));
        } else {
            $memory_based_rows = $base_rows_per_step;
        }

        // Table size-based optimization
        if ($table_rows < 1000) {
            // Small tables: process all at once
            $optimized_rows = min($table_rows, 10000);
        } elseif ($table_rows < 100000) {
            // Medium tables: larger batches
            $optimized_rows = min(10000, $memory_based_rows);
        } else {
            // Large tables: balance between speed and memory
            $optimized_rows = min(5000, $memory_based_rows);
        }

        // Row size-based optimization
        if ($avg_row_length > 10000) { // Large rows (>10KB)
            $optimized_rows = min(1000, $optimized_rows);
        } elseif ($avg_row_length < 500) { // Small rows (<500B)
            $optimized_rows = min(20000, $optimized_rows * 2);
        }

        return max(100, min(50000, $optimized_rows));
    }

    /**
     * Generate optimized bulk INSERT SQL statements
     * 
     * @param string $table_name Table name
     * @param array $rows Array of row data
     * @return string Bulk INSERT SQL
     */
    private static function generate_bulk_insert_sql(string $table_name, array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        global $wpdb;
        $escaped_table = '`' . str_replace('`', '``', $table_name) . '`';
        $sql = '';

        // Process rows in chunks for bulk INSERT statements
        $chunk_size = min(1000, count($rows)); // Max 1000 rows per INSERT for optimal performance
        $row_chunks = array_chunk($rows, $chunk_size);

        foreach ($row_chunks as $chunk) {
            $sql .= "INSERT INTO {$escaped_table} VALUES\n";
            $value_strings = [];

            foreach ($chunk as $row) {
                $values = [];
                foreach ($row as $value) {
                    $values[] = self::safe_sql_escape($value);
                }

                // Validate the row has data
                if (!empty($values)) {
                    $value_strings[] = '(' . implode(',', $values) . ')';
                }
            }

            // Only add INSERT if we have valid data
            if (!empty($value_strings)) {
                $sql .= implode(",\n", $value_strings) . ";\n";
            }
        }

        return $sql;
    }

    /**
     * Archive next batch of files (original implementation)
     * 
     * @param ExportSession $session Export session
     * @return array Response data
     * @throws Exception
     */
    public static function archive_next_batch(ExportSession $session): array
    {
        // Use the optimized version by default
        return self::archive_next_batch_optimized($session);
    }

    /**
     * High-performance batch archiving with streaming and optimizations
     * 
     * @param ExportSession $session Export session
     * @return array Response data
     * @throws Exception
     */
    public static function archive_next_batch_optimized(ExportSession $session): array
    {
        $start_time = microtime(true);

        // Get current batch of files
        $current_batch = $session->get_current_batch();
        $relative_paths = $session->get_relative_paths();
        $current_index = $session->get_current_index();

        if (empty($current_batch)) {
            return [
                'success' => true,
                'step' => 'archive_files',
                'progress' => 100,
                'complete' => true,
                'message' => 'File archiving completed'
            ];
        }

        // Get archive path
        $archive_path = $session->get_archive_path();
        if (!$archive_path) {
            throw new \Exception('Archive path not set');
        }

        // Initialize ZIP with optimized settings
        static $zip_handle = null;
        static $last_archive_path = null;

        if ($zip_handle === null || $last_archive_path !== $archive_path) {
            if ($zip_handle !== null) {
                $zip_handle->close();
            }

            $zip_handle = new \ZipArchive();
            $result = $zip_handle->open($archive_path, \ZipArchive::CREATE);

            if ($result !== TRUE) {
                throw new \Exception("Cannot open archive: {$archive_path} (Error: {$result})");
            }

            $last_archive_path = $archive_path;
        }

        $files_added = 0;
        $files_skipped = 0;
        $total_size_processed = 0;
        $small_files_batch = [];
        $large_files = [];

        // First pass: categorize files by size for optimized processing
        foreach ($current_batch as $i => $file_path) {
            if (!file_exists($file_path)) {
                $files_skipped++;
                continue;
            }

            $relative_path = $relative_paths[$current_index + $i] ?? basename($file_path);
            $file_size = filesize($file_path);
            $total_size_processed += $file_size;

            if ($file_size < 512000) { // < 500KB - batch process small files
                $small_files_batch[] = [
                    'path' => $file_path,
                    'relative' => $relative_path,
                    'size' => $file_size
                ];
            } else { // >= 500KB - process large files individually with streaming
                $large_files[] = [
                    'path' => $file_path,
                    'relative' => $relative_path,
                    'size' => $file_size
                ];
            }
        }

        // Process small files in batch using addFromString for better performance
        if (!empty($small_files_batch)) {
            foreach ($small_files_batch as $file_info) {
                $file_content = file_get_contents($file_info['path']);
                if ($file_content !== false) {
                    if ($zip_handle->addFromString($file_info['relative'], $file_content)) {
                        $files_added++;
                    } else {
                        $files_skipped++;
                    }
                    // Free memory immediately
                    unset($file_content);
                } else {
                    $files_skipped++;
                }
            }
        }

        // Process large files individually using addFile for streaming
        foreach ($large_files as $file_info) {
            if ($zip_handle->addFile($file_info['path'], $file_info['relative'])) {
                $files_added++;
            } else {
                $files_skipped++;
            }
        }

        // Calculate runtime
        $runtime = microtime(true) - $start_time;

        // Update session
        $batch_size = count($current_batch);
        $session->increment_index_batch($batch_size, $runtime);

        // Check if archiving is complete
        $complete = $session->is_archiving_complete();

        // Close ZIP handle if archiving is complete
        if ($complete && $zip_handle !== null) {
            $zip_handle->close();
            $zip_handle = null;
            $last_archive_path = null;
        }

        $message = "Processed {$files_added} files";
        if ($files_skipped > 0) {
            $message .= " (skipped {$files_skipped})";
        }
        if (!empty($small_files_batch)) {
            $message .= " - " . count($small_files_batch) . " small files batched";
        }
        if (!empty($large_files)) {
            $message .= " - " . count($large_files) . " large files streamed";
        }
        $message .= " - " . size_format($total_size_processed) . " processed";

        return [
            'success' => true,
            'step' => 'archive_files',
            'progress' => $session->get_progress(),
            'complete' => $complete,
            'estimated_size' => $session->get_estimated_size_remaining(),
            'estimated_time' => $session->get_file_archiving_time_remaining(),
            'total_files' => $session->get_total_files(),
            'current_index' => $session->get_current_index(),
            'files_added' => $files_added,
            'files_skipped' => $files_skipped,
            'batch_size' => $batch_size,
            'small_files_batched' => count($small_files_batch),
            'large_files_streamed' => count($large_files),
            'total_size_processed' => $total_size_processed,
            'message' => $message
        ];
    }

    /**
     * Safely escape a value for SQL insertion with robust handling of complex data
     * 
     * @param mixed $value Value to escape
     * @return string Escaped value
     */
    private static function safe_sql_escape($value): string
    {
        global $wpdb;

        if (is_null($value)) {
            return 'NULL';
        }

        // Convert to string
        $string_value = (string) $value;

        // Try WordPress's robust escaping first
        if (method_exists($wpdb, '_real_escape')) {
            $escaped = $wpdb->_real_escape($string_value);
        } else {
            // Fallback to mysqli_real_escape_string if available
            if (function_exists('mysqli_real_escape_string') && isset($wpdb->dbh)) {
                $escaped = mysqli_real_escape_string($wpdb->dbh, $string_value);
            } else {
                // Last resort: use esc_sql with additional cleaning
                $escaped = esc_sql($string_value);
            }
        }

        // Additional safety measures for problematic characters
        // Handle null bytes and other control characters that might cause issues
        $escaped = str_replace("\0", "\\0", $escaped);
        $escaped = str_replace("\r", "\\r", $escaped);
        $escaped = str_replace("\n", "\\n", $escaped);



        return "'" . $escaped . "'";
    }

    /**
     * Validate SQL syntax for basic issues
     * 
     * @param string $sql SQL to validate
     * @return bool True if valid, false if invalid
     */
    private static function validate_sql_syntax(string $sql): bool
    {
        // Basic validation checks
        if (empty($sql)) {
            return false;
        }

        // Check for balanced parentheses in INSERT statements
        $open_parens = substr_count($sql, '(');
        $close_parens = substr_count($sql, ')');
        if ($open_parens !== $close_parens) {
            return false;
        }

        // Check for properly terminated statements
        if (!preg_match('/;\s*$/', trim($sql))) {
            return false;
        }

        // Check for INSERT statement format
        if (!preg_match('/INSERT\s+INTO\s+`[\w]+`\s+VALUES/i', $sql)) {
            return false;
        }

        // Check for obviously malformed data (e.g., unterminated quotes)
        $quote_count = substr_count($sql, "'");
        if ($quote_count % 2 !== 0) {
            return false;
        }

        return true;
    }
}
