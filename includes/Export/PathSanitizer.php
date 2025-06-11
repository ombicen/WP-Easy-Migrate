<?php

namespace WP_Easy_Migrate\Export;

class PathSanitizer
{
    public static function sanitize(string $path): string
    {
        $path = str_replace(['\\', ':', '*', '?', '"', '<', '>', '|'], '_', $path);
        $path = preg_replace('/[\x00-\x1F\x7F]+/', '', $path);
        return $path;
    }
}
