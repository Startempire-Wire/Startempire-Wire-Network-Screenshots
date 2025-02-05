<?php
/**
 * Admin Class for Screenshot Management
 * 
 * Handles the WordPress admin interface for the screenshot service, including:
 * - Script/style loading for admin pages
 * - Screenshot testing interface
 * - API key management
 * - Service status display
 */
class SEWN_Admin {
    /**
     * Service for handling screenshot generation
     * @var object
     */
    private $screenshot_service;

    /**
     * Logger instance for tracking operations
     * @var object
     */
    private $logger;

    /**
     * Initialize admin functionality and set up WordPress hooks
     *
     * @param object $screenshot_service Service that handles screenshot generation
     * @param object $logger Logger for tracking operations
     */
    public function __construct($screenshot_service, $logger) {
        $this->screenshot_service = $screenshot_service;
        $this->logger = $logger;

        // Add these lines to register AJAX handlers
        add_action('wp_ajax_sewn_get_swagger_docs', [$this, 'handle_swagger_docs']);
        add_action('wp_ajax_nopriv_sewn_get_swagger_docs', [$this, 'handle_swagger_docs']);
        add_action('wp_ajax_sewn_refresh_service', [$this, 'handle_service_refresh']);

        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('admin_init', [$this, 'init_admin']);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Initialize admin interface and log the action
     */
    public function init_admin() {
        $this->logger->debug('Admin interface initialized');
    }

    /**
     * Load admin-specific scripts and styles
     * Only loads on plugin-specific pages to avoid conflicts
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_scripts($hook) {
        // Add debug logging
        $this->logger->debug('Enqueuing admin scripts on hook: ' . $hook);

        // Only load on our plugin pages
        if (!in_array($hook, [
            'tools_page_sewn-screenshot-tester', 
            'sewn-screenshots_page_sewn-screenshots-api',
            'settings_page_sewn-screenshot-settings'
        ])) {
            return;
        }

        // Enqueue admin styles
        wp_enqueue_style(
            'sewn-admin-style',
            plugins_url('assets/css/admin.css', SEWN_PLUGIN_FILE),
            [],
            SEWN_SCREENSHOTS_VERSION
        );

        // Enqueue admin settings script
        wp_enqueue_script(
            'sewn-admin-settings',
            plugins_url('assets/js/admin-settings.js', SEWN_PLUGIN_FILE),
            ['jquery'],
            SEWN_SCREENSHOTS_VERSION,
            true
        );

        wp_localize_script('sewn-admin-settings', 'sewn_settings', [
            'rest_url' => rest_url(),
            'rest_nonce' => wp_create_nonce('wp_rest'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'admin_nonce' => wp_create_nonce('sewn_admin_nonce'),
            'nonce' => wp_create_nonce('sewn_screenshot_test'),
            'is_production' => $this->screenshot_service->is_production_server(),
            'i18n' => [
                'refreshing' => __('Refreshing...', 'startempire-wire-network-screenshots'),
                'error' => __('Error refreshing service', 'startempire-wire-network-screenshots'),
                'success' => __('Service status updated', 'startempire-wire-network-screenshots')
            ]
        ]);

        // Ensure dashicons are loaded
        wp_enqueue_style('dashicons');
    }

    /**
     * Render the screenshot testing interface
     * Provides a form for testing screenshot generation with various options
     */
    public function render_tester_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Get current screenshot method from settings
        $current_method = $this->settings->get_screenshot_method();

        ?>
        <div class="wrap">
            <h1>Screenshot Tester</h1>
            
            <div class="sewn-test-section">
                <div class="notice notice-info">
                    <p>Current screenshot method: <strong><?php echo esc_html($current_method); ?></strong></p>
                    <?php if ($current_method === 'wkhtmltopdf'): ?>
                        <p>Using local wkhtmltopdf installation</p>
                    <?php else: ?>
                        <p>Using fallback API service</p>
                    <?php endif; ?>
                </div>

                <h2>Test Screenshot Service</h2>
                
                <div class="sewn-test-controls">
                    <input type="url" 
                           id="test-url" 
                           placeholder="Enter URL to test"
                           class="regular-text">
                    
                    <button type="button" 
                            id="sewn-test-button" 
                            class="button button-primary">
                        Take Screenshot
                    </button>
                </div>

                <div class="sewn-test-options">
                    <h3>Screenshot Options</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Width</th>
                            <td>
                                <input type="number" 
                                       id="sewn-test-width" 
                                       value="1280" 
                                       min="100" 
                                       max="3840">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Height</th>
                            <td>
                                <input type="number" 
                                       id="sewn-test-height" 
                                       value="800" 
                                       min="100" 
                                       max="2160">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Quality</th>
                            <td>
                                <input type="number" 
                                       id="sewn-test-quality" 
                                       value="85" 
                                       min="1" 
                                       max="100">
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="button" class="button reset-options">Reset to Defaults</button>
                    </p>
                </div>

                <div class="sewn-status-indicator">
                    <h3>Service Status</h3>
                    <div class="service-status">
                        <?php $this->render_service_status(); ?>
                    </div>
                </div>

                <div id="sewn-test-result"></div>
            </div>

            <?php $this->render_api_key_section(); ?>
        </div>
        <?php

        $this->logger->debug('Tester page rendered');
    }

    /**
     * Render the API key management section
     * Displays and manages API keys for multiple screenshot services
     */
    private function render_api_key_section() {
        $services = [
            'screenshotmachine' => 'Screenshot Machine',
            'browserless' => 'Browserless',
            'urlbox' => 'URLbox'
        ];

        echo '<div class="sewn-api-keys">';
        foreach ($services as $service_id => $service_name) {
            $option_name = 'sewn_' . $service_id . '_key';
            $current_key = get_option($option_name, '');
            ?>
            <div class="api-key-row">
                <h3><?php echo esc_html($service_name); ?> API Key</h3>
                <div class="api-key-field">
                    <input type="text" 
                           id="<?php echo esc_attr($option_name); ?>"
                           name="<?php echo esc_attr($option_name); ?>"
                           value="<?php echo esc_attr($current_key); ?>"
                           class="regular-text"
                           readonly>
                    <button type="button" 
                            class="button regenerate-api-key" 
                            data-service="<?php echo esc_attr($service_id); ?>">
                        Regenerate Key
                    </button>
                    <button type="button" 
                            class="button toggle-visibility" 
                            data-target="<?php echo esc_attr($option_name); ?>">
                        Show/Hide
                    </button>
                    <div class="api-feedback"></div>
                </div>
            </div>
            <?php
        }
        echo '</div>';
    }

    /**
     * Get the status of all screenshot services
     * Checks availability of local and API-based services
     *
     * @return array Status of each screenshot service
     */
    private function get_service_status() {
        $methods = [
            'wkhtmltopdf' => $this->screenshot_service->is_production_server(),
            'screenshotmachine' => !empty(get_option('sewn_screenshotmachine_key')),
            'browserless' => !empty(get_option('sewn_browserless_key')),
            'urlbox' => !empty(get_option('sewn_urlbox_key'))
        ];

        // Check if services are actually responding
        if ($methods['wkhtmltopdf']) {
            $methods['wkhtmltopdf'] = $this->screenshot_service->check_wkhtmltopdf();
        }

        return $methods;
    }

    /**
     * Display the current status of all screenshot services
     * Shows which services are available and configured
     */
    public function render_service_status() {
        $methods = $this->get_service_status();
        ?>
        <div class="sewn-status-indicator">
            <h3>Service Status</h3>
            <div class="service-status">
                <?php foreach ($methods as $method => $available): ?>
                    <div class="status-item">
                        <span class="status-dot <?php echo $available ? 'active' : 'inactive'; ?>"></span>
                        <?php echo ucfirst($method); ?>
                        <?php if (!$available): ?>
                            <span class="status-message">Not configured</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the test results page
     * Displays results from screenshot service tests
     */
    public function render_test_results() {
        $test_results = new SEWN_Test_Results($this->logger);
        $test_results->render_test_page();
    }

    /**
     * Render the main plugin dashboard
     * Shows overview of screenshot service status and recent activity
     */
    public function render_dashboard_page() {
        ?>
        <div class="wrap">
            <h1>Startempire Wire Screenshots Dashboard</h1>
            
            <div class="sewn-dashboard-grid">
                <div class="sewn-card">
                    <h2>Quick Actions</h2>
                    <a href="<?php echo admin_url('admin.php?page=sewn-screenshot-tester'); ?>" class="button button-primary">Take Screenshot</a>
                    <a href="<?php echo admin_url('admin.php?page=sewn-api-settings'); ?>" class="button">API Settings</a>
                </div>

                <div class="sewn-card">
                    <h2>Service Status</h2>
                    <?php $this->render_service_status(); ?>
                </div>

                <div class="sewn-card">
                    <h2>Recent Activity</h2>
                    <?php $this->render_recent_activity(); ?>
                </div>

                <div class="sewn-card">
                    <h2>Test Status</h2>
                    <?php $this->render_test_summary(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the API settings page
     * Manages API keys and service configuration
     */
    public function render_api_settings_page() {
        ?>
        <div class="wrap">
            <h1>API Settings</h1>
            <?php $this->render_api_key_section(); ?>
        </div>
        <?php
    }

    /**
     * Display recent screenshot activity
     * Shows the last 5 screenshots taken
     */
    private function render_recent_activity() {
        $recent_screenshots = $this->screenshot_service->get_recent_screenshots(5);
        if (!empty($recent_screenshots)) {
            echo '<ul class="sewn-activity-list">';
            foreach ($recent_screenshots as $screenshot) {
                echo '<li>' . esc_html($screenshot['url']) . ' - ' . esc_html($screenshot['date']) . '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p>No recent activity</p>';
        }
    }

    /**
     * Display summary of recent test results
     * Shows pass/fail statistics for screenshot tests
     */
    private function render_test_summary() {
        $test_results = new SEWN_Test_Results($this->logger);
        $summary = $test_results->get_latest_summary();
        if ($summary) {
            echo '<div class="sewn-test-summary">';
            echo '<p>Last Run: ' . esc_html($summary['date']) . '</p>';
            echo '<p>Passed: ' . esc_html($summary['passed']) . '</p>';
            echo '<p>Failed: ' . esc_html($summary['failed']) . '</p>';
            echo '</div>';
        } else {
            echo '<p>No tests run yet</p>';
        }
    }

    /**
     * Enqueue scripts for the admin interface
     * Loads API tester functionality and localizes script data
     */
    public function enqueue_scripts() {
        wp_enqueue_script(
            'sewn-api-tester',
            SEWN_SCREENSHOTS_URL . 'assets/js/api-tester-enhanced.js',
            array('jquery'),
            SEWN_SCREENSHOTS_VERSION,
            true
        );

        // Properly localize the script with all required data
        wp_localize_script('sewn-api-tester', 'sewnApiTester', array(
            'restUrl' => trailingslashit(rest_url()),
            'apiBase' => 'sewn-screenshots/v1',
            'nonce' => wp_create_nonce('wp_rest'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'homeUrl' => home_url(),
            'debug' => WP_DEBUG,
            'version' => SEWN_SCREENSHOTS_VERSION
        ));

        // Add REST API support to the page
        wp_enqueue_script('wp-api');
    }

    /**
     * Handle AJAX request for Swagger documentation
     */
    public function handle_swagger_docs() {
        $start_time = microtime(true);
        
        try {
            // Log request details
            $this->logger->debug('Swagger docs request received', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'headers' => getallheaders(),
                'request' => $_REQUEST,
                'memory_usage' => memory_get_usage(true)
            ]);

            // Verify nonce
            $nonce_check = check_ajax_referer('sewn_swagger_docs', '_ajax_nonce', false);
            $this->logger->debug('Nonce verification', [
                'success' => $nonce_check !== false,
                'nonce' => $_REQUEST['_ajax_nonce'] ?? 'not_set'
            ]);

            if (!$nonce_check) {
                throw new Exception('Invalid nonce verification');
            }

            if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
                $this->logger->warning('Invalid request method', [
                    'expected' => 'GET',
                    'received' => $_SERVER['REQUEST_METHOD']
                ]);
                wp_send_json_error('Invalid request method', 400);
                return;
            }

            // Start output buffering
            ob_start();
            
            // Set headers
            nocache_headers();
            header('Content-Type: application/json; charset=utf-8');
            
            // Log headers
            $this->logger->debug('Response headers set', [
                'headers' => headers_list()
            ]);

            // Generate docs
            $swagger = new SEWN_Swagger_Docs($this->logger);
            $docs = $swagger->generate_docs();
            
            // Clean any output before our JSON
            $buffered_output = ob_get_clean();
            if (!empty($buffered_output)) {
                $this->logger->warning('Unexpected output buffered', [
                    'output' => $buffered_output
                ]);
            }
            
            // Validate JSON
            $decoded = json_decode($docs);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON generated: ' . json_last_error_msg());
            }
            
            // Log success
            $this->logger->info('Swagger documentation generated successfully', [
                'execution_time' => microtime(true) - $start_time,
                'memory_peak' => memory_get_peak_usage(true),
                'spec_size' => strlen($docs)
            ]);
            
            // Output the JSON
            echo $docs;
            
        } catch (Exception $e) {
            $this->logger->error('Swagger documentation generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'execution_time' => microtime(true) - $start_time,
                'memory_peak' => memory_get_peak_usage(true)
            ]);
            
            wp_send_json_error([
                'message' => 'Failed to generate API documentation',
                'error' => $e->getMessage()
            ], 500);
        }
        
        wp_die();
    }

    /**
     * Handle AJAX request to refresh service status
     */
    public function handle_service_refresh() {
        try {
            // Verify nonce
            check_ajax_referer('sewn_admin_nonce', 'nonce');

            // Get service ID from request
            $service_id = isset($_POST['service']) ? sanitize_text_field($_POST['service']) : '';
            if (empty($service_id)) {
                throw new Exception('Service ID is required');
            }

            // Get fresh service status
            $status = $this->get_service_status();
            $health_info = [
                'status' => isset($status[$service_id]) && $status[$service_id] ? 'healthy' : 'error',
                'message' => isset($status[$service_id]) && $status[$service_id] 
                    ? __('Service is responding normally', 'startempire-wire-network-screenshots')
                    : __('Service is not available', 'startempire-wire-network-screenshots')
            ];

            wp_send_json_success($health_info);

        } catch (Exception $e) {
            $this->logger->error('Service refresh failed', [
                'error' => $e->getMessage(),
                'service' => $service_id ?? 'unknown'
            ]);

            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }
} 