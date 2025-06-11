<?php

namespace WP_Easy_Migrate\Export;

use WPEasyMigrate\Logger as EasyMigrateLogger;

class DatabaseExporter
{
    private $logger;
    public function __construct(EasyMigrateLogger $logger)
    {
        $this->logger = $logger;
    }
    public function export(string $export_dir): string
    {
        global $wpdb;
        $db_file = $export_dir . 'database.sql';
        $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
        $sql_content = "-- WordPress Database Export\n";
        $sql_content .= "-- Generated on: " . current_time('mysql') . "\n";
        $sql_content .= "-- WordPress Version: " . get_bloginfo('version') . "\n\n";
        $sql_content .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $sql_content .= "SET time_zone = \"+00:00\";\n\n";
        foreach ($tables as $table) {
            $table_name = $table[0];
            $create_table = $wpdb->get_row("SHOW CREATE TABLE `{$table_name}`", ARRAY_N);
            $sql_content .= "\n-- Table structure for table `{$table_name}`\n";
            $sql_content .= "DROP TABLE IF EXISTS `{$table_name}`;\n";
            $sql_content .= $create_table[1] . ";\n\n";
            $rows = $wpdb->get_results("SELECT * FROM `{$table_name}`", ARRAY_A);
            if (!empty($rows)) {
                $sql_content .= "-- Dumping data for table `{$table_name}`\n";
                foreach ($rows as $row) {
                    $values = [];
                    foreach ($row as $value) {
                        $values[] = \WP_Easy_Migrate\Export\SqlFormatter::escape($value);
                    }
                    $sql_content .= "INSERT INTO `{$table_name}` VALUES (" . implode(', ', $values) . ");\n";
                }
                $sql_content .= "\n";
            }
        }
        file_put_contents($db_file, $sql_content);
        if (!file_exists($db_file)) {
            throw new \Exception('Failed to create database dump');
        }
        return $db_file;
    }
    // Database export methods will go here
}
