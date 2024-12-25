<?php
if (!defined('ABSPATH')) exit;

class SEWN_Logger {
    private $log_table;
    
    public function __construct() {
        global $wpdb;
        $this->log_table = $wpdb->prefix . 'sewn_logs';
        $this->maybe_create_table();
    }

    private function maybe_create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->log_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL,
            message text NOT NULL,
            context text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY level (level),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function debug($message, $context = []) {
        $this->log('debug', $message, $context);
    }

    public function error($message, $context = []) {
        $this->log('error', $message, $context);
    }

    public function warning($message, $context = []) {
        $this->log('warning', $message, $context);
    }

    public function info($message, $context = []) {
        $this->log('info', $message, $context);
    }

    private function log($level, $message, $context = []) {
        global $wpdb;
        
        $wpdb->insert(
            $this->log_table,
            [
                'level' => $level,
                'message' => $message,
                'context' => json_encode($context),
                'created_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s']
        );
    }

    public function get_recent_logs($limit = 10) {
        global $wpdb;
        
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT level, message, context, created_at 
                FROM {$this->log_table} 
                ORDER BY created_at DESC 
                LIMIT %d",
                $limit
            )
        );

        $formatted_logs = [];
        foreach ($logs as $log) {
            $context = json_decode($log->context, true);
            $formatted_logs[] = sprintf(
                '[%s] %s: %s %s',
                $log->created_at,
                strtoupper($log->level),
                $log->message,
                $context ? '- ' . json_encode($context) : ''
            );
        }

        return $formatted_logs;
    }
}