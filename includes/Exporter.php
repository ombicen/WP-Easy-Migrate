<?php

namespace WPEasyMigrate;

use WPEasyMigrate\Logger;
use WPEasyMigrate\Archiver;
use WP_Easy_Migrate\Export\DatabaseExporter;
use WP_Easy_Migrate\Export\FileArchiver;
use WP_Easy_Migrate\Export\ManifestBuilder;
use WP_Easy_Migrate\Export\DirectoryScanner;
use WP_Easy_Migrate\Export\PathSanitizer;

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
     * Database exporter instance
     */
    private $databaseExporter;

    /**
     * File archiver instance
     */
    private $fileArchiver;

    /**
     * Manifest builder instance
     */
    private $manifestBuilder;

    /**
     * Directory scanner instance
     */
    private $directoryScanner;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->logger = new Logger();
        $this->archiver = new Archiver();
        $this->databaseExporter = new DatabaseExporter($this->logger);
        $this->fileArchiver = new FileArchiver($this->logger);
        $this->manifestBuilder = new ManifestBuilder();
        $this->directoryScanner = new DirectoryScanner();

        // Initialize export directory
        $this->export_dir = WP_EASY_MIGRATE_UPLOADS_DIR . 'temp/';
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
                $db_file = $this->databaseExporter->export($current_export_dir);
                $this->logger->log("Database exported: {$db_file}", 'info');
            }

            // Export files
            $files_exported = [];

            if ($options['include_uploads']) {
                $uploads_file = $this->fileArchiver->archiveUploads($current_export_dir, $options['exclude_patterns']);
                if ($uploads_file) {
                    $files_exported['uploads'] = $uploads_file;
                    $this->logger->log("Uploads exported: {$uploads_file}", 'info');
                }
            }

            if ($options['include_plugins']) {
                $plugins_file = $this->fileArchiver->archivePlugins($current_export_dir);
                if ($plugins_file) {
                    $files_exported['plugins'] = $plugins_file;
                    $this->logger->log("Plugins exported: {$plugins_file}", 'info');
                }
            }

            if ($options['include_themes']) {
                $themes_file = $this->fileArchiver->archiveThemes($current_export_dir);
                if ($themes_file) {
                    $files_exported['themes'] = $themes_file;
                    $this->logger->log("Themes exported: {$themes_file}", 'info');
                }
            }

            // Create manifest
            $manifest_data = $this->manifestBuilder->build([
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
            $exports_dir = WP_EASY_MIGRATE_UPLOADS_DIR . 'exports/';
            wp_mkdir_p($exports_dir);
            $archive_path = $this->fileArchiver->archiveExport($current_export_dir, $exports_dir, $export_id);

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
     * Export database in chunks (static method for ExportController compatibility)
     * 
     * @param ExportSession $session Export session
     * @return array Result with message
     * @throws \Exception
     */
    public static function export_database_chunk(ExportSession $session): array
    {
        $logger = new Logger();
        $databaseExporter = new DatabaseExporter($logger);

        return $databaseExporter->exportChunk($session);
    }

    /**
     * Archive next batch of files (static method for ExportController compatibility)
     * 
     * @param ExportSession $session Export session
     * @return array Result with message
     * @throws \Exception
     */
    public static function archive_next_batch(ExportSession $session): array
    {
        $logger = new Logger();
        $fileArchiver = new FileArchiver($logger);

        return $fileArchiver->archiveNextBatch($session);
    }

    /**
     * Create manifest (instance method for ExportController compatibility)
     * 
     * @param array $data Manifest data
     * @return string JSON manifest
     */
    public function create_manifest(array $data): string
    {
        return $this->manifestBuilder->build($data);
    }
}
