<?php

namespace WPEasyMigrate;

/**
 * Exporter Class
 * 
 * Handles exporting WordPress sites including database, files, and creating manifests
 */
class Exporter {
    
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
    public function __construct() {
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
    public function export_site(array $options = []): string {
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
            
        } catch (Exception $e) {
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
    private function export_database(string $export_dir): string {
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
                        if (is_null($value)) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = "'" . $wpdb->_real_escape($value) . "'";
                        }
                    }
                    $sql_content .= "INSERT INTO `{$table_name}` VALUES (" . implode(', ', $values) . ");\n";
                }
                $sql_content .= "\n";
            }
        }
        
        file_put_contents($db_file, $sql_content);
        
        if (!file_exists($db_file)) {
            throw new Exception('Failed to create database dump');
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
    private function export_uploads(string $export_dir, array $exclude_patterns = []): ?string {
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
    private function export_plugins(string $export_dir): ?string {
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
    private function export_themes(string $export_dir): ?string {
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
    private function create_archive(string $source_dir, string $archive_path): string {
        if (!class_exists('ZipArchive')) {
            throw new Exception('ZipArchive class not available');
        }
        
        $zip = new \ZipArchive();
        $result = $zip->open($archive_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        
        if ($result !== TRUE) {
            throw new Exception("Cannot create archive: {$archive_path} (Error: {$result})");
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
            throw new Exception("Failed to create archive: {$archive_path}");
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
    private function create_filtered_archive(string $source_dir, string $archive_path, array $exclude_patterns = []): string {
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
    public function create_manifest(array $info): string {
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
    private function get_mysql_version(): string {
        global $wpdb;
        return $wpdb->get_var("SELECT VERSION()");
    }
    
    /**
     * Count files in directory
     * 
     * @param string $dir Directory path
     * @return int File count
     */
    private function count_exported_files(string $dir): int {
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
    private function get_directory_size(string $dir): int {
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
    private function cleanup_directory(string $dir): void {
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
}