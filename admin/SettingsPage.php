<?php

namespace WPEasyMigrate\Admin;

use WPEasyMigrate\Logger;
use WPEasyMigrate\CompatibilityChecker;

/**
 * Settings Page Class
 * 
 * Handles the admin interface for WP Easy Migrate
 */
class SettingsPage {
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new Logger();
    }
    
    /**
     * Render the settings page
     */
    public function render(): void {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'export';
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=wp-easy-migrate&tab=export" class="nav-tab <?php echo $active_tab === 'export' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Export', 'wp-easy-migrate'); ?>
                </a>
                <a href="?page=wp-easy-migrate&tab=import" class="nav-tab <?php echo $active_tab === 'import' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Import', 'wp-easy-migrate'); ?>
                </a>
                <a href="?page=wp-easy-migrate&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Logs', 'wp-easy-migrate'); ?>
                </a>
                <a href="?page=wp-easy-migrate&tab=system" class="nav-tab <?php echo $active_tab === 'system' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('System Info', 'wp-easy-migrate'); ?>
                </a>
            </nav>
            
            <div class="tab-content">
                <?php
                switch ($active_tab) {
                    case 'export':
                        $this->render_export_tab();
                        break;
                    case 'import':
                        $this->render_import_tab();
                        break;
                    case 'logs':
                        $this->render_logs_tab();
                        break;
                    case 'system':
                        $this->render_system_tab();
                        break;
                    default:
                        $this->render_export_tab();
                }
                ?>
            </div>
        </div>
        
        <style>
        .wp-easy-migrate-section {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            margin: 20px 0;
            padding: 20px;
        }
        
        .wp-easy-migrate-section h2 {
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        
        .wp-easy-migrate-progress {
            display: none;
            margin: 15px 0;
        }
        
        .wp-easy-migrate-progress-bar {
            background: #f0f0f1;
            border-radius: 3px;
            height: 20px;
            overflow: hidden;
        }
        
        .wp-easy-migrate-progress-fill {
            background: #0073aa;
            height: 100%;
            transition: width 0.3s ease;
            width: 0%;
        }
        
        .wp-easy-migrate-log {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
            height: 300px;
            overflow-y: auto;
            padding: 10px;
            white-space: pre-wrap;
        }
        
        .wp-easy-migrate-status {
            margin: 15px 0;
            padding: 10px;
            border-radius: 4px;
        }
        
        .wp-easy-migrate-status.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .wp-easy-migrate-status.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .wp-easy-migrate-status.warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }
        
        .system-info-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .system-info-table th,
        .system-info-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        
        .system-info-table th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        
        .compatibility-check {
            margin: 20px 0;
        }
        
        .compatibility-item {
            display: flex;
            align-items: center;
            margin: 10px 0;
            padding: 10px;
            border-radius: 4px;
        }
        
        .compatibility-item.passed {
            background: #d4edda;
            border: 1px solid #c3e6cb;
        }
        
        .compatibility-item.failed {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
        }
        
        .compatibility-icon {
            margin-right: 10px;
            font-size: 16px;
        }
        </style>
        <?php
    }
    
    /**
     * Render export tab
     */
    private function render_export_tab(): void {
        ?>
        <div class="wp-easy-migrate-section">
            <h2><?php _e('Export WordPress Site', 'wp-easy-migrate'); ?></h2>
            <p><?php _e('Create a complete backup of your WordPress site including database, files, themes, and plugins.', 'wp-easy-migrate'); ?></p>
            
            <form id="wp-easy-migrate-export-form">
                <?php wp_nonce_field('wp_easy_migrate_nonce', 'nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Include Uploads', 'wp-easy-migrate'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="include_uploads" value="1" checked>
                                <?php _e('Include media files and uploads directory', 'wp-easy-migrate'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Include Plugins', 'wp-easy-migrate'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="include_plugins" value="1" checked>
                                <?php _e('Include all installed plugins', 'wp-easy-migrate'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Include Themes', 'wp-easy-migrate'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="include_themes" value="1" checked>
                                <?php _e('Include all installed themes', 'wp-easy-migrate'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Split Archive Size', 'wp-easy-migrate'); ?></th>
                        <td>
                            <select name="split_size">
                                <option value="0"><?php _e('No splitting', 'wp-easy-migrate'); ?></option>
                                <option value="50">50 MB</option>
                                <option value="100" selected>100 MB</option>
                                <option value="250">250 MB</option>
                                <option value="500">500 MB</option>
                                <option value="1000">1 GB</option>
                            </select>
                            <p class="description"><?php _e('Split large archives into smaller parts for easier handling.', 'wp-easy-migrate'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button button-primary" id="start-export">
                        <?php _e('Start Export', 'wp-easy-migrate'); ?>
                    </button>
                </p>
            </form>
            
            <div id="export-progress" class="wp-easy-migrate-progress">
                <h3><?php _e('Export Progress', 'wp-easy-migrate'); ?></h3>
                <div class="wp-easy-migrate-progress-bar">
                    <div class="wp-easy-migrate-progress-fill"></div>
                </div>
                <p id="export-status"><?php _e('Preparing export...', 'wp-easy-migrate'); ?></p>
            </div>
            
            <div id="export-result" class="wp-easy-migrate-status" style="display: none;"></div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#wp-easy-migrate-export-form').on('submit', function(e) {
                e.preventDefault();
                
                var $form = $(this);
                var $button = $('#start-export');
                var $progress = $('#export-progress');
                var $result = $('#export-result');
                
                // Disable form and show progress
                $button.prop('disabled', true).text('<?php _e('Exporting...', 'wp-easy-migrate'); ?>');
                $progress.show();
                $result.hide();
                
                // Start export
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: $form.serialize() + '&action=wp_easy_migrate_export',
                    success: function(response) {
                        if (response.success) {
                            $result.removeClass('error').addClass('success')
                                   .html('<strong><?php _e('Export completed successfully!', 'wp-easy-migrate'); ?></strong><br>' + 
                                         '<?php _e('File:', 'wp-easy-migrate'); ?> ' + response.data.file)
                                   .show();
                        } else {
                            $result.removeClass('success').addClass('error')
                                   .html('<strong><?php _e('Export failed:', 'wp-easy-migrate'); ?></strong><br>' + response.data.message)
                                   .show();
                        }
                    },
                    error: function() {
                        $result.removeClass('success').addClass('error')
                               .html('<strong><?php _e('Export failed:', 'wp-easy-migrate'); ?></strong><br><?php _e('Network error occurred.', 'wp-easy-migrate'); ?>')
                               .show();
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('<?php _e('Start Export', 'wp-easy-migrate'); ?>');
                        $progress.hide();
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render import tab
     */
    private function render_import_tab(): void {
        ?>
        <div class="wp-easy-migrate-section">
            <h2><?php _e('Import WordPress Site', 'wp-easy-migrate'); ?></h2>
            <p><?php _e('Import a WordPress site from a previously exported archive.', 'wp-easy-migrate'); ?></p>
            
            <div class="wp-easy-migrate-status warning">
                <strong><?php _e('Warning:', 'wp-easy-migrate'); ?></strong>
                <?php _e('Importing will overwrite your current site data. Make sure you have a backup before proceeding.', 'wp-easy-migrate'); ?>
            </div>
            
            <form id="wp-easy-migrate-import-form" enctype="multipart/form-data">
                <?php wp_nonce_field('wp_easy_migrate_nonce', 'nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Import File', 'wp-easy-migrate'); ?></th>
                        <td>
                            <input type="file" name="import_file" accept=".zip" required>
                            <p class="description">
                                <?php _e('Select the exported archive file (.zip) or the first part of a split archive (.part1.zip).', 'wp-easy-migrate'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button button-primary" id="start-import">
                        <?php _e('Start Import', 'wp-easy-migrate'); ?>
                    </button>
                </p>
            </form>
            
            <div id="import-progress" class="wp-easy-migrate-progress">
                <h3><?php _e('Import Progress', 'wp-easy-migrate'); ?></h3>
                <div class="wp-easy-migrate-progress-bar">
                    <div class="wp-easy-migrate-progress-fill"></div>
                </div>
                <p id="import-status"><?php _e('Preparing import...', 'wp-easy-migrate'); ?></p>
            </div>
            
            <div id="import-result" class="wp-easy-migrate-status" style="display: none;"></div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#wp-easy-migrate-import-form').on('submit', function(e) {
                e.preventDefault();
                
                var $form = $(this);
                var $button = $('#start-import');
                var $progress = $('#import-progress');
                var $result = $('#import-result');
                
                // Check if file is selected
                var fileInput = $form.find('input[type="file"]')[0];
                if (!fileInput.files.length) {
                    alert('<?php _e('Please select a file to import.', 'wp-easy-migrate'); ?>');
                    return;
                }
                
                // Confirm import
                if (!confirm('<?php _e('Are you sure you want to import? This will overwrite your current site data.', 'wp-easy-migrate'); ?>')) {
                    return;
                }
                
                // Disable form and show progress
                $button.prop('disabled', true).text('<?php _e('Importing...', 'wp-easy-migrate'); ?>');
                $progress.show();
                $result.hide();
                
                // Create FormData for file upload
                var formData = new FormData($form[0]);
                formData.append('action', 'wp_easy_migrate_import');
                
                // Start import
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            $result.removeClass('error').addClass('success')
                                   .html('<strong><?php _e('Import completed successfully!', 'wp-easy-migrate'); ?></strong><br>' + 
                                         '<?php _e('Your site has been restored. You may need to refresh the page.', 'wp-easy-migrate'); ?>')
                                   .show();
                        } else {
                            $result.removeClass('success').addClass('error')
                                   .html('<strong><?php _e('Import failed:', 'wp-easy-migrate'); ?></strong><br>' + response.data.message)
                                   .show();
                        }
                    },
                    error: function() {
                        $result.removeClass('success').addClass('error')
                               .html('<strong><?php _e('Import failed:', 'wp-easy-migrate'); ?></strong><br><?php _e('Network error occurred.', 'wp-easy-migrate'); ?>')
                               .show();
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('<?php _e('Start Import', 'wp-easy-migrate'); ?>');
                        $progress.hide();
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render logs tab
     */
    private function render_logs_tab(): void {
        $recent_logs = $this->logger->get_recent_logs(100);
        ?>
        <div class="wp-easy-migrate-section">
            <h2><?php _e('Activity Logs', 'wp-easy-migrate'); ?></h2>
            <p><?php _e('View recent plugin activity and troubleshoot issues.', 'wp-easy-migrate'); ?></p>
            
            <div style="margin-bottom: 15px;">
                <button type="button" class="button" id="refresh-logs">
                    <?php _e('Refresh Logs', 'wp-easy-migrate'); ?>
                </button>
                <button type="button" class="button" id="clear-logs">
                    <?php _e('Clear Logs', 'wp-easy-migrate'); ?>
                </button>
                <span style="margin-left: 20px;">
                    <?php printf(__('Log file size: %s', 'wp-easy-migrate'), $this->logger->get_log_file_size_formatted()); ?>
                </span>
            </div>
            
            <div id="logs-container" class="wp-easy-migrate-log">
                <?php
                if (empty($recent_logs)) {
                    echo __('No logs available.', 'wp-easy-migrate');
                } else {
                    foreach ($recent_logs as $log) {
                        echo sprintf(
                            "[%s] [%s] %s\n",
                            esc_html($log['timestamp']),
                            esc_html($log['level']),
                            esc_html($log['message'])
                        );
                    }
                }
                ?>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#refresh-logs').on('click', function() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wp_easy_migrate_get_logs',
                        nonce: '<?php echo wp_create_nonce('wp_easy_migrate_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var logsHtml = '';
                            if (response.data.logs.length === 0) {
                                logsHtml = '<?php _e('No logs available.', 'wp-easy-migrate'); ?>';
                            } else {
                                response.data.logs.forEach(function(log) {
                                    logsHtml += '[' + log.timestamp + '] [' + log.level + '] ' + log.message + '\n';
                                });
                            }
                            $('#logs-container').html(logsHtml);
                        }
                    }
                });
            });
            
            $('#clear-logs').on('click', function() {
                if (confirm('<?php _e('Are you sure you want to clear all logs?', 'wp-easy-migrate'); ?>')) {
                    // This would need a separate AJAX handler
                    alert('<?php _e('Clear logs functionality would be implemented here.', 'wp-easy-migrate'); ?>');
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render system info tab
     */
    private function render_system_tab(): void {
        $checker = new CompatibilityChecker();
        $system_info = $checker->get_system_info();
        $compatibility = $checker->check();
        ?>
        <div class="wp-easy-migrate-section">
            <h2><?php _e('System Compatibility', 'wp-easy-migrate'); ?></h2>
            
            <div class="compatibility-check">
                <?php foreach ($compatibility['checks'] as $check): ?>
                    <div class="compatibility-item <?php echo $check['passed'] ? 'passed' : 'failed'; ?>">
                        <span class="compatibility-icon">
                            <?php echo $check['passed'] ? '✓' : '✗'; ?>
                        </span>
                        <div>
                            <strong><?php echo esc_html($check['name']); ?>:</strong>
                            <?php echo esc_html($check['message']); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="wp-easy-migrate-section">
            <h2><?php _e('System Information', 'wp-easy-migrate'); ?></h2>
            
            <h3><?php _e('PHP Information', 'wp-easy-migrate'); ?></h3>
            <table class="system-info-table">
                <tr><th><?php _e('PHP Version', 'wp-easy-migrate'); ?></th><td><?php echo esc_html($system_info['php']['version']); ?></td></tr>
                <tr><th><?php _e('Memory Limit', 'wp-easy-migrate'); ?></th><td><?php echo esc_html($system_info['php']['memory_limit']); ?></td></tr>
                <tr><th><?php _e('Max Execution Time', 'wp-easy-migrate'); ?></th><td><?php echo esc_html($system_info['php']['max_execution_time']); ?>s</td></tr>
                <tr><th><?php _e('Upload Max Filesize', 'wp-easy-migrate'); ?></th><td><?php echo esc_html($system_info['php']['upload_max_filesize']); ?></td></tr>
                <tr><th><?php _e('Post Max Size', 'wp-easy-migrate'); ?></th><td><?php echo esc_html($system_info['php']['post_max_size']); ?></td></tr>
            </table>
            
            <h3><?php _e('WordPress Information', 'wp-easy-migrate'); ?></h3>
            <table class="system-info-table">
                <tr><th><?php _e('WordPress Version', 'wp-easy-migrate'); ?></th><td><?php echo esc_html($system_info['wordpress']['version']); ?></td></tr>
                <tr><th><?php _e('Multisite', 'wp-easy-migrate'); ?></th><td><?php echo $system_info['wordpress']['multisite'] ? __('Yes', 'wp-easy-migrate') : __('No', 'wp-easy-migrate'); ?></td></tr>
                <tr><th><?php _e('Debug Mode', 'wp-easy-migrate'); ?></th><td><?php echo $system_info['wordpress']['debug'] ? __('Enabled', 'wp-easy-migrate') : __('Disabled', 'wp-easy-migrate'); ?></td></tr>
                <tr><th><?php _e('Language', 'wp-easy-migrate'); ?></th><td><?php echo esc_html($system_info['wordpress']['language']); ?></td></tr>
            </table>
            
            <h3><?php _e('Database Information', 'wp-easy-migrate'); ?></h3>
            <table class="system-info-table">
                <tr><th><?php _e('Database Version', 'wp-easy-migrate'); ?></th><td><?php echo esc_html($system_info['database']['version']); ?></td></tr>
                <tr><th><?php _e('Charset', 'wp-easy-migrate'); ?></th><td><?php echo esc_html($system_info['database']['charset']); ?></td></tr>
                <tr><th><?php _e('Collation', 'wp-easy-migrate'); ?></th><td><?php echo esc_html($system_info['database']['collation']); ?></td></tr>
                <tr><th><?php _e('Table Prefix', 'wp-easy-migrate'); ?></th><td><?php echo esc_html($system_info['database']['prefix']); ?></td></tr>
            </table>
            
            <h3><?php _e('Server Information', 'wp-easy-migrate'); ?></h3>
            <table class="system-info-table">
                <tr><th><?php _e('Server Software', 'wp-easy-migrate'); ?></th><td><?php echo esc_html($system_info['server']['software']); ?></td></tr>
                <tr><th><?php _e('Operating System', 'wp-easy-migrate'); ?></th><td><?php echo esc_html($system_info['server']['os']); ?></td></tr>
                <tr><th><?php _e('Architecture', 'wp-easy-migrate'); ?></th><td><?php echo esc_html($system_info['server']['architecture']); ?></td></tr>
                <tr><th><?php _e('Free Disk Space', 'wp-easy-migrate'); ?></th><td><?php echo size_format($system_info['server']['disk_free_space']); ?></td></tr>
                <tr><th><?php _e('Total Disk Space', 'wp-easy-migrate'); ?></th><td><?php echo size_format($system_info['server']['disk_total_space']); ?></td></tr>
            </table>
        </div>
        <?php
    }
}