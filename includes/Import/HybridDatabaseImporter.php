<?php

namespace WP_Easy_Migrate\Import;

class HybridDatabaseImporter
{
    private $logger;

    public function __construct($logger)
    {
        $this->logger = $logger;
    }

    /**
     * Import database using best available method
     */
    public function import(string $db_file): bool
    {
        if (!file_exists($db_file)) {
            throw new \Exception("Database file not found: {$db_file}");
        }

        // Check if mysql command is available for fast import
        if ($this->isMysqlAvailable()) {
            return $this->importWithMysql($db_file);
        } else {
            return $this->importWithPHP($db_file);
        }
    }

    /**
     * Check if mysql command is available
     */
    private function isMysqlAvailable(): bool
    {
        // Check if exec is disabled
        if (!function_exists('exec')) {
            $this->logger->log('exec() function is disabled', 'info');
            return false;
        }

        // Check if mysql command exists
        $output = [];
        $return_code = 0;
        exec('mysql --version 2>&1', $output, $return_code);

        if ($return_code === 0) {
            $this->logger->log('mysql command is available: ' . implode(' ', $output), 'info');
            return true;
        } else {
            $this->logger->log('mysql command not available, using PHP import', 'info');
            return false;
        }
    }

    /**
     * Import using mysql command (fast, handles mysqldump output perfectly)
     */
    private function importWithMysql(string $db_file): bool
    {
        $host = DB_HOST;
        $user = DB_USER;
        $password = DB_PASSWORD;
        $database = DB_NAME;

        // Handle port in host (e.g., "localhost:3306")
        $port = 3306;
        if (strpos($host, ':') !== false) {
            list($host, $port) = explode(':', $host, 2);
        }

        $command = sprintf(
            'mysql --host=%s --port=%s --user=%s --password=%s %s < %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($user),
            escapeshellarg($password),
            escapeshellarg($database),
            escapeshellarg($db_file)
        );

        $output = [];
        $return_code = 0;
        exec($command . ' 2>&1', $output, $return_code);

        if ($return_code !== 0) {
            $this->logger->log('mysql import failed: ' . implode("\n", $output), 'error');
            // Fall back to PHP method
            return $this->importWithPHP($db_file);
        }

        $this->logger->log('Database imported with mysql command', 'info');
        return true;
    }

    /**
     * Import using PHP (compatible everywhere, handles complex SQL properly)
     */
    private function importWithPHP(string $db_file): bool
    {
        global $wpdb;

        $this->logger->log("Starting PHP database import: {$db_file}", 'info');

        // Read SQL file
        $sql_content = file_get_contents($db_file);
        if (!$sql_content) {
            throw new \Exception('Failed to read database file');
        }

        // Use advanced SQL parsing for both mysqldump and WordPress-generated SQL
        $queries = $this->parseSQL($sql_content);

        $this->logger->log("Processing " . count($queries) . " database queries", 'info');

        // Disable foreign key checks for import
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        $wpdb->query('SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO"');
        $wpdb->query('SET AUTOCOMMIT = 0');
        $wpdb->query('START TRANSACTION');

        try {
            $successful_queries = 0;

            foreach ($queries as $index => $query) {
                $query = trim($query);

                // Skip empty queries and comments
                if (empty($query) || $this->isCommentLine($query)) {
                    continue;
                }

                // Skip MySQL-specific commands that WordPress doesn't need
                if ($this->isMysqlSpecificCommand($query)) {
                    continue;
                }

                $result = $wpdb->query($query);

                if ($result === false) {
                    $this->logger->log("Query failed at index {$index}: " . $wpdb->last_error, 'error');
                    $this->logger->log("Problematic query: " . substr($query, 0, 200) . "...", 'error');
                    throw new \Exception("Database query failed: " . $wpdb->last_error);
                }

                $successful_queries++;

                // Log progress every 100 queries
                if ($successful_queries % 100 === 0) {
                    $this->logger->log("Processed {$successful_queries} queries", 'info');
                }
            }

            // Commit transaction
            $wpdb->query('COMMIT');
            $this->logger->log("Successfully executed {$successful_queries} queries", 'info');
        } catch (\Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            throw $e;
        } finally {
            // Restore settings
            $wpdb->query('SET AUTOCOMMIT = 1');
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->logger->log('Database import completed with PHP', 'info');
        return true;
    }

    /**
     * Advanced SQL parsing that handles both mysqldump and WordPress-generated SQL
     */
    private function parseSQL(string $sql_content): array
    {
        $queries = [];
        $current_query = '';
        $in_string = false;
        $string_char = '';
        $escaped = false;

        $lines = explode("\n", $sql_content);

        foreach ($lines as $line) {
            $line = rtrim($line);

            // Skip comment lines
            if ($this->isCommentLine($line)) {
                continue;
            }

            $i = 0;
            $line_length = strlen($line);

            while ($i < $line_length) {
                $char = $line[$i];

                if (!$in_string) {
                    if ($char === '"' || $char === "'") {
                        $in_string = true;
                        $string_char = $char;
                    } elseif ($char === ';') {
                        // End of query
                        $query = trim($current_query);
                        if (!empty($query)) {
                            $queries[] = $query;
                        }
                        $current_query = '';
                        $i++;
                        continue;
                    }
                } else {
                    if (!$escaped && $char === $string_char) {
                        $in_string = false;
                        $string_char = '';
                    }

                    $escaped = (!$escaped && $char === '\\');
                }

                $current_query .= $char;
                $i++;
            }

            $current_query .= "\n";
        }

        // Add final query if it doesn't end with semicolon
        $final_query = trim($current_query);
        if (!empty($final_query)) {
            $queries[] = $final_query;
        }

        return $queries;
    }

    /**
     * Check if a line is a comment
     */
    private function isCommentLine(string $line): bool
    {
        $line = trim($line);
        return empty($line) ||
            strpos($line, '--') === 0 ||
            strpos($line, '#') === 0 ||
            strpos($line, '/*') === 0;
    }

    /**
     * Check if a query is a MySQL-specific command that WordPress doesn't need
     */
    private function isMysqlSpecificCommand(string $query): bool
    {
        $query = strtoupper(trim($query));

        $mysql_commands = [
            'SET FOREIGN_KEY_CHECKS',
            'SET SQL_MODE',
            'SET AUTOCOMMIT',
            'START TRANSACTION',
            'COMMIT',
            'ROLLBACK',
            'LOCK TABLES',
            'UNLOCK TABLES',
            'SET NAMES',
            'SET CHARACTER SET'
        ];

        foreach ($mysql_commands as $command) {
            if (strpos($query, $command) === 0) {
                return true;
            }
        }

        return false;
    }
}
