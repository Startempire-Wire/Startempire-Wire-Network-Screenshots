<?php
/*
Plugin Name: Startempire Wire Network Screenshots
Description: REST API endpoint for website screenshots with caching and monitoring
Version: 1.0
Author: Startempire Wire
*/

if (!defined('ABSPATH')) exit;

// Define plugin constants
define('SEWN_SCREENSHOTS_PATH', plugin_dir_path(__FILE__));
define('SEWN_SCREENSHOTS_URL', plugin_dir_url(__FILE__));
define('SEWN_SCREENSHOTS_VERSION', '1.0');

// Database version constant
define('SEWN_SCREENSHOTS_DB_VERSION', '1.1');

// Add database update check
function sewn_check_db_updates() {
    $current_db_version = get_option('sewn_screenshots_db_version', '1.0');
    
    if (version_compare($current_db_version, SEWN_SCREENSHOTS_DB_VERSION, '<')) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sewn_screenshots';
        
        // Check if updated_at column exists
        $row = $wpdb->get_results("SHOW COLUMNS FROM `{$table_name}` LIKE 'updated_at'");
        if (empty($row)) {
            $wpdb->query("ALTER TABLE `{$table_name}` 
                         ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP 
                         ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
        }
        
        update_option('sewn_screenshots_db_version', SEWN_SCREENSHOTS_DB_VERSION);
    }
}

// Add the check to initialization
add_action('init', 'sewn_check_db_updates');

// Load required files
require_once SEWN_SCREENSHOTS_PATH . 'includes/class-logger.php';
require_once SEWN_SCREENSHOTS_PATH . 'includes/admin/class-settings.php';
require_once SEWN_SCREENSHOTS_PATH . 'includes/admin/class-api-tester.php';
require_once SEWN_SCREENSHOTS_PATH . 'includes/admin/class-api-manager.php';
require_once SEWN_SCREENSHOTS_PATH . 'includes/admin/class-dashboard.php';
require_once SEWN_SCREENSHOTS_PATH . 'includes/admin/class-test-results.php';
require_once SEWN_SCREENSHOTS_PATH . 'includes/services/class-cache.php';
require_once SEWN_SCREENSHOTS_PATH . 'includes/admin/class-api-manager.php';
require_once SEWN_SCREENSHOTS_PATH . 'includes/services/class-api-logger.php';
require_once SEWN_SCREENSHOTS_PATH . 'includes/admin/class-api-tester.php';
require_once SEWN_SCREENSHOTS_PATH . 'includes/class-rate-limiter.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-screenshot-service.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-ajax-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-admin.php';
require_once SEWN_SCREENSHOTS_PATH . 'includes/admin/class-menu-manager.php';
require_once SEWN_SCREENSHOTS_PATH . 'includes/admin/class-test-results.php';
require_once SEWN_SCREENSHOTS_PATH . 'includes/class-screenshot-service.php';
require_once SEWN_SCREENSHOTS_PATH . 'includes/class-rest-controller.php';

// Make sure the class is loaded before it's used
spl_autoload_register(function ($class_name) {
    if (strpos($class_name, 'SEWN_') === 0) {
        $class_file = str_replace('SEWN_', '', $class_name);
        $class_file = strtolower(str_replace('_', '-', $class_file));
        $class_path = plugin_dir_path(__FILE__) . 'includes/class-' . $class_file . '.php';
        
        if (file_exists($class_path)) {
            require_once $class_path;
        }
    }
});

// Initialize core services first
$logger = new SEWN_Logger();
$screenshot_service = new SEWN_Screenshot_Service($logger);
$rate_limiter = new SEWN_Rate_Limiter($logger);

// Register REST API routes
add_action('rest_api_init', function() use ($logger, $screenshot_service, $rate_limiter) {
    $controller = new SEWN_REST_Controller($screenshot_service, $logger, $rate_limiter);
    $controller->register_routes();
    
    // Add authentication support while preserving existing methods
    add_filter('rest_authentication_errors', function($result) use ($logger) {
        // Check for admin users first
        if (current_user_can('manage_options')) {
            $logger->debug('Admin user authenticated', [
                'user_id' => get_current_user_id()
            ]);
            return true;
        }
        
        // Existing authentication methods remain unchanged
        $api_key = isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : '';
        if (!empty($api_key) && $api_key === get_option('sewn_api_key')) {
            return true;
        }
        
        // Check for logged-in users with specific capability
        if (current_user_can('access_sewn_network')) {
            return true;
        }
        
        return $result; // Return existing result if no auth method succeeds
    });
});

// Make services available globally if needed
global $sewn_screenshot_service, $sewn_logger, $sewn_rate_limiter;
$sewn_logger = $logger;
$sewn_screenshot_service = $screenshot_service;
$sewn_rate_limiter = $rate_limiter;

class SEWN_Screenshots {
    private $logger;
    private $settings;
    private $api_tester;
    private $api_manager;
    private $menu_manager;
    private $dashboard;
    private $test_results;

    public function __construct() {
        $this->logger = new SEWN_Logger();
        $this->settings = new SEWN_Settings($this->logger);
        $this->api_tester = new SEWN_API_Tester($this->logger, $this->settings);
        $this->api_manager = new SEWN_API_Manager($this->logger, $this->settings);
        $this->dashboard = new SEWN_Dashboard($this->logger, $this->settings, $this->api_tester, $this->api_manager);
        $this->test_results = new SEWN_Test_Results($this->logger);

        // Initialize menu manager
        $this->menu_manager = new SEWN_Menu_Manager(
            $this->logger,
            $this->dashboard,
            $this->api_tester,
            $this->api_manager,
            $this->settings,
            $this->test_results
        );

        add_action('init', [$this, 'initialize']);
        $this->init_hooks();
    }

    public function initialize() {
        // Initialization code here
    }

    private function init_hooks() {
        $this->logger->debug('Initializing plugin hooks');
        
        add_action('admin_notices', function() {
            global $wpdb;
            if (!empty($wpdb->last_error)) {
                $this->logger->error('Database error', [
                    'error' => $wpdb->last_error,
                    'query' => $wpdb->last_query
                ]);
            }
        });
    }

    public function schedule_cleanup() {
        if (!wp_next_scheduled('sewn_screenshots_cleanup')) {
            wp_schedule_event(time(), 'daily', 'sewn_screenshots_cleanup');
        }
    }

    public function cleanup_old_screenshots() {
        $this->logger->info('Starting scheduled cleanup');
        
        $screenshots_dir = SEWN_SCREENSHOTS_PATH . 'screenshots';
        if (!is_dir($screenshots_dir)) {
            return;
        }

        $files = glob($screenshots_dir . '/*.[jJ][pP][gG]');
        $cleanup_age = 7 * 24 * 60 * 60; // 7 days
        $cleaned = 0;

        foreach ($files as $file) {
            if (filemtime($file) < time() - $cleanup_age) {
                if (unlink($file)) {
                    $cleaned++;
                } else {
                    $this->logger->error('Failed to delete file', ['file' => $file]);
                }
            }
        }

        $this->logger->info('Cleanup completed', [
            'files_cleaned' => $cleaned,
            'total_files' => count($files)
        ]);

        // Rotate logs
        $this->logger->rotate_logs();
    }

    public function enqueue_admin_assets($hook) {
        if ('toplevel_page_screenshot-service' !== $hook) return;

        wp_enqueue_style(
            'sewn-screenshots-admin',
            SEWN_SCREENSHOTS_URL . 'assets/css/admin.css',
            [],
            SEWN_SCREENSHOTS_VERSION
        );

        wp_enqueue_script(
            'sewn-screenshots-admin',
            SEWN_SCREENSHOTS_URL . 'assets/js/admin.js',
            ['jquery'],
            SEWN_SCREENSHOTS_VERSION,
            true
        );
    }

    public function verify_api_request($request) {
        $api_key = $request->get_header('X-API-Key');
        return $api_key === get_option('sewn_api_key');
    }

    public function handle_screenshot_request($request) {
        $url = $request->get_param('url');
        $type = $request->get_param('type');
        
        try {
            $screenshot = new SEWN_Screenshot_Bridge($this->logger);
            $options = $type === 'preview' ? [
                'width' => 800,
                'height' => 600,
                'quality' => 60
            ] : [];
            
            $result = $screenshot->take_screenshot(
                $url,
                $options
            );
            
            return rest_ensure_response($result);
        } catch (Exception $e) {
            $this->logger->error('Screenshot capture failed', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return new WP_Error('capture_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    public function get_preview_screenshot($request) {
        $url = $request->get_param('url');
        
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->logger->error('Invalid URL for preview screenshot', ['url' => $url]);
            return new WP_Error('invalid_url', 'Invalid URL provided', ['status' => 400]);
        }

        try {
            $screenshot = new SEWN_Screenshot_Bridge($this->logger);
            $result = $screenshot->take_screenshot($url, [
                'width' => 800,
                'height' => 600,
                'quality' => 60,
                'watermark' => true,
                'preview' => true
            ]);

            return rest_ensure_response([
                'success' => true,
                'url' => $result['url'],
                'size' => $result['size'],
                'cached' => $result['cached'],
                'timestamp' => current_time('timestamp')
            ]);

        } catch (Exception $e) {
            $this->logger->error('Preview screenshot generation failed', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return new WP_Error('preview_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    public function get_full_screenshot($request) {
        if (!$this->verify_premium_access($request)) {
            return new WP_Error('unauthorized', 'Premium access required', ['status' => 403]);
        }

        $url = $request->get_param('url');
        
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->logger->error('Invalid URL for full screenshot', ['url' => $url]);
            return new WP_Error('invalid_url', 'Invalid URL provided', ['status' => 400]);
        }

        try {
            $screenshot = new SEWN_Screenshot_Bridge($this->logger);
            $result = $screenshot->take_screenshot($url, [
                'width' => get_option('sewn_default_width', 1280),
                'height' => get_option('sewn_default_height', 800),
                'quality' => get_option('sewn_default_quality', 85),
                'watermark' => false,
                'full' => true
            ]);

            return rest_ensure_response([
                'success' => true,
                'url' => $result['url'],
                'size' => $result['size'],
                'cached' => $result['cached'],
                'timestamp' => current_time('timestamp')
            ]);

        } catch (Exception $e) {
            $this->logger->error('Full screenshot generation failed', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return new WP_Error('capture_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    private function verify_premium_access($request) {
        $api_key = $request->get_header('X-API-Key');
        $token = $request->get_param('access_token');
        
        if ($api_key && $api_key === get_option('sewn_api_key')) {
            return true;
        }

        if ($token) {
            return $this->api_manager->verify_premium_token($token);
        }

        return false;
    }

    public function check_auth($request) {
        return current_user_can('access_sewn_network');
    }

    public function deactivate() {
        wp_clear_scheduled_hook('sewn_screenshots_cleanup');
    }

    public static function activate() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'sewn_screenshots';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            url varchar(255) NOT NULL,
            screenshot_path varchar(255) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY url (url)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        self::maybe_update_api_logs_table();
        
        if (!wp_next_scheduled('sewn_screenshots_cleanup')) {
            wp_schedule_event(time(), 'daily', 'sewn_screenshots_cleanup');
        }
    }

    private static function maybe_update_api_logs_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sewn_api_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            request_time datetime NOT NULL,
            url varchar(2083) NOT NULL,
            status_code int(11) NOT NULL,
            cache_hits int(11) DEFAULT 0,
            PRIMARY KEY  (id),
            KEY url (url(191))
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public static function get_instance() {
        static $instance = null;
        if (null === $instance) {
            $instance = new self();
        }
        return $instance;
    }
}

// Register activation and deactivation hooks
register_activation_hook(__FILE__, ['SEWN_Screenshots', 'activate']);
register_deactivation_hook(__FILE__, function() {
    SEWN_Screenshots::get_instance()->deactivate();
});

register_activation_hook(__FILE__, function() {
    $logger = new SEWN_Logger();
    $settings = new SEWN_Settings($logger);
    $api_tester = new SEWN_API_Tester($logger, $settings);
    $api_manager = new SEWN_API_Manager($logger, $settings);
    $dashboard = new SEWN_Dashboard($logger, $settings, $api_tester, $api_manager);
    
    // Create required tables
    $dashboard->create_screenshots_table();
});

// Add this to your deactivation hook handler
register_deactivation_hook(__FILE__, function() {
    $logger = new SEWN_Logger();
    
    // Only delete tables if explicitly configured
    if (get_option('sewn_cleanup_on_deactivate', false)) {
        global $wpdb;
        
        // List of tables to clean up
        $tables = [
            $wpdb->prefix . 'sewn_screenshots',
            $wpdb->prefix . 'sewn_logs',
            $wpdb->prefix . 'sewn_api_logs'
        ];
        
        foreach ($tables as $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
                $wpdb->query("DROP TABLE IF EXISTS $table");
                $logger->info("Cleaned up table: $table");
            }
        }
        
        // Clean up related options
        $options = [
            'sewn_api_key',
            'sewn_settings',
            'sewn_cleanup_on_deactivate'
        ];
        
        foreach ($options as $option) {
            delete_option($option);
            $logger->info("Cleaned up option: $option");
        }
        
        $logger->info('Plugin cleanup completed');
    } else {
        $logger->info('Plugin deactivated without cleanup');
    }
});

// Add a setting to control cleanup behavior
add_action('admin_init', function() {
    register_setting('sewn_screenshots_options', 'sewn_cleanup_on_deactivate', [
        'type' => 'boolean',
        'default' => false,
        'description' => 'Delete all plugin data when deactivating'
    ]);
});

SEWN_Screenshots::get_instance();