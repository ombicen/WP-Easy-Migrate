<?php

namespace WPEasyMigrate;

/**
 * ImportSession Class
 * 
 * Manages import session state and progress tracking
 */
class ImportSession
{
    /**
     * Session option key
     */
    const OPTION_KEY = 'wp_easy_migrate_import_session';

    /**
     * Import steps
     */
    const STEPS = [
        'upload_file',
        'extract_archive',
        'validate_manifest',
        'backup_current_site',
        'import_database',
        'import_files',
        'update_urls',
        'cleanup'
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
        $this->logger->log("Import session saved: Step {$this->data['current_step']}, Progress {$this->data['progress']}%", 'debug');
    }

    /**
     * Reset session to initial state
     */
    public function reset(): void
    {
        $this->data = $this->get_default_data();
        $this->save();
        $this->logger->log('Import session reset', 'info');
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
            'current_step' => 'upload_file',
            'step_index' => 0,
            'progress' => 0,
            'completed' => false,
            'error' => null,
            'import_id' => null,
            'import_dir' => null,
            'archive_path' => null,
            'extracted_dir' => null,
            'manifest' => null,
            'backup_path' => null,
            'started_at' => current_time('mysql'),
            'last_updated' => current_time('mysql'),
            'files_imported' => [],
            'current_operation' => '',
            'total_operations' => 0,
            'completed_operations' => 0
        ];
    }

    /**
     * Start new import session
     */
    public function start(): void
    {
        $this->reset();

        $import_id = date('Y-m-d_H-i-s') . '_' . wp_generate_password(8, false);
        $import_dir = WP_EASY_MIGRATE_UPLOADS_DIR . 'imports/' . $import_id . '/';

        $this->data['import_id'] = $import_id;
        $this->data['import_dir'] = $import_dir;

        $this->save();
        $this->logger->log("Import session started: {$import_id}", 'info');
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
        $this->save();
    }

    // Getters
    public function get_session_id(): string
    {
        return $this->data['session_id'];
    }

    public function get_current_step(): string
    {
        return $this->data['current_step'];
    }

    public function get_progress(): int
    {
        return $this->data['progress'];
    }

    public function is_completed(): bool
    {
        return $this->data['completed'];
    }

    public function get_error(): ?string
    {
        return $this->data['error'];
    }

    public function get_import_id(): ?string
    {
        return $this->data['import_id'];
    }

    public function get_import_dir(): ?string
    {
        return $this->data['import_dir'];
    }

    public function set_archive_path(string $path): void
    {
        $this->data['archive_path'] = $path;
        $this->save();
    }

    public function get_archive_path(): ?string
    {
        return $this->data['archive_path'];
    }

    public function set_extracted_dir(string $dir): void
    {
        $this->data['extracted_dir'] = $dir;
        $this->save();
    }

    public function get_extracted_dir(): ?string
    {
        return $this->data['extracted_dir'];
    }

    public function set_manifest(array $manifest): void
    {
        $this->data['manifest'] = $manifest;
        $this->save();
    }

    public function get_manifest(): ?array
    {
        return $this->data['manifest'];
    }

    public function set_backup_path(string $path): void
    {
        $this->data['backup_path'] = $path;
        $this->save();
    }

    public function get_backup_path(): ?string
    {
        return $this->data['backup_path'];
    }

    public function add_imported_file(string $type): void
    {
        if (!in_array($type, $this->data['files_imported'])) {
            $this->data['files_imported'][] = $type;
            $this->save();
        }
    }

    public function get_imported_files(): array
    {
        return $this->data['files_imported'];
    }

    public function set_current_operation(string $operation): void
    {
        $this->data['current_operation'] = $operation;
        $this->save();
    }

    public function get_current_operation(): string
    {
        return $this->data['current_operation'];
    }

    /**
     * Get session status for API response
     * 
     * @return array Session status
     */
    public function get_status(): array
    {
        return [
            'session_id' => $this->data['session_id'],
            'step' => $this->data['current_step'],
            'step_index' => $this->data['step_index'],
            'progress' => $this->data['progress'],
            'completed' => $this->data['completed'],
            'error' => $this->data['error'],
            'import_id' => $this->data['import_id'],
            'archive_path' => $this->data['archive_path'],
            'current_operation' => $this->data['current_operation'],
            'files_imported' => $this->data['files_imported'],
            'started_at' => $this->data['started_at'],
            'last_updated' => $this->data['last_updated']
        ];
    }
}
