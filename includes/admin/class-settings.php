<?php
if (!defined('ABSPATH')) exit;

class SEWN_Settings {
    private $options_group = 'sewn_screenshots_options';
    private $logger;

    public function __construct($logger) {
        $this->logger = $logger;
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_sewn_regenerate_api_key', [$this, 'handle_regenerate_api_key']);
    }

    public function register_settings() {
        // General Settings
        register_setting($this->options_group, 'sewn_api_key');
        register_setting($this->options_group, 'sewn_rate_limit');
        
        // Screenshot Settings
        register_setting($this->options_group, 'sewn_default_width', ['default' => 1280]);
        register_setting($this->options_group, 'sewn_default_height', ['default' => 800]);
        register_setting($this->options_group, 'sewn_default_quality', ['default' => 85]);
        register_setting($this->options_group, 'sewn_default_format', ['default' => 'jpg']);
        
        // Storage Settings
        register_setting($this->options_group, 'sewn_storage_limit', ['default' => 500]); // MB
        register_setting($this->options_group, 'sewn_retention_days', ['default' => 7]);
        
        // Cache Settings
        register_setting($this->options_group, 'sewn_cache_enabled', ['default' => true]);
        register_setting($this->options_group, 'sewn_cache_duration', ['default' => 24]); // Hours

        add_settings_section(
            'sewn_screenshot_settings',
            'Screenshot Settings',
            [$this, 'render_screenshot_settings_section'],
            'sewn-screenshots'
        );

        // Add wkhtmltopdf path setting
        register_setting('sewn-screenshots', 'sewn_wkhtmltopdf_path', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_wkhtmltopdf_path'],
            'default' => ''
        ]);

        add_settings_field(
            'sewn_wkhtmltopdf_path',
            'wkhtmltopdf Path',
            [$this, 'render_wkhtmltopdf_path_field'],
            'sewn-screenshots',
            'sewn_screenshot_settings'
        );
    }

    public function get_all_settings() {
        $defaults = [
            'api_key' => wp_generate_password(32, false),
            // Add other default settings here
        ];
        
        return wp_parse_args(get_option('sewn_settings', []), $defaults);
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields($this->options_group);
                do_settings_sections('sewn-screenshots');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function sanitize_wkhtmltopdf_path($path) {
        $path = sanitize_text_field($path);
        
        if (!empty($path) && (!file_exists($path) || !is_executable($path))) {
            add_settings_error(
                'sewn_wkhtmltopdf_path',
                'invalid_path',
                'The specified wkhtmltopdf path is invalid or not executable.'
            );
            return get_option('sewn_wkhtmltopdf_path', '');
        }
        
        return $path;
    }

    public function render_screenshot_settings_section() {
        echo '<p>Configure screenshot capture settings including wkhtmltopdf path if available.</p>';
    }

    public function render_wkhtmltopdf_path_field() {
        $path = get_option('sewn_wkhtmltopdf_path', '');
        $detected_path = $this->detect_wkhtmltopdf_path();
        
        echo '<input type="text" id="sewn_wkhtmltopdf_path" name="sewn_wkhtmltopdf_path" 
              value="' . esc_attr($path) . '" class="regular-text" />';
        
        if ($detected_path) {
            echo '<p class="description">Detected wkhtmltopdf at: ' . esc_html($detected_path) . '</p>';
        }
        
        if (!empty($path)) {
            if (file_exists($path) && is_executable($path)) {
                echo '<p class="description success">✓ Path is valid and executable</p>';
            } else {
                echo '<p class="description error">✗ Path is invalid or not executable</p>';
            }
        }
    }

    private function detect_wkhtmltopdf_path() {
        $possible_paths = [
            '/usr/local/bin/wkhtmltoimage',
            '/usr/bin/wkhtmltoimage',
            // Add more common paths
        ];

        foreach ($possible_paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return false;
    }

    public function handle_regenerate_api_key() {
        try {
            // Verify nonce
            if (!check_ajax_referer('sewn_api_management', 'nonce', false)) {
                throw new Exception('Invalid security token');
            }

            // Verify user capabilities
            if (!current_user_can('manage_options')) {
                throw new Exception('Insufficient permissions');
            }

            // Generate new API key
            $new_key = wp_generate_password(32, false);
            
            // Save the new key
            $this->update_setting('api_key', $new_key);
            
            $this->logger->info('API key regenerated successfully', [
                'user_id' => get_current_user_id()
            ]);

            wp_send_json_success([
                'key' => $new_key,
                'message' => 'API key regenerated successfully'
            ]);

        } catch (Exception $e) {
            $this->logger->error('API key regeneration failed', [
                'error' => $e->getMessage()
            ]);
            
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function update_setting($key, $value) {
        $settings = get_option('sewn_settings', []);
        $settings[$key] = $value;
        update_option('sewn_settings', $settings);
    }
}