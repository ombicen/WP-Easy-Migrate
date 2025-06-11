<?php

namespace WP_Easy_Migrate\Export;

class SqlFormatter
{
    public static function escape($value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        $escaped = addslashes($value);
        $escaped = str_replace(["\0", "\n", "\r", "\x1a"], ["\\0", "\\n", "\\r", "\\Z"], $escaped);
        return "'{$escaped}'";
    }
}
