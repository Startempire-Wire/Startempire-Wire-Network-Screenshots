<?php
class SEWN_API_Tester {
    private $logger;
    private $settings;

    public function __construct($logger, $settings) {
        $this->logger = $logger;
        $this->settings = $settings;
        
        // Add AJAX handler
        add_action('wp_ajax_sewn_test_screenshot', [$this, 'handle_test_request']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function enqueue_scripts($hook) {
        if (strpos($hook, 'sewn-screenshots') === false) {
            return;
        }

        // Enqueue the main admin CSS
        wp_enqueue_style(
            'sewn-admin-style',
            SEWN_SCREENSHOTS_URL . 'assets/css/admin.css',
            [],
            SEWN_SCREENSHOTS_VERSION
        );

        // Enqueue the API tester script
        wp_enqueue_script(
            'sewn-api-tester-enhanced',
            SEWN_SCREENSHOTS_URL . 'assets/js/api-tester-enhanced.js',
            ['jquery'],
            SEWN_SCREENSHOTS_VERSION,
            true
        );

        // Add all required configuration parameters
        wp_localize_script('sewn-api-tester-enhanced', 'sewnApiTester', [
            'restUrl' => trailingslashit(rest_url()),
            'apiBase' => 'sewn-screenshots/v1',
            'nonce' => wp_create_nonce('wp_rest'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'homeUrl' => home_url(),
            'debug' => WP_DEBUG,
            'version' => SEWN_SCREENSHOTS_VERSION,
            'endpoints' => [
                'screenshot' => 'screenshot',
                'preview' => 'preview/screenshot',
                'status' => 'status',
                'cache' => 'cache/purge'
            ],
            'headers' => [
                'X-WP-Nonce' => wp_create_nonce('wp_rest'),
                'Content-Type' => 'application/json'
            ]
        ]);
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
        $this->logger->debug('=== Starting Screenshot Test Request ===', [
            'timestamp' => current_time('mysql'),
            'post_data' => $_POST,
            'server' => [
                'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'],
                'CONTENT_TYPE' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
                'HTTP_X_REQUESTED_WITH' => $_SERVER['HTTP_X_REQUESTED_WITH'] ?? 'not set'
            ],
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ]);

        try {
            // Verify nonce and capabilities
            $nonce_check = check_ajax_referer('sewn_api_test', 'nonce', false);
            $this->logger->debug('Nonce check', [
                'result' => $nonce_check,
                'provided_nonce' => $_POST['nonce'] ?? 'not set'
            ]);

            if (!$nonce_check) {
                throw new Exception('Security check failed');
            }

            $can_manage = current_user_can('manage_options');
            $this->logger->debug('Capability check', [
                'can_manage_options' => $can_manage,
                'current_user' => get_current_user_id()
            ]);

            if (!$can_manage) {
                throw new Exception('Insufficient permissions');
            }

            // Get and validate test URL
            $test_url = isset($_POST['test_url']) ? esc_url_raw($_POST['test_url']) : '';
            $this->logger->debug('URL validation', [
                'raw_url' => $_POST['test_url'] ?? 'not set',
                'sanitized_url' => $test_url
            ]);

            if (empty($test_url)) {
                throw new Exception('Please provide a valid URL');
            }

            // Get test options and validate
            $options = isset($_POST['options']) ? (array)$_POST['options'] : [];
            $this->logger->debug('Processing options', [
                'received_options' => $_POST['options'] ?? [],
                'processed_options' => $options
            ]);

            // Directory checks
            $upload_dir = wp_upload_dir();
            $screenshots_dir = $upload_dir['basedir'] . '/screenshots';
            
            $this->logger->debug('Directory status', [
                'upload_base' => $upload_dir['basedir'],
                'screenshots_dir' => $screenshots_dir,
                'exists' => file_exists($screenshots_dir),
                'writable' => is_writable($screenshots_dir),
                'permissions' => decoct(fileperms($screenshots_dir) & 0777),
                'wp_content_permissions' => decoct(fileperms(WP_CONTENT_DIR) & 0777),
                'process_user' => get_current_user(),
                'process_uid' => getmyuid(),
                'process_gid' => getmygid()
            ]);

            // Before screenshot attempt
            $this->logger->debug('Pre-screenshot configuration', [
                'bridge_class_exists' => class_exists('SEWN_Screenshot_Bridge'),
                'settings' => $this->settings,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'temp_dir_writable' => is_writable(sys_get_temp_dir())
            ]);

            // Screenshot attempt
            $screenshot_bridge = new SEWN_Screenshot_Bridge($this->logger);
            $filename = 'screenshot-' . uniqid() . '.png';
            
            $result = $screenshot_bridge->take_screenshot($test_url, [
                'width' => isset($options['width']) ? (int)$options['width'] : 1280,
                'height' => isset($options['height']) ? (int)$options['height'] : 800,
                'quality' => isset($options['quality']) ? (int)$options['quality'] : 85,
                'filename' => $filename
            ]);

            $this->logger->debug('Screenshot attempt result', [
                'result' => $result,
                'file_exists' => isset($result['path']) ? file_exists($result['path']) : false,
                'file_size' => isset($result['path']) ? filesize($result['path']) : 0,
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true)
            ]);

            if (!$result || !isset($result['path'])) {
                throw new Exception('Failed to capture screenshot: ' . ($result['error'] ?? 'Unknown error'));
            }

            if (!file_exists($result['path'])) {
                throw new Exception('Screenshot file not created: ' . $result['path']);
            }

            // Get file details
            $file_size = size_format(filesize($result['path']), 2);
            $dimensions = getimagesize($result['path']);

            if (!$dimensions) {
                throw new Exception('Invalid image file created');
            }

            // Get the URL for the screenshot
            $screenshot_url = str_replace(
                $upload_dir['basedir'],
                $upload_dir['baseurl'],
                $result['path']
            );

            $response_data = [
                'screenshot_url' => $screenshot_url,
                'file_size' => $file_size,
                'width' => $dimensions[0],
                'height' => $dimensions[1],
                'quality' => isset($options['quality']) ? $options['quality'] : 85
            ];

            $this->logger->debug('Sending success response', ['response' => $response_data]);
            wp_send_json_success($response_data);

        } catch (Exception $e) {
            $this->logger->error('Screenshot test failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'url' => isset($_POST['test_url']) ? $_POST['test_url'] : '',
                'options' => isset($_POST['options']) ? $_POST['options'] : [],
                'last_error' => error_get_last()
            ]);

            wp_send_json_error([
                'error' => $e->getMessage(),
                'details' => $this->logger->get_recent_logs(5)
            ]);
        }
    }

    private function get_screenshot_url($file_path) {
        $upload_dir = wp_upload_dir();
        return str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $file_path);
    }
} 