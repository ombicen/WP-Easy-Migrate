<?php

namespace WPEasyMigrate;

use WP_Easy_Migrate\Export\DatabaseExporter;
use WP_Easy_Migrate\Export\FileArchiver;
use WP_Easy_Migrate\Export\ManifestBuilder;

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
     * Exporter instance (for compatibility)
     */
    private $exporter;

    /**
     * Archiver instance
     */
    private $archiver;

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
     * Constructor
     */
    public function __construct()
    {
        $this->logger = new Logger();
        $this->exporter = new Exporter();
        $this->archiver = new Archiver();
        $this->databaseExporter = new DatabaseExporter($this->logger);
        $this->fileArchiver = new FileArchiver($this->logger);
        $this->manifestBuilder = new ManifestBuilder();
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
                $validated_post = $this->validate_post_data($_POST);
                $options = $this->parse_export_options($validated_post);
                $session->start($options);
                $this->logger->log('New export session started', 'info');
            }

            // Load current session
            $session->load();

            // Debug session state
            $this->logger->log("Session loaded - Current step: " . $session->get_current_step(), 'debug');
            $this->logger->log("Session loaded - Export ID: " . $session->get_export_id(), 'debug');
            $this->logger->log("Session loaded - Export dir: " . $session->get_export_dir(), 'debug');
            $this->logger->log("Session loaded - DB export path: " . $session->get_db_export_path(), 'debug');

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
        } catch (\Exception $e) {
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
     * @throws \Exception
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
                $this->logger->log("Database export step - include_database: " . ($options['include_database'] ? 'YES' : 'NO'), 'info');

                if (!$options['include_database']) {
                    // Skip database export if not included
                    $this->logger->log('Database export skipped', 'info');
                } else {
                    // Check if database export has been initialized
                    $db_path = $session->get_db_export_path();
                    $db_tables = $session->get_db_tables();
                    $current_table = $session->get_current_table();

                    $this->logger->log("Database export state - Path: '{$db_path}', Tables: " . count($db_tables) . ", Current table: '{$current_table}'", 'info');

                    // If not initialized (no path set, no tables, and no current table), or export is incomplete
                    if (empty($db_path) || empty($db_tables) || !$session->is_database_export_complete()) {
                        $this->logger->log("Database export not complete, calling export_database_chunk", 'info');

                        $result = Exporter::export_database_chunk($session);

                        $db_path_after = $session->get_db_export_path();
                        $this->logger->log("Database path after chunk export: {$db_path_after}", 'info');

                        // Check if the export just completed
                        if (
                            $session->is_database_export_complete() ||
                            (isset($result['message']) && strpos($result['message'], 'Database export completed') !== false)
                        ) {
                            $this->logger->log("Database export completed, proceeding to next step", 'info');

                            // Verify the file exists
                            $db_file_path = $session->get_db_export_path();
                            if ($db_file_path && file_exists($db_file_path)) {
                                $db_size = filesize($db_file_path);
                                $this->logger->log("Database export completed successfully: " . size_format($db_size), 'info');
                            }

                            // Move to next step since database export is complete
                            $session->next_step();

                            // Return completion message to frontend
                            wp_send_json_success([
                                'message' => __('Database export completed! Moving to file archiving...', 'wp-easy-migrate'),
                                'status' => $session->get_enhanced_status_with_db(),
                                'step_completed' => true
                            ]);
                            return;
                        } else {
                            // Return immediately for database export step without moving to next step
                            wp_send_json_success([
                                'message' => $result['message'],
                                'status' => $session->get_enhanced_status_with_db()
                            ]);
                            return;
                        }
                    } else {
                        // Database export is complete, verify the file exists
                        $db_file_path = $session->get_db_export_path();
                        $this->logger->log("Database export marked as complete, checking file: {$db_file_path}", 'info');
                        if ($db_file_path && file_exists($db_file_path)) {
                            $db_size = filesize($db_file_path);
                            $this->logger->log("Database export completed successfully: " . size_format($db_size), 'info');
                        } else {
                            $this->logger->log("Warning: Database export completed but file not found: {$db_file_path}", 'warning');
                        }
                    }
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
                } else {
                    // File archiving is complete, ensure archive exists
                    $archive_path = $session->get_archive_path();
                    if (!file_exists($archive_path)) {
                        // Create empty archive if no files were processed
                        $this->logger->log("No files were archived, creating empty archive: {$archive_path}", 'info');
                        $zip = new \ZipArchive();
                        $result = $zip->open($archive_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
                        if ($result === TRUE) {
                            // Add a placeholder file to make it a valid zip
                            $zip->addFromString('.wp-easy-migrate-placeholder', 'This archive was created by WP Easy Migrate');
                            $zip->close();
                            $this->logger->log("Empty archive created successfully", 'info');
                        } else {
                            throw new \Exception("Failed to create empty archive: {$archive_path} (Error: {$result})");
                        }
                    }

                    // Verify archive can be opened for future modifications
                    $zip = new \ZipArchive();
                    $result = $zip->open($archive_path);
                    if ($result === TRUE) {
                        $zip->close();
                        $this->logger->log("Archive verified and ready for database addition", 'info');
                    } else {
                        $this->logger->log("Warning: Archive may not be properly formatted for future modifications", 'warning');
                    }
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
                throw new \Exception("Unknown export step: {$step}");
        }

        // Move to next step
        // export_database and archive_files only skip progression when they return early
        $session->next_step();
    }

    /**
     * Prepare export directory and initial setup
     * 
     * @param ExportSession $session Export session
     * @throws \Exception
     */
    private function prepare_export(ExportSession $session): void
    {
        $export_dir = $session->get_export_dir();

        if (!wp_mkdir_p($export_dir)) {
            throw new \Exception("Failed to create export directory: {$export_dir}");
        }

        // Set up archive path
        $export_id = $session->get_export_id();
        $exports_dir = WP_EASY_MIGRATE_UPLOADS_DIR . "exports/";
        $archive_path = $exports_dir . "wp-export-{$export_id}.zip";

        // Ensure exports directory exists
        if (!wp_mkdir_p($exports_dir)) {
            throw new \Exception("Failed to create exports directory: {$exports_dir}");
        }

        $session->set_archive_path($archive_path);

        $this->logger->log("Export directory prepared: {$export_dir}", 'info');
        $this->logger->log("Archive path set: {$archive_path}", 'info');
    }

    /**
     * Scan files for archiving
     * 
     * @param ExportSession $session Export session
     * @throws \Exception
     */
    private function scan_files(ExportSession $session): void
    {
        $options = $session->get_options();

        // Check if any folders are selected for export
        $has_folders_selected = $options['include_uploads'] || $options['include_plugins'] || $options['include_themes'];

        if (!$has_folders_selected) {
            $this->logger->log("No folders selected for export, skipping file scan", 'info');
            // Set empty file list
            $session->set_file_list([], [], []);
            return;
        }

        $fileList = [];
        $fileSizes = [];
        $relativePaths = [];

        $excluded_dirs = ['node_modules', 'vendor', '.git', 'cache', 'uploads/cache']; // Customize exclude list
        $excluded_extensions = ['tmp', 'log', 'bak']; // skip temp files

        // Only scan directories that are selected for export
        $directories_to_scan = [];

        if ($options['include_uploads']) {
            $uploads_dir = wp_upload_dir()['basedir'];
            if (is_dir($uploads_dir)) {
                $directories_to_scan[] = [
                    'path' => $uploads_dir,
                    'type' => 'uploads'
                ];
                $this->logger->log("Will scan uploads directory: {$uploads_dir}", 'info');
            } else {
                $this->logger->log("Uploads directory not found: {$uploads_dir}", 'warning');
            }
        }

        if ($options['include_plugins']) {
            $plugins_dir = WP_PLUGIN_DIR;
            if (is_dir($plugins_dir)) {
                $directories_to_scan[] = [
                    'path' => $plugins_dir,
                    'type' => 'plugins'
                ];
                $this->logger->log("Will scan plugins directory: {$plugins_dir}", 'info');
            } else {
                $this->logger->log("Plugins directory not found: {$plugins_dir}", 'warning');
            }
        }

        if ($options['include_themes']) {
            $themes_dir = get_theme_root();
            if (is_dir($themes_dir)) {
                $directories_to_scan[] = [
                    'path' => $themes_dir,
                    'type' => 'themes'
                ];
                $this->logger->log("Will scan themes directory: {$themes_dir}", 'info');
            } else {
                $this->logger->log("Themes directory not found: {$themes_dir}", 'warning');
            }
        }

        $this->logger->log("Total directories to scan: " . count($directories_to_scan), 'info');

        // Scan each selected directory
        foreach ($directories_to_scan as $dir_info) {
            $this->logger->log("Scanning {$dir_info['type']} directory: {$dir_info['path']}", 'info');

            $directoryIterator = new \RecursiveDirectoryIterator(
                $dir_info['path'],
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
            );
            $iterator = new \RecursiveIteratorIterator($directoryIterator);

            foreach ($iterator as $fileinfo) {
                if (!$fileinfo->isFile()) {
                    continue;
                }

                $filePath = $fileinfo->getPathname();
                $relativePath = $dir_info['type'] . '/' . ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($filePath, strlen($dir_info['path']))), '/');

                // Skip excluded directories
                $skip_file = false;
                foreach ($excluded_dirs as $dir) {
                    if (stripos($filePath, DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR) !== false) {
                        $skip_file = true;
                        break;
                    }
                }

                if ($skip_file) {
                    continue;
                }

                // Skip excluded extensions
                $ext = strtolower($fileinfo->getExtension());
                if (in_array($ext, $excluded_extensions, true)) {
                    continue;
                }

                // Apply exclude patterns from options
                $should_exclude = false;
                foreach ($options['exclude_patterns'] as $pattern) {
                    if (fnmatch($pattern, $relativePath) || fnmatch($pattern, basename($filePath))) {
                        $should_exclude = true;
                        break;
                    }
                }

                if ($should_exclude) {
                    continue;
                }

                $fileList[] = $filePath;
                $fileSizes[] = $fileinfo->getSize();
                $relativePaths[] = $relativePath;
            }
        }

        $session->set_file_list($fileList, $fileSizes, $relativePaths);

        if (isset($options['files_per_step'])) {
            $session->set_files_per_step($options['files_per_step']);
        }

        $included_types = [];
        if ($options['include_uploads']) $included_types[] = 'uploads';
        if ($options['include_plugins']) $included_types[] = 'plugins';
        if ($options['include_themes']) $included_types[] = 'themes';

        $types_text = !empty($included_types) ? '(' . implode(', ', $included_types) . ')' : '(no folders selected)';
        $this->logger->log("File scan completed: " . count($fileList) . " files, " . size_format(array_sum($fileSizes)) . " " . $types_text, 'info');
    }

    /**
     * Create manifest file
     * 
     * @param ExportSession $session Export session
     * @throws \Exception
     */
    private function create_manifest(ExportSession $session): void
    {
        $options = $session->get_options();

        // Prepare database file info for manifest
        $database_info = null;
        if ($options['include_database']) {
            $db_file_path = $session->get_db_export_path();
            if ($db_file_path && file_exists($db_file_path)) {
                $database_info = [
                    'filename' => 'database.sql',
                    'size' => filesize($db_file_path),
                    'tables_exported' => count($session->get_db_tables()),
                    'export_method' => 'chunked_export'
                ];
                $this->logger->log("Database info for manifest: " . size_format($database_info['size']) . ", {$database_info['tables_exported']} tables", 'info');
            } else {
                $this->logger->log("Database file not found for manifest: {$db_file_path}", 'warning');
            }
        }

        $manifest_data = $this->manifestBuilder->build([
            'export_id' => $session->get_export_id(),
            'site_url' => get_site_url(),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'mysql_version' => $this->get_mysql_version(),
            'options' => $options,
            'files' => [], // Files are now in the main archive
            'database' => $database_info, // Add database file info
            'export_date' => current_time('mysql'),
            'file_count' => $session->get_total_files(),
            'total_size' => $session->get_total_size()
        ]);

        // Create standalone manifest file for verification
        $export_id = $session->get_export_id();
        $exports_dir = WP_EASY_MIGRATE_UPLOADS_DIR . "exports/";
        $standalone_manifest_path = $exports_dir . "wp-export-{$export_id}-manifest.json";

        if (file_put_contents($standalone_manifest_path, $manifest_data) === false) {
            throw new \Exception("Failed to create standalone manifest file: {$standalone_manifest_path}");
        }

        // Add manifest to the existing archive (don't overwrite!)
        $archive_path = $session->get_archive_path();

        if (!file_exists($archive_path)) {
            throw new \Exception("Archive file not found when trying to add manifest: {$archive_path}");
        }

        $this->logger->log("Adding manifest to existing archive: {$archive_path}", 'info');
        $archive_size = filesize($archive_path);
        $this->logger->log("Archive size before manifest: " . size_format($archive_size), 'info');

        $zip = new \ZipArchive();

        // Try to open existing archive with default settings (read/write mode)
        $start_time = microtime(true);
        $result = $zip->open($archive_path);

        if ($result !== TRUE) {
            $error_messages = [
                \ZipArchive::ER_NOENT => 'No such file',
                \ZipArchive::ER_INCONS => 'Zip archive inconsistent',
                \ZipArchive::ER_INVAL => 'Invalid argument',
                \ZipArchive::ER_MEMORY => 'Malloc failure',
                \ZipArchive::ER_NOZIP => 'Not a zip archive',
                \ZipArchive::ER_OPEN => 'Can\'t open file',
                \ZipArchive::ER_READ => 'Read error',
                \ZipArchive::ER_SEEK => 'Seek error'
            ];

            $error_msg = $error_messages[$result] ?? "Unknown error code: {$result}";
            throw new \Exception("Cannot open existing archive for manifest: {$archive_path} (Error {$result}: {$error_msg})");
        }

        $open_time = microtime(true) - $start_time;
        $this->logger->log("Archive opened successfully for manifest addition in " . round($open_time, 3) . "s", 'info');

        try {
            // Check if manifest already exists to avoid duplicates
            if ($zip->locateName('manifest.json') === false) {
                if ($zip->addFromString('manifest.json', $manifest_data)) {
                    $this->logger->log("Manifest added to existing archive successfully", 'info');
                } else {
                    throw new \Exception("Failed to add manifest data to archive");
                }
            } else {
                $this->logger->log("Manifest already exists in archive, skipping", 'info');
            }

            // Close the archive
            if (!$zip->close()) {
                throw new \Exception("Failed to close archive after adding manifest");
            }

            $this->logger->log("Archive closed successfully after manifest addition", 'info');
        } catch (\Exception $e) {
            // Make sure to close the zip handle even if there's an error
            $zip->close();
            throw $e;
        }

        // Verify the manifest was added
        if (file_exists($archive_path)) {
            $verify_zip = new \ZipArchive();
            if ($verify_zip->open($archive_path, \ZipArchive::RDONLY) === TRUE) {
                $has_manifest = $verify_zip->locateName('manifest.json') !== false;
                $verify_zip->close();

                if ($has_manifest) {
                    $this->logger->log("✓ Manifest verified in archive", 'info');
                } else {
                    $this->logger->log("✗ Manifest not found in archive after addition", 'error');
                }
            }
        }

        // Store standalone manifest path in session for cleanup
        $session->set_step_data('standalone_manifest_path', $standalone_manifest_path);

        $this->logger->log("Manifest creation completed", 'info');
    }

    /**
     * Split archive if needed
     * 
     * @param ExportSession $session Export session
     * @throws \Exception
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
     * @throws \Exception
     */
    private function finalize_export(ExportSession $session): void
    {
        $export_dir = $session->get_export_dir();
        $options = $session->get_options();
        $archive_path = $session->get_archive_path();

        $this->logger->log("Finalizing export - Export Dir: {$export_dir}, Archive: {$archive_path}", 'info');

        // Add database to archive if it exists and was requested - DO THIS BEFORE CLEANUP!
        if ($options['include_database']) {
            $this->logger->log("Database export is enabled in options", 'info');

            // Get database file path from session (this is the correct location)
            $db_file_from_session = $session->get_db_export_path();

            $this->logger->log("Session database path: {$db_file_from_session}", 'info');

            // Check if database file exists
            if ($db_file_from_session && file_exists($db_file_from_session)) {
                $db_size = filesize($db_file_from_session);
                $this->logger->log("Database file found, size: " . size_format($db_size), 'info');

                if (!file_exists($archive_path)) {
                    throw new \Exception("Archive file not found when trying to add database: {$archive_path}");
                }

                $zip = new \ZipArchive();

                // Open existing archive to append database
                $result = $zip->open($archive_path);

                if ($result === TRUE) {
                    // Check if database.sql already exists in the archive
                    if ($zip->locateName('database.sql') === false) {
                        if ($zip->addFile($db_file_from_session, 'database.sql')) {
                            $this->logger->log("Database successfully added to archive from: {$db_file_from_session}", 'info');
                        } else {
                            $this->logger->log("Failed to add database file to archive", 'error');
                        }
                    } else {
                        $this->logger->log("Database already exists in archive", 'info');
                    }

                    if (!$zip->close()) {
                        $this->logger->log("Warning: Failed to properly close archive after adding database", 'warning');
                    }
                } else {
                    $error_messages = [
                        \ZipArchive::ER_NOENT => 'No such file',
                        \ZipArchive::ER_INCONS => 'Zip archive inconsistent',
                        \ZipArchive::ER_INVAL => 'Invalid argument',
                        \ZipArchive::ER_MEMORY => 'Malloc failure',
                        \ZipArchive::ER_NOZIP => 'Not a zip archive',
                        \ZipArchive::ER_OPEN => 'Can\'t open file',
                        \ZipArchive::ER_READ => 'Read error',
                        \ZipArchive::ER_SEEK => 'Seek error'
                    ];
                    $error_msg = $error_messages[$result] ?? "Unknown error code: {$result}";
                    throw new \Exception("Failed to open archive for database addition: {$archive_path} (Error {$result}: {$error_msg})");
                }
            } else {
                $this->logger->log("Database file not found for inclusion: {$db_file_from_session}", 'warning');

                // List files in export directory for debugging
                if (is_dir($export_dir)) {
                    $files = scandir($export_dir);
                    $this->logger->log("Files in export directory: " . implode(', ', array_filter($files, function ($f) {
                        return $f !== '.' && $f !== '..';
                    })), 'info');
                }
            }
        } else {
            $this->logger->log("Database export skipped per user settings", 'info');
        }

        // Log final archive contents for debugging BEFORE cleanup
        if (file_exists($archive_path)) {
            $archive_size = filesize($archive_path);
            $this->logger->log("Final archive size: " . size_format($archive_size), 'info');

            $zip = new \ZipArchive();
            if ($zip->open($archive_path, \ZipArchive::RDONLY) === TRUE) {
                $contents = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    $contents[] = $stat['name'];
                }
                $zip->close();
                $this->logger->log("Final archive contents (" . count($contents) . " files): " . implode(', ', array_slice($contents, 0, 10)) . (count($contents) > 10 ? '... and ' . (count($contents) - 10) . ' more files' : ''), 'info');

                // Specifically check for database.sql
                if (in_array('database.sql', $contents)) {
                    $this->logger->log("✓ Database file confirmed in final archive", 'info');
                } else {
                    $this->logger->log("✗ Database file NOT found in final archive", 'error');

                    // Additional debugging - check if database was supposed to be included
                    if ($options['include_database']) {
                        $db_file_path = $session->get_db_export_path();
                        if ($db_file_path && file_exists($db_file_path)) {
                            $this->logger->log("Database file exists but was not added to archive: {$db_file_path}", 'error');
                        } else {
                            $this->logger->log("Database file does not exist: {$db_file_path}", 'error');
                        }
                    }
                }
            } else {
                $this->logger->log("Could not open final archive for verification", 'error');
            }
        } else {
            $this->logger->log("Final archive does not exist: {$archive_path}", 'error');
        }

        // Clean up temporary directory AFTER adding database to archive
        $this->logger->log("Cleaning up temporary export directory: {$export_dir}", 'info');
        $this->cleanup_directory($export_dir);

        $this->logger->log("Export finalized: {$session->get_export_id()}", 'info');
    }

    /**
     * Parse export options from request
     * 
     * @return array Export options
     */
    private function parse_export_options(array $validated_post): array
    {
        return [
            'include_uploads' => isset($_POST['include_uploads']) ? (bool) $_POST['include_uploads'] : false,
            'include_plugins' => isset($_POST['include_plugins']) ? (bool) $_POST['include_plugins'] : false,
            'include_themes' => isset($_POST['include_themes']) ? (bool) $_POST['include_themes'] : false,
            'include_database' => isset($_POST['include_database']) ? (bool) $_POST['include_database'] : false,
            'split_size' => isset($_POST['split_size']) ? (int) $_POST['split_size'] : 100,
            'files_per_step' => isset($_POST['files_per_step']) ? (int) $_POST['files_per_step'] : 50,
            'db_export_mode' => isset($_POST['db_export_mode']) ? sanitize_text_field($_POST['db_export_mode']) : 'optimized',
            'db_rows_per_step' => isset($_POST['db_rows_per_step']) ? (int) $_POST['db_rows_per_step'] : 5000,
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
     * @throws \Exception
     */
    private function create_archive_from_directory(string $source_dir, string $archive_path): void
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
    }

    /**
     * Create filtered archive excluding certain patterns
     * 
     * @param string $source_dir Source directory
     * @param string $archive_path Archive path
     * @param array $exclude_patterns Patterns to exclude
     * @throws \Exception
     */
    private function create_filtered_archive(string $source_dir, string $archive_path, array $exclude_patterns = []): void
    {
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
     * Validate and sanitize POST data for export options
     *
     * @param array $post POST data
     * @return array Validated and sanitized POST data
     */
    private function validate_post_data(array $post): array
    {
        // Add validation and sanitization as needed for your fields
        return [
            'include_uploads'   => isset($post['include_uploads']) ? (bool) $post['include_uploads'] : false,
            'include_plugins'   => isset($post['include_plugins']) ? (bool) $post['include_plugins'] : false,
            'include_themes'    => isset($post['include_themes']) ? (bool) $post['include_themes'] : false,
            'include_database'  => isset($post['include_database']) ? (bool) $post['include_database'] : false,
            'split_size'        => isset($post['split_size']) ? (int) $post['split_size'] : 100,
            'files_per_step'    => isset($post['files_per_step']) ? (int) $post['files_per_step'] : 50,
            'db_export_mode'    => isset($post['db_export_mode']) ? sanitize_text_field($post['db_export_mode']) : 'optimized',
            'db_rows_per_step'  => isset($post['db_rows_per_step']) ? (int) $post['db_rows_per_step'] : 5000,
            'exclude_patterns'  => isset($post['exclude_patterns']) && is_array($post['exclude_patterns']) ? array_map('sanitize_text_field', $post['exclude_patterns']) : [
                '*.log',
                '*/cache/*',
                '*/wp-easy-migrate/*'
            ]
        ];
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
