<?php

namespace WPEasyMigrate;

/**
 * ExportSession Class
 * 
 * Manages export session state and progress tracking
 */
class ExportSession
{

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
    public function __construct()
    {
        $this->logger = new Logger();
        $this->load();
    }

    /**
     * Load session data from database
     */
    public function load(): void
    {
        $this->data = get_option(self::OPTION_KEY, $this->get_default_data());

        // Ensure data integrity
        if (!is_array($this->data) || !isset($this->data['session_id'])) {
            $this->data = $this->get_default_data();
        }
    }

    /**
     * Save session data to database
     */
    public function save(): void
    {
        $this->data['last_updated'] = current_time('mysql');
        update_option(self::OPTION_KEY, $this->data);
        $this->logger->log("Export session saved: Step {$this->data['current_step']}, Progress {$this->data['progress']}%", 'debug');
    }

    /**
     * Reset session to initial state
     */
    public function reset(): void
    {
        $this->data = $this->get_default_data();
        $this->save();
        $this->logger->log('Export session reset', 'info');
    }

    /**
     * Get default session data
     * 
     * @return array Default session data
     */
    private function get_default_data(): array
    {
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
            'relative_paths' => [],
            'total_files' => 0,
            'total_size' => 0,
            'current_index' => 0,
            'start_time' => null,
            'last_runtime' => [],
            'estimated_size_remaining' => 0,
            'estimated_time_remaining' => 0,
            // Database export tracking
            'db_tables' => [],
            'current_table' => null,
            'table_offset' => 0,
            'db_export_path' => null,
            'db_total_tables' => 0,
            'db_current_table_index' => 0,
            // Adaptive batching for small tables
            'db_tables_info' => [],
            'db_table_batches' => [],
            'db_current_batch_index' => 0,
            'db_adaptive_mode' => false,
            // Batch processing settings - HIGH PERFORMANCE DEFAULTS
            'files_per_step' => 100, // Increased from 50 to 100 for better performance
            'db_rows_per_step' => 15000, // Increased from 5000 to 15000 for much better performance
            'use_optimized_db_export' => true // Enable optimized database export by default
        ];
    }

    /**
     * Start new export session
     * 
     * @param array $options Export options
     */
    public function start(array $options = []): void
    {
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
            'files_per_step' => 100,
            'db_export_mode' => 'ultra', // Use ultra-optimized by default
            'db_rows_per_step' => 15000,
            'exclude_patterns' => [
                '*.log',
                '*/cache/*',
                '*/wp-easy-migrate/*'
            ]
        ]);

        // Set files per step from options immediately
        if (isset($options['files_per_step'])) {
            $this->data['files_per_step'] = max(1, (int) $options['files_per_step']);
        }

        // Set database options
        if (isset($options['db_rows_per_step'])) {
            $this->data['db_rows_per_step'] = max(100, min(50000, (int) $options['db_rows_per_step']));
        }

        if (isset($options['db_export_mode'])) {
            $this->data['use_optimized_db_export'] = ($options['db_export_mode'] === 'optimized' || $options['db_export_mode'] === 'ultra');
        }

        $this->save();
        $this->logger->log("Export session started: {$export_id} with {$this->data['files_per_step']} files per step, {$this->data['db_rows_per_step']} DB rows per step, " . ($this->data['use_optimized_db_export'] ? 'optimized' : 'standard') . " DB mode", 'info');
    }

    /**
     * Move to next step
     */
    public function next_step(): void
    {
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
    private function update_progress(): void
    {
        $total_steps = count(self::STEPS);
        $current_index = $this->data['step_index'];
        $this->data['progress'] = round(($current_index / $total_steps) * 100);
    }

    /**
     * Set error state
     * 
     * @param string $error Error message
     */
    public function set_error(string $error): void
    {
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
    public function is_active(): bool
    {
        return !$this->data['completed'] && empty($this->data['error']);
    }

    /**
     * Check if session is completed
     * 
     * @return bool True if session is completed
     */
    public function is_completed(): bool
    {
        return $this->data['completed'];
    }

    /**
     * Check if session has error
     * 
     * @return bool True if session has error
     */
    public function has_error(): bool
    {
        return !empty($this->data['error']);
    }

    /**
     * Get current step
     * 
     * @return string Current step name
     */
    public function get_current_step(): string
    {
        return $this->data['current_step'];
    }

    /**
     * Get progress percentage
     * 
     * @return int Progress percentage (0-100)
     */
    public function get_progress(): int
    {
        return $this->data['progress'];
    }

    /**
     * Get export ID
     * 
     * @return string|null Export ID
     */
    public function get_export_id(): ?string
    {
        return $this->data['export_id'];
    }

    /**
     * Get export directory
     * 
     * @return string|null Export directory path
     */
    public function get_export_dir(): ?string
    {
        return $this->data['export_dir'];
    }

    /**
     * Get export options
     * 
     * @return array Export options
     */
    public function get_options(): array
    {
        return $this->data['options'];
    }

    /**
     * Get exported files
     * 
     * @return array Exported files
     */
    public function get_files_exported(): array
    {
        return $this->data['files_exported'];
    }

    /**
     * Add exported file
     * 
     * @param string $type File type (uploads, plugins, themes)
     * @param string $path File path
     */
    public function add_exported_file(string $type, string $path): void
    {
        $this->data['files_exported'][$type] = $path;
        $this->save();
    }

    /**
     * Get archive path
     * 
     * @return string|null Archive path
     */
    public function get_archive_path(): ?string
    {
        return $this->data['archive_path'];
    }

    /**
     * Set archive path
     * 
     * @param string $path Archive path
     */
    public function set_archive_path(string $path): void
    {
        $this->data['archive_path'] = $path;
        $this->save();
    }

    /**
     * Get error message
     * 
     * @return string|null Error message
     */
    public function get_error(): ?string
    {
        return $this->data['error'];
    }

    /**
     * Get step data
     * 
     * @param string $key Data key
     * @return mixed Step data value
     */
    public function get_step_data(string $key)
    {
        return $this->data['step_data'][$key] ?? null;
    }

    /**
     * Set step data
     * 
     * @param string $key Data key
     * @param mixed $value Data value
     */
    public function set_step_data(string $key, $value): void
    {
        $this->data['step_data'][$key] = $value;
        $this->save();
    }

    /**
     * Get session status for API response
     * 
     * @return array Session status
     */
    public function get_status(): array
    {
        $status = [
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

        // Include step data for download functionality
        if (!empty($this->data['step_data'])) {
            if (isset($this->data['step_data']['archive_parts'])) {
                $status['archive_parts'] = $this->data['step_data']['archive_parts'];
            }
            if (isset($this->data['step_data']['standalone_manifest_path'])) {
                $status['standalone_manifest_path'] = $this->data['step_data']['standalone_manifest_path'];
            }
        }

        return $status;
    }

    /**
     * Clean up old sessions
     * 
     * @param int $max_age Maximum age in seconds (default: 24 hours)
     */
    public static function cleanup_old_sessions(int $max_age = 86400): void
    {
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
    public function get_estimated_time_remaining(): int
    {
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
     * @param array $relative_paths Array of relative paths
     */
    public function set_file_list(array $file_list, array $file_sizes, array $relative_paths = []): void
    {
        $this->data['file_list'] = $file_list;
        $this->data['file_sizes'] = $file_sizes;
        $this->data['relative_paths'] = $relative_paths;
        $this->data['total_files'] = count($file_list);
        $this->data['total_size'] = array_sum($file_sizes);
        $this->data['current_index'] = 0;
        $this->data['start_time'] = microtime(true);
        $this->data['estimated_size_remaining'] = $this->data['total_size'];
        $this->save();
    }

    /**
     * Get relative paths for files
     *
     * @return array
     */
    public function get_relative_paths(): array
    {
        return $this->data['relative_paths'] ?? [];
    }

    /**
     * Get file list
     * 
     * @return array File list
     */
    public function get_file_list(): array
    {
        return $this->data['file_list'] ?? [];
    }

    /**
     * Get file sizes
     * 
     * @return array File sizes
     */
    public function get_file_sizes(): array
    {
        return $this->data['file_sizes'] ?? [];
    }

    /**
     * Get total files count
     * 
     * @return int Total files
     */
    public function get_total_files(): int
    {
        return $this->data['total_files'] ?? 0;
    }

    /**
     * Get total size
     * 
     * @return int Total size in bytes
     */
    public function get_total_size(): int
    {
        return $this->data['total_size'] ?? 0;
    }

    /**
     * Get current file index
     * 
     * @return int Current index
     */
    public function get_current_index(): int
    {
        return $this->data['current_index'] ?? 0;
    }

    /**
     * Increment current index and update progress
     * 
     * @param float $runtime Runtime for this file in seconds
     */
    public function increment_index(float $runtime): void
    {
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
    public function is_archiving_complete(): bool
    {
        return $this->data['current_index'] >= $this->data['total_files'];
    }

    /**
     * Get current file path
     * 
     * @return string|null Current file path
     */
    public function get_current_file(): ?string
    {
        $index = $this->data['current_index'];
        return $this->data['file_list'][$index] ?? null;
    }

    /**
     * Get current file size
     * 
     * @return int Current file size
     */
    public function get_current_file_size(): int
    {
        $index = $this->data['current_index'];
        return $this->data['file_sizes'][$index] ?? 0;
    }

    /**
     * Update estimated size remaining
     */
    private function update_estimated_size_remaining(): void
    {
        $remaining_files = array_slice($this->data['file_sizes'], $this->data['current_index']);
        $this->data['estimated_size_remaining'] = array_sum($remaining_files);
    }

    /**
     * Update estimated time remaining
     */
    private function update_estimated_time_remaining(): void
    {
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
    public function get_estimated_size_remaining(): int
    {
        return $this->data['estimated_size_remaining'] ?? 0;
    }

    /**
     * Get estimated time remaining for file archiving
     * 
     * @return int Estimated time remaining in seconds
     */
    public function get_file_archiving_time_remaining(): int
    {
        return $this->data['estimated_time_remaining'] ?? 0;
    }

    /**
     * Get enhanced status for API response
     * 
     * @return array Enhanced session status
     */
    public function get_enhanced_status(): array
    {
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

    // Database export methods

    /**
     * Initialize database export
     * 
     * @param array $tables Array of table names
     */
    public function init_database_export(array $tables): void
    {
        $this->logger->log("Initializing database export with " . count($tables) . " tables", 'info');

        if (empty($tables)) {
            $this->logger->log('No tables to export', 'warning');
            // Set current_table to null to indicate completion
            $this->data['current_table'] = null;
        } else {
            $this->data['current_table'] = $tables[0];
            $this->logger->log("First table to export: {$tables[0]}", 'info');
        }

        $this->data['db_tables'] = $tables;
        $this->data['db_total_tables'] = count($tables);
        $this->data['db_current_table_index'] = 0;
        $this->data['table_offset'] = 0;

        // Ensure export directory exists before setting database path
        $export_dir = $this->get_export_dir();
        $this->logger->log("Export directory: {$export_dir}", 'info');

        if (!$export_dir) {
            throw new \Exception('Export directory not set in session');
        }

        if (!is_dir($export_dir)) {
            $result = wp_mkdir_p($export_dir);
            if (!$result) {
                throw new \Exception("Failed to create export directory: {$export_dir}");
            }
            $this->logger->log("Created export directory for database: {$export_dir}", 'info');
        }

        $this->data['db_export_path'] = $export_dir . 'database.sql';
        $this->logger->log("Database export path set to: {$this->data['db_export_path']}", 'info');

        // Verify the path was set correctly
        if (!$this->data['db_export_path']) {
            throw new \Exception('Failed to set database export path');
        }

        $this->save();

        // Verify the path is still set after save
        $this->load();
        $saved_path = $this->get_db_export_path();
        $this->logger->log("Database export path after save/load: {$saved_path}", 'info');

        if (!$saved_path) {
            throw new \Exception('Database export path was not saved correctly');
        }
    }

    /**
     * Initialize adaptive database export with table batching
     * 
     * @param array $tables_info Array of table information with sizes
     */
    public function init_adaptive_database_export(array $tables_info): void
    {
        $this->logger->log("Initializing adaptive database export with " . count($tables_info) . " tables", 'info');

        if (empty($tables_info)) {
            $this->logger->log('No tables to export', 'warning');
            $this->data['current_table'] = null;
            $this->data['db_adaptive_mode'] = false;
            return;
        }

        // Store table information
        $this->data['db_tables_info'] = $tables_info;
        $this->data['db_adaptive_mode'] = true;

        // Create batches: group small tables together, keep large tables separate
        $batches = [];
        $current_small_batch = [];
        $small_tables_count = 0;
        $small_tables_total_size = 0;
        $max_batch_size = 5; // Maximum 5 small tables per batch
        $max_batch_total_rows = 5000; // Maximum total rows per batch

        foreach ($tables_info as $table_info) {
            if ($table_info['is_small']) {
                // Add to current small batch if it doesn't exceed limits
                if (
                    count($current_small_batch) < $max_batch_size &&
                    ($small_tables_total_size + $table_info['rows']) <= $max_batch_total_rows
                ) {

                    $current_small_batch[] = $table_info;
                    $small_tables_total_size += $table_info['rows'];
                    $small_tables_count++;
                } else {
                    // Current batch is full, start a new one
                    if (!empty($current_small_batch)) {
                        $batches[] = $current_small_batch;
                    }
                    $current_small_batch = [$table_info];
                    $small_tables_total_size = $table_info['rows'];
                }
            } else {
                // Large table gets its own batch
                if (!empty($current_small_batch)) {
                    $batches[] = $current_small_batch;
                    $current_small_batch = [];
                    $small_tables_total_size = 0;
                }
                $batches[] = [$table_info];
            }
        }

        // Add any remaining small tables
        if (!empty($current_small_batch)) {
            $batches[] = $current_small_batch;
        }

        $this->data['db_table_batches'] = $batches;
        $this->data['db_current_batch_index'] = 0;
        $this->data['db_total_tables'] = count($tables_info);
        $this->data['table_offset'] = 0;

        // Set current table for compatibility
        if (!empty($batches) && !empty($batches[0])) {
            $this->data['current_table'] = $batches[0][0]['name'];
        } else {
            $this->data['current_table'] = null;
        }

        // Ensure export directory exists before setting database path
        $export_dir = $this->get_export_dir();
        if (!$export_dir) {
            throw new \Exception('Export directory not set in session');
        }

        if (!is_dir($export_dir)) {
            $result = wp_mkdir_p($export_dir);
            if (!$result) {
                throw new \Exception("Failed to create export directory: {$export_dir}");
            }
        }

        $this->data['db_export_path'] = $export_dir . 'database.sql';

        $this->logger->log("Adaptive database export initialized: " . count($batches) . " batches, {$small_tables_count} small tables grouped", 'info');

        $this->save();
    }

    /**
     * Get database tables
     * 
     * @return array Database tables
     */
    public function get_db_tables(): array
    {
        return $this->data['db_tables'] ?? [];
    }

    /**
     * Get current table being exported
     * 
     * @return string|null Current table name
     */
    public function get_current_table(): ?string
    {
        return $this->data['current_table'] ?? null;
    }

    /**
     * Get current table offset
     * 
     * @return int Current offset
     */
    public function get_table_offset(): int
    {
        return $this->data['table_offset'] ?? 0;
    }

    /**
     * Get database export path
     * 
     * @return string|null Database export path
     */
    public function get_db_export_path(): ?string
    {
        return $this->data['db_export_path'] ?? null;
    }

    /**
     * Set database export path
     * 
     * @param string $path Database export path
     */
    public function set_db_export_path(string $path): void
    {
        $this->data['db_export_path'] = $path;
        $this->save();
    }

    /**
     * Get database rows per step
     * 
     * @return int Rows per step
     */
    public function get_db_rows_per_step(): int
    {
        return $this->data['db_rows_per_step'] ?? 5000;
    }

    /**
     * Set database rows per step
     * 
     * @param int $rows Rows per step
     */
    public function set_db_rows_per_step(int $rows): void
    {
        $this->data['db_rows_per_step'] = max(100, min(50000, $rows));
        $this->save();
    }

    /**
     * Check if optimized database export is enabled
     * 
     * @return bool True if optimized export is enabled
     */
    public function use_optimized_db_export(): bool
    {
        return $this->data['use_optimized_db_export'] ?? true;
    }

    /**
     * Set optimized database export setting
     * 
     * @param bool $enabled Whether to use optimized export
     */
    public function set_optimized_db_export(bool $enabled): void
    {
        $this->data['use_optimized_db_export'] = $enabled;
        $this->save();
    }

    /**
     * Get current table batch for adaptive processing
     * 
     * @return array Current batch of tables to process
     */
    public function get_current_table_batch(): array
    {
        if (!$this->data['db_adaptive_mode'] || empty($this->data['db_table_batches'])) {
            // Fallback to single table mode
            $current_table = $this->get_current_table();
            if ($current_table) {
                return [['name' => $current_table, 'rows' => 0, 'size' => 0, 'is_small' => false]];
            }
            return [];
        }

        $batch_index = $this->data['db_current_batch_index'] ?? 0;
        if ($batch_index < count($this->data['db_table_batches'])) {
            return $this->data['db_table_batches'][$batch_index];
        }

        return [];
    }

    /**
     * Move to next table batch
     */
    public function next_table_batch(): void
    {
        if ($this->data['db_adaptive_mode']) {
            $this->data['db_current_batch_index']++;
            $this->data['table_offset'] = 0;

            // Update current table for compatibility
            $next_batch = $this->get_current_table_batch();
            if (!empty($next_batch)) {
                $this->data['current_table'] = $next_batch[0]['name'];
            } else {
                $this->data['current_table'] = null;
            }
        } else {
            // Fallback to original behavior
            $this->next_table();
        }

        $this->save();
    }

    /**
     * Move to next table
     */
    public function next_table(): void
    {
        if ($this->data['db_adaptive_mode']) {
            $this->next_table_batch();
            return;
        }

        $this->data['db_current_table_index']++;
        $this->data['table_offset'] = 0;

        if ($this->data['db_current_table_index'] < count($this->data['db_tables'])) {
            $this->data['current_table'] = $this->data['db_tables'][$this->data['db_current_table_index']];
        } else {
            $this->data['current_table'] = null;
        }

        $this->save();
    }

    /**
     * Update table offset
     * 
     * @param int $offset New offset
     */
    public function update_table_offset(int $offset): void
    {
        $this->data['table_offset'] = $offset;
        $this->save();
    }

    /**
     * Mark a table as completed
     * 
     * @param string $table_name Table name that was completed
     */
    public function mark_table_completed(string $table_name): void
    {
        if (!isset($this->data['completed_tables'])) {
            $this->data['completed_tables'] = [];
        }

        if (!in_array($table_name, $this->data['completed_tables'])) {
            $this->data['completed_tables'][] = $table_name;
        }

        $this->save();
    }

    /**
     * Get list of completed tables
     * 
     * @return array List of completed table names
     */
    public function get_completed_tables(): array
    {
        return $this->data['completed_tables'] ?? [];
    }

    /**
     * Check if database export is complete
     * 
     * @return bool True if complete
     */
    public function is_database_export_complete(): bool
    {
        // Check if all tables are completed (works for both modes)
        $total_tables = $this->data['db_total_tables'] ?? 0;
        $completed_tables = count($this->get_completed_tables());
        if ($total_tables > 0 && $completed_tables >= $total_tables) {
            return true;
        }

        if ($this->data['db_adaptive_mode']) {
            // In adaptive mode, check if all batches are processed
            $total_batches = count($this->data['db_table_batches'] ?? []);
            $current_batch_index = $this->data['db_current_batch_index'] ?? 0;
            return $current_batch_index >= $total_batches;
        }

        // Legacy mode: check if current table is null
        return $this->data['current_table'] === null;
    }

    /**
     * Get database export progress
     * 
     * @return int Progress percentage (0-100)
     */
    public function get_database_export_progress(): int
    {
        if ($this->data['db_adaptive_mode']) {
            $total_batches = count($this->data['db_table_batches'] ?? []);
            if ($total_batches === 0) {
                return 100;
            }

            $current_batch = $this->data['db_current_batch_index'] ?? 0;
            return round(($current_batch / $total_batches) * 100);
        }

        if ($this->data['db_total_tables'] === 0) {
            return 100;
        }

        return round(($this->data['db_current_table_index'] / $this->data['db_total_tables']) * 100);
    }

    // Batch processing methods

    /**
     * Get files per step
     * 
     * @return int Files per step
     */
    public function get_files_per_step(): int
    {
        return $this->data['files_per_step'] ?? 50;
    }

    /**
     * Set files per step
     * 
     * @param int $count Files per step
     */
    public function set_files_per_step(int $count): void
    {
        $this->data['files_per_step'] = max(1, min(200, $count));
        $this->save();
    }

    /**
     * Increment current index by batch size and update progress
     * 
     * @param int $batch_size Number of files processed
     * @param float $runtime Runtime for this batch in seconds
     */
    public function increment_index_batch(int $batch_size, float $runtime): void
    {
        $this->data['current_index'] += $batch_size;

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
     * Get current batch of files with adaptive sizing
     * 
     * @return array Array of file paths for current batch
     */
    public function get_current_batch(): array
    {
        $start_index = $this->data['current_index'];
        $base_batch_size = $this->data['files_per_step'];

        // Adaptive batch sizing based on file sizes
        $adaptive_batch_size = $this->get_adaptive_batch_size($start_index, $base_batch_size);

        return array_slice($this->data['file_list'], $start_index, $adaptive_batch_size);
    }

    /**
     * Calculate adaptive batch size based on file sizes to optimize performance
     * 
     * @param int $start_index Starting index in file list
     * @param int $base_batch_size Base batch size
     * @return int Optimized batch size
     */
    private function get_adaptive_batch_size(int $start_index, int $base_batch_size): int
    {
        if (empty($this->data['file_sizes'])) {
            return $base_batch_size;
        }

        // Look ahead at upcoming files to adjust batch size
        $max_files_to_check = min($base_batch_size * 2, count($this->data['file_sizes']) - $start_index);
        $upcoming_sizes = array_slice($this->data['file_sizes'], $start_index, $max_files_to_check);

        if (empty($upcoming_sizes)) {
            return 1; // Last file
        }

        $total_upcoming_size = array_sum($upcoming_sizes);
        $avg_file_size = $total_upcoming_size / count($upcoming_sizes);

        // Adaptive sizing logic:
        // - For small files (< 100KB avg): Use larger batches (up to 100 files)
        // - For medium files (100KB - 5MB avg): Use moderate batches (25-75 files)  
        // - For large files (> 5MB avg): Use smaller batches (10-25 files)

        if ($avg_file_size < 102400) { // < 100KB
            $adaptive_size = min(100, $base_batch_size * 2);
        } elseif ($avg_file_size < 5242880) { // < 5MB
            $adaptive_size = max(25, min(75, $base_batch_size));
        } else { // >= 5MB
            $adaptive_size = max(10, min(25, $base_batch_size));
        }

        // Don't exceed remaining files
        $remaining_files = count($this->data['file_list']) - $start_index;
        return min($adaptive_size, $remaining_files);
    }

    /**
     * Get current batch of file sizes
     * 
     * @return array Array of file sizes for current batch
     */
    public function get_current_batch_sizes(): array
    {
        $start_index = $this->data['current_index'];
        $batch_size = $this->data['files_per_step'];

        return array_slice($this->data['file_sizes'], $start_index, $batch_size);
    }

    /**
     * Get enhanced status with database and batch info
     * 
     * @return array Enhanced session status
     */
    public function get_enhanced_status_with_db(): array
    {
        $status = $this->get_enhanced_status();

        // Calculate tables processed using completed tables list for accuracy
        $completed_tables = $this->get_completed_tables();
        $tables_processed = count($completed_tables);

        // For legacy mode, fall back to index if no completed tables tracked
        if (!($this->data['db_adaptive_mode'] ?? false) && $tables_processed === 0) {
            $tables_processed = $this->data['db_current_table_index'] ?? 0;
        }

        // Add database export specific data
        $status['database_export'] = [
            'total_tables' => $this->data['db_total_tables'] ?? 0,
            'current_table_index' => $tables_processed, // Use calculated value for both modes
            'tables_processed' => $tables_processed, // Add explicit field for clarity
            'current_table' => $this->data['current_table'] ?? null,
            'table_offset' => $this->data['table_offset'] ?? 0,
            'progress' => $this->get_database_export_progress(),
            'adaptive_mode' => $this->data['db_adaptive_mode'] ?? false,
            'total_batches' => count($this->data['db_table_batches'] ?? []),
            'current_batch_index' => $this->data['db_current_batch_index'] ?? 0,
            'current_batch_size' => count($this->get_current_table_batch())
        ];

        // Add batch processing info
        $status['batch_processing'] = [
            'files_per_step' => $this->data['files_per_step'] ?? 50,
            'db_rows_per_step' => $this->data['db_rows_per_step'] ?? 5000
        ];

        return $status;
    }
}
