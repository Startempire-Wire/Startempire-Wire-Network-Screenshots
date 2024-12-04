<?php
/*
Plugin Name: Startempire Wire Network Screenshots
Description: REST API endpoint for website screenshots with caching and monitoring
Version: 1.0
Author: Startempire Wire
*/

if (!defined('ABSPATH')) exit;

// Define plugin constants
define('SEWN_SCREENSHOTS_VERSION', '1.0.0');
define('SEWN_SCREENSHOTS_PATH', plugin_dir_path(__FILE__));
define('SEWN_SCREENSHOTS_URL', plugin_dir_url(__FILE__));
define('SEWN_SCREENSHOTS_MAX_STORAGE', 500 * 1024 * 1024); // 500MB
define('SEWN_SCREENSHOTS_CACHE_TIME', 24 * 60 * 60); // 24 hours

// Load required files
require_once SEWN_SCREENSHOTS_PATH . 'includes/logging.php';
require_once SEWN_SCREENSHOTS_PATH . 'includes/admin/dashboard.php';
require_once SEWN_SCREENSHOTS_PATH . 'includes/admin/settings.php';
require_once SEWN_SCREENSHOTS_PATH . 'includes/services/cache.php';

// Initialize plugin
class SEWN_Screenshots {
    private static $instance = null;
    private $logger;
    private $dashboard;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->logger = new SEWN_Logger();
        $this->dashboard = new SEWN_Dashboard($this->logger);
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('rest_api_init', [$this, 'register_endpoints']);
        add_action('init', [$this, 'schedule_cleanup']);
        
        // Register cleanup hook
        add_action('sewn_screenshots_cleanup', [$this, 'cleanup_old_screenshots']);
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

    public function register_endpoints() {
        register_rest_route('sewn/v1', '/screenshot', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_screenshot_request'],
            'permission_callback' => [$this, 'verify_api_request'],
            'args' => [
                'url' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return filter_var($param, FILTER_VALIDATE_URL);
                    }
                ],
                'type' => [
                    'default' => 'full',
                    'enum' => ['full', 'preview']
                ]
            ]
        ]);

        register_rest_route('sewn/v1', '/auth/connect', [
            'methods' => 'GET',
            'callback' => 'handle_network_auth',
            'permission_callback' => '__return_true'
        ]);
        
        register_rest_route('sewn/v1', '/auth/exchange', [
            'methods' => 'POST',
            'callback' => 'exchange_parent_token',
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('sewn/v1', '/preview/screenshot', [
            'methods' => 'GET',
            'callback' => [$this, 'get_preview_screenshot'],
            'permission_callback' => '__return_true'
        ]);

        
    }

    public function verify_api_request($request) {
        $api_key = $request->get_header('X-API-Key');
        return $api_key === get_option('sewn_api_key');
    }

    public function handle_screenshot_request($request) {
        $url = $request->get_param('url');
        $type = $request->get_param('type');
        
        try {
            $screenshot = new ScreenshotService();
            $options = $type === 'preview' ? [
                'width' => 800,
                'height' => 600,
                'quality' => 60
            ] : [];
            
            $result = await $screenshot->takeScreenshot(
                $url,
                uniqid() . '.jpg',
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
        // Generate low-res/watermarked preview
        $url = $request->get_param('url');
        // Implementation details...
    }

    public function get_full_screenshot($request) {
        // Generate full quality screenshot
        // Implementation details...
    }

    public function check_auth($request) {
        return current_user_can('access_sewn_network');
    }

    public function deactivate() {
        $timestamp = wp_next_scheduled('sewn_screenshots_cleanup');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'sewn_screenshots_cleanup');
        }
    }
}

// Initialize plugin
function SEWN_Screenshots() {
    return SEWN_Screenshots::get_instance();
}

// Activation/Deactivation hooks
register_activation_hook(__FILE__, function() {
    SEWN_Screenshots()->schedule_cleanup();
});

register_deactivation_hook(__FILE__, function() {
    SEWN_Screenshots()->deactivate();
});

SEWN_Screenshots();