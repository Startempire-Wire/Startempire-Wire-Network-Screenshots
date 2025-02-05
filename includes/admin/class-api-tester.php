<?php
/**
 * Location: Admin → Tools → API Tester
 * Dependencies: SEWN_Screenshot_Service, SEWN_Settings
 * Variables & Classes: $screenshot_service, SEWN_API_Tester
 * 
 * Provides interactive interface for testing API endpoints and service configurations. Validates API
 * credentials and connection parameters through diagnostic checks. Generates visual test results
 * for both local and remote screenshot capture services.
 */

class SEWN_API_Tester {
    private $logger;
    private $settings;
    private $screenshot_service;

    public function __construct($logger, $settings, $screenshot_service) {
        $this->logger = $logger;
        $this->settings = $settings;
        $this->screenshot_service = $screenshot_service;
        
        // Add AJAX handler
        add_action('wp_ajax_sewn_test_screenshot', [$this, 'handle_test_request']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function enqueue_scripts($hook) {
        // Only load on API tester page
        if (strpos($hook, 'sewn-screenshots') === false) {
            return;
        }

        wp_enqueue_script(
            'sewn-api-tester-enhanced',
            SEWN_SCREENSHOTS_URL . 'assets/js/api-tester-enhanced.js',
            ['jquery'],
            SEWN_SCREENSHOTS_VERSION,
            true
        );

        wp_localize_script(
            'sewn-api-tester-enhanced',
            'sewnApiTesterData',
            array(
                'rest_url' => rest_url('sewn-screenshots/v1/screenshot'),
                'rest_nonce' => wp_create_nonce('wp_rest'),
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('sewn_api_test'),
                'plugin_url' => SEWN_SCREENSHOTS_URL,
                'debug' => defined('WP_DEBUG') && WP_DEBUG,
                'strings' => array(
                    'generating' => __('Generating...', 'startempire-wire-network-screenshots'),
                    'success' => __('Screenshot taken successfully', 'startempire-wire-network-screenshots'),
                    'error' => __('Error taking screenshot', 'startempire-wire-network-screenshots'),
                    'permission_denied' => __('Permission denied. Please verify you are logged in and try again.', 'startempire-wire-network-screenshots')
                )
            )
        );
    }

    public function render_test_interface() {
        $this->logger->debug('Initializing API tester interface');
        
        try {
            ?>
            <div class="wrap sewn-dashboard-wrapper">
                <h1><?php _e('Screenshot API Tester', 'startempire-wire-network-screenshots'); ?></h1>
                
                <div class="sewn-dashboard-grid">
                    <!-- Test Form Card -->
                    <div class="sewn-card">
                        <h2><?php _e('Test Screenshot API', 'startempire-wire-network-screenshots'); ?></h2>
                        <div class="sewn-api-test-form">
                            <div class="form-group">
                                <label for="test-url"><?php _e('URL to Screenshot', 'startempire-wire-network-screenshots'); ?></label>
                                <input type="url" 
                                       id="test-url" 
                                       class="regular-text" 
                                       placeholder="https://example.com" 
                                       required
                                       data-log="url-input-change">
                            </div>
                            
                            <div class="form-group">
                                <label><?php _e('Screenshot Options', 'startempire-wire-network-screenshots'); ?></label>
                                <div class="options-grid">
                                    <div>
                                        <label for="test-width"><?php _e('Width', 'startempire-wire-network-screenshots'); ?></label>
                                        <input type="number" 
                                               id="test-width" 
                                               value="1280" 
                                               min="100" 
                                               max="2560"
                                               data-log="width-change">
                                    </div>
                                    <div>
                                        <label for="test-height"><?php _e('Height', 'startempire-wire-network-screenshots'); ?></label>
                                        <input type="number" 
                                               id="test-height" 
                                               value="800" 
                                               min="100" 
                                               max="2560"
                                               data-log="height-change">
                                    </div>
                                    <div>
                                        <label for="test-quality"><?php _e('Quality', 'startempire-wire-network-screenshots'); ?></label>
                                        <input type="number" 
                                               id="test-quality" 
                                               value="85" 
                                               min="1" 
                                               max="100"
                                               data-log="quality-change">
                                    </div>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="button" 
                                        id="run-api-test" 
                                        class="button button-primary"
                                        data-log="test-button-click">
                                    <?php _e('Take Screenshot', 'startempire-wire-network-screenshots'); ?>
                                </button>
                                <span class="spinner"></span>
                                <div class="test-feedback"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Results Card -->
                    <div class="sewn-card">
                        <h2><?php _e('Test Results', 'startempire-wire-network-screenshots'); ?></h2>
                        <div id="test-results" class="sewn-api-test-results">
                            <div class="no-results"><?php _e('No tests run yet', 'startempire-wire-network-screenshots'); ?></div>
                        </div>
                        <button href="<?php echo admin_url('admin.php?page=sewn-screenshots'); ?>" class="button button-primary" id="return-to-dashboard">Return to Dashboard</button>
                    </div>
                </div>
            </div>
            <?php
            
        } catch (Exception $e) {
            $this->logger->error('Failed to render API tester interface', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            wp_die('Error loading API tester: ' . esc_html($e->getMessage()));
        }
    }

    public function handle_test_request() {
        $this->logger->debug('Handling test request', [
            'post_data' => $_POST
        ]);
        
        check_ajax_referer('sewn_api_test', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Permission denied', 'startempire-wire-network-screenshots')
            ]);
            return;
        }

        // Get and validate URL
        $test_url = isset($_POST['test_url']) ? esc_url_raw($_POST['test_url']) : '';
        if (empty($test_url)) {
            wp_send_json_error([
                'message' => __('Please provide a valid URL', 'startempire-wire-network-screenshots')
            ]);
            return;
        }

        try {
            // Get and validate service configuration
            $active_mode = get_option('sewn_active_api', 'primary');
            $primary_key = get_option('sewn_api_key');
            $primary_configured = get_option('sewn_primary_service_configured', false);
            $fallback_key = get_option('sewn_fallback_api_key');
            
            // Log service state
            $this->logger->debug('Service state during test', [
                'active_mode' => $active_mode,
                'primary_configured' => $primary_configured,
                'has_primary_key' => !empty($primary_key),
                'has_fallback_key' => !empty($fallback_key)
            ]);

            // Determine which service to use
            if ($active_mode === 'primary' && $primary_configured && !empty($primary_key)) {
                $service = 'primary';
                $api_key = $primary_key;
                $this->logger->info('Using primary service for test');
            } else if (!empty($fallback_key)) {
                $service = get_option('sewn_fallback_service', 'screenshotmachine');
                $api_key = $fallback_key;
                $this->logger->info('Using fallback service for test', [
                    'service' => $service
                ]);
            } else {
                throw new Exception('No valid service configuration found');
            }

            // Get screenshot options
            $width = isset($_POST['options']['width']) ? intval($_POST['options']['width']) : 1280;
            $height = isset($_POST['options']['height']) ? intval($_POST['options']['height']) : 800;
            $quality = isset($_POST['options']['quality']) ? intval($_POST['options']['quality']) : 85;

            // Prepare REST API request
            $request_url = rest_url('sewn-screenshots/v1/screenshot');
            $request_args = [
                'method' => 'POST',
                'headers' => [
                    'X-API-Key' => $api_key,
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode([
                    'url' => $test_url,
                    'type' => 'test',
                    'options' => [
                        'width' => $width,
                        'height' => $height,
                        'quality' => $quality,
                        'mode' => $service,
                        'service' => $service
                    ]
                ])
            ];

            $this->logger->debug('Making screenshot request', [
                'service' => $service,
                'has_key' => !empty($api_key),
                'options' => [
                    'width' => $width,
                    'height' => $height,
                    'quality' => $quality
                ]
            ]);

            // Make REST API request
            $response = wp_remote_request($request_url, $request_args);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (!$body || isset($body['code'])) {
                throw new Exception($body['message'] ?? 'Invalid response from API');
            }

            wp_send_json_success($body);

        } catch (Exception $e) {
            $this->logger->error('Screenshot test failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }
} 