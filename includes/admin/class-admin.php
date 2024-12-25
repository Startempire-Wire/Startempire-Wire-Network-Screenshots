<?php

class SEWN_Admin {
    private $screenshot_service;
    private $logger;

    public function __construct($screenshot_service, $logger) {
        $this->screenshot_service = $screenshot_service;
        $this->logger = $logger;

        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('admin_init', [$this, 'init_admin']);
    }

    public function init_admin() {
        $this->logger->debug('Admin interface initialized');
    }

    public function enqueue_admin_scripts($hook) {
        // Only load on our plugin page
        if ($hook !== 'tools_page_sewn-screenshot-tester') {
            return;
        }

        wp_enqueue_style(
            'sewn-admin-style',
            plugins_url('assets/css/admin-settings.css', SEWN_PLUGIN_FILE),
            [],
            SEWN_SCREENSHOTS_VERSION
        );

        wp_enqueue_script(
            'sewn-admin-settings',
            plugins_url('assets/js/admin-settings.js', SEWN_PLUGIN_FILE),
            ['jquery', 'jquery-ui-sortable'],
            SEWN_SCREENSHOTS_VERSION,
            true
        );

        wp_localize_script('sewn-admin-settings', 'sewn_settings', [
            'nonce' => wp_create_nonce('sewn_screenshot_test'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'is_production' => $this->screenshot_service->is_production_server()
        ]);

        $this->logger->debug('Admin scripts enqueued', [
            'hook' => $hook,
            'version' => SEWN_VERSION
        ]);
    }

    public function render_tester_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $current_method = $this->screenshot_service->is_production_server() ? 'wkhtmltopdf' : 'API';

        ?>
        <div class="wrap">
            <h1>Screenshot Tester</h1>
            
            <div class="notice notice-info">
                <p>Current screenshot method: <strong><?php echo esc_html($current_method); ?></strong></p>
                <?php if ($current_method === 'wkhtmltopdf'): ?>
                    <p>Using local wkhtmltopdf installation</p>
                <?php else: ?>
                    <p>Using fallback API service</p>
                <?php endif; ?>
            </div>

            <div class="sewn-test-section">
                <h2>Test Screenshot Service</h2>
                
                <div class="sewn-test-controls">
                    <input type="url" 
                           id="sewn-test-url" 
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
                    <input type="password" 
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

    // Add the new render method for test results
    public function render_test_results() {
        // Instantiate and render test results
        $test_results = new SEWN_Test_Results($this->logger);
        $test_results->render_test_page();
    }

    // Add new dashboard page render method
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

    // Add method for API settings page
    public function render_api_settings_page() {
        ?>
        <div class="wrap">
            <h1>API Settings</h1>
            <?php $this->render_api_key_section(); ?>
        </div>
        <?php
    }

    // Add method for recent activity
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

    // Add method for test summary
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
} 