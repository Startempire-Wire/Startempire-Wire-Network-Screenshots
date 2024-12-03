<?php
if (!defined('ABSPATH')) exit;

class SEWN_Logger {
    private $log_file;
    private $log_levels = [
        'ERROR' => 1,
        'WARNING' => 2,
        'INFO' => 3,
        'DEBUG' => 4
    ];

    public function __construct() {
        $this->log_file = SEWN_SCREENSHOTS_PATH . 'logs/screenshot-service.log';
        $this->ensure_log_directory();
    }

    private function ensure_log_directory() {
        $log_dir = dirname($this->log_file);
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            
            // Create .htaccess to protect logs
            $htaccess = $log_dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, 'Deny from all');
            }
        }
        
        // Ensure log file exists and is writable
        if (!file_exists($this->log_file)) {
            touch($this->log_file);
        }
        
        if (!is_writable($this->log_file)) {
            chmod($this->log_file, 0644);
        }
    }

    public function log($level, $message, $context = []) {
        if (!isset($this->log_levels[$level])) {
            $level = 'INFO';
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $formatted_message = sprintf(
            "[%s] %s: %s %s\n",
            $timestamp,
            strtoupper($level),
            $message,
            !empty($context) ? json_encode($context) : ''
        );
        
        error_log($formatted_message, 3, $this->log_file);
    }

    public function error($message, $context = []) {
        $this->log('ERROR', $message, $context);
    }

    public function warning($message, $context = []) {
        $this->log('WARNING', $message, $context);
    }

    public function info($message, $context = []) {
        $this->log('INFO', $message, $context);
    }

    public function debug($message, $context = []) {
        $this->log('DEBUG', $message, $context);
    }

    public function rotate_logs() {
        if (file_exists($this->log_file) && filesize($this->log_file) > 5 * 1024 * 1024) { // 5MB
            $backup_name = $this->log_file . '.' . date('Y-m-d-H-i-s');
            rename($this->log_file, $backup_name);
            $this->ensure_log_directory(); // Create new log file
        }
    }

    public function get_recent_logs($lines = 100) {
        if (!file_exists($this->log_file)) {
            return [];
        }

        $logs = file($this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$logs) {
            return [];
        }

        return array_slice(array_reverse($logs), 0, $lines);
    }
}