<?php

namespace WPEasyMigrate;

/**
 * ImportController Class
 * 
 * Handles step-by-step import processing
 */
class ImportController
{
    /**
     * Logger instance
     */
    private $logger;

    /**
     * Importer instance
     */
    private $importer;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->logger = new Logger();
        $this->importer = new Importer();
    }

    /**
     * Handle import step AJAX request
     */
    public function handle_import_step(): void
    {
        // Verify nonce and permissions
        check_ajax_referer('wp_easy_migrate_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wp-easy-migrate'));
        }

        try {
            $session = new ImportSession();

            // Check if this is a new import
            if (isset($_POST['start_import'])) {
                $session->start();
                $this->logger->log('New import session started', 'info');
            }

            // Check for current session state
            if ($session->is_completed()) {
                wp_send_json_success([
                    'message' => __('Import completed successfully!', 'wp-easy-migrate'),
                    'status' => $session->get_status(),
                    'completed' => true
                ]);
                return;
            }

            if ($session->get_error()) {
                wp_send_json_error([
                    'message' => $session->get_error(),
                    'status' => $session->get_status()
                ]);
                return;
            }

            // Execute current step
            $this->execute_step($session);

            // Return status
            wp_send_json_success([
                'message' => $this->get_step_message($session->get_current_step()),
                'status' => $session->get_status()
            ]);
        } catch (\Exception $e) {
            $this->logger->log("Import step error: " . $e->getMessage(), 'error');
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Execute current import step
     * 
     * @param ImportSession $session Import session
     * @throws \Exception
     */
    private function execute_step(ImportSession $session): void
    {
        $step = $session->get_current_step();
        $this->logger->log("Executing import step: {$step}", 'info');

        switch ($step) {
            case 'upload_file':
                $this->handle_file_upload($session);
                break;

            case 'extract_archive':
                $this->extract_archive($session);
                break;

            case 'validate_manifest':
                $this->validate_manifest($session);
                break;

            case 'backup_current_site':
                $this->backup_current_site($session);
                break;

            case 'import_database':
                $this->import_database($session);
                break;

            case 'import_files':
                $this->import_files($session);
                break;

            case 'update_urls':
                $this->update_urls($session);
                break;

            case 'cleanup':
                $this->cleanup($session);
                break;

            default:
                throw new \Exception("Unknown import step: {$step}");
        }

        // Move to next step
        $session->next_step();
    }

    /**
     * Handle file upload
     * 
     * @param ImportSession $session Import session
     * @throws \Exception
     */
    private function handle_file_upload(ImportSession $session): void
    {
        if (!isset($_FILES['import_file'])) {
            throw new \Exception(__('No file uploaded', 'wp-easy-migrate'));
        }

        $file_data = $_FILES['import_file'];

        if (!isset($file_data['tmp_name']) || !is_uploaded_file($file_data['tmp_name'])) {
            throw new \Exception(__('Invalid file upload', 'wp-easy-migrate'));
        }

        if ($file_data['error'] !== UPLOAD_ERR_OK) {
            throw new \Exception(__('File upload error: ', 'wp-easy-migrate') . $file_data['error']);
        }

        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/wp-easy-migrate/imports/';
        wp_mkdir_p($target_dir);

        $filename = sanitize_file_name($file_data['name']);
        $target_path = $target_dir . $filename;

        if (!move_uploaded_file($file_data['tmp_name'], $target_path)) {
            throw new \Exception(__('Failed to move uploaded file', 'wp-easy-migrate'));
        }

        $session->set_archive_path($target_path);
        $session->set_current_operation("Uploaded: {$filename}");

        $this->logger->log("File uploaded: {$target_path}", 'info');
    }

    /**
     * Extract archive
     * 
     * @param ImportSession $session Import session
     * @throws \Exception
     */
    private function extract_archive(ImportSession $session): void
    {
        $archive_path = $session->get_archive_path();
        $import_dir = $session->get_import_dir();

        if (!$archive_path || !file_exists($archive_path)) {
            throw new \Exception(__('Archive file not found', 'wp-easy-migrate'));
        }

        // Create import directory
        wp_mkdir_p($import_dir);

        $session->set_current_operation('Extracting archive...');

        // Extract archive
        $zip = new \ZipArchive();
        $result = $zip->open($archive_path);

        if ($result !== TRUE) {
            throw new \Exception("Cannot open archive: {$archive_path} (Error: {$result})");
        }

        $extracted_path = $import_dir . 'extracted/';
        wp_mkdir_p($extracted_path);

        if (!$zip->extractTo($extracted_path)) {
            $zip->close();
            throw new \Exception("Failed to extract archive: {$archive_path}");
        }

        $zip->close();

        $session->set_extracted_dir($extracted_path);
        $session->set_current_operation('Archive extracted successfully');

        $this->logger->log("Archive extracted: {$archive_path} -> {$extracted_path}", 'info');
    }

    /**
     * Validate manifest
     * 
     * @param ImportSession $session Import session
     * @throws \Exception
     */
    private function validate_manifest(ImportSession $session): void
    {
        $extracted_dir = $session->get_extracted_dir();
        $manifest_path = $extracted_dir . '/manifest.json';

        if (!file_exists($manifest_path)) {
            throw new \Exception(__('Manifest file not found in archive', 'wp-easy-migrate'));
        }

        $manifest_content = file_get_contents($manifest_path);
        $manifest = json_decode($manifest_content, true);

        if (!$manifest) {
            throw new \Exception(__('Invalid manifest file', 'wp-easy-migrate'));
        }

        // Validate required fields
        $required_fields = ['version', 'generator', 'export_id', 'site_info', 'export_info'];
        foreach ($required_fields as $field) {
            if (!isset($manifest[$field])) {
                throw new \Exception("Missing required manifest field: {$field}");
            }
        }

        if ($manifest['generator'] !== 'WP Easy Migrate') {
            throw new \Exception(__('Archive was not created by WP Easy Migrate', 'wp-easy-migrate'));
        }

        $session->set_manifest($manifest);
        $session->set_current_operation('Manifest validated successfully');

        $this->logger->log('Manifest validation passed', 'info');
    }

    /**
     * Backup current site
     * 
     * @param ImportSession $session Import session
     * @throws \Exception
     */
    private function backup_current_site(ImportSession $session): void
    {
        $session->set_current_operation('Creating backup of current site...');

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
            $import_dir = $session->get_import_dir();
            $backup_filename = 'pre-import-backup-' . date('Y-m-d-H-i-s') . '.zip';
            $final_backup_path = $import_dir . $backup_filename;
            rename($backup_path, $final_backup_path);

            $session->set_backup_path($final_backup_path);
            $session->set_current_operation('Backup created successfully');

            $this->logger->log("Pre-import backup created: {$final_backup_path}", 'info');
        } catch (\Exception $e) {
            $this->logger->log("Failed to create pre-import backup: " . $e->getMessage(), 'warning');
            $session->set_current_operation('Backup creation failed, continuing...');
        }
    }

    /**
     * Import database
     * 
     * @param ImportSession $session Import session
     * @throws \Exception
     */
    private function import_database(ImportSession $session): void
    {
        $extracted_dir = $session->get_extracted_dir();
        $db_file = $extracted_dir . '/database.sql';

        if (!file_exists($db_file)) {
            $session->set_current_operation('No database file found, skipping...');
            $this->logger->log('No database file found in archive', 'warning');
            return;
        }

        $session->set_current_operation('Importing database...');

        $result = $this->importer->restore_database($db_file);

        if ($result) {
            $session->set_current_operation('Database imported successfully');
            $this->logger->log('Database imported successfully', 'info');
        } else {
            throw new \Exception(__('Database import failed', 'wp-easy-migrate'));
        }
    }

    /**
     * Import files
     * 
     * @param ImportSession $session Import session
     * @throws \Exception
     */
    private function import_files(ImportSession $session): void
    {
        $extracted_dir = $session->get_extracted_dir();

        // Import uploads
        if (file_exists($extracted_dir . '/uploads.zip')) {
            $session->set_current_operation('Importing uploads...');
            $this->importer->restore_files($extracted_dir . '/uploads.zip', wp_upload_dir()['basedir']);
            $session->add_imported_file('uploads');
            $this->logger->log('Uploads imported successfully', 'info');
        }

        // Import plugins
        if (file_exists($extracted_dir . '/plugins.zip')) {
            $session->set_current_operation('Importing plugins...');
            $this->importer->restore_files($extracted_dir . '/plugins.zip', WP_PLUGIN_DIR);
            $session->add_imported_file('plugins');
            $this->logger->log('Plugins imported successfully', 'info');
        }

        // Import themes
        if (file_exists($extracted_dir . '/themes.zip')) {
            $session->set_current_operation('Importing themes...');
            $this->importer->restore_files($extracted_dir . '/themes.zip', get_theme_root());
            $session->add_imported_file('themes');
            $this->logger->log('Themes imported successfully', 'info');
        }

        $imported_files = $session->get_imported_files();
        $session->set_current_operation('Files imported: ' . implode(', ', $imported_files));
    }

    /**
     * Update site URLs
     * 
     * @param ImportSession $session Import session
     * @throws \Exception
     */
    private function update_urls(ImportSession $session): void
    {
        $manifest = $session->get_manifest();

        if (!$manifest || !isset($manifest['site_info']['url'])) {
            $session->set_current_operation('No URL update needed');
            return;
        }

        $session->set_current_operation('Updating site URLs...');

        $old_url = $manifest['site_info']['url'];
        $new_url = get_site_url();

        if ($old_url === $new_url) {
            $session->set_current_operation('Site URLs match, no update needed');
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

        $session->set_current_operation('Site URLs updated successfully');
        $this->logger->log('Site URLs updated successfully', 'info');
    }

    /**
     * Cleanup import files
     * 
     * @param ImportSession $session Import session
     * @throws \Exception
     */
    private function cleanup(ImportSession $session): void
    {
        $session->set_current_operation('Cleaning up temporary files...');

        $import_dir = $session->get_import_dir();
        $archive_path = $session->get_archive_path();

        // Clean up extracted directory
        if ($import_dir && is_dir($import_dir)) {
            $this->cleanup_directory($import_dir);
        }

        // Clean up uploaded archive
        if ($archive_path && file_exists($archive_path)) {
            unlink($archive_path);
        }

        // Clear caches
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        flush_rewrite_rules();
        do_action('wp_easy_migrate_clear_caches');

        $session->set_current_operation('Import completed successfully!');
        $this->logger->log('Import cleanup completed', 'info');
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
        $this->logger->log("Cleaned up directory: {$dir}", 'info');
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
            'upload_file' => __('Uploading file...', 'wp-easy-migrate'),
            'extract_archive' => __('Extracting archive...', 'wp-easy-migrate'),
            'validate_manifest' => __('Validating manifest...', 'wp-easy-migrate'),
            'backup_current_site' => __('Creating backup...', 'wp-easy-migrate'),
            'import_database' => __('Importing database...', 'wp-easy-migrate'),
            'import_files' => __('Importing files...', 'wp-easy-migrate'),
            'update_urls' => __('Updating URLs...', 'wp-easy-migrate'),
            'cleanup' => __('Cleaning up...', 'wp-easy-migrate')
        ];

        return $messages[$step] ?? __('Processing...', 'wp-easy-migrate');
    }
}
