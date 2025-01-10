<?php
/**
 * Manages the screenshot service API functionality including:
 * - Primary and fallback screenshot service integrations
 * - API key management and validation
 * - Admin interface for service configuration
 * - Usage tracking and logging
 * - Security and access control
 */
class SEWN_API_Manager {
    private $logger;
    private $settings;
    /** 
     * Defines available fallback screenshot services that can be used when primary service fails
     * Each service requires an API key and has a specific endpoint URL
     */
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

    /**
     * Initializes the API manager and sets up AJAX handlers for admin interactions
     * 
     * @param SEWN_Logger $logger Logger instance
     * @param array|null $settings Optional. Settings configuration
     */
    public function __construct($logger, $settings = null) {
        if (!$logger) {
            throw new Exception('Logger is required for API Manager');
        }
        
        $this->logger = $logger;
        $this->settings = $settings ?? [];
        
        // Assert service state on initialization
        $this->assert_service_state();
        
        $this->logger->debug('API Manager initialized', [
            'settings' => $this->settings,
            'active_mode' => get_option('sewn_active_api'),
            'primary_configured' => get_option('sewn_primary_service_configured'),
            'has_primary_key' => !empty(get_option('sewn_api_key'))
        ]);
        
        // Add AJAX handlers
        add_action('wp_ajax_sewn_regenerate_api_key', [$this, 'handle_key_regeneration']);
        add_action('wp_ajax_sewn_toggle_api', [$this, 'handle_api_toggle']);
        add_action('wp_ajax_sewn_log_ui_action', [$this, 'handle_ui_log']);
        add_action('wp_ajax_sewn_test_api', [$this, 'handle_api_test']);
        add_action('wp_ajax_sewn_update_fallback_service', [$this, 'handle_fallback_update']);
        add_action('wp_ajax_sewn_update_api_mode', [$this, 'handle_api_mode_update']);
        add_action('wp_ajax_sewn_generate_api_key', [$this, 'handle_key_generation']);
        
        // Add script loading
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    /**
     * Loads admin JavaScript only on plugin pages
     * Provides localized data for AJAX operations and translations
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'sewn-screenshots') === false) {
            return;
        }

        // Enqueue scripts with proper dependencies
        wp_enqueue_style(
            'sewn-admin-style',
            SEWN_SCREENSHOTS_URL . 'assets/css/admin-settings.css',
            [],
            SEWN_SCREENSHOTS_VERSION
        );

        wp_enqueue_script(
            'sewn-admin-settings',
            SEWN_SCREENSHOTS_URL . 'assets/js/admin-settings.js',
            ['jquery-core'], // Use jquery-core instead of jquery
            SEWN_SCREENSHOTS_VERSION,
            true
        );

        // Remove unnecessary scripts
        wp_dequeue_script('dashboard-carousel');
        
        wp_localize_script('sewn-admin-settings', 'sewn_settings', [
            'nonce' => wp_create_nonce('sewn_api_management'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'page' => 'api_manager' // Add page identifier
        ]);
    }

    /**
     * Retrieves API usage statistics for the dashboard
     * Creates logging table if it doesn't exist
     */
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

    /**
     * Retrieves recent API requests for admin dashboard display
     */
    public function get_recent_requests($limit = 10) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sewn_api_logs';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    /**
     * Checks if the screenshot API service is currently active
     */
    private function is_api_active() {
        return get_option('sewn_api_active', true);
    }

    /**
     * AJAX handler for logging UI interactions
     * Validates nonce and logs user actions for debugging
     */
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

    /**
     * Handles API mode switching and state toggling
     */
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

            // Handle API Enable/Disable State
            if (isset($_POST['state'])) {
                $new_state = filter_var($_POST['state'], FILTER_VALIDATE_BOOLEAN);
                
                $this->logger->debug('Attempting to update API state', ['new_state' => $new_state]);
                
                $updated = update_option('sewn_api_active', $new_state);
                
                if (!$updated) {
                    throw new Exception('Failed to update API state');
                }

                $this->logger->info('API state updated successfully', [
                    'new_state' => $new_state,
                    'user_id' => get_current_user_id()
                ]);

                wp_send_json_success([
                    'message' => $new_state ? 'API enabled successfully' : 'API disabled successfully',
                    'state' => $new_state
                ]);
            }

            // Handle API Mode Switch (Primary/Fallback)
            if (isset($_POST['mode'])) {
                $mode = sanitize_text_field($_POST['mode']);
                
                if (!in_array($mode, ['primary', 'fallback'])) {
                    throw new Exception('Invalid API mode');
                }

                $this->logger->debug('Attempting to switch API mode', ['new_mode' => $mode]);

                // If switching to primary mode, ensure API key exists
                $response_data = [];
                if ($mode === 'primary') {
                    $api_key = $this->ensure_api_key_exists();
                    $response_data['key'] = $api_key;
                    $this->logger->info('Generated/Retrieved API key for primary mode', [
                        'key_length' => strlen($api_key)
                    ]);
                }

                $updated = update_option('sewn_active_api', $mode);
                
                if (!$updated) {
                    throw new Exception('Failed to update API mode');
                }

                $this->logger->info('API mode updated successfully', [
                    'mode' => $mode,
                    'user_id' => get_current_user_id()
                ]);

                wp_send_json_success(array_merge([
                    'message' => sprintf('Switched to %s API mode successfully', $mode),
                    'mode' => $mode
                ], $response_data));
            }

            throw new Exception('No valid action specified');

        } catch (Exception $e) {
            $this->logger->error('API toggle failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * AJAX handler for generating new API keys
     * Includes security validation and logging
     */
    public function handle_key_regeneration() {
        check_ajax_referer('sewn_api_management', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Generate a new API key
        $new_key = wp_generate_password(32, false);
        
        // Save the new key
        update_option('sewn_api_key', $new_key);
        
        // Log the regeneration
        $this->logger->info('Primary API key regenerated');
        
        wp_send_json_success([
            'key' => $new_key,
            'message' => __('API key generated successfully', 'startempire-wire-network-screenshots')
        ]);
    }

    /**
     * AJAX handler for testing API connections
     * Tests connection to selected screenshot service
     */
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

    /**
     * Renders the API management dashboard interface
     * Displays API configuration, status, and controls
     */
    public function render_api_dashboard() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $primary_key = get_option('sewn_api_key', '');
        $current_service = get_option('sewn_fallback_service', 'none');
        $active_api = get_option('sewn_active_api', 'primary');
        
        ?>
        <div class="wrap">
            <h1><?php _e('API Management', 'startempire-wire-network-screenshots'); ?></h1>
            
            <div class="sewn-tabs-wrapper">
                <!-- Tabs -->
                <div class="nav-tab-wrapper">
                    <button class="nav-tab <?php echo $active_api === 'primary' ? 'nav-tab-active' : ''; ?>" 
                            data-target="primary-api-panel">
                        <?php _e('Primary API', 'startempire-wire-network-screenshots'); ?>
                    </button>
                    <button class="nav-tab <?php echo $active_api === 'fallback' ? 'nav-tab-active' : ''; ?>" 
                            data-target="fallback-api-panel">
                        <?php _e('Fallback Services', 'startempire-wire-network-screenshots'); ?>
                    </button>
                </div>

                <!-- Tab Panels -->
                <div class="sewn-tab-panels">
                    <!-- Primary API Panel -->
                    <div id="primary-api-panel" 
                         class="sewn-tab-panel <?php echo $active_api === 'primary' ? 'is-active' : ''; ?>">
                        <div class="sewn-panel-content">
                            <div class="key-status <?php echo !empty($primary_key) ? 'active' : 'inactive'; ?>">
                                <span class="status-dot"></span>
                                <span><?php echo !empty($primary_key) ? 
                                    __('Primary API Key: Active', 'startempire-wire-network-screenshots') : 
                                    __('Primary API Key: Not Set', 'startempire-wire-network-screenshots'); ?></span>
                            </div>
                            
                            <div class="sewn-api-form">
                                <input type="text" 
                                       id="sewn_primary_key" 
                                       value="<?php echo esc_attr($primary_key); ?>" 
                                       class="regular-text" 
                                       readonly />
                                <button class="button regenerate-api-key" 
                                        data-service="primary"
                                        data-nonce="<?php echo wp_create_nonce('sewn_api_management'); ?>">
                                    <?php echo empty($primary_key) ? 
                                        __('Generate Key', 'startempire-wire-network-screenshots') : 
                                        __('Regenerate Key', 'startempire-wire-network-screenshots'); ?>
                                </button>
                                <div class="api-feedback"></div>
                            </div>

                            <!-- API Documentation -->
                            <div class="api-documentation">
                                <h2>API Documentation</h2>
                                
                                <div class="api-section authentication">
                                    <h3>Authentication Methods</h3>
                                    <div class="auth-methods">
                                        <div class="auth-method">
                                            <h4>API Key</h4>
                                            <div class="code-block">
                                                <code>X-API-Key: your-api-key</code>
                                                <button class="copy-button" data-clipboard-text="X-API-Key: your-api-key">
                                                    <span class="dashicons dashicons-clipboard"></span>
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div class="auth-method">
                                            <h4>JWT Token</h4>
                                            <div class="code-block">
                                                <code>Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...</code>
                                                <button class="copy-button" data-clipboard-text="Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...">
                                                    <span class="dashicons dashicons-clipboard"></span>
                                                </button>
                                            </div>
                                        </div>
                                        
                                        <div class="auth-method">
                                            <h4>OAuth2</h4>
                                            <div class="code-block">
                                                <code>access_token=your-oauth-token</code>
                                                <button class="copy-button" data-clipboard-text="access_token=your-oauth-token">
                                                    <span class="dashicons dashicons-clipboard"></span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="api-section endpoints">
                                    <h3>Available Endpoints</h3>
                                    
                                    <div class="endpoint-card">
                                        <div class="endpoint-header">
                                            <span class="method post">POST</span>
                                            <code class="endpoint-url">/wp-json/sewn-screenshots/v1/screenshot</code>
                                            <button class="copy-button" data-clipboard-text="<?php echo esc_url(rest_url('sewn-screenshots/v1/screenshot')); ?>">
                                                <span class="dashicons dashicons-clipboard"></span>
                                            </button>
                                        </div>
                                        <div class="endpoint-meta">
                                            <span class="badge auth-required">Auth Required</span>
                                            <span class="badge rate-limited">Rate Limited</span>
                                            <span class="badge cached">Cached</span>
                                        </div>
                                        <div class="endpoint-description">
                                            <p>Takes a new screenshot with optional parameters.</p>
                                            <div class="parameters">
                                                <h5>Parameters</h5>
                                                <table class="params-table">
                                                    <tr>
                                                        <th>Parameter</th>
                                                        <th>Type</th>
                                                        <th>Required</th>
                                                        <th>Description</th>
                                                    </tr>
                                                    <tr>
                                                        <td>url</td>
                                                        <td>string</td>
                                                        <td>Yes</td>
                                                        <td>URL to capture</td>
                                                    </tr>
                                                    <tr>
                                                        <td>type</td>
                                                        <td>string</td>
                                                        <td>No</td>
                                                        <td>Screenshot type (full or preview)</td>
                                                    </tr>
                                                    <tr>
                                                        <td>options</td>
                                                        <td>object</td>
                                                        <td>No</td>
                                                        <td>
                                                            Configuration options:
                                                            <ul>
                                                                <li>width: 100-2560px (default: 1280)</li>
                                                                <li>height: 100-2560px (default: 800)</li>
                                                                <li>quality: 1-100 (default: 85)</li>
                                                            </ul>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </div>
                                            <div class="example">
                                                <h5>Example Request</h5>
                                                <div class="code-block">
                                                    <pre><code>curl -X POST \
  <?php echo esc_url(rest_url('sewn-screenshots/v1/screenshot')); ?> \
  -H 'X-API-Key: your-api-key' \
  -H 'Content-Type: application/json' \
  -d '{
    "url": "https://example.com",
    "type": "full",
    "options": {
      "width": 1920,
      "height": 1080,
      "quality": 90
    }
  }'</code></pre>
                                                    <button class="copy-button" data-clipboard-text="curl -X POST \
  <?php echo esc_url(rest_url('sewn-screenshots/v1/screenshot')); ?> \
  -H 'X-API-Key: your-api-key' \
  -H 'Content-Type: application/json' \
  -d '{
    &quot;url&quot;: &quot;https://example.com&quot;,
    &quot;type&quot;: &quot;full&quot;,
    &quot;options&quot;: {
      &quot;width&quot;: 1920,
      &quot;height&quot;: 1080,
      &quot;quality&quot;: 90
    }
  }'">
                                                        <span class="dashicons dashicons-clipboard"></span>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Preview Screenshot Endpoint -->
                                    <div class="endpoint-card">
                                        <div class="endpoint-header">
                                            <span class="method get">GET</span>
                                            <code class="endpoint-url">/wp-json/sewn-screenshots/v1/preview/screenshot</code>
                                            <button class="copy-button" data-clipboard-text="<?php echo esc_url(rest_url('sewn-screenshots/v1/preview/screenshot')); ?>">
                                                <span class="dashicons dashicons-clipboard"></span>
                                            </button>
                                        </div>
                                        <div class="endpoint-meta">
                                            <span class="badge public">Public</span>
                                            <span class="badge cached">24h Cache</span>
                                        </div>
                                        <div class="endpoint-description">
                                            <p>Gets an optimized preview screenshot.</p>
                                            <div class="parameters">
                                                <h5>Parameters</h5>
                                                <table class="params-table">
                                                    <tr>
                                                        <th>Parameter</th>
                                                        <th>Type</th>
                                                        <th>Required</th>
                                                        <th>Description</th>
                                                    </tr>
                                                    <tr>
                                                        <td>url</td>
                                                        <td>string</td>
                                                        <td>Yes</td>
                                                        <td>URL to capture</td>
                                                    </tr>
                                                    <tr>
                                                        <td>options</td>
                                                        <td>object</td>
                                                        <td>No</td>
                                                        <td>
                                                            Preview options:
                                                            <ul>
                                                                <li>width: 320-2560px (default: 1280)</li>
                                                                <li>height: 240-1600px (default: 800)</li>
                                                                <li>quality: 1-100 (default: 85)</li>
                                                                <li>format: png|jpg|webp (default: png)</li>
                                                            </ul>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </div>
                                            <div class="example">
                                                <h5>Example Request</h5>
                                                <div class="code-block">
                                                    <pre><code>curl -X GET \
  "<?php echo esc_url(rest_url('sewn-screenshots/v1/preview/screenshot')); ?>?url=https://example.com"</code></pre>
                                                    <button class="copy-button" data-clipboard-text='curl -X GET "<?php echo esc_url(rest_url('sewn-screenshots/v1/preview/screenshot')); ?>?url=https://example.com"'>
                                                        <span class="dashicons dashicons-clipboard"></span>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Service Status Endpoint -->
                                    <div class="endpoint-card">
                                        <div class="endpoint-header">
                                            <span class="method get">GET</span>
                                            <code class="endpoint-url">/wp-json/sewn-screenshots/v1/status</code>
                                            <button class="copy-button" data-clipboard-text="<?php echo esc_url(rest_url('sewn-screenshots/v1/status')); ?>">
                                                <span class="dashicons dashicons-clipboard"></span>
                                            </button>
                                        </div>
                                        <div class="endpoint-meta">
                                            <span class="badge auth-required">Auth Required</span>
                                            <span class="badge rate-limited">Rate Limited</span>
                                        </div>
                                        <div class="endpoint-description">
                                            <p>Returns service health and metrics.</p>
                                            <div class="example">
                                                <h5>Example Request</h5>
                                                <div class="code-block">
                                                    <pre><code>curl -X GET \
  <?php echo esc_url(rest_url('sewn-screenshots/v1/status')); ?> \
  -H 'X-API-Key: your-api-key'</code></pre>
                                                    <button class="copy-button" data-clipboard-text='curl -X GET "<?php echo esc_url(rest_url('sewn-screenshots/v1/status')); ?>" -H "X-API-Key: your-api-key"'>
                                                        <span class="dashicons dashicons-clipboard"></span>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Cache Purge Endpoint -->
                                    <div class="endpoint-card">
                                        <div class="endpoint-header">
                                            <span class="method post">POST</span>
                                            <code class="endpoint-url">/wp-json/sewn-screenshots/v1/cache/purge</code>
                                            <button class="copy-button" data-clipboard-text="<?php echo esc_url(rest_url('sewn-screenshots/v1/cache/purge')); ?>">
                                                <span class="dashicons dashicons-clipboard"></span>
                                            </button>
                                        </div>
                                        <div class="endpoint-meta">
                                            <span class="badge admin-only">Admin Only</span>
                                        </div>
                                        <div class="endpoint-description">
                                            <p>Purges the screenshot cache.</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="api-section rate-limits">
                                    <h3>Rate Limits</h3>
                                    <div class="tier-limits">
                                        <div class="tier">
                                            <h4>Free Tier</h4>
                                            <p>60 requests per hour</p>
                                        </div>
                                        <div class="tier">
                                            <h4>Premium Tier</h4>
                                            <p>300 requests per hour</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="api-section error-handling">
                                    <h3>Error Handling</h3>
                                    <p>All endpoints return standardized error responses:</p>
                                    <div class="code-block">
                                        <pre><code>{
  "code": "error_code",
  "message": "Human readable message",
  "data": {
    "status": 400|401|403|404|429|500,
    "details": {}
  }
}</code></pre>
                                        <button class="copy-button" data-clipboard-text='{
  "code": "error_code",
  "message": "Human readable message",
  "data": {
    "status": 400,
    "details": {}
  }
}'>
                                            <span class="dashicons dashicons-clipboard"></span>
                                        </button>
                                    </div>
                                    
                                    <div class="error-codes">
                                        <h4>Common Error Codes</h4>
                                        <table class="error-codes-table">
                                            <tr>
                                                <th>Code</th>
                                                <th>Description</th>
                                            </tr>
                                            <tr>
                                                <td>invalid_url</td>
                                                <td>URL validation failed</td>
                                            </tr>
                                            <tr>
                                                <td>rate_limit_exceeded</td>
                                                <td>Too many requests</td>
                                            </tr>
                                            <tr>
                                                <td>auth_failed</td>
                                                <td>Authentication failed</td>
                                            </tr>
                                            <tr>
                                                <td>screenshot_failed</td>
                                                <td>Screenshot generation failed</td>
                                            </tr>
                                            <tr>
                                                <td>cache_error</td>
                                                <td>Cache operation failed</td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Fallback API Panel -->
                    <div id="fallback-api-panel" 
                         class="sewn-tab-panel <?php echo $active_api === 'fallback' ? 'is-active' : ''; ?>">
                        <div class="sewn-panel-content">
                            <div id="fallback-service-messages"></div>
                            
                            <form id="fallback-service-form" method="post">
                                <?php wp_nonce_field('sewn_api_management', 'fallback_nonce'); ?>
                                
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">
                                            <label for="service"><?php _e('Service', 'startempire-wire-network-screenshots'); ?></label>
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
                                            <label for="api_key"><?php _e('API Key', 'startempire-wire-network-screenshots'); ?></label>
                                        </th>
                                        <td>
                                            <?php $this->render_fallback_api_key_field(); ?>
                                        </td>
                                    </tr>
                                </table>

                                <p class="submit">
                                    <button type="submit" class="button button-primary">
                                        <?php _e('Update Fallback Service', 'startempire-wire-network-screenshots'); ?>
                                    </button>
                                </p>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Checks for local wkhtmltoimage installation
     * Used as a fallback for screenshot generation
     */
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

    /**
     * Retrieves current fallback service configuration
     */
    private function get_fallback_config() {
        return [
            'service' => get_option('sewn_fallback_service', 'screenshotmachine'),
            'api_key' => get_option('sewn_fallback_api_key', '')
        ];
    }

    /**
     * AJAX handler for updating fallback service settings
     * Includes extensive error logging and validation
     */
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

    /**
     * Gets currently selected screenshot service
     */
    public function get_current_service() {
        // Assert service state before getting service
        $this->assert_service_state();
        
        $active_mode = get_option('sewn_active_api', 'primary');
        $primary_configured = get_option('sewn_primary_service_configured', false);
        $primary_key = get_option('sewn_api_key');
        
        // Log current state
        $this->logger->debug('Service state', [
            'active_mode' => $active_mode,
            'primary_configured' => $primary_configured,
            'has_primary_key' => !empty($primary_key)
        ]);
        
        if ($active_mode === 'primary' && $primary_configured && !empty($primary_key)) {
            $this->logger->info('Using primary service');
            return 'primary';
        }
        
        $this->logger->warning('Using fallback service', [
            'reason' => [
                'mode' => $active_mode !== 'primary' ? 'fallback_mode_active' : null,
                'not_configured' => !$primary_configured ? 'primary_not_configured' : null,
                'no_key' => empty($primary_key) ? 'missing_primary_key' : null
            ]
        ]);
        
        return get_option('sewn_fallback_service', 'screenshotmachine');
    }

    /**
     * Gets current API key for active service
     */
    public function get_current_api_key() {
        return $this->ensure_api_key_exists(); // This will handle migration if needed
    }

    /**
     * Returns list of configured fallback services
     */
    public function get_available_fallback_services() {
        return $this->fallback_services;
    }

    /**
     * Validates provided API key against stored key
     * Handles both old and new format keys for backwards compatibility
     */
    public function verify_api_key($key) {
        if (empty($key)) {
            return false;
        }
        
        $stored_key = get_option('sewn_api_key', '');
        
        // If stored key is in old format, migrate it
        if (!empty($stored_key) && strpos($stored_key, 'sewn_') === 0) {
            $stored_key = $this->ensure_api_key_exists(); // This will migrate to new format
        }
        
        return !empty($stored_key) && $key === $stored_key;
    }

    /**
     * Validates provided fallback service API key
     */
    public function verify_fallback_key($key) {
        if (empty($key)) {
            return false;
        }
        
        $stored_key = get_option('sewn_fallback_api_key', '');
        return !empty($stored_key) && $key === $stored_key;
    }

    /**
     * Renders the fallback API key input field with visibility toggle
     */
    public function render_fallback_api_key_field($disabled = false) {
        $current_api_key = get_option('sewn_fallback_api_key', '');
        ?>
        <div class="sewn-api-key-wrapper">
            <input type="password" 
                   id="sewn_fallback_api_key" 
                   name="sewn_fallback_api_key" 
                   value="<?php echo esc_attr($current_api_key); ?>" 
                   class="regular-text"
                   <?php echo $disabled ? 'disabled' : ''; ?>>
            <button type="button" 
                    class="button sewn-toggle-visibility" 
                    data-target="sewn_fallback_api_key"
                    <?php echo $disabled ? 'disabled' : ''; ?>>
                <span class="dashicons dashicons-visibility"></span>
            </button>
        </div>
        <p class="description">
            <?php _e('Enter your fallback API key for backup screenshot service.', 'startempire-wire-network-screenshots'); ?>
        </p>
        <?php
    }

    /**
     * AJAX handler for updating API mode
     */
    public function handle_api_mode_update() {
        check_ajax_referer('sewn_api_management', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $mode = sanitize_text_field($_POST['mode']);
        if (!in_array($mode, ['primary', 'fallback'])) {
            wp_send_json_error('Invalid API mode');
        }
        
        update_option('sewn_active_api', $mode);
        wp_send_json_success();
    }

    /**
     * AJAX handler for generating new API keys
     * Includes security validation and logging
     */
    public function handle_key_generation() {
        check_ajax_referer('sewn_api_management', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $service = sanitize_text_field($_POST['service']);
        $new_key = $this->generate_api_key();
        
        // Store the key in WordPress options with the correct option name
        $option_name = ($service === 'primary') ? 'sewn_api_key' : 'sewn_fallback_api_key';
        $updated = update_option($option_name, $new_key);
        
        if ($updated) {
            wp_send_json_success([
                'key' => $new_key,
                'message' => __('API key generated successfully', 'startempire-wire-network-screenshots')
            ]);
        } else {
            wp_send_json_error(__('Failed to save API key', 'startempire-wire-network-screenshots'));
        }
    }

    /**
     * Generates a secure random API key
     */
    private function generate_api_key() {
        // Use consistent key generation method
        return wp_generate_password(32, false);
    }

    /**
     * Get the active API mode with proper fallback handling
     */
    public function get_active_api_mode() {
        // Ensure default key exists
        $this->ensure_api_key_exists();
        
        // Get configured mode
        $mode = get_option('sewn_active_api', 'primary');
        
        // If fallback is selected, verify it's properly configured
        if ($mode === 'fallback') {
            $fallback_key = get_option('sewn_fallback_api_key');
            if (empty($fallback_key)) {
                $this->logger->warning('Fallback selected but not configured, using primary');
                return 'primary';
            }
        }
        
        return $mode;
    }

    /**
     * Validate API key against current authentication mode
     * 
     * @param string $api_key The API key to validate
     * @return boolean True if valid, false otherwise
     */
    public function validate_api_key($api_key) {
        if (empty($api_key)) {
            return false;
        }

        $mode = $this->get_active_api_mode();
        $primary_key = get_option('sewn_api_key');
        
        if ($mode === 'primary') {
            return $api_key === $primary_key;
        }
        
        // Fallback mode - check both keys
        $fallback_key = get_option('sewn_fallback_api_key');
        return ($api_key === $primary_key || $api_key === $fallback_key);
    }

    /**
     * Ensures a valid API key exists and returns it
     * Also handles migration from old format to new format
     */
    public function ensure_api_key_exists() {
        $primary_key = get_option('sewn_api_key');
        
        // Check if key exists and needs migration (has prefix)
        if (!empty($primary_key) && strpos($primary_key, 'sewn_') === 0) {
            $this->logger->info('Migrating API key from old format');
            // Generate new format key
            $primary_key = wp_generate_password(32, false);
            update_option('sewn_api_key', $primary_key);
        } else if (empty($primary_key)) {
            // Generate new key if none exists
            $primary_key = wp_generate_password(32, false);
            update_option('sewn_api_key', $primary_key);
            $this->logger->info('Generated new API key');
        }
        
        return $primary_key;
    }

    public function activate() {
        // Set default API mode to primary
        if (!get_option('sewn_active_api')) {
            update_option('sewn_active_api', 'primary');
        }
        
        // Clean up any conflicting settings
        $this->cleanup_service_settings();
        
        // Ensure API key exists
        $this->ensure_api_key_exists();
        
        $this->logger->info('API Manager activated with clean settings');
    }

    private function assert_service_state() {
        $active_mode = get_option('sewn_active_api');
        $primary_configured = get_option('sewn_primary_service_configured');
        $primary_key = get_option('sewn_api_key');
        
        if (empty($active_mode) || !in_array($active_mode, ['primary', 'fallback'])) {
            update_option('sewn_active_api', 'primary');
            $this->logger->info('Reset active API mode to primary');
        }
        
        if (empty($primary_configured)) {
            update_option('sewn_primary_service_configured', true);
            $this->logger->info('Set primary service as configured');
        }
        
        if (empty($primary_key)) {
            $this->ensure_api_key_exists();
            $this->logger->info('Generated missing API key');
        }
    }

    private function cleanup_service_settings() {
        $active_mode = get_option('sewn_active_api', 'primary');
        
        if ($active_mode === 'primary') {
            // Clean up all fallback-related settings
            $this->cleanup_fallback_settings();
            
            // Ensure primary service is properly configured
            update_option('sewn_primary_service_configured', true);
            $this->ensure_api_key_exists();
            
            $this->logger->info('Cleaned up service settings for primary mode');
        }
    }

    public function cleanup_fallback_settings() {
        delete_option('sewn_fallback_service');
        delete_option('sewn_fallback_api_key');
        
        // If we're cleaning up fallback settings, ensure we're in primary mode
        update_option('sewn_active_api', 'primary');
        
        $this->logger->info('Cleaned up fallback service settings');
    }

    // Add deactivation method
    public function deactivate() {
        $this->cleanup_fallback_settings();
        $this->logger->info('API Manager deactivated and cleaned up');
    }
} 