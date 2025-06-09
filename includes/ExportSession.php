<?php

namespace WPEasyMigrate;

/**
 * ExportSession Class
 * 
 * Manages export session state and progress tracking
 */
class ExportSession {
    
    /**
     * Session option key
     */
    const OPTION_KEY = 'wp_easy_migrate_export_session';
    
    /**
     * Export steps
     */
    const STEPS = [
        'prepare_export',
        'scan_files',
        'export_database', 
        'archive_files',
        'create_manifest',
        'split_archive',
        'finalize_export'
    ];
    
    /**
     * Session data
     */
    private $data;
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new Logger();
        $this->load();
    }
    
    /**
     * Load session data from database
     */
    public function load(): void {
        $this->data = get_option(self::OPTION_KEY, $this->get_default_data());
        
        // Ensure data integrity
        if (!is_array($this->data) || !isset($this->data['session_id'])) {
            $this->data = $this->get_default_data();
        }
    }
    
    /**
     * Save session data to database
     */
    public function save(): void {
        $this->data['last_updated'] = current_time('mysql');
        update_option(self::OPTION_KEY, $this->data);
        $this->logger->log("Export session saved: Step {$this->data['current_step']}, Progress {$this->data['progress']}%", 'debug');
    }
    
    /**
     * Reset session to initial state
     */
    public function reset(): void {
        $this->data = $this->get_default_data();
        $this->save();
        $this->logger->log('Export session reset', 'info');
    }
    
    /**
     * Get default session data
     * 
     * @return array Default session data
     */
    private function get_default_data(): array {
        return [
            'session_id' => wp_generate_password(16, false),
            'current_step' => 'prepare_export',
            'step_index' => 0,
            'progress' => 0,
            'completed' => false,
            'error' => null,
            'export_id' => null,
            'export_dir' => null,
            'options' => [],
            'files_exported' => [],
            'archive_path' => null,
            'started_at' => current_time('mysql'),
            'last_updated' => current_time('mysql'),
            'step_data' => [],
            // File-by-file archiving data
            'file_list' => [],
            'file_sizes' => [],
            'total_files' => 0,
            'total_size' => 0,
            'current_index' => 0,
            'start_time' => null,
            'last_runtime' => [],
            'estimated_size_remaining' => 0,
            'estimated_time_remaining' => 0
        ];
    }
    
    /**
     * Start new export session
     * 
     * @param array $options Export options
     */
    public function start(array $options = []): void {
        $this->reset();
        
        $export_id = date('Y-m-d_H-i-s') . '_' . wp_generate_password(8, false);
        $export_dir = WP_EASY_MIGRATE_UPLOADS_DIR . 'exports/' . $export_id . '/';
        
        $this->data['export_id'] = $export_id;
        $this->data['export_dir'] = $export_dir;
        $this->data['options'] = wp_parse_args($options, [
            'include_uploads' => true,
            'include_plugins' => true,
            'include_themes' => true,
            'include_database' => true,
            'split_size' => 100,
            'exclude_patterns' => [
                '*.log',
                '*/cache/*',
                '*/wp-easy-migrate/*'
            ]
        ]);
        
        $this->save();
        $this->logger->log("Export session started: {$export_id}", 'info');
    }
    
    /**
     * Move to next step
     */
    public function next_step(): void {
        $current_index = $this->data['step_index'];
        
        if ($current_index < count(self::STEPS) - 1) {
            $this->data['step_index']++;
            $this->data['current_step'] = self::STEPS[$this->data['step_index']];
            $this->update_progress();
        } else {
            $this->data['completed'] = true;
            $this->data['progress'] = 100;
        }
        
        $this->save();
    }
    
    /**
     * Update progress based on current step
     */
    private function update_progress(): void {
        $total_steps = count(self::STEPS);
        $current_index = $this->data['step_index'];
        $this->data['progress'] = round(($current_index / $total_steps) * 100);
    }
    
    /**
     * Set error state
     * 
     * @param string $error Error message
     */
    public function set_error(string $error): void {
        $this->data['error'] = $error;
        $this->data['completed'] = true;
        $this->save();
        $this->logger->log("Export session error: {$error}", 'error');
    }
    
    /**
     * Check if session is active
     * 
     * @return bool True if session is active
     */
    public function is_active(): bool {
        return !$this->data['completed'] && empty($this->data['error']);
    }
    
    /**
     * Check if session is completed
     * 
     * @return bool True if session is completed
     */
    public function is_completed(): bool {
        return $this->data['completed'];
    }
    
    /**
     * Check if session has error
     * 
     * @return bool True if session has error
     */
    public function has_error(): bool {
        return !empty($this->data['error']);
    }
    
    /**
     * Get current step
     * 
     * @return string Current step name
     */
    public function get_current_step(): string {
        return $this->data['current_step'];
    }
    
    /**
     * Get progress percentage
     * 
     * @return int Progress percentage (0-100)
     */
    public function get_progress(): int {
        return $this->data['progress'];
    }
    
    /**
     * Get export ID
     * 
     * @return string|null Export ID
     */
    public function get_export_id(): ?string {
        return $this->data['export_id'];
    }
    
    /**
     * Get export directory
     * 
     * @return string|null Export directory path
     */
    public function get_export_dir(): ?string {
        return $this->data['export_dir'];
    }
    
    /**
     * Get export options
     * 
     * @return array Export options
     */
    public function get_options(): array {
        return $this->data['options'];
    }
    
    /**
     * Get exported files
     * 
     * @return array Exported files
     */
    public function get_files_exported(): array {
        return $this->data['files_exported'];
    }
    
    /**
     * Add exported file
     * 
     * @param string $type File type (uploads, plugins, themes)
     * @param string $path File path
     */
    public function add_exported_file(string $type, string $path): void {
        $this->data['files_exported'][$type] = $path;
        $this->save();
    }
    
    /**
     * Get archive path
     * 
     * @return string|null Archive path
     */
    public function get_archive_path(): ?string {
        return $this->data['archive_path'];
    }
    
    /**
     * Set archive path
     * 
     * @param string $path Archive path
     */
    public function set_archive_path(string $path): void {
        $this->data['archive_path'] = $path;
        $this->save();
    }
    
    /**
     * Get error message
     * 
     * @return string|null Error message
     */
    public function get_error(): ?string {
        return $this->data['error'];
    }
    
    /**
     * Get step data
     * 
     * @param string $key Data key
     * @return mixed Step data value
     */
    public function get_step_data(string $key) {
        return $this->data['step_data'][$key] ?? null;
    }
    
    /**
     * Set step data
     * 
     * @param string $key Data key
     * @param mixed $value Data value
     */
    public function set_step_data(string $key, $value): void {
        $this->data['step_data'][$key] = $value;
        $this->save();
    }
    
    /**
     * Get session status for API response
     * 
     * @return array Session status
     */
    public function get_status(): array {
        return [
            'session_id' => $this->data['session_id'],
            'step' => $this->data['current_step'],
            'step_index' => $this->data['step_index'],
            'progress' => $this->data['progress'],
            'completed' => $this->data['completed'],
            'error' => $this->data['error'],
            'export_id' => $this->data['export_id'],
            'archive_path' => $this->data['archive_path'],
            'started_at' => $this->data['started_at'],
            'last_updated' => $this->data['last_updated']
        ];
    }
    
    /**
     * Clean up old sessions
     * 
     * @param int $max_age Maximum age in seconds (default: 24 hours)
     */
    public static function cleanup_old_sessions(int $max_age = 86400): void {
        $session = new self();
        $session->load();
        
        if (isset($session->data['started_at'])) {
            $started_time = strtotime($session->data['started_at']);
            if (time() - $started_time > $max_age) {
                delete_option(self::OPTION_KEY);
            }
        }
    }
    
    /**
     * Get estimated time remaining
     * 
     * @return int Estimated seconds remaining
     */
    public function get_estimated_time_remaining(): int {
        if ($this->data['progress'] <= 0) {
            return 0;
        }
        
        $elapsed = time() - strtotime($this->data['started_at']);
        $rate = $this->data['progress'] / $elapsed;
        $remaining_progress = 100 - $this->data['progress'];
        
        return $rate > 0 ? round($remaining_progress / $rate) : 0;
    }
    
    // File-by-file archiving methods
    
    /**
     * Set file list for archiving
     * 
     * @param array $file_list Array of file paths
     * @param array $file_sizes Array of file sizes
     */
    public function set_file_list(array $file_list, array $file_sizes): void {
        $this->data['file_list'] = $file_list;
        $this->data['file_sizes'] = $file_sizes;
        $this->data['total_files'] = count($file_list);
        $this->data['total_size'] = array_sum($file_sizes);
        $this->data['current_index'] = 0;
        $this->data['start_time'] = microtime(true);
        $this->data['estimated_size_remaining'] = $this->data['total_size'];
        $this->save();
    }
    
    /**
     * Get file list
     * 
     * @return array File list
     */
    public function get_file_list(): array {
        return $this->data['file_list'] ?? [];
    }
    
    /**
     * Get file sizes
     * 
     * @return array File sizes
     */
    public function get_file_sizes(): array {
        return $this->data['file_sizes'] ?? [];
    }
    
    /**
     * Get total files count
     * 
     * @return int Total files
     */
    public function get_total_files(): int {
        return $this->data['total_files'] ?? 0;
    }
    
    /**
     * Get total size
     * 
     * @return int Total size in bytes
     */
    public function get_total_size(): int {
        return $this->data['total_size'] ?? 0;
    }
    
    /**
     * Get current file index
     * 
     * @return int Current index
     */
    public function get_current_index(): int {
        return $this->data['current_index'] ?? 0;
    }
    
    /**
     * Increment current index and update progress
     * 
     * @param float $runtime Runtime for this file in seconds
     */
    public function increment_index(float $runtime): void {
        $this->data['current_index']++;
        
        // Track runtime for estimation
        $this->data['last_runtime'][] = $runtime;
        
        // Keep only last 10 runtimes for averaging
        if (count($this->data['last_runtime']) > 10) {
            $this->data['last_runtime'] = array_slice($this->data['last_runtime'], -10);
        }
        
        // Update progress
        if ($this->data['total_files'] > 0) {
            $this->data['progress'] = round(($this->data['current_index'] / $this->data['total_files']) * 100);
        }
        
        // Update estimated size remaining
        $this->update_estimated_size_remaining();
        
        // Update estimated time remaining
        $this->update_estimated_time_remaining();
        
        $this->save();
    }
    
    /**
     * Check if all files are processed
     * 
     * @return bool True if all files processed
     */
    public function is_archiving_complete(): bool {
        return $this->data['current_index'] >= $this->data['total_files'];
    }
    
    /**
     * Get current file path
     * 
     * @return string|null Current file path
     */
    public function get_current_file(): ?string {
        $index = $this->data['current_index'];
        return $this->data['file_list'][$index] ?? null;
    }
    
    /**
     * Get current file size
     * 
     * @return int Current file size
     */
    public function get_current_file_size(): int {
        $index = $this->data['current_index'];
        return $this->data['file_sizes'][$index] ?? 0;
    }
    
    /**
     * Update estimated size remaining
     */
    private function update_estimated_size_remaining(): void {
        $remaining_files = array_slice($this->data['file_sizes'], $this->data['current_index']);
        $this->data['estimated_size_remaining'] = array_sum($remaining_files);
    }
    
    /**
     * Update estimated time remaining
     */
    private function update_estimated_time_remaining(): void {
        if (empty($this->data['last_runtime'])) {
            $this->data['estimated_time_remaining'] = 0;
            return;
        }
        
        $avg_runtime = array_sum($this->data['last_runtime']) / count($this->data['last_runtime']);
        $remaining_files = $this->data['total_files'] - $this->data['current_index'];
        $this->data['estimated_time_remaining'] = round($avg_runtime * $remaining_files);
    }
    
    /**
     * Get estimated size remaining
     * 
     * @return int Estimated size remaining in bytes
     */
    public function get_estimated_size_remaining(): int {
        return $this->data['estimated_size_remaining'] ?? 0;
    }
    
    /**
     * Get estimated time remaining for file archiving
     * 
     * @return int Estimated time remaining in seconds
     */
    public function get_file_archiving_time_remaining(): int {
        return $this->data['estimated_time_remaining'] ?? 0;
    }
    
    /**
     * Get enhanced status for API response
     * 
     * @return array Enhanced session status
     */
    public function get_enhanced_status(): array {
        $status = $this->get_status();
        
        // Add file archiving specific data
        $status['file_archiving'] = [
            'total_files' => $this->get_total_files(),
            'current_index' => $this->get_current_index(),
            'total_size' => $this->get_total_size(),
            'estimated_size_remaining' => $this->get_estimated_size_remaining(),
            'estimated_time_remaining' => $this->get_file_archiving_time_remaining(),
            'current_file' => $this->get_current_file() ? basename($this->get_current_file()) : null
        ];
        
        return $status;
    }
}