<?php
if (!defined('ABSPATH')) exit;

class SEWN_Dashboard {
    private $plugin_slug = 'sewn-screenshots';
    private $logger;

    public function __construct($logger) {
        $this->logger = $logger;
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_menu_page() {
        add_menu_page(
            'Screenshot Service',
            'Screenshot Service',
            'manage_options',
            $this->plugin_slug,
            [$this, 'render_dashboard'],
            'dashicons-camera',
            30
        );
    }

    public function register_settings() {
        register_setting($this->plugin_slug . '-settings', 'sewn_api_key');
        register_setting($this->plugin_slug . '-settings', 'sewn_rate_limit');
    }

    public function render_dashboard() {
        // Get stats and settings
        $api_key = get_option('sewn_api_key');
        if (!$api_key) {
            $api_key = wp_generate_password(32, false);
            update_option('sewn_api_key', $api_key);
        }

        $rate_limit = get_option('sewn_rate_limit', 60);
        $recent_logs = $this->logger->get_recent_logs(50);
        ?>
        <div class="wrap">
            <h1>Screenshot Service Dashboard</h1>

            <div class="sewn-dashboard-wrapper">
                <!-- API Key Section -->
                <div class="sewn-card">
                    <h2>API Key</h2>
                    <div class="sewn-api-key-wrapper">
                        <input type="text" readonly value="<?php echo esc_attr($api_key); ?>" class="regular-text">
                        <button type="button" class="button button-secondary" id="sewn-regenerate-key">
                            Regenerate Key
                        </button>
                    </div>
                </div>

                <!-- Settings Section -->
                <div class="sewn-card">
                    <h2>Settings</h2>
                    <form method="post" action="options.php">
                        <?php settings_fields($this->plugin_slug . '-settings'); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Rate Limit (per minute)</th>
                                <td>
                                    <input type="number" name="sewn_rate_limit" 
                                           value="<?php echo esc_attr($rate_limit); ?>" 
                                           min="1" max="1000">
                                </td>
                            </tr>
                        </table>
                        <?php submit_button(); ?>
                    </form>
                </div>

                <!-- Logs Section -->
                <div class="sewn-card">
                    <h2>Recent Logs</h2>
                    <div class="sewn-log-viewer">
                        <?php foreach ($recent_logs as $log): ?>
                            <div class="sewn-log-entry">
                                <?php echo esc_html($log); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}