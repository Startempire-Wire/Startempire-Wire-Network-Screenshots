<?php
class SEWN_API_Logger {
    private $wpdb;
    private $table_name;
    private $logger;

    public function __construct($logger = null) {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'sewn_api_logs';
        $this->logger = $logger;
    }

    public function log_request($data) {
        $defaults = [
            'timestamp' => current_time('mysql'),
            'method' => '',
            'endpoint' => '',
            'extension_id' => '',
            'status' => '',
            'response_time' => 0,
            'error_message' => '',
            'request_data' => '',
            'ip_address' => '',
            'user_agent' => ''
        ];

        $data = wp_parse_args($data, $defaults);
        
        return $this->wpdb->insert(
            $this->table_name,
            $data,
            [
                '%s', // timestamp
                '%s', // method
                '%s', // endpoint
                '%s', // extension_id
                '%s', // status
                '%f', // response_time
                '%s', // error_message
                '%s', // request_data
                '%s', // ip_address
                '%s'  // user_agent
            ]
        );
    }

    public function create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sewn_api_logs';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            method varchar(10) NOT NULL,
            endpoint varchar(255) NOT NULL,
            extension_id varchar(50),
            status varchar(20) NOT NULL,
            response_time int(11),
            error_message text,
            request_data text,
            ip_address varchar(45),
            user_agent varchar(255),
            PRIMARY KEY  (id),
            KEY timestamp (timestamp),
            KEY extension_id (extension_id),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $result = dbDelta($sql);
        
        // Only log if logger is available
        if ($this->logger) {
            $this->logger->debug('API logs table creation attempted', [
                'result' => $result,
                'last_error' => $wpdb->last_error
            ]);
        }
        
        return $result;
    }

    public function get_recent_requests($limit = 50) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sewn_api_logs';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            // Create table if it doesn't exist
            $this->create_table();
            return [];
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT %d",
            $limit
        ), ARRAY_A);
    }
} 