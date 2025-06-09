<?php

namespace WPEasyMigrate;

/**
 * ExportController Class
 * 
 * Handles step-based export process with AJAX polling
 */
class ExportController
{

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Exporter instance
     */
    private $exporter;

    /**
     * Archiver instance
     */
    private $archiver;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->logger = new Logger();
        $this->exporter = new Exporter();
        $this->archiver = new Archiver();
    }

    /**
     * Handle export step AJAX request
     */
    public function handle_export_step(): void
    {
        // Verify nonce and capabilities
        if (!check_ajax_referer('wp_easy_migrate_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed', 'wp-easy-migrate')]);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'wp-easy-migrate')]);
        }

        try {
            $session = new ExportSession();

            // Check if this is a new export request
            if (isset($_POST['start_export']) && $_POST['start_export']) {
                $options = $this->parse_export_options($_POST);
                $session->start($options);
                $this->logger->log('New export session started', 'info');
            }

            // Load current session
            $session->load();

            if (!$session->is_active()) {
                if ($session->has_error()) {
                    wp_send_json_error([
                        'message' => $session->get_error(),
                        'status' => $session->get_status()
                    ]);
                } elseif ($session->is_completed()) {
                    wp_send_json_success([
                        'message' => __('Export completed successfully!', 'wp-easy-migrate'),
                        'status' => $session->get_status()
                    ]);
                } else {
                    wp_send_json_error(['message' => __('No active export session', 'wp-easy-migrate')]);
                }
                return;
            }

            // Execute current step
            $this->execute_step($session);

            // Return updated status
            wp_send_json_success([
                'message' => $this->get_step_message($session->get_current_step()),
                'status' => $session->get_status()
            ]);
        } catch (Exception $e) {
            $this->logger->log('Export step error: ' . $e->getMessage(), 'error');

            // Update session with error
            if (isset($session)) {
                $session->set_error($e->getMessage());
            }

            wp_send_json_error([
                'message' => $e->getMessage(),
                'status' => isset($session) ? $session->get_status() : null
            ]);
        }
    }

    /**
     * Execute current export step
     * 
     * @param ExportSession $session Export session
     * @throws Exception
     */
    private function execute_step(ExportSession $session): void
    {
        $step = $session->get_current_step();
        $this->logger->log("Executing export step: {$step}", 'info');

        switch ($step) {
            case 'prepare_export':
                $this->prepare_export($session);
                break;

            case 'scan_files':
                $this->scan_files($session);
                break;

            case 'export_database':
                // Handle database export in chunks
                $options = $session->get_options();
                if (!$options['include_database']) {
                    // Skip database export if not included
                    $this->logger->log('Database export skipped', 'info');
                } elseif (!$session->is_database_export_complete()) {
                    $result = Exporter::export_database_chunk($session);

                    // Return immediately for database export step without moving to next step
                    wp_send_json_success([
                        'message' => $result['message'],
                        'status' => $session->get_enhanced_status_with_db()
                    ]);
                    return;
                } else {
                    // Database export is complete
                    $this->logger->log('Database export completed', 'info');
                }
                // Database export is complete or skipped, proceed to next step
                break;

            case 'archive_files':
                // Handle batch file archiving
                if (!$session->is_archiving_complete()) {
                    $result = Exporter::archive_next_batch($session);

                    // Return immediately for file archiving step without moving to next step
                    wp_send_json_success([
                        'message' => $result['message'],
                        'status' => $session->get_enhanced_status_with_db()
                    ]);
                    return;
                }
                // File archiving is complete, proceed to move to next step
                break;

            case 'create_manifest':
                $this->create_manifest($session);
                break;

            case 'split_archive':
                $this->split_archive($session);
                break;

            case 'finalize_export':
                $this->finalize_export($session);
                break;

            default:
                throw new Exception("Unknown export step: {$step}");
        }

        // Move to next step
        // export_database and archive_files only skip progression when they return early
        $session->next_step();
    }

    /**
     * Prepare export directory and initial setup
     * 
     * @param ExportSession $session Export session
     * @throws Exception
     */
    private function prepare_export(ExportSession $session): void
    {
        $export_dir = $session->get_export_dir();

        if (!wp_mkdir_p($export_dir)) {
            throw new Exception("Failed to create export directory: {$export_dir}");
        }

        // Set up archive path
        $export_id = $session->get_export_id();
        $archive_path = WP_EASY_MIGRATE_UPLOADS_DIR . "exports/wp-export-{$export_id}.zip";
        $session->set_archive_path($archive_path);

        $this->logger->log("Export directory prepared: {$export_dir}", 'info');
    }

    /**
     * Scan files for archiving
     * 
     * @param ExportSession $session Export session
     * @throws Exception
     */
    private function scan_files(ExportSession $session): void
    {
        $options = $session->get_options();
        $root_path = $options['export_path'] ?? ABSPATH; // export root folder

        $fileList = [];
        $fileSizes = [];
        $relativePaths = [];

        $excluded_dirs = ['node_modules', 'vendor', '.git', 'cache', 'uploads/cache']; // Customize exclude list
        $excluded_extensions = ['tmp', 'log', 'bak']; // skip temp files

        $directoryIterator = new \RecursiveDirectoryIterator(
            $root_path,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
        );
        $iterator = new \RecursiveIteratorIterator($directoryIterator);

        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isFile()) {
                continue;
            }

            $filePath = $fileinfo->getPathname();
            $relativePath = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($filePath, strlen($root_path))), '/');

            // Skip excluded directories
            foreach ($excluded_dirs as $dir) {
                if (stripos($filePath, DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR) !== false) {
                    continue 2;
                }
            }

            // Skip excluded extensions
            $ext = strtolower($fileinfo->getExtension());
            if (in_array($ext, $excluded_extensions, true)) {
                continue;
            }

            $fileList[] = $filePath;
            $fileSizes[] = $fileinfo->getSize();
            $relativePaths[] = $relativePath;
        }

        $session->set_file_list($fileList, $fileSizes, $relativePaths);

        if (isset($options['files_per_step'])) {
            $session->set_files_per_step($options['files_per_step']);
        }

        $this->logger->log("File scan completed: " . count($fileList) . " files, " . size_format(array_sum($fileSizes)), 'info');
    }




    /**
     * Create manifest file
     * 
     * @param ExportSession $session Export session
     * @throws Exception
     */
    private function create_manifest(ExportSession $session): void
    {
        $options = $session->get_options();

        $manifest_data = $this->exporter->create_manifest([
            'export_id' => $session->get_export_id(),
            'site_url' => get_site_url(),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'mysql_version' => $this->get_mysql_version(),
            'options' => $options,
            'files' => [], // Files are now in the main archive
            'export_date' => current_time('mysql'),
            'file_count' => $session->get_total_files(),
            'total_size' => $session->get_total_size()
        ]);

        // Add manifest to the main archive
        $archive_path = $session->get_archive_path();
        $zip = new \ZipArchive();
        $result = $zip->open($archive_path, \ZipArchive::CREATE);

        if ($result !== TRUE) {
            throw new Exception("Cannot open archive for manifest: {$archive_path} (Error: {$result})");
        }

        $zip->addFromString('manifest.json', $manifest_data);
        $zip->close();

        $this->logger->log("Manifest added to archive", 'info');
    }


    /**
     * Split archive if needed
     * 
     * @param ExportSession $session Export session
     * @throws Exception
     */
    private function split_archive(ExportSession $session): void
    {
        $options = $session->get_options();
        $archive_path = $session->get_archive_path();

        if ($options['split_size'] <= 0 || !$archive_path || !file_exists($archive_path)) {
            $this->logger->log('Archive splitting skipped', 'info');
            return;
        }

        $archive_size_mb = filesize($archive_path) / (1024 * 1024);

        if ($archive_size_mb <= $options['split_size']) {
            $this->logger->log("Archive size ({$archive_size_mb}MB) is within limit", 'info');
            return;
        }

        $this->logger->log("Splitting archive: {$archive_size_mb}MB > {$options['split_size']}MB", 'info');

        $parts = $this->archiver->split_archive($archive_path, $options['split_size']);

        // Remove original large archive
        unlink($archive_path);

        // Update session with first part path
        $session->set_archive_path($parts[0]);
        $session->set_step_data('archive_parts', $parts);

        $this->logger->log("Archive split into " . count($parts) . " parts", 'info');
    }

    /**
     * Finalize export process
     * 
     * @param ExportSession $session Export session
     * @throws Exception
     */
    private function finalize_export(ExportSession $session): void
    {
        $export_dir = $session->get_export_dir();

        // Add database to archive if it exists
        $db_file = $export_dir . 'database.sql';
        if (file_exists($db_file)) {
            $archive_path = $session->get_archive_path();
            $zip = new \ZipArchive();
            $result = $zip->open($archive_path, \ZipArchive::CREATE);

            if ($result === TRUE) {
                $zip->addFile($db_file, 'database.sql');
                $zip->close();
                $this->logger->log("Database added to archive", 'info');
            }
        }

        // Clean up temporary directory
        $this->cleanup_directory($export_dir);

        $this->logger->log("Export finalized: {$session->get_export_id()}", 'info');
    }

    /**
     * Parse export options from POST data
     * 
     * @param array $post_data POST data
     * @return array Parsed options
     */
    private function parse_export_options(array $post_data): array
    {
        return [
            'include_uploads' => isset($post_data['include_uploads']) ? (bool) $post_data['include_uploads'] : true,
            'include_plugins' => isset($post_data['include_plugins']) ? (bool) $post_data['include_plugins'] : true,
            'include_themes' => isset($post_data['include_themes']) ? (bool) $post_data['include_themes'] : true,
            'include_database' => isset($post_data['include_database']) ? (bool) $post_data['include_database'] : true,
            'split_size' => isset($post_data['split_size']) ? (int) $post_data['split_size'] : 100,
            'files_per_step' => isset($post_data['files_per_step']) ? (int) $post_data['files_per_step'] : 10,
            'exclude_patterns' => [
                '*.log',
                '*/cache/*',
                '*/wp-easy-migrate/*'
            ]
        ];
    }

    /**
     * Get step message for UI
     * 
     * @param string $step Step name
     * @return string Step message
     */
    private function get_step_message(string $step): string
    {
        $messages = [
            'prepare_export' => __('Preparing export...', 'wp-easy-migrate'),
            'scan_files' => __('Scanning files...', 'wp-easy-migrate'),
            'export_database' => __('Exporting database...', 'wp-easy-migrate'),
            'archive_files' => __('Archiving files...', 'wp-easy-migrate'),
            'create_manifest' => __('Creating manifest...', 'wp-easy-migrate'),
            'split_archive' => __('Splitting archive...', 'wp-easy-migrate'),
            'finalize_export' => __('Finalizing export...', 'wp-easy-migrate')
        ];

        return $messages[$step] ?? __('Processing...', 'wp-easy-migrate');
    }

    /**
     * Create archive from directory
     * 
     * @param string $source_dir Source directory
     * @param string $archive_path Archive path
     * @throws Exception
     */
    private function create_archive_from_directory(string $source_dir, string $archive_path): void
    {
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
    }

    /**
     * Create filtered archive excluding certain patterns
     * 
     * @param string $source_dir Source directory
     * @param string $archive_path Archive path
     * @param array $exclude_patterns Patterns to exclude
     * @throws Exception
     */
    private function create_filtered_archive(string $source_dir, string $archive_path, array $exclude_patterns = []): void
    {
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
    }

    /**
     * Helper methods
     */
    private function get_mysql_version(): string
    {
        global $wpdb;
        return $wpdb->get_var("SELECT VERSION()");
    }

    private function count_exported_files(string $dir): int
    {
        $count = 0;
        if (is_dir($dir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function get_directory_size(string $dir): int
    {
        $size = 0;
        if (is_dir($dir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        }
        return $size;
    }

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
}
