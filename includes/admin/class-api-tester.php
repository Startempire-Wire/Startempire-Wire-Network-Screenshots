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

        wp_enqueue_script(
            'sewn-api-tester-enhanced',
            SEWN_SCREENSHOTS_URL . 'assets/js/api-tester-enhanced.js',
            ['jquery'],
            SEWN_SCREENSHOTS_VERSION,
            true
        );

        wp_localize_script('sewn-api-tester-enhanced', 'sewnApiTester', [
            'nonce' => wp_create_nonce('sewn_api_test'),
            'ajaxurl' => admin_url('admin-ajax.php')
        ]);
    }

    public function render_test_interface() {
        $this->logger->debug('Initializing API tester interface');
        
        try {
            ?>
            <div class="wrap">
                <h1>API Tester</h1>
                <?php $this->logger->debug('Rendering API test form'); ?>
                
                <div class="sewn-dashboard-grid">
                    <!-- Test Form -->
                    <div class="sewn-card">
                        <h2>Test Screenshot API</h2>
                        <div class="sewn-api-test-form">
                            <div class="form-group">
                                <label for="test-url">URL to Screenshot</label>
                                <input type="url" 
                                       id="test-url" 
                                       class="regular-text" 
                                       placeholder="https://example.com" 
                                       required
                                       data-log="url-input-change">
                            </div>
                            
                            <div class="form-group">
                                <label>Screenshot Options</label>
                                <div class="options-grid">
                                    <div>
                                        <label for="test-width">Width</label>
                                        <input type="number" 
                                               id="test-width" 
                                               value="1280" 
                                               min="100" 
                                               max="2560"
                                               data-log="width-change">
                                    </div>
                                    <div>
                                        <label for="test-height">Height</label>
                                        <input type="number" 
                                               id="test-height" 
                                               value="800" 
                                               min="100" 
                                               max="2560"
                                               data-log="height-change">
                                    </div>
                                    <div>
                                        <label for="test-quality">Quality</label>
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
                                    Take Screenshot
                                </button>
                                <span class="spinner"></span>
                                <div class="test-feedback"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Test Results -->
                    <div class="sewn-card">
                        <h2>Test Results</h2>
                        <div id="test-results" class="sewn-api-test-results">
                            <div class="no-results">No tests run yet</div>
                        </div>
                    </div>
                </div>
            </div>
            <?php
            $this->logger->debug('API tester interface rendered successfully');
            
            // Enqueue enhanced JS
            wp_enqueue_script(
                'sewn-api-tester-enhanced',
                SEWN_SCREENSHOTS_URL . 'assets/js/api-tester-enhanced.js',
                ['jquery'],
                SEWN_SCREENSHOTS_VERSION,
                true
            );
            
        } catch (Exception $e) {
            $this->logger->error('Failed to render API tester interface', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            wp_die('Error loading API tester: ' . esc_html($e->getMessage()));
        }
    }

    public function handle_test_request() {
        $this->logger->debug('Screenshot test request received');

        try {
            // Verify nonce and capabilities
            if (!check_ajax_referer('sewn_api_test', 'nonce', false)) {
                throw new Exception('Security check failed');
            }

            if (!current_user_can('manage_options')) {
                throw new Exception('Insufficient permissions');
            }

            // Get and validate test URL
            $test_url = isset($_POST['test_url']) ? esc_url_raw($_POST['test_url']) : '';
            if (empty($test_url)) {
                throw new Exception('Please provide a valid URL');
            }

            // Get test options
            $options = isset($_POST['options']) ? (array)$_POST['options'] : [];
            
            // Take screenshot
            $screenshot = new SEWN_Screenshot_Service($this->logger);
            $result = $screenshot->take_screenshot($test_url, $options);

            if (!$result || !isset($result['file_path'])) {
                throw new Exception('Failed to capture screenshot');
            }

            // Get file details
            $file_size = size_format(filesize($result['file_path']), 2);
            $dimensions = getimagesize($result['file_path']);

            wp_send_json_success([
                'screenshot_url' => $this->get_screenshot_url($result['file_path']),
                'file_size' => $file_size,
                'width' => $dimensions[0],
                'height' => $dimensions[1],
                'quality' => isset($options['quality']) ? $options['quality'] : 85
            ]);

        } catch (Exception $e) {
            $this->logger->error('Screenshot test failed', [
                'error' => $e->getMessage(),
                'url' => isset($_POST['test_url']) ? $_POST['test_url'] : '',
                'options' => isset($_POST['options']) ? $_POST['options'] : []
            ]);

            wp_send_json_error($e->getMessage());
        }
    }

    private function get_screenshot_url($file_path) {
        $upload_dir = wp_upload_dir();
        return str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $file_path);
    }
} 