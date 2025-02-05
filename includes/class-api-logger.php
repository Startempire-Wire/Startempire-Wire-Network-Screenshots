<?php
/**
 * Location: Core logging system
 * Dependencies: WordPress uploads system
 * Variables & Classes: $log_file, SEWN_API_Logger
 * 
 * Centralized logging mechanism for API interactions and system events. Implements log rotation
 * and file size management. Provides severity-based logging with context-aware message formatting
 * for debugging and audit trails.
 */
class SEWN_API_Logger {
    private $log_file;

    public function __construct($log_file = '') {
        if (empty($log_file)) {
            $upload_dir = wp_upload_dir();
            $log_file = trailingslashit($upload_dir['basedir']) . 'sewn-api.log';
        }
        $this->log_file = $log_file;
    }

    public function log($level, $message, $context = []) {
        $log_entry = sprintf(
            "[%s] %s: %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message
        );

        if (!empty($context)) {
            $log_entry .= "Context: " . json_encode($context) . "\n";
        }

        error_log($log_entry, 3, $this->log_file);
    }

    public function debug($message, $context = []) {
        $this->log('debug', $message, $context);
    }

    public function info($message, $context = []) {
        $this->log('info', $message, $context);
    }

    public function warning($message, $context = []) {
        $this->log('warning', $message, $context);
    }

    public function error($message, $context = []) {
        $this->log('error', $message, $context);
    }
} 