<?php

namespace WP_Easy_Migrate\Export;

class DirectoryScanner
{
    public function scan(string $directory, array $exclude_patterns = []): array
    {
        $files = [];
        $sizes = [];
        if (!is_dir($directory)) {
            return ['files' => $files, 'sizes' => $sizes];
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $file_path = $file->getRealPath();
            $relative_path = substr($file_path, strlen($directory) + 1);
            $should_exclude = false;
            foreach ($exclude_patterns as $pattern) {
                if (fnmatch($pattern, $relative_path) || fnmatch($pattern, basename($file_path))) {
                    $should_exclude = true;
                    break;
                }
            }
            if ($should_exclude) {
                continue;
            }
            $files[] = $file_path;
            $sizes[] = $file->getSize();
        }
        return ['files' => $files, 'sizes' => $sizes];
    }
}
