<?php

namespace WPEasyMigrate;

/**
 * CompatibilityChecker Class
 * 
 * Checks system compatibility and requirements for WP Easy Migrate
 */
class CompatibilityChecker {
    
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
     * Check system compatibility
     * 
     * @param array $manifest Optional manifest data for import compatibility
     * @return array Compatibility check results
     */
    public function check(array $manifest = []): array {
        $results = [
            'compatible' => true,
            'warnings' => [],
            'errors' => [],
            'missing' => [],
            'checks' => []
        ];
        
        // Check PHP version
        $php_check = $this->check_php_version($manifest);
        $results['checks']['php'] = $php_check;
        if (!$php_check['passed']) {
            $results['compatible'] = false;
            $results['errors'][] = $php_check['message'];
            $results['missing'][] = 'PHP ' . $php_check['required'];
        }
        
        // Check WordPress version
        $wp_check = $this->check_wordpress_version($manifest);
        $results['checks']['wordpress'] = $wp_check;
        if (!$wp_check['passed']) {
            $results['compatible'] = false;
            $results['errors'][] = $wp_check['message'];
            $results['missing'][] = 'WordPress ' . $wp_check['required'];
        }
        
        // Check required PHP extensions
        $extensions_check = $this->check_php_extensions($manifest);
        $results['checks']['extensions'] = $extensions_check;
        if (!$extensions_check['passed']) {
            $results['compatible'] = false;
            $results['errors'][] = $extensions_check['message'];
            $results['missing'] = array_merge($results['missing'], $extensions_check['missing']);
        }
        
        // Check memory limit
        $memory_check = $this->check_memory_limit();
        $results['checks']['memory'] = $memory_check;
        if (!$memory_check['passed']) {
            $results['warnings'][] = $memory_check['message'];
        }
        
        // Check execution time limit
        $time_check = $this->check_execution_time();
        $results['checks']['execution_time'] = $time_check;
        if (!$time_check['passed']) {
            $results['warnings'][] = $time_check['message'];
        }
        
        // Check disk space
        $disk_check = $this->check_disk_space();
        $results['checks']['disk_space'] = $disk_check;
        if (!$disk_check['passed']) {
            $results['warnings'][] = $disk_check['message'];
        }
        
        // Check file permissions
        $permissions_check = $this->check_file_permissions();
        $results['checks']['permissions'] = $permissions_check;
        if (!$permissions_check['passed']) {
            $results['compatible'] = false;
            $results['errors'][] = $permissions_check['message'];
        }
        
        // Check database compatibility (if manifest provided)
        if (!empty($manifest)) {
            $db_check = $this->check_database_compatibility($manifest);
            $results['checks']['database'] = $db_check;
            if (!$db_check['passed']) {
                $results['warnings'][] = $db_check['message'];
            }
        }
        
        // Log results
        if ($results['compatible']) {
            $this->logger->log('Compatibility check passed', 'info');
        } else {
            $this->logger->log('Compatibility check failed: ' . implode(', ', $results['errors']), 'error');
        }
        
        return $results;
    }
    
    /**
     * Check PHP version compatibility
     * 
     * @param array $manifest Manifest data
     * @return array Check result
     */
    private function check_php_version(array $manifest = []): array {
        $required_version = '7.4';
        $current_version = PHP_VERSION;
        
        // Use manifest requirement if available
        if (!empty($manifest['requirements']['min_php_version'])) {
            $required_version = $manifest['requirements']['min_php_version'];
        }
        
        $passed = version_compare($current_version, $required_version, '>=');
        
        return [
            'name' => 'PHP Version',
            'passed' => $passed,
            'current' => $current_version,
            'required' => $required_version,
            'message' => $passed 
                ? "PHP version {$current_version} meets requirement ({$required_version}+)"
                : "PHP version {$current_version} is below required version {$required_version}"
        ];
    }
    
    /**
     * Check WordPress version compatibility
     * 
     * @param array $manifest Manifest data
     * @return array Check result
     */
    private function check_wordpress_version(array $manifest = []): array {
        $required_version = '5.0';
        $current_version = get_bloginfo('version');
        
        // Use manifest requirement if available
        if (!empty($manifest['requirements']['min_wp_version'])) {
            $required_version = $manifest['requirements']['min_wp_version'];
        }
        
        $passed = version_compare($current_version, $required_version, '>=');
        
        return [
            'name' => 'WordPress Version',
            'passed' => $passed,
            'current' => $current_version,
            'required' => $required_version,
            'message' => $passed 
                ? "WordPress version {$current_version} meets requirement ({$required_version}+)"
                : "WordPress version {$current_version} is below required version {$required_version}"
        ];
    }
    
    /**
     * Check required PHP extensions
     * 
     * @param array $manifest Manifest data
     * @return array Check result
     */
    private function check_php_extensions(array $manifest = []): array {
        $required_extensions = ['zip', 'mysqli', 'json'];
        
        // Use manifest requirements if available
        if (!empty($manifest['requirements']['required_extensions'])) {
            $required_extensions = array_merge($required_extensions, $manifest['requirements']['required_extensions']);
        }
        
        $missing_extensions = [];
        $loaded_extensions = [];
        
        foreach ($required_extensions as $extension) {
            if (extension_loaded($extension)) {
                $loaded_extensions[] = $extension;
            } else {
                $missing_extensions[] = $extension;
            }
        }
        
        $passed = empty($missing_extensions);
        
        return [
            'name' => 'PHP Extensions',
            'passed' => $passed,
            'required' => $required_extensions,
            'loaded' => $loaded_extensions,
            'missing' => $missing_extensions,
            'message' => $passed 
                ? 'All required PHP extensions are loaded'
                : 'Missing PHP extensions: ' . implode(', ', $missing_extensions)
        ];
    }
    
    /**
     * Check memory limit
     * 
     * @return array Check result
     */
    private function check_memory_limit(): array {
        $memory_limit = ini_get('memory_limit');
        $memory_limit_bytes = $this->convert_to_bytes($memory_limit);
        $recommended_bytes = 256 * 1024 * 1024; // 256MB
        
        $passed = $memory_limit_bytes >= $recommended_bytes || $memory_limit_bytes === -1;
        
        return [
            'name' => 'Memory Limit',
            'passed' => $passed,
            'current' => $memory_limit,
            'recommended' => '256M',
            'message' => $passed 
                ? "Memory limit {$memory_limit} is sufficient"
                : "Memory limit {$memory_limit} may be too low. Recommended: 256M or higher"
        ];
    }
    
    /**
     * Check execution time limit
     * 
     * @return array Check result
     */
    private function check_execution_time(): array {
        $max_execution_time = ini_get('max_execution_time');
        $recommended_time = 300; // 5 minutes
        
        $passed = $max_execution_time >= $recommended_time || $max_execution_time == 0;
        
        return [
            'name' => 'Execution Time Limit',
            'passed' => $passed,
            'current' => $max_execution_time == 0 ? 'unlimited' : $max_execution_time . 's',
            'recommended' => $recommended_time . 's',
            'message' => $passed 
                ? "Execution time limit is sufficient"
                : "Execution time limit ({$max_execution_time}s) may be too low for large exports/imports"
        ];
    }
    
    /**
     * Check available disk space
     * 
     * @return array Check result
     */
    private function check_disk_space(): array {
        $upload_dir = wp_upload_dir()['basedir'];
        $free_bytes = disk_free_space($upload_dir);
        $required_bytes = 1024 * 1024 * 1024; // 1GB
        
        $passed = $free_bytes >= $required_bytes;
        
        return [
            'name' => 'Disk Space',
            'passed' => $passed,
            'available' => size_format($free_bytes),
            'recommended' => '1GB',
            'message' => $passed 
                ? "Available disk space (" . size_format($free_bytes) . ") is sufficient"
                : "Low disk space. Available: " . size_format($free_bytes) . ", Recommended: 1GB+"
        ];
    }
    
    /**
     * Check file permissions
     * 
     * @return array Check result
     */
    private function check_file_permissions(): array {
        $directories_to_check = [
            wp_upload_dir()['basedir'],
            WP_CONTENT_DIR,
            ABSPATH
        ];
        
        $permission_issues = [];
        
        foreach ($directories_to_check as $dir) {
            if (!is_writable($dir)) {
                $permission_issues[] = $dir;
            }
        }
        
        $passed = empty($permission_issues);
        
        return [
            'name' => 'File Permissions',
            'passed' => $passed,
            'checked_directories' => $directories_to_check,
            'issues' => $permission_issues,
            'message' => $passed 
                ? 'All required directories are writable'
                : 'Permission issues with directories: ' . implode(', ', $permission_issues)
        ];
    }
    
    /**
     * Check database compatibility
     * 
     * @param array $manifest Manifest data
     * @return array Check result
     */
    private function check_database_compatibility(array $manifest): array {
        global $wpdb;
        
        $current_mysql_version = $wpdb->get_var("SELECT VERSION()");
        $source_mysql_version = $manifest['site_info']['mysql_version'] ?? '';
        
        // Extract major.minor version for comparison
        $current_major_minor = $this->extract_mysql_version($current_mysql_version);
        $source_major_minor = $this->extract_mysql_version($source_mysql_version);
        
        // Check if versions are compatible (current should be >= source)
        $passed = version_compare($current_major_minor, $source_major_minor, '>=');
        
        // Check charset compatibility
        $current_charset = $wpdb->get_var("SELECT @@character_set_database");
        $charset_compatible = true; // Assume compatible unless we find issues
        
        return [
            'name' => 'Database Compatibility',
            'passed' => $passed && $charset_compatible,
            'current_version' => $current_mysql_version,
            'source_version' => $source_mysql_version,
            'current_charset' => $current_charset,
            'message' => $passed 
                ? 'Database versions are compatible'
                : "Database version mismatch. Current: {$current_mysql_version}, Source: {$source_mysql_version}"
        ];
    }
    
    /**
     * Get system information
     * 
     * @return array System information
     */
    public function get_system_info(): array {
        global $wpdb;
        
        return [
            'php' => [
                'version' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'extensions' => get_loaded_extensions()
            ],
            'wordpress' => [
                'version' => get_bloginfo('version'),
                'multisite' => is_multisite(),
                'debug' => defined('WP_DEBUG') && WP_DEBUG,
                'language' => get_locale(),
                'timezone' => get_option('timezone_string'),
                'upload_dir' => wp_upload_dir()
            ],
            'database' => [
                'version' => $wpdb->get_var("SELECT VERSION()"),
                'charset' => $wpdb->get_var("SELECT @@character_set_database"),
                'collation' => $wpdb->get_var("SELECT @@collation_database"),
                'prefix' => $wpdb->prefix
            ],
            'server' => [
                'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'os' => PHP_OS,
                'architecture' => php_uname('m'),
                'disk_free_space' => disk_free_space(ABSPATH),
                'disk_total_space' => disk_total_space(ABSPATH)
            ]
        ];
    }
    
    /**
     * Convert memory limit string to bytes
     * 
     * @param string $memory_limit Memory limit string (e.g., "256M")
     * @return int Memory limit in bytes
     */
    private function convert_to_bytes(string $memory_limit): int {
        if ($memory_limit === '-1') {
            return -1; // Unlimited
        }
        
        $memory_limit = trim($memory_limit);
        $last_char = strtolower($memory_limit[strlen($memory_limit) - 1]);
        $number = (int) $memory_limit;
        
        switch ($last_char) {
            case 'g':
                $number *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $number *= 1024 * 1024;
                break;
            case 'k':
                $number *= 1024;
                break;
        }
        
        return $number;
    }
    
    /**
     * Extract MySQL major.minor version
     * 
     * @param string $version Full MySQL version string
     * @return string Major.minor version
     */
    private function extract_mysql_version(string $version): string {
        if (preg_match('/^(\d+\.\d+)/', $version, $matches)) {
            return $matches[1];
        }
        return $version;
    }
}