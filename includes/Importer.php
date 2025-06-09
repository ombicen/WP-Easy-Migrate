<?php

namespace WPEasyMigrate;

/**
 * Importer Class
 * 
 * Handles importing WordPress sites from exported archives
 */
class Importer {
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Archiver instance
     */
    private $archiver;
    
    /**
     * Import directory
     */
    private $import_dir;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new Logger();
        $this->archiver = new Archiver();
        $this->import_dir = WP_EASY_MIGRATE_UPLOADS_DIR . 'imports/';
    }
    
    /**
     * Import WordPress site from archive
     * 
     * @param array|string $import_data Upload file data or folder path
     * @return bool Success status
     * @throws Exception
     */
    public function import_site($import_data): bool {
        $this->logger->log('Starting site import', 'info');
        
        try {
            // Handle different import data types
            if (is_array($import_data)) {
                // File upload
                $archive_path = $this->handle_file_upload($import_data);
            } else {
                // Direct path
                $archive_path = $import_data;
            }
            
            // Create unique import directory
            $import_id = date('Y-m-d_H-i-s') . '_' . wp_generate_password(8, false);
            $current_import_dir = $this->import_dir . $import_id . '/';
            wp_mkdir_p($current_import_dir);
            
            $this->logger->log("Import directory created: {$current_import_dir}", 'info');
            
            // Extract archive
            $extracted_dir = $this->extract_archive($archive_path, $current_import_dir);
            
            // Read and validate manifest
            $manifest = $this->read_manifest($extracted_dir);
            $this->validate_manifest($manifest);
            
            // Check compatibility
            $compatibility = new CompatibilityChecker();
            $compat_results = $compatibility->check($manifest);
            
            if (!$compat_results['compatible']) {
                throw new Exception('Import compatibility check failed: ' . implode(', ', $compat_results['errors']));
            }
            
            if (!empty($compat_results['warnings'])) {
                foreach ($compat_results['warnings'] as $warning) {
                    $this->logger->log("Compatibility warning: {$warning}", 'warning');
                }
            }
            
            // Backup current site (optional but recommended)
            $this->create_backup_before_import($current_import_dir);
            
            // Import database
            if (file_exists($extracted_dir . '/database.sql')) {
                $this->restore_database($extracted_dir . '/database.sql');
                $this->logger->log('Database imported successfully', 'info');
            }
            
            // Import files
            $files_imported = [];
            
            if (file_exists($extracted_dir . '/uploads.zip')) {
                $this->restore_files($extracted_dir . '/uploads.zip', wp_upload_dir()['basedir']);
                $files_imported[] = 'uploads';
                $this->logger->log('Uploads imported successfully', 'info');
            }
            
            if (file_exists($extracted_dir . '/plugins.zip')) {
                $this->restore_files($extracted_dir . '/plugins.zip', WP_PLUGIN_DIR);
                $files_imported[] = 'plugins';
                $this->logger->log('Plugins imported successfully', 'info');
            }
            
            if (file_exists($extracted_dir . '/themes.zip')) {
                $this->restore_files($extracted_dir . '/themes.zip', get_theme_root());
                $files_imported[] = 'themes';
                $this->logger->log('Themes imported successfully', 'info');
            }
            
            // Update site URLs if needed
            $this->update_site_urls($manifest);
            
            // Clean up temporary files
            $this->cleanup_directory($current_import_dir);
            
            // Clear caches
            $this->clear_caches();
            
            $this->logger->log('Site import completed successfully', 'info');
            return true;
            
        } catch (Exception $e) {
            $this->logger->log("Import failed: " . $e->getMessage(), 'error');
            throw $e;
        }
    }
    
    /**
     * Handle file upload
     * 
     * @param array $file_data Upload file data
     * @return string Path to uploaded file
     * @throws Exception
     */
    private function handle_file_upload(array $file_data): string {
        if (!isset($file_data['tmp_name']) || !is_uploaded_file($file_data['tmp_name'])) {
            throw new Exception('Invalid file upload');
        }
        
        if ($file_data['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error: ' . $file_data['error']);
        }
        
        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/wp-easy-migrate/imports/';
        wp_mkdir_p($target_dir);
        
        $filename = sanitize_file_name($file_data['name']);
        $target_path = $target_dir . $filename;
        
        if (!move_uploaded_file($file_data['tmp_name'], $target_path)) {
            throw new Exception('Failed to move uploaded file');
        }
        
        $this->logger->log("File uploaded: {$target_path}", 'info');
        return $target_path;
    }
    
    /**
     * Extract archive to directory
     * 
     * @param string $archive_path Path to archive file
     * @param string $extract_dir Directory to extract to
     * @return string Path to extracted directory
     * @throws Exception
     */
    private function extract_archive(string $archive_path, string $extract_dir): string {
        if (!file_exists($archive_path)) {
            throw new Exception("Archive file not found: {$archive_path}");
        }
        
        // Check if this is a split archive
        if (preg_match('/\.part\d+\.zip$/', $archive_path)) {
            return $this->extract_split_archive($archive_path, $extract_dir);
        }
        
        // Extract single archive
        $zip = new \ZipArchive();
        $result = $zip->open($archive_path);
        
        if ($result !== TRUE) {
            throw new Exception("Cannot open archive: {$archive_path} (Error: {$result})");
        }
        
        $extracted_path = $extract_dir . 'extracted/';
        wp_mkdir_p($extracted_path);
        
        if (!$zip->extractTo($extracted_path)) {
            $zip->close();
            throw new Exception("Failed to extract archive: {$archive_path}");
        }
        
        $zip->close();
        
        $this->logger->log("Archive extracted: {$archive_path} -> {$extracted_path}", 'info');
        return $extracted_path;
    }
    
    /**
     * Extract split archive
     * 
     * @param string $first_part_path Path to first part
     * @param string $extract_dir Directory to extract to
     * @return string Path to extracted directory
     * @throws Exception
     */
    private function extract_split_archive(string $first_part_path, string $extract_dir): string {
        // Find all parts
        $base_pattern = preg_replace('/\.part\d+\.zip$/', '.part*.zip', $first_part_path);
        $parts = glob($base_pattern);
        
        if (empty($parts)) {
            throw new Exception('No archive parts found');
        }
        
        // Verify parts
        if (!$this->archiver->verify_parts($parts)) {
            throw new Exception('Archive parts verification failed');
        }
        
        // Combine parts
        $combined_path = $extract_dir . 'combined.zip';
        $this->archiver->combine_parts($parts, $combined_path);
        
        // Extract combined archive
        return $this->extract_archive($combined_path, $extract_dir);
    }
    
    /**
     * Read manifest file
     * 
     * @param string $extracted_dir Extracted directory path
     * @return array Manifest data
     * @throws Exception
     */
    private function read_manifest(string $extracted_dir): array {
        $manifest_path = $extracted_dir . '/manifest.json';
        
        if (!file_exists($manifest_path)) {
            throw new Exception('Manifest file not found in archive');
        }
        
        $manifest_content = file_get_contents($manifest_path);
        $manifest = json_decode($manifest_content, true);
        
        if (!$manifest) {
            throw new Exception('Invalid manifest file');
        }
        
        $this->logger->log('Manifest loaded successfully', 'info');
        return $manifest;
    }
    
    /**
     * Validate manifest data
     * 
     * @param array $manifest Manifest data
     * @throws Exception
     */
    private function validate_manifest(array $manifest): void {
        $required_fields = ['version', 'generator', 'export_id', 'site_info', 'export_info'];
        
        foreach ($required_fields as $field) {
            if (!isset($manifest[$field])) {
                throw new Exception("Missing required manifest field: {$field}");
            }
        }
        
        if ($manifest['generator'] !== 'WP Easy Migrate') {
            throw new Exception('Archive was not created by WP Easy Migrate');
        }
        
        $this->logger->log('Manifest validation passed', 'info');
    }
    
    /**
     * Restore database from SQL file
     * 
     * @param string $db_path Path to database SQL file
     * @return bool Success status
     * @throws Exception
     */
    public function restore_database(string $db_path): bool {
        global $wpdb;
        
        if (!file_exists($db_path)) {
            throw new Exception("Database file not found: {$db_path}");
        }
        
        $this->logger->log("Starting database restore: {$db_path}", 'info');
        
        // Read SQL file
        $sql_content = file_get_contents($db_path);
        if (!$sql_content) {
            throw new Exception('Failed to read database file');
        }
        
        // Split into individual queries
        $queries = $this->split_sql_queries($sql_content);
        
        $this->logger->log("Processing " . count($queries) . " database queries", 'info');
        
        // Disable foreign key checks
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        
        try {
            foreach ($queries as $index => $query) {
                $query = trim($query);
                
                if (empty($query) || strpos($query, '--') === 0) {
                    continue; // Skip empty queries and comments
                }
                
                $result = $wpdb->query($query);
                
                if ($result === false) {
                    $this->logger->log("Query failed at index {$index}: " . $wpdb->last_error, 'error');
                    throw new Exception("Database query failed: " . $wpdb->last_error);
                }
                
                // Log progress every 100 queries
                if (($index + 1) % 100 === 0) {
                    $this->logger->log("Processed " . ($index + 1) . " queries", 'info');
                }
            }
        } finally {
            // Re-enable foreign key checks
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        
        $this->logger->log('Database restore completed', 'info');
        return true;
    }
    
    /**
     * Restore files from archive
     * 
     * @param string $archive_path Path to files archive
     * @param string $target_dir Target directory
     * @return bool Success status
     * @throws Exception
     */
    public function restore_files(string $archive_path, string $target_dir): bool {
        if (!file_exists($archive_path)) {
            throw new Exception("Files archive not found: {$archive_path}");
        }
        
        $this->logger->log("Restoring files: {$archive_path} -> {$target_dir}", 'info');
        
        $zip = new \ZipArchive();
        $result = $zip->open($archive_path);
        
        if ($result !== TRUE) {
            throw new Exception("Cannot open files archive: {$archive_path} (Error: {$result})");
        }
        
        // Create target directory if it doesn't exist
        if (!is_dir($target_dir)) {
            wp_mkdir_p($target_dir);
        }
        
        // Extract files
        if (!$zip->extractTo($target_dir)) {
            $zip->close();
            throw new Exception("Failed to extract files to: {$target_dir}");
        }
        
        $zip->close();
        
        $this->logger->log("Files restored successfully to: {$target_dir}", 'info');
        return true;
    }
    
    /**
     * Update site URLs after import
     * 
     * @param array $manifest Manifest data
     */
    private function update_site_urls(array $manifest): void {
        $old_url = $manifest['site_info']['url'];
        $new_url = get_site_url();
        
        if ($old_url === $new_url) {
            $this->logger->log('Site URLs match, no update needed', 'info');
            return;
        }
        
        $this->logger->log("Updating site URLs: {$old_url} -> {$new_url}", 'info');
        
        global $wpdb;
        
        // Update options table
        $wpdb->update(
            $wpdb->options,
            ['option_value' => $new_url],
            ['option_name' => 'home']
        );
        
        $wpdb->update(
            $wpdb->options,
            ['option_value' => $new_url],
            ['option_name' => 'siteurl']
        );
        
        // Update content URLs
        $wpdb->query($wpdb->prepare("
            UPDATE {$wpdb->posts} 
            SET post_content = REPLACE(post_content, %s, %s)
        ", $old_url, $new_url));
        
        $wpdb->query($wpdb->prepare("
            UPDATE {$wpdb->comments} 
            SET comment_content = REPLACE(comment_content, %s, %s)
        ", $old_url, $new_url));
        
        // Update metadata
        $wpdb->query($wpdb->prepare("
            UPDATE {$wpdb->postmeta} 
            SET meta_value = REPLACE(meta_value, %s, %s)
        ", $old_url, $new_url));
        
        $wpdb->query($wpdb->prepare("
            UPDATE {$wpdb->options} 
            SET option_value = REPLACE(option_value, %s, %s)
        ", $old_url, $new_url));
        
        $this->logger->log('Site URLs updated successfully', 'info');
    }
    
    /**
     * Create backup before import
     * 
     * @param string $backup_dir Backup directory
     */
    private function create_backup_before_import(string $backup_dir): void {
        $this->logger->log('Creating backup before import', 'info');
        
        try {
            $exporter = new Exporter();
            $backup_path = $exporter->export_site([
                'include_uploads' => false, // Skip uploads for faster backup
                'include_plugins' => false,
                'include_themes' => false,
                'include_database' => true,
                'split_size' => 0 // Don't split backup
            ]);
            
            // Move backup to import directory
            $backup_filename = 'pre-import-backup-' . date('Y-m-d-H-i-s') . '.zip';
            $final_backup_path = $backup_dir . $backup_filename;
            rename($backup_path, $final_backup_path);
            
            $this->logger->log("Pre-import backup created: {$final_backup_path}", 'info');
            
        } catch (Exception $e) {
            $this->logger->log("Failed to create pre-import backup: " . $e->getMessage(), 'warning');
        }
    }
    
    /**
     * Split SQL content into individual queries
     * 
     * @param string $sql_content SQL content
     * @return array Array of SQL queries
     */
    private function split_sql_queries(string $sql_content): array {
        // Remove comments and split by semicolon
        $sql_content = preg_replace('/^--.*$/m', '', $sql_content);
        $queries = explode(';', $sql_content);
        
        return array_filter($queries, function($query) {
            return !empty(trim($query));
        });
    }
    
    /**
     * Clear WordPress caches
     */
    private function clear_caches(): void {
        // Clear WordPress object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        // Clear rewrite rules
        flush_rewrite_rules();
        
        // Clear any plugin caches
        do_action('wp_easy_migrate_clear_caches');
        
        $this->logger->log('Caches cleared', 'info');
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
        $this->logger->log("Cleaned up directory: {$dir}", 'info');
    }
}