<?php
class SEWN_API_Manager {
    private $logger;
    private $settings;
    private $fallback_services = [
        'screenshotmachine' => [
            'name' => 'Screenshot Machine',
            'url' => 'https://api.screenshotmachine.com/',
            'requires_key' => true
        ],
        'screenshotapi' => [
            'name' => 'Screenshot API',
            'url' => 'https://shot.screenshotapi.net/screenshot/',
            'requires_key' => true
        ]
    ];

    public function __construct($logger, $settings) {
        $this->logger = $logger;
        $this->settings = $settings;
        
        // Add AJAX handlers
        add_action('wp_ajax_sewn_regenerate_api_key', [$this, 'handle_key_regeneration']);
        add_action('wp_ajax_sewn_toggle_api', [$this, 'handle_api_toggle']);
        add_action('wp_ajax_sewn_log_ui_action', [$this, 'handle_ui_log']);
        add_action('wp_ajax_sewn_test_api', [$this, 'handle_api_test']);
        add_action('wp_ajax_sewn_update_fallback_service', [$this, 'handle_fallback_update']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'sewn-screenshots') === false) {
            return;
        }

        wp_enqueue_script(
            'sewn-admin',
            SEWN_SCREENSHOTS_URL . 'assets/js/admin.js',
            ['jquery'],
            SEWN_SCREENSHOTS_VERSION,
            true
        );

        // Localize script with nonce and other data
        wp_localize_script('sewn-admin', 'sewnAdmin', [
            'nonce' => wp_create_nonce('sewn_api_management'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'strings' => [
                'apiEnabled' => __('API enabled successfully', 'startempire-wire-network-screenshots'),
                'apiDisabled' => __('API disabled successfully', 'startempire-wire-network-screenshots'),
                'error' => __('Operation failed', 'startempire-wire-network-screenshots')
            ]
        ]);
    }

    private function get_api_stats() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sewn_api_logs';
        
        try {
            // Ensure table exists
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                $this->logger->warning('API logs table not found, attempting to create');
                $api_logger = new SEWN_API_Logger();
                $api_logger->create_table();
            }

            $yesterday = date('Y-m-d H:i:s', strtotime('-24 hours'));
            
            $daily_requests = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE timestamp > %s",
                $yesterday
            ));

            $successful_requests = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE status = 'success' AND timestamp > %s",
                $yesterday
            ));

            $active_extensions = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT extension_id) FROM $table_name WHERE timestamp > %s",
                $yesterday
            ));

            $success_rate = $daily_requests > 0 ? ($successful_requests / $daily_requests) * 100 : 0;

            return [
                'daily_requests' => (int)$daily_requests,
                'success_rate' => round($success_rate, 2),
                'active_extensions' => (int)$active_extensions
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to get API stats', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'daily_requests' => 0,
                'success_rate' => 0,
                'active_extensions' => 0
            ];
        }
    }

    private function get_recent_requests($limit = 50) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sewn_api_logs';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    private function is_api_active() {
        return get_option('sewn_api_active', true);
    }

    public function handle_ui_log() {
        if (!check_ajax_referer('sewn_api_management', 'nonce', false)) {
            $this->logger->warning('Invalid nonce in UI log request');
            wp_send_json_error('Invalid nonce');
            return;
        }

        $log_data = $_POST['log_data'] ?? null;
        if ($log_data) {
            $this->logger->debug('UI Action', [
                'action' => $log_data['action'],
                'data' => $log_data['data'],
                'user_id' => get_current_user_id(),
                'timestamp' => $log_data['timestamp']
            ]);
            wp_send_json_success();
        } else {
            $this->logger->warning('Empty log data received');
            wp_send_json_error('No log data provided');
        }
    }

    public function handle_api_toggle() {
        $this->logger->debug('API toggle request received');

        try {
            // Verify nonce and capabilities
            if (!check_ajax_referer('sewn_api_management', 'nonce', false)) {
                throw new Exception('Invalid security token');
            }

            if (!current_user_can('manage_options')) {
                throw new Exception('Insufficient permissions');
            }

            // Get and validate the state parameter
            $new_state = isset($_POST['state']) ? filter_var($_POST['state'], FILTER_VALIDATE_BOOLEAN) : null;
            if ($new_state === null) {
                throw new Exception('Missing state parameter');
            }

            $this->logger->debug('Attempting to update API state', ['new_state' => $new_state]);

            // Update the API state
            $updated = update_option('sewn_api_active', $new_state);
            
            if ($updated) {
                $this->logger->info('API state updated successfully', [
                    'new_state' => $new_state,
                    'user_id' => get_current_user_id()
                ]);

                wp_send_json_success([
                    'message' => $new_state ? 'API enabled successfully' : 'API disabled successfully',
                    'state' => $new_state
                ]);
            } else {
                throw new Exception('Failed to update API state');
            }

        } catch (Exception $e) {
            $this->logger->error('API toggle failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            wp_send_json_error($e->getMessage());
        }
    }

    public function handle_key_regeneration() {
        $this->logger->debug('API key regeneration request received');

        try {
            // Verify nonce and capabilities
            if (!check_ajax_referer('sewn_api_management', 'nonce', false)) {
                throw new Exception('Invalid security token');
            }

            if (!current_user_can('manage_options')) {
                throw new Exception('Insufficient permissions');
            }

            // Generate new API key
            $new_key = wp_generate_password(32, false);
            
            // Save the new key
            $updated = update_option('sewn_api_key', $new_key);
            
            if ($updated) {
                $this->logger->info('API key regenerated', [
                    'user_id' => get_current_user_id()
                ]);

                wp_send_json_success([
                    'message' => 'API key regenerated successfully',
                    'key' => $new_key
                ]);
            } else {
                throw new Exception('Failed to save new API key');
            }

        } catch (Exception $e) {
            $this->logger->error('API key regeneration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => 'key_regeneration_failed'
            ]);
        }
    }

    public function handle_api_test() {
        $this->logger->debug('API test request received');

        try {
            // Verify nonce and capabilities
            if (!check_ajax_referer('sewn_api_management', 'nonce', false)) {
                throw new Exception('Invalid security token');
            }

            if (!current_user_can('manage_options')) {
                throw new Exception('Insufficient permissions');
            }

            // Get the service ID and test data
            $service_id = isset($_POST['service_id']) ? filter_var($_POST['service_id'], FILTER_SANITIZE_STRING) : null;
            $test_data = isset($_POST['test_data']) ? json_decode(stripslashes($_POST['test_data']), true) : null;

            if ($service_id === null || $test_data === null) {
                throw new Exception('Missing service ID or test data');
            }

            $this->logger->debug('Attempting to test API', ['service_id' => $service_id]);

            // Test the API
            $api_test = new SEWN_API_Test($service_id, $test_data);
            $result = $api_test->test();

            if ($result['success']) {
                $this->logger->info('API test successful', ['service_id' => $service_id]);
                wp_send_json_success([
                    'message' => 'API test successful',
                    'result' => $result['result']
                ]);
            } else {
                $this->logger->error('API test failed', ['service_id' => $service_id, 'error' => $result['error']]);
                wp_send_json_error([
                    'message' => 'API test failed',
                    'error' => $result['error']
                ]);
            }

        } catch (Exception $e) {
            $this->logger->error('API test failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => 'api_test_failed'
            ]);
        }
    }

    public function render_api_dashboard() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Get current values
        $current_service = get_option('sewn_fallback_service', 'screenshotmachine');
        $current_api_key = get_option('sewn_fallback_api_key', '');

        $this->logger->debug('Rendering dashboard with values:', [
            'service' => $current_service,
            'has_key' => !empty($current_api_key)
        ]);

        ?>
        <div class="wrap">
            <h1>API Management</h1>
            
            <div class="sewn-card">
                <h2>Fallback Service Configuration</h2>
                
                <div id="fallback-service-messages"></div>
                
                <form id="fallback-service-form" method="post">
                    <?php wp_nonce_field('sewn_api_management', 'fallback_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="service">Service</label>
                            </th>
                            <td>
                                <select name="service" id="service" class="regular-text">
                                    <?php foreach ($this->fallback_services as $key => $service): ?>
                                        <option value="<?php echo esc_attr($key); ?>" 
                                                <?php selected($current_service, $key); ?>>
                                            <?php echo esc_html($service['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="api_key">API Key</label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="api_key" 
                                       name="api_key" 
                                       value="<?php echo esc_attr($current_api_key); ?>"
                                       class="regular-text">
                                <?php if (!empty($current_api_key)): ?>
                                    <span class="api-key-status success">✓ API Key Set</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary">
                            Update Fallback Service
                        </button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    private function detect_wkhtmltoimage() {
        $possible_paths = [
            '/usr/local/bin/wkhtmltoimage',
            '/usr/bin/wkhtmltoimage',
            'C:\\Program Files\\wkhtmltopdf\\bin\\wkhtmltoimage.exe',
            'C:\\Program Files (x86)\\wkhtmltopdf\\bin\\wkhtmltoimage.exe'
        ];

        foreach ($possible_paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return false;
    }

    private function get_fallback_config() {
        return [
            'service' => get_option('sewn_fallback_service', 'screenshotmachine'),
            'api_key' => get_option('sewn_fallback_api_key', '')
        ];
    }

    public function handle_fallback_update() {
        try {
            $this->logger->debug('Starting fallback update handler');
            
            // Verify nonce
            if (!check_ajax_referer('sewn_api_management', 'nonce', false)) {
                $this->logger->error('Nonce verification failed');
                throw new Exception('Invalid security token');
            }

            // Verify capabilities
            if (!current_user_can('manage_options')) {
                $this->logger->error('Insufficient permissions for user');
                throw new Exception('Insufficient permissions');
            }

            // Log all POST data
            $this->logger->debug('Received POST data:', [
                'all_post' => $_POST,
                'service' => isset($_POST['service']) ? $_POST['service'] : 'not set',
                'api_key_present' => isset($_POST['api_key']),
                'api_key_length' => isset($_POST['api_key']) ? strlen($_POST['api_key']) : 0
            ]);

            $service = isset($_POST['service']) ? sanitize_text_field($_POST['service']) : '';
            $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';

            // Validate service
            if (!array_key_exists($service, $this->fallback_services)) {
                $this->logger->error('Invalid service selected', ['service' => $service]);
                throw new Exception('Invalid fallback service selected');
            }

            // Log pre-update values
            $this->logger->debug('Pre-update values:', [
                'old_service' => get_option('sewn_fallback_service'),
                'old_key_exists' => !empty(get_option('sewn_fallback_api_key'))
            ]);

            // Store values
            $updated_service = update_option('sewn_fallback_service', $service);
            $updated_key = update_option('sewn_fallback_api_key', $api_key);

            // Log update results
            $this->logger->debug('Update operation results:', [
                'service_updated' => $updated_service,
                'key_updated' => $updated_key
            ]);

            // Verify stored values
            $stored_service = get_option('sewn_fallback_service');
            $stored_key = get_option('sewn_fallback_api_key');

            $this->logger->debug('Post-update verification:', [
                'stored_service' => $stored_service,
                'stored_key_length' => strlen($stored_key),
                'service_matches' => $stored_service === $service,
                'key_matches' => $stored_key === $api_key
            ]);

            if ($stored_service !== $service || $stored_key !== $api_key) {
                $this->logger->error('Value verification failed', [
                    'expected_service' => $service,
                    'stored_service' => $stored_service,
                    'key_length_match' => strlen($stored_key) === strlen($api_key)
                ]);
                throw new Exception('Failed to verify stored values');
            }

            $response_data = [
                'message' => 'Fallback service configuration updated successfully',
                'service' => $stored_service,
                'api_key' => $stored_key,
                'has_key' => !empty($stored_key)
            ];

            $this->logger->debug('Sending success response:', $response_data);
            wp_send_json_success($response_data);

        } catch (Exception $e) {
            $this->logger->error('Fallback update failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }
} 