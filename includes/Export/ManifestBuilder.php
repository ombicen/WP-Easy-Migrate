<?php

namespace WP_Easy_Migrate\Export;

class ManifestBuilder
{
    public function build(array $info): string
    {
        $manifest = [
            'version' => '1.0',
            'generator' => 'WP Easy Migrate',
            'export_id' => $info['export_id'],
            'site_info' => [
                'url' => $info['site_url'],
                'name' => get_bloginfo('name'),
                'description' => get_bloginfo('description'),
                'wp_version' => $info['wp_version'],
                'php_version' => $info['php_version'],
                'mysql_version' => $info['mysql_version'],
            ],
            'export_info' => [
                'date' => $info['export_date'],
                'options' => $info['options'],
                'files' => $info['files'] ?? [],
                'file_count' => $info['file_count'] ?? 0,
                'total_size' => $info['total_size'] ?? 0,
            ],
            'requirements' => [
                'min_wp_version' => '5.0',
                'min_php_version' => '7.4',
                'required_extensions' => ['zip', 'mysqli'],
            ]
        ];
        return wp_json_encode($manifest, JSON_PRETTY_PRINT);
    }
}
