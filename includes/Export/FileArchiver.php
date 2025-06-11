<?php

namespace WP_Easy_Migrate\Export;

use WPEasyMigrate\Logger;

class FileArchiver
{
    private $logger;
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }
    public function archiveUploads(string $export_dir, array $exclude_patterns = []): ?string
    {
        $uploads_dir = wp_upload_dir()['basedir'];
        if (!is_dir($uploads_dir)) {
            $this->logger->log('Uploads directory not found', 'warning');
            return null;
        }
        $uploads_archive = $export_dir . 'uploads.zip';
        return $this->createFilteredArchive($uploads_dir, $uploads_archive, $exclude_patterns);
    }
    public function archivePlugins(string $export_dir): ?string
    {
        $plugins_dir = WP_PLUGIN_DIR;
        if (!is_dir($plugins_dir)) {
            $this->logger->log('Plugins directory not found', 'warning');
            return null;
        }
        $plugins_archive = $export_dir . 'plugins.zip';
        return $this->createArchive($plugins_dir, $plugins_archive);
    }
    public function archiveThemes(string $export_dir): ?string
    {
        $themes_dir = get_theme_root();
        if (!is_dir($themes_dir)) {
            $this->logger->log('Themes directory not found', 'warning');
            return null;
        }
        $themes_archive = $export_dir . 'themes.zip';
        return $this->createArchive($themes_dir, $themes_archive);
    }
    public function archiveExport(string $current_export_dir, string $export_dir, string $export_id): string
    {
        $archive_path = $export_dir . "wp-export-{$export_id}.zip";
        return $this->createArchive($current_export_dir, $archive_path);
    }
    private function createArchive(string $source_dir, string $archive_path): string
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
            $sanitized_relative_path = \WP_Easy_Migrate\Export\PathSanitizer::sanitize($relative_path);
            if ($file->isDir()) {
                $zip->addEmptyDir($sanitized_relative_path);
            } elseif ($file->isFile()) {
                $zip->addFile($file_path, $sanitized_relative_path);
            }
        }
        $zip->close();
        if (!file_exists($archive_path)) {
            throw new \Exception("Failed to create archive: {$archive_path}");
        }
        return $archive_path;
    }
    private function createFilteredArchive(string $source_dir, string $archive_path, array $exclude_patterns = []): string
    {
        if (!class_exists('ZipArchive')) {
            throw new \Exception('ZipArchive class is not available. Please ensure the PHP zip extension is installed.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($archive_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \Exception("Failed to open archive for writing: {$archive_path}");
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            $file_path = $file->getRealPath();
            $relative_path = substr($file_path, strlen($source_dir) + 1);
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
            $sanitized_relative_path = \WP_Easy_Migrate\Export\PathSanitizer::sanitize($relative_path);
            if ($file->isDir()) {
                $zip->addEmptyDir($sanitized_relative_path);
            } elseif ($file->isFile()) {
                $zip->addFile($file_path, $sanitized_relative_path);
            }
        }
        $zip->close();
        if (!file_exists($archive_path)) {
            throw new \Exception("Failed to create archive: {$archive_path}");
        }
        return $archive_path;
    }
}
