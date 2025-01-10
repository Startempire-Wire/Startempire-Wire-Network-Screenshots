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

// Add this with other includes at the top of the file
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-swagger-docs.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/class-api-quota-checker.php';

// Define plugin constants if not already defined
if (!defined('SEWN_SCREENSHOTS_PATH')) {
    define('SEWN_SCREENSHOTS_PATH', plugin_dir_path(__FILE__));
}

// Required core files - update the order to load dependencies first
require_once SEWN_SCREENSHOTS_PATH . 'includes/class-logger.php';  // Load logger first
require_once SEWN_SCREENSHOTS_PATH . 'includes/class-installation-tracker.php';
require_once SEWN_SCREENSHOTS_PATH . 'includes/class-security-manager.php';
require_once SEWN_SCREENSHOTS_PATH . 'includes/class-health-check.php';
require_once SEWN_SCREENSHOTS_PATH . 'includes/admin/class-admin-notifications.php';
require_once SEWN_SCREENSHOTS_PATH . 'includes/class-hooks-manager.php';

// Add database update check
function sewn_check_db_updates() {
    $current_db_version = get_option('sewn_screenshots_db_version', '1.0');
    
    if (version_compare($current_db_version, SEWN_SCREENSHOTS_DB_VERSION, '<')) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sewn_screenshots';
        
        // Check if columns exist and add them if they don't
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table_name}`");
        
        if (!in_array('thumbnail_path', $columns)) {
            $wpdb->query("ALTER TABLE `{$table_name}` 
                         ADD COLUMN `thumbnail_path` varchar(255) DEFAULT NULL");
        }
        
        if (!in_array('image_path', $columns)) {
            $wpdb->query("ALTER TABLE `{$table_name}` 
                         ADD COLUMN `image_path` varchar(255) DEFAULT NULL");
        }
        
        if (!in_array('status', $columns)) {
            $wpdb->query("ALTER TABLE `{$table_name}` 
                         ADD COLUMN `status` varchar(50) DEFAULT 'pending'");
        }
        
        if (!in_array('updated_at', $columns)) {
            $wpdb->query("ALTER TABLE `{$table_name}` 
                         ADD COLUMN `updated_at` datetime DEFAULT CURRENT_TIMESTAMP 
                         ON UPDATE CURRENT_TIMESTAMP");
        }

        // Add indexes if they don't exist
        $indexes = $wpdb->get_results("SHOW INDEX FROM `{$table_name}`");
        $index_names = array_column($indexes, 'Key_name');
        
        if (!in_array('status', $index_names)) {
            $wpdb->query("ALTER TABLE `{$table_name}` ADD INDEX `status` (`status`)");
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
require_once SEWN_SCREENSHOTS_PATH . 'includes/class-assets.php';

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

// Initialize components with proper dependency injection
$logger = new SEWN_Logger();
$service_detector = new SEWN_Screenshot_Service_Detector($logger);
$health_check = new SEWN_Health_Check($logger);

// Initialize settings
$settings = new SEWN_Settings(
    $logger,
    $service_detector,
    $health_check
);
$api_manager = new SEWN_API_Manager($logger, $settings);
$rate_limiter = new SEWN_Rate_Limiter($logger);
$screenshot_service = new SEWN_Screenshot_Service($logger);
$rest_controller = new SEWN_REST_Controller($logger, $rate_limiter, $screenshot_service);

// Update API Tester initialization to include screenshot service
$api_tester = new SEWN_API_Tester($logger, $settings, $screenshot_service);

// Register REST API routes
add_action('rest_api_init', function() use ($logger, $screenshot_service, $rate_limiter) {
    $controller = new SEWN_REST_Controller($logger, $rate_limiter, $screenshot_service);
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
    private $swagger_docs;
    private $screenshot_service;
    private $rest_controller;
    private $rate_limiter;

    public function __construct() {
        $this->logger = new SEWN_Logger();
        $this->settings = new SEWN_Settings(
            $this->logger,
            new SEWN_Screenshot_Service_Detector($this->logger),
            new SEWN_Health_Check($this->logger)
        );
        $this->rate_limiter = new SEWN_Rate_Limiter($this->logger);
        $this->api_tester = new SEWN_API_Tester($this->logger, $this->settings, $this->screenshot_service);
        $this->api_manager = new SEWN_API_Manager($this->logger, $this->settings);
        $this->dashboard = new SEWN_Dashboard($this->logger, $this->settings, $this->api_tester, $this->api_manager);
        $this->test_results = new SEWN_Test_Results($this->logger);
        
        // Add this line to initialize Swagger docs
        $this->swagger_docs = new SEWN_Swagger_Docs();

        // Update menu manager initialization to include swagger_docs
        $this->menu_manager = new SEWN_Menu_Manager(
            $this->logger,
            $this->dashboard,
            $this->api_tester,
            $this->api_manager,
            $this->settings,
            $this->test_results,
            $this->swagger_docs
        );

        // Screenshot handling
        $this->screenshot_service = new SEWN_Screenshot_Service($this->logger);
        
        // REST Controller with all dependencies in correct order
        $this->rest_controller = new SEWN_REST_Controller(
            $this->logger,
            $this->rate_limiter,
            $this->screenshot_service
        );
        
        // Initialize REST routes
        add_action('rest_api_init', [$this->rest_controller, 'register_routes']);

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
            thumbnail_path varchar(255) DEFAULT NULL,
            image_path varchar(255) DEFAULT NULL,
            status varchar(50) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY url (url),
            KEY status (status)
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
    try {
        // Initialize core services first
        $logger = new SEWN_Logger();
        $settings = new SEWN_Settings($logger);
        $screenshot_service = new SEWN_Screenshot_Service($logger);
        
        // Initialize API manager and ensure new format key
        $api_manager = new SEWN_API_Manager($logger, $settings);
        $api_manager->ensure_api_key_exists(); // This will migrate if needed
        
        // Initialize dependent services
        $api_tester = new SEWN_API_Tester($logger, $settings, $screenshot_service);
        $dashboard = new SEWN_Dashboard($logger, $settings, $api_tester, $api_manager);
        
        // Create required tables with new columns
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'sewn_screenshots';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            url varchar(255) NOT NULL,
            screenshot_path varchar(255) NOT NULL,
            thumbnail_path varchar(255) DEFAULT NULL,
            image_path varchar(255) DEFAULT NULL,
            status varchar(50) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY url (url),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Create other required tables
        $dashboard->create_screenshots_table();
        
        // Initialize new components
        $installation_tracker = new SEWN_Installation_Tracker($logger);
        $security_manager = new SEWN_Security_Manager($logger);
        $health_check = new SEWN_Health_Check($logger);
        $admin_notifications = new SEWN_Admin_Notifications($logger);
        
        // Register all hooks
        SEWN_Hooks_Manager::register_hooks();
        
        // Initialize API settings
        $api_manager->activate();
        
        // Update database version
        update_option('sewn_screenshots_db_version', SEWN_SCREENSHOTS_DB_VERSION);
        
    } catch (Exception $e) {
        // Log error but don't output anything
        if (isset($logger)) {
            $logger->error('Plugin activation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
        // Re-throw to prevent activation
        throw $e;
    }
});

// Add this to your deactivation hook handler
register_deactivation_hook(__FILE__, function() {
    $logger = new SEWN_Logger();
    $settings = new SEWN_Settings($logger);
    $api_manager = new SEWN_API_Manager($logger, $settings);
    
    // Clean up API Manager settings first
    $api_manager->deactivate();
    
    // Always clean up these critical options regardless of cleanup setting
    $critical_options = [
        'sewn_fallback_service',
        'sewn_fallback_api_key',
        'sewn_active_api',
        'sewn_primary_service_configured'
    ];
    
    foreach ($critical_options as $option) {
        delete_option($option);
        $logger->info("Cleaned up critical option: $option");
    }
    
    // Continue with existing cleanup if configured
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
        
        // Clean up all other options
        $options = [
            'sewn_api_key',
            'sewn_settings',
            'sewn_cleanup_on_deactivate'
            // Critical options already handled above
        ];
        
        foreach ($options as $option) {
            delete_option($option);
            $logger->info("Cleaned up option: $option");
        }
        
        $logger->info('Plugin cleanup completed');
    } else {
        $logger->info('Plugin deactivated with minimal cleanup');
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

// Add activation hook
register_activation_hook(__FILE__, function() use ($api_manager) {
    // Ensure API key exists on activation
    $api_manager->ensure_api_key_exists();
});

// Add settings update handler
add_action('update_option_sewn_active_api', function($old_value, $new_value) use ($api_manager, $logger) {
    // Validate mode change
    if ($new_value === 'fallback') {
        $fallback_key = get_option('sewn_fallback_api_key');
        if (empty($fallback_key)) {
            // Revert to primary if fallback not configured
            update_option('sewn_active_api', 'primary');
            $logger->warning('Fallback mode selected without valid key, reverting to primary');
        }
    }
}, 10, 2);

register_activation_hook(__FILE__, [$api_manager, 'activate']);

// Add new initialization hook
add_action('plugins_loaded', function() use ($logger) {
    try {
        // Initialize components that need to be always active
        $installation_tracker = new SEWN_Installation_Tracker($logger);
        $security_manager = new SEWN_Security_Manager($logger);
        $health_check = new SEWN_Health_Check($logger);
        $admin_notifications = new SEWN_Admin_Notifications($logger);
        
        // Register AJAX handlers for new components
        add_action('wp_ajax_sewn_get_installation_progress', [$installation_tracker, 'get_progress']);
        add_action('wp_ajax_sewn_verify_security_token', [$security_manager, 'verify_request']);
        add_action('wp_ajax_sewn_run_health_check', [$health_check, 'run_health_check']);
        
    } catch (Exception $e) {
        $logger->error('Failed to initialize plugin components', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
});

// Initialize hooks
SEWN_Hooks_Manager::register_hooks();
SEWN_Hooks_Manager::register_callback_functions();

SEWN_Screenshots::get_instance();