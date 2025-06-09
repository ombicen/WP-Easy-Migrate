<?php

namespace WPEasyMigrate;

/**
 * Archiver Class
 * 
 * Handles archive splitting and combining operations
 */
class Archiver {
    
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
     * Split large archive into smaller parts
     * 
     * @param string $file_path Path to the archive file
     * @param int $max_size_mb Maximum size per part in MB
     * @return array Array of part file paths
     * @throws Exception
     */
    public function split_archive(string $file_path, int $max_size_mb): array {
        if (!file_exists($file_path)) {
            throw new Exception("Archive file not found: {$file_path}");
        }
        
        $this->logger->log("Starting archive split: {$file_path} (max size: {$max_size_mb}MB)", 'info');
        
        $max_size_bytes = $max_size_mb * 1024 * 1024;
        $file_size = filesize($file_path);
        
        if ($file_size <= $max_size_bytes) {
            $this->logger->log("File size is within limit, no splitting needed", 'info');
            return [$file_path];
        }
        
        $parts = [];
        $part_number = 1;
        $base_name = pathinfo($file_path, PATHINFO_FILENAME);
        $directory = dirname($file_path);
        
        $source_handle = fopen($file_path, 'rb');
        if (!$source_handle) {
            throw new Exception("Cannot open source file for reading: {$file_path}");
        }
        
        try {
            while (!feof($source_handle)) {
                $part_filename = "{$directory}/{$base_name}.part{$part_number}.zip";
                $part_handle = fopen($part_filename, 'wb');
                
                if (!$part_handle) {
                    throw new Exception("Cannot create part file: {$part_filename}");
                }
                
                $bytes_written = 0;
                
                // Write data to part file
                while (!feof($source_handle) && $bytes_written < $max_size_bytes) {
                    $chunk_size = min(8192, $max_size_bytes - $bytes_written);
                    $data = fread($source_handle, $chunk_size);
                    
                    if ($data === false) {
                        break;
                    }
                    
                    $written = fwrite($part_handle, $data);
                    if ($written === false) {
                        throw new Exception("Failed to write to part file: {$part_filename}");
                    }
                    
                    $bytes_written += $written;
                }
                
                fclose($part_handle);
                $parts[] = $part_filename;
                
                $this->logger->log("Created part {$part_number}: {$part_filename} ({$bytes_written} bytes)", 'info');
                $part_number++;
            }
            
        } finally {
            fclose($source_handle);
        }
        
        // Create parts manifest
        $this->create_parts_manifest($parts, $file_path);
        
        $this->logger->log("Archive split completed: " . count($parts) . " parts created", 'info');
        return $parts;
    }
    
    /**
     * Combine archive parts back into single file
     * 
     * @param array $parts Array of part file paths
     * @param string $target_path Target file path for combined archive
     * @return string Path to combined archive
     * @throws Exception
     */
    public function combine_parts(array $parts, string $target_path): string {
        if (empty($parts)) {
            throw new Exception("No parts provided for combining");
        }
        
        $this->logger->log("Starting parts combination: " . count($parts) . " parts", 'info');
        
        // Verify all parts exist
        foreach ($parts as $part) {
            if (!file_exists($part)) {
                throw new Exception("Part file not found: {$part}");
            }
        }
        
        // Sort parts by part number
        usort($parts, function($a, $b) {
            preg_match('/\.part(\d+)\./', $a, $matches_a);
            preg_match('/\.part(\d+)\./', $b, $matches_b);
            
            $num_a = isset($matches_a[1]) ? (int)$matches_a[1] : 0;
            $num_b = isset($matches_b[1]) ? (int)$matches_b[1] : 0;
            
            return $num_a - $num_b;
        });
        
        $target_handle = fopen($target_path, 'wb');
        if (!$target_handle) {
            throw new Exception("Cannot create target file: {$target_path}");
        }
        
        try {
            foreach ($parts as $index => $part_path) {
                $this->logger->log("Processing part " . ($index + 1) . ": {$part_path}", 'info');
                
                $part_handle = fopen($part_path, 'rb');
                if (!$part_handle) {
                    throw new Exception("Cannot open part file: {$part_path}");
                }
                
                try {
                    while (!feof($part_handle)) {
                        $data = fread($part_handle, 8192);
                        if ($data === false) {
                            break;
                        }
                        
                        if (fwrite($target_handle, $data) === false) {
                            throw new Exception("Failed to write to target file: {$target_path}");
                        }
                    }
                } finally {
                    fclose($part_handle);
                }
            }
        } finally {
            fclose($target_handle);
        }
        
        // Verify combined file
        if (!file_exists($target_path)) {
            throw new Exception("Failed to create combined archive: {$target_path}");
        }
        
        $combined_size = filesize($target_path);
        $expected_size = array_sum(array_map('filesize', $parts));
        
        if ($combined_size !== $expected_size) {
            throw new Exception("Combined file size mismatch. Expected: {$expected_size}, Got: {$combined_size}");
        }
        
        $this->logger->log("Parts combination completed: {$target_path} ({$combined_size} bytes)", 'info');
        return $target_path;
    }
    
    /**
     * Create parts manifest file
     * 
     * @param array $parts Array of part file paths
     * @param string $original_file Original file path
     */
    private function create_parts_manifest(array $parts, string $original_file): void {
        $manifest = [
            'version' => '1.0',
            'original_file' => basename($original_file),
            'original_size' => filesize($original_file),
            'parts_count' => count($parts),
            'created_at' => current_time('mysql'),
            'parts' => []
        ];
        
        foreach ($parts as $index => $part_path) {
            $manifest['parts'][] = [
                'number' => $index + 1,
                'filename' => basename($part_path),
                'size' => filesize($part_path),
                'checksum' => md5_file($part_path)
            ];
        }
        
        $manifest_path = dirname($parts[0]) . '/' . pathinfo($original_file, PATHINFO_FILENAME) . '.parts.json';
        file_put_contents($manifest_path, wp_json_encode($manifest, JSON_PRETTY_PRINT));
        
        $this->logger->log("Parts manifest created: {$manifest_path}", 'info');
    }
    
    /**
     * Verify archive parts integrity
     * 
     * @param array $parts Array of part file paths
     * @return bool True if all parts are valid
     */
    public function verify_parts(array $parts): bool {
        if (empty($parts)) {
            return false;
        }
        
        $this->logger->log("Verifying " . count($parts) . " archive parts", 'info');
        
        // Look for manifest file
        $first_part = $parts[0];
        $base_name = preg_replace('/\.part\d+\.zip$/', '', basename($first_part));
        $manifest_path = dirname($first_part) . '/' . $base_name . '.parts.json';
        
        if (!file_exists($manifest_path)) {
            $this->logger->log("Parts manifest not found: {$manifest_path}", 'warning');
            return $this->verify_parts_without_manifest($parts);
        }
        
        $manifest_content = file_get_contents($manifest_path);
        $manifest = json_decode($manifest_content, true);
        
        if (!$manifest) {
            $this->logger->log("Invalid parts manifest", 'error');
            return false;
        }
        
        // Verify part count
        if (count($parts) !== $manifest['parts_count']) {
            $this->logger->log("Part count mismatch. Expected: {$manifest['parts_count']}, Found: " . count($parts), 'error');
            return false;
        }
        
        // Verify each part
        foreach ($manifest['parts'] as $part_info) {
            $part_path = dirname($first_part) . '/' . $part_info['filename'];
            
            if (!file_exists($part_path)) {
                $this->logger->log("Part file missing: {$part_path}", 'error');
                return false;
            }
            
            if (filesize($part_path) !== $part_info['size']) {
                $this->logger->log("Part size mismatch: {$part_path}", 'error');
                return false;
            }
            
            if (md5_file($part_path) !== $part_info['checksum']) {
                $this->logger->log("Part checksum mismatch: {$part_path}", 'error');
                return false;
            }
        }
        
        $this->logger->log("All parts verified successfully", 'info');
        return true;
    }
    
    /**
     * Verify parts without manifest (basic verification)
     * 
     * @param array $parts Array of part file paths
     * @return bool True if basic verification passes
     */
    private function verify_parts_without_manifest(array $parts): bool {
        foreach ($parts as $part_path) {
            if (!file_exists($part_path)) {
                $this->logger->log("Part file missing: {$part_path}", 'error');
                return false;
            }
            
            if (filesize($part_path) === 0) {
                $this->logger->log("Part file is empty: {$part_path}", 'error');
                return false;
            }
        }
        
        $this->logger->log("Basic parts verification completed", 'info');
        return true;
    }
    
    /**
     * Get archive information
     * 
     * @param string $archive_path Path to archive file
     * @return array Archive information
     */
    public function get_archive_info(string $archive_path): array {
        if (!file_exists($archive_path)) {
            return [];
        }
        
        $info = [
            'path' => $archive_path,
            'size' => filesize($archive_path),
            'size_formatted' => size_format(filesize($archive_path)),
            'created' => filemtime($archive_path),
            'is_split' => false,
            'parts' => []
        ];
        
        // Check if this is a split archive
        if (preg_match('/\.part\d+\.zip$/', $archive_path)) {
            $info['is_split'] = true;
            
            // Find all parts
            $base_pattern = preg_replace('/\.part\d+\.zip$/', '.part*.zip', $archive_path);
            $parts = glob($base_pattern);
            $info['parts'] = $parts;
            $info['total_size'] = array_sum(array_map('filesize', $parts));
            $info['total_size_formatted'] = size_format($info['total_size']);
        }
        
        return $info;
    }
    
    /**
     * Clean up archive parts
     * 
     * @param array $parts Array of part file paths
     * @param bool $include_manifest Whether to delete manifest file
     */
    public function cleanup_parts(array $parts, bool $include_manifest = true): void {
        foreach ($parts as $part_path) {
            if (file_exists($part_path)) {
                unlink($part_path);
                $this->logger->log("Deleted part: {$part_path}", 'info');
            }
        }
        
        if ($include_manifest && !empty($parts)) {
            $first_part = $parts[0];
            $base_name = preg_replace('/\.part\d+\.zip$/', '', basename($first_part));
            $manifest_path = dirname($first_part) . '/' . $base_name . '.parts.json';
            
            if (file_exists($manifest_path)) {
                unlink($manifest_path);
                $this->logger->log("Deleted manifest: {$manifest_path}", 'info');
            }
        }
    }
}