<?php

namespace Mhc\Inc\Services;

defined('ABSPATH') || exit;

class QbLogger
{
    /**
     * Generic logger. Writes JSON lines to daily file in uploads/mhc_logs
     * @param string $level info|error|debug
     * @param string $message short message
     * @param array $context additional data
     */
    public static function log(string $level, string $message, array $context = []) : void
    {
        // Prefer WP uploads dir so it's writable
        if (function_exists('wp_get_upload_dir')) {
            $u = wp_get_upload_dir();
            $base = $u['basedir'];
        } else {
            // fallback to plugin dir
            $base = dirname(__DIR__, 2);
        }

        $dir = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mhc_logs';

        if (function_exists('wp_mkdir_p')) {
            wp_mkdir_p($dir);
        } elseif (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $date = date('Y-m-d');
        $file = $dir . DIRECTORY_SEPARATOR . "qb_checks-{$date}.log";

        $entry = [
            'ts' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        // Use LOCK_EX to avoid interleaving
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $message, array $context = []) : void
    {
        self::log('info', $message, $context);
    }

    public static function error(string $message, array $context = []) : void
    {
        self::log('error', $message, $context);
    }

    public static function debug(string $message, array $context = []) : void
    {
        self::log('debug', $message, $context);
    }

    /** Convenience helper to log a check creation */
    public static function logCheckCreated(array $data) : void
    {
        self::info('check_created', $data);
    }
}
