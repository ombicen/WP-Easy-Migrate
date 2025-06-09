<?php

namespace WPEasyMigrate;

/**
 * Logger Class
 * 
 * Handles logging operations for the WP Easy Migrate plugin
 */
class Logger {
    
    /**
     * Log levels
     */
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_CRITICAL = 'critical';
    
    /**
     * Log file path
     */
    private $log_file;
    
    /**
     * Maximum log file size in bytes (10MB)
     */
    private $max_file_size = 10485760;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->log_file = WP_EASY_MIGRATE_LOGS_DIR . 'wp-easy-migrate.log';
        $this->ensure_log_directory();
    }
    
    /**
     * Log a message
     * 
     * @param string $message Log message
     * @param string $level Log level
     * @param array $context Additional context data
     */
    public function log(string $message, string $level = self::LEVEL_INFO, array $context = []): void {
        $this->rotate_log_if_needed();
        
        $timestamp = current_time('Y-m-d H:i:s');
        $level = strtoupper($level);
        
        // Format context data
        $context_string = '';
        if (!empty($context)) {
            $context_string = ' | Context: ' . wp_json_encode($context);
        }
        
        // Get memory usage
        $memory_usage = size_format(memory_get_usage(true));
        
        // Format log entry
        $log_entry = sprintf(
            "[%s] [%s] [Memory: %s] %s%s\n",
            $timestamp,
            $level,
            $memory_usage,
            $message,
            $context_string
        );
        
        // Write to file
        file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        // Also log to WordPress debug log if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WP Easy Migrate [{$level}]: {$message}");
        }
    }
    
    /**
     * Log debug message
     * 
     * @param string $message Debug message
     * @param array $context Additional context
     */
    public function debug(string $message, array $context = []): void {
        $this->log($message, self::LEVEL_DEBUG, $context);
    }
    
    /**
     * Log info message
     * 
     * @param string $message Info message
     * @param array $context Additional context
     */
    public function info(string $message, array $context = []): void {
        $this->log($message, self::LEVEL_INFO, $context);
    }
    
    /**
     * Log warning message
     * 
     * @param string $message Warning message
     * @param array $context Additional context
     */
    public function warning(string $message, array $context = []): void {
        $this->log($message, self::LEVEL_WARNING, $context);
    }
    
    /**
     * Log error message
     * 
     * @param string $message Error message
     * @param array $context Additional context
     */
    public function error(string $message, array $context = []): void {
        $this->log($message, self::LEVEL_ERROR, $context);
    }
    
    /**
     * Log critical message
     * 
     * @param string $message Critical message
     * @param array $context Additional context
     */
    public function critical(string $message, array $context = []): void {
        $this->log($message, self::LEVEL_CRITICAL, $context);
    }
    
    /**
     * Get recent log entries
     * 
     * @param int $lines Number of lines to retrieve
     * @return array Array of log entries
     */
    public function get_recent_logs(int $lines = 100): array {
        if (!file_exists($this->log_file)) {
            return [];
        }
        
        $log_content = file_get_contents($this->log_file);
        $log_lines = explode("\n", trim($log_content));
        
        // Get the last N lines
        $recent_lines = array_slice($log_lines, -$lines);
        
        $parsed_logs = [];
        foreach ($recent_lines as $line) {
            if (empty($line)) {
                continue;
            }
            
            $parsed_log = $this->parse_log_line($line);
            if ($parsed_log) {
                $parsed_logs[] = $parsed_log;
            }
        }
        
        return array_reverse($parsed_logs); // Most recent first
    }
    
    /**
     * Get logs by level
     * 
     * @param string $level Log level to filter by
     * @param int $limit Maximum number of entries
     * @return array Array of log entries
     */
    public function get_logs_by_level(string $level, int $limit = 50): array {
        $all_logs = $this->get_recent_logs(1000);
        $filtered_logs = [];
        
        foreach ($all_logs as $log) {
            if (strtolower($log['level']) === strtolower($level)) {
                $filtered_logs[] = $log;
                
                if (count($filtered_logs) >= $limit) {
                    break;
                }
            }
        }
        
        return $filtered_logs;
    }
    
    /**
     * Clear log file
     */
    public function clear_logs(): void {
        if (file_exists($this->log_file)) {
            file_put_contents($this->log_file, '');
            $this->log('Log file cleared', self::LEVEL_INFO);
        }
    }
    
    /**
     * Get log file size
     * 
     * @return int File size in bytes
     */
    public function get_log_file_size(): int {
        if (!file_exists($this->log_file)) {
            return 0;
        }
        
        return filesize($this->log_file);
    }
    
    /**
     * Get formatted log file size
     * 
     * @return string Formatted file size
     */
    public function get_log_file_size_formatted(): string {
        return size_format($this->get_log_file_size());
    }
    
    /**
     * Export logs to file
     * 
     * @param string $export_path Path to export file
     * @param array $filters Filters to apply
     * @return bool Success status
     */
    public function export_logs(string $export_path, array $filters = []): bool {
        $logs = $this->get_recent_logs(10000);
        
        // Apply filters
        if (!empty($filters)) {
            $logs = $this->filter_logs($logs, $filters);
        }
        
        $export_content = "WP Easy Migrate - Log Export\n";
        $export_content .= "Generated: " . current_time('Y-m-d H:i:s') . "\n";
        $export_content .= "Total Entries: " . count($logs) . "\n";
        $export_content .= str_repeat("=", 50) . "\n\n";
        
        foreach ($logs as $log) {
            $export_content .= sprintf(
                "[%s] [%s] %s\n",
                $log['timestamp'],
                $log['level'],
                $log['message']
            );
            
            if (!empty($log['context'])) {
                $export_content .= "Context: " . wp_json_encode($log['context']) . "\n";
            }
            
            $export_content .= "\n";
        }
        
        return file_put_contents($export_path, $export_content) !== false;
    }
    
    /**
     * Ensure log directory exists
     */
    private function ensure_log_directory(): void {
        if (!file_exists(WP_EASY_MIGRATE_LOGS_DIR)) {
            wp_mkdir_p(WP_EASY_MIGRATE_LOGS_DIR);
            
            // Add .htaccess for security
            $htaccess_content = "Order deny,allow\nDeny from all\n";
            file_put_contents(WP_EASY_MIGRATE_LOGS_DIR . '.htaccess', $htaccess_content);
        }
    }
    
    /**
     * Rotate log file if it exceeds maximum size
     */
    private function rotate_log_if_needed(): void {
        if (!file_exists($this->log_file)) {
            return;
        }
        
        if (filesize($this->log_file) > $this->max_file_size) {
            $backup_file = WP_EASY_MIGRATE_LOGS_DIR . 'wp-easy-migrate-' . date('Y-m-d-H-i-s') . '.log';
            rename($this->log_file, $backup_file);
            
            // Keep only the last 5 backup files
            $this->cleanup_old_logs();
        }
    }
    
    /**
     * Clean up old log files
     */
    private function cleanup_old_logs(): void {
        $log_files = glob(WP_EASY_MIGRATE_LOGS_DIR . 'wp-easy-migrate-*.log');
        
        if (count($log_files) > 5) {
            // Sort by modification time (oldest first)
            usort($log_files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            // Remove oldest files
            $files_to_remove = array_slice($log_files, 0, count($log_files) - 5);
            foreach ($files_to_remove as $file) {
                unlink($file);
            }
        }
    }
    
    /**
     * Parse a log line into components
     * 
     * @param string $line Log line
     * @return array|null Parsed log data or null if invalid
     */
    private function parse_log_line(string $line): ?array {
        // Pattern: [timestamp] [level] [Memory: usage] message | Context: {...}
        $pattern = '/^\[([^\]]+)\] \[([^\]]+)\] \[Memory: ([^\]]+)\] (.+)$/';
        
        if (!preg_match($pattern, $line, $matches)) {
            return null;
        }
        
        $message_and_context = $matches[4];
        $context = [];
        
        // Check if there's context data
        if (strpos($message_and_context, ' | Context: ') !== false) {
            $parts = explode(' | Context: ', $message_and_context, 2);
            $message = $parts[0];
            $context_json = $parts[1];
            $context = json_decode($context_json, true) ?: [];
        } else {
            $message = $message_and_context;
        }
        
        return [
            'timestamp' => $matches[1],
            'level' => $matches[2],
            'memory' => $matches[3],
            'message' => $message,
            'context' => $context,
            'raw' => $line
        ];
    }
    
    /**
     * Filter logs based on criteria
     * 
     * @param array $logs Array of log entries
     * @param array $filters Filter criteria
     * @return array Filtered logs
     */
    private function filter_logs(array $logs, array $filters): array {
        $filtered = [];
        
        foreach ($logs as $log) {
            $include = true;
            
            // Filter by level
            if (isset($filters['level']) && strtolower($log['level']) !== strtolower($filters['level'])) {
                $include = false;
            }
            
            // Filter by date range
            if (isset($filters['date_from']) && strtotime($log['timestamp']) < strtotime($filters['date_from'])) {
                $include = false;
            }
            
            if (isset($filters['date_to']) && strtotime($log['timestamp']) > strtotime($filters['date_to'])) {
                $include = false;
            }
            
            // Filter by message content
            if (isset($filters['search']) && stripos($log['message'], $filters['search']) === false) {
                $include = false;
            }
            
            if ($include) {
                $filtered[] = $log;
            }
        }
        
        return $filtered;
    }
}