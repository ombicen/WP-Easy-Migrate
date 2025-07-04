<?php

/**
 * Plugin Name: WP Easy Migrate
 * Plugin URI: https://example.com/wp-easy-migrate
 * Description: Export and import entire WordPress sites including media, plugins, themes, and database. Supports archive splitting and safe re-importing.
 * Version: 1.0.0
 * Author: WP Easy Migrate Team
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-easy-migrate
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Network: false
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WP_EASY_MIGRATE_VERSION', '1.0.0');
define('WP_EASY_MIGRATE_PLUGIN_FILE', __FILE__);
define('WP_EASY_MIGRATE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_EASY_MIGRATE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_EASY_MIGRATE_INCLUDES_DIR', WP_EASY_MIGRATE_PLUGIN_DIR . 'includes/');
define('WP_EASY_MIGRATE_ADMIN_DIR', WP_EASY_MIGRATE_PLUGIN_DIR . 'admin/');
define('WP_EASY_MIGRATE_UPLOADS_DIR', wp_upload_dir()['basedir'] . '/wp-easy-migrate/');
define('WP_EASY_MIGRATE_LOGS_DIR', WP_EASY_MIGRATE_UPLOADS_DIR . 'logs/');

/**
 * PSR-4 Autoloader for WP Easy Migrate
 */
spl_autoload_register(function ($class) {
    // Handle main namespace
    $prefix1 = 'WPEasyMigrate\\';
    $prefix2 = 'WP_Easy_Migrate\\';

    $relative_class = null;
    $base_dirs = [
        WP_EASY_MIGRATE_INCLUDES_DIR,
        WP_EASY_MIGRATE_ADMIN_DIR
    ];

    // Check if the class uses the main namespace prefix
    $len1 = strlen($prefix1);
    if (strncmp($prefix1, $class, $len1) === 0) {
        $relative_class = substr($class, $len1);
    }
    // Check if the class uses the Export namespace prefix
    else {
        $len2 = strlen($prefix2);
        if (strncmp($prefix2, $class, $len2) === 0) {
            $relative_class = substr($class, $len2);
        }
    }

    if ($relative_class === null) {
        return;
    }

    // Replace namespace separators with directory separators
    $relative_path = str_replace('\\', '/', $relative_class) . '.php';

    foreach ($base_dirs as $base_dir) {
        $file = $base_dir . $relative_path;
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

/**
 * Main Plugin Class
 */
class WPEasyMigrate
{

    /**
     * Plugin instance
     */
    private static $instance = null;

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Get plugin instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->init_hooks();
        $this->init_logger();
        $this->create_directories();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks()
    {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('init', [$this, 'init']);
        add_action('admin_init', [$this, 'admin_init']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);

        // AJAX handlers
        add_action('wp_ajax_wp_easy_migrate_export', [$this, 'handle_export_ajax']);
        add_action('wp_ajax_wp_easy_migrate_import', [$this, 'handle_import_ajax']);
        add_action('wp_ajax_wp_easy_migrate_get_logs', [$this, 'handle_get_logs_ajax']);
        add_action('wp_ajax_wp_easy_migrate_clear_logs', [$this, 'handle_clear_logs_ajax']);
        add_action('wp_ajax_wpem_export_step', [$this, 'handle_export_step_ajax']);
        add_action('wp_ajax_wpem_import_step', [$this, 'handle_import_step_ajax']);
        add_action('wp_ajax_wp_easy_migrate_download', [$this, 'handle_download_ajax']);
    }

    /**
     * Initialize logger
     */
    private function init_logger()
    {
        // Ensure the Logger class is loaded
        if (!class_exists('\WPEasyMigrate\Logger')) {
            require_once WP_EASY_MIGRATE_INCLUDES_DIR . 'Logger.php';
        }

        $this->logger = new \WPEasyMigrate\Logger();
    }

    /**
     * Create necessary directories
     */
    private function create_directories()
    {
        $dirs = [
            WP_EASY_MIGRATE_UPLOADS_DIR,
            WP_EASY_MIGRATE_LOGS_DIR,
            WP_EASY_MIGRATE_UPLOADS_DIR . 'exports/',
            WP_EASY_MIGRATE_UPLOADS_DIR . 'imports/',
            WP_EASY_MIGRATE_UPLOADS_DIR . 'temp/'
        ];

        foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
                // Add .htaccess for security
                file_put_contents($dir . '.htaccess', 'deny from all');
            }
        }
    }

    /**
     * Plugin activation
     */
    public function activate()
    {
        $this->create_directories();
        $this->logger->log('WP Easy Migrate plugin activated', 'info');

        // Check system requirements
        if (!class_exists('\WPEasyMigrate\CompatibilityChecker')) {
            require_once WP_EASY_MIGRATE_INCLUDES_DIR . 'CompatibilityChecker.php';
        }

        $checker = new \WPEasyMigrate\CompatibilityChecker();
        $requirements = $checker->check();

        if (!$requirements['compatible']) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                'WP Easy Migrate requires: ' . implode(', ', $requirements['missing']),
                'Plugin Activation Error',
                ['back_link' => true]
            );
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate()
    {
        $this->logger->log('WP Easy Migrate plugin deactivated', 'info');
    }

    /**
     * Initialize plugin
     */
    public function init()
    {
        load_plugin_textdomain('wp-easy-migrate', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Admin initialization
     */
    public function admin_init()
    {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }
    }

    /**
     * Add admin menu
     */
    public function admin_menu()
    {
        add_management_page(
            __('WP Easy Migrate', 'wp-easy-migrate'),
            __('WP Easy Migrate', 'wp-easy-migrate'),
            'manage_options',
            'wp-easy-migrate',
            [$this, 'admin_page']
        );
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function admin_scripts($hook)
    {
        if ('tools_page_wp-easy-migrate' !== $hook) {
            return;
        }

        // Enqueue export JavaScript
        wp_enqueue_script(
            'wp-easy-migrate-export',
            WP_EASY_MIGRATE_PLUGIN_URL . 'admin/js/export.js',
            ['jquery'],
            WP_EASY_MIGRATE_VERSION,
            true
        );

        // Enqueue import JavaScript
        wp_enqueue_script(
            'wp-easy-migrate-import',
            WP_EASY_MIGRATE_PLUGIN_URL . 'admin/js/import.js',
            ['jquery'],
            WP_EASY_MIGRATE_VERSION,
            true
        );

        wp_enqueue_style(
            'wp-easy-migrate-admin',
            WP_EASY_MIGRATE_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WP_EASY_MIGRATE_VERSION
        );

        wp_localize_script('wp-easy-migrate-export', 'wpEasyMigrate', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_easy_migrate_nonce'),
            'strings' => [
                'exporting' => __('Exporting...', 'wp-easy-migrate'),
                'importing' => __('Importing...', 'wp-easy-migrate'),
                'success' => __('Operation completed successfully!', 'wp-easy-migrate'),
                'error' => __('An error occurred. Please check the logs.', 'wp-easy-migrate'),
                'startExport' => __('Start Export', 'wp-easy-migrate'),
                'exportFailed' => __('Export failed', 'wp-easy-migrate'),
                'exportCancelled' => __('Export cancelled', 'wp-easy-migrate'),
                'exportInProgress' => __('Export is in progress. Are you sure you want to leave?', 'wp-easy-migrate'),
                'importInProgress' => __('Import is in progress. Are you sure you want to leave?', 'wp-easy-migrate'),
                'file' => __('File', 'wp-easy-migrate'),
                'processing' => __('Processing...', 'wp-easy-migrate'),
                'preparingExport' => __('Preparing export...', 'wp-easy-migrate'),
                'scanningFiles' => __('Scanning files...', 'wp-easy-migrate'),
                'exportingDatabase' => __('Exporting database...', 'wp-easy-migrate'),
                'archivingFiles' => __('Archiving files...', 'wp-easy-migrate'),
                'creatingManifest' => __('Creating manifest...', 'wp-easy-migrate'),
                'splittingArchive' => __('Splitting archive...', 'wp-easy-migrate'),
                'finalizingExport' => __('Finalizing export...', 'wp-easy-migrate'),
            ]
        ]);
    }

    /**
     * Admin page callback
     */
    public function admin_page()
    {
        // Ensure the SettingsPage class is loaded
        if (!class_exists('\WPEasyMigrate\Admin\SettingsPage')) {
            require_once WP_EASY_MIGRATE_ADMIN_DIR . 'SettingsPage.php';
        }

        $settings_page = new \WPEasyMigrate\Admin\SettingsPage();
        $settings_page->render();
    }

    /**
     * Handle export AJAX request
     */
    public function handle_export_ajax()
    {
        check_ajax_referer('wp_easy_migrate_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wp-easy-migrate'));
        }

        try {
            $options = [
                'include_uploads' => isset($_POST['include_uploads']) ? (bool) $_POST['include_uploads'] : true,
                'include_plugins' => isset($_POST['include_plugins']) ? (bool) $_POST['include_plugins'] : true,
                'include_themes' => isset($_POST['include_themes']) ? (bool) $_POST['include_themes'] : true,
                'split_size' => isset($_POST['split_size']) ? (int) $_POST['split_size'] : 100,
            ];

            if (!class_exists('\WPEasyMigrate\Exporter')) {
                require_once WP_EASY_MIGRATE_INCLUDES_DIR . 'Exporter.php';
            }

            $exporter = new \WPEasyMigrate\Exporter();
            $result = $exporter->export_site($options);

            wp_send_json_success([
                'message' => __('Export completed successfully!', 'wp-easy-migrate'),
                'file' => $result
            ]);
        } catch (Exception $e) {
            $this->logger->log('Export error: ' . $e->getMessage(), 'error');
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle import AJAX request
     */
    public function handle_import_ajax()
    {
        check_ajax_referer('wp_easy_migrate_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wp-easy-migrate'));
        }

        try {
            if (!isset($_FILES['import_file'])) {
                throw new Exception(__('No file uploaded', 'wp-easy-migrate'));
            }

            if (!class_exists('\WPEasyMigrate\Importer')) {
                require_once WP_EASY_MIGRATE_INCLUDES_DIR . 'Importer.php';
            }

            $importer = new \WPEasyMigrate\Importer();
            $result = $importer->import_site($_FILES['import_file']);

            wp_send_json_success([
                'message' => __('Import completed successfully!', 'wp-easy-migrate')
            ]);
        } catch (Exception $e) {
            $this->logger->log('Import error: ' . $e->getMessage(), 'error');
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle get logs AJAX request
     */
    public function handle_get_logs_ajax()
    {
        check_ajax_referer('wp_easy_migrate_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wp-easy-migrate'));
        }

        $logs = $this->logger->get_recent_logs(50);
        wp_send_json_success(['logs' => $logs]);
    }

    /**
     * Handle clear logs AJAX request
     */
    public function handle_clear_logs_ajax()
    {
        check_ajax_referer('wp_easy_migrate_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wp-easy-migrate'));
        }
        $this->logger->clear_logs();
        wp_send_json_success(['message' => __('Logs cleared successfully.', 'wp-easy-migrate')]);
    }

    /**
     * Handle export step AJAX request
     */
    public function handle_export_step_ajax()
    {
        // Ensure the ExportController class is loaded
        if (!class_exists('\WPEasyMigrate\ExportController')) {
            require_once WP_EASY_MIGRATE_INCLUDES_DIR . 'ExportController.php';
        }

        $controller = new \WPEasyMigrate\ExportController();
        $controller->handle_export_step();
    }

    /**
     * Handle import step AJAX request
     */
    public function handle_import_step_ajax()
    {
        // Ensure the ImportController class is loaded
        if (!class_exists('\WPEasyMigrate\ImportController')) {
            require_once WP_EASY_MIGRATE_INCLUDES_DIR . 'ImportController.php';
        }

        $controller = new \WPEasyMigrate\ImportController();
        $controller->handle_import_step();
    }

    /**
     * Handle download AJAX request
     */
    public function handle_download_ajax()
    {
        // Verify nonce and capabilities
        if (!wp_verify_nonce($_REQUEST['nonce'], 'wp_easy_migrate_nonce')) {
            wp_die(__('Security check failed', 'wp-easy-migrate'), '', array('response' => 403));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'wp-easy-migrate'), '', array('response' => 403));
        }

        if (!isset($_GET['file'])) {
            wp_die(__('No file specified', 'wp-easy-migrate'), '', array('response' => 400));
        }

        $requested_file = sanitize_file_name($_GET['file']);

        // Define allowed file patterns
        $allowed_patterns = [
            '/^wp-export-[a-zA-Z0-9_-]+-manifest\.json$/',  // Manifest files
            '/^wp-export-[a-zA-Z0-9_-]+\.zip$/',             // Single archive
            '/^wp-export-[a-zA-Z0-9_-]+\.part\d+\.zip$/'     // Split archive parts
        ];

        // Check if filename matches allowed patterns
        $is_allowed = false;
        foreach ($allowed_patterns as $pattern) {
            if (preg_match($pattern, $requested_file)) {
                $is_allowed = true;
                break;
            }
        }

        if (!$is_allowed) {
            wp_die(__('Invalid file requested', 'wp-easy-migrate'), '', array('response' => 400));
        }

        $file_path = WP_EASY_MIGRATE_UPLOADS_DIR . 'exports/' . $requested_file;

        // Check if file exists and is readable
        if (!file_exists($file_path) || !is_readable($file_path)) {
            wp_die(__('File not found or not accessible', 'wp-easy-migrate'), '', array('response' => 404));
        }

        // Security check: ensure file is within exports directory
        $real_file_path = realpath($file_path);
        $exports_dir = realpath(WP_EASY_MIGRATE_UPLOADS_DIR . 'exports/');

        if (!$real_file_path || strpos($real_file_path, $exports_dir) !== 0) {
            wp_die(__('Access denied', 'wp-easy-migrate'), '', array('response' => 403));
        }

        $this->logger->log("Download request for file: {$requested_file}", 'info');

        // Set headers for file download
        $file_size = filesize($real_file_path);
        $file_extension = pathinfo($requested_file, PATHINFO_EXTENSION);

        if ($file_extension === 'json') {
            $content_type = 'application/json';
        } else {
            $content_type = 'application/zip';
        }

        // Clear any existing output
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Set download headers
        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename="' . $requested_file . '"');
        header('Content-Length: ' . $file_size);
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: 0');
        header('Pragma: public');

        // Disable WordPress's default headers
        nocache_headers();

        // Stream the file
        $handle = fopen($real_file_path, 'rb');
        if ($handle) {
            while (!feof($handle)) {
                echo fread($handle, 8192);
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            }
            fclose($handle);
        } else {
            wp_die(__('Unable to read file', 'wp-easy-migrate'), '', array('response' => 500));
        }

        exit;
    }
}

// Initialize the plugin
WPEasyMigrate::get_instance();
