<?php
if (!defined('ABSPATH')) exit;

class SEWN_Settings {
    private $options_group = 'sewn_screenshots_options';
    private $logger;
    private $possible_paths = [
        '/usr/local/bin/wkhtmltoimage',
        '/usr/bin/wkhtmltoimage',
        '/opt/local/bin/wkhtmltoimage',
        '/opt/bin/wkhtmltoimage',
        'C:\\Program Files\\wkhtmltopdf\\bin\\wkhtmltoimage.exe',
        'C:\\Program Files (x86)\\wkhtmltopdf\\bin\\wkhtmltoimage.exe'
    ];
    private $service_detector;
    private $fallback_services = [
        'screenshotmachine' => [
            'name' => 'Screenshot Machine',
            'url' => 'https://screenshotmachine.com',
            'docs_url' => 'https://screenshotmachine.com/docs/',
            'requires_secret' => true,
            'supports_quotas' => true,
            'pricing_url' => 'https://screenshotmachine.com/pricing/'
        ],
        'url2png' => [
            'name' => 'URL2PNG',
            'url' => 'https://url2png.com',
            'docs_url' => 'https://url2png.com/docs',
            'requires_secret' => true,
            'supports_quotas' => true,
            'pricing_url' => 'https://url2png.com/plans'
        ]
    ];
    private $quota_checker;

    public function __construct($logger) {
        $this->logger = $logger;
        $this->service_detector = new SEWN_Screenshot_Service_Detector($logger);
        $this->quota_checker = new SEWN_API_Quota_Checker($logger);
        
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_sewn_refresh_services', [$this, 'handle_refresh_services']);
        add_action('wp_ajax_sewn_test_fallback', [$this, 'handle_test_fallback']);
        add_action('wp_ajax_sewn_refresh_quota', [$this, 'handle_refresh_quota']);
    }

    public function enqueue_scripts($hook) {
        if (strpos($hook, 'sewn-screenshots') === false) {
            return;
        }

        wp_enqueue_style(
            'sewn-admin-styles',
            SEWN_SCREENSHOTS_URL . 'assets/css/admin.css',
            [],
            SEWN_SCREENSHOTS_VERSION
        );

        wp_enqueue_script(
            'sewn-admin-scripts',
            SEWN_SCREENSHOTS_URL . 'assets/js/admin.js',
            ['jquery'],
            SEWN_SCREENSHOTS_VERSION,
            true
        );

        // Initialize the admin script data
        $admin_data = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'health_check_nonce' => wp_create_nonce('sewn_health_check'),
            'installation_nonce' => wp_create_nonce('sewn_installation_status'),
            'security_nonce' => wp_create_nonce('sewn_security_settings'),
            'i18n' => [
                'running_health_check' => __('Running health check...', 'startempire-wire-network-screenshots'),
                'health_check_complete' => __('Health check complete', 'startempire-wire-network-screenshots'),
                'updating_security' => __('Updating security settings...', 'startempire-wire-network-screenshots'),
                'security_updated' => __('Security settings updated', 'startempire-wire-network-screenshots')
            ]
        ];

        wp_localize_script(
            'sewn-admin-scripts',
            'sewnAdmin',
            $admin_data
        );
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

        // Register wkhtmltopdf path setting
        register_setting('sewn-screenshots', 'sewn_wkhtmltopdf_path', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_wkhtmltopdf_path'],
            'default' => ''
        ]);

        add_settings_section(
            'sewn_screenshot_settings',
            'Screenshot Settings',
            [$this, 'render_screenshot_settings_section'],
            'sewn-screenshots'
        );

        add_settings_section(
            'sewn_screenshot_services',
            'Screenshot Services',
            [$this, 'render_services_section'],
            'sewn-screenshots'
        );

        // Service selection setting
        register_setting(
            $this->options_group,
            'sewn_active_service',
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_service_selection'],
                'default' => 'wkhtmltoimage'
            ]
        );

        // Fallback service settings
        register_setting($this->options_group, 'sewn_fallback_service');
        register_setting($this->options_group, 'sewn_fallback_api_key');
        register_setting($this->options_group, 'sewn_fallback_api_secret');

        // Add new sections
        add_settings_section(
            'sewn_health_status',
            __('System Health Status', 'startempire-wire-network-screenshots'),
            [$this, 'render_health_status_section'],
            'sewn-screenshots'
        );

        add_settings_section(
            'sewn_security_settings',
            __('Security Settings', 'startempire-wire-network-screenshots'),
            [$this, 'render_security_settings_section'],
            'sewn-screenshots'
        );

        add_settings_section(
            'sewn_installation_status',
            __('Installation Status', 'startempire-wire-network-screenshots'),
            [$this, 'render_installation_status_section'],
            'sewn-screenshots'
        );
    }

    public function register_admin_tabs() {
        return [
            'dashboard' => [
                'title' => __('Dashboard', 'startempire-wire-network-screenshots'),
                'callback' => [$this, 'render_dashboard_tab']
            ],
            'configuration' => [
                'title' => __('Configuration', 'startempire-wire-network-screenshots'),
                'callback' => [$this, 'render_configuration_tab']
            ],
            'system-status' => [
                'title' => __('System Status', 'startempire-wire-network-screenshots'),
                'callback' => [$this, 'render_system_status_tab']
            ],
            'installation' => [
                'title' => __('Installation', 'startempire-wire-network-screenshots'),
                'callback' => [$this, 'render_installation_tab']
            ]
        ];
    }

    public function render_installation_progress_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sewn_installation_log';
        $logs = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT 50");
        ?>
        <div class="sewn-installation-table-wrapper">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Date', 'startempire-wire-network-screenshots'); ?></th>
                        <th><?php _e('Event', 'startempire-wire-network-screenshots'); ?></th>
                        <th><?php _e('Status', 'startempire-wire-network-screenshots'); ?></th>
                        <th><?php _e('Details', 'startempire-wire-network-screenshots'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html(human_time_diff(strtotime($log->created_at))); ?> ago</td>
                            <td><?php echo esc_html($log->event); ?></td>
                            <td>
                                <span class="sewn-status-badge <?php echo esc_attr($log->status); ?>">
                                    <?php echo esc_html(ucfirst($log->status)); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($log->details); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
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
        
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'services';
        ?>
        <div class="wrap sewn-admin-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <!-- Status Cards -->
            <div class="sewn-status-cards">
                <div class="sewn-card">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <h3><?php _e('Screenshot Service', 'startempire-wire-network-screenshots'); ?></h3>
                    <p><?php echo get_option('sewn_active_service', 'wkhtmltoimage'); ?></p>
                </div>
                
                <div class="sewn-card">
                    <span class="dashicons dashicons-backup"></span>
                    <h3><?php _e('Fallback Service', 'startempire-wire-network-screenshots'); ?></h3>
                    <p><?php echo get_option('sewn_fallback_service') ? __('Configured', 'startempire-wire-network-screenshots') : __('Not Configured', 'startempire-wire-network-screenshots'); ?></p>
                </div>
                
                <div class="sewn-card">
                    <span class="dashicons dashicons-performance"></span>
                    <h3><?php _e('Cache Status', 'startempire-wire-network-screenshots'); ?></h3>
                    <p><?php echo get_option('sewn_cache_enabled', true) ? __('Enabled', 'startempire-wire-network-screenshots') : __('Disabled', 'startempire-wire-network-screenshots'); ?></p>
                </div>
            </div>

            <!-- Tab Navigation -->
            <nav class="nav-tab-wrapper">
                <a href="?page=sewn-screenshots&tab=services" 
                   class="nav-tab <?php echo $current_tab === 'services' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Services', 'startempire-wire-network-screenshots'); ?>
                </a>
                <a href="?page=sewn-screenshots&tab=security" 
                   class="nav-tab <?php echo $current_tab === 'security' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Security', 'startempire-wire-network-screenshots'); ?>
                </a>
                <a href="?page=sewn-screenshots&tab=installation" 
                   class="nav-tab <?php echo $current_tab === 'installation' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Installation', 'startempire-wire-network-screenshots'); ?>
                </a>
                <a href="?page=sewn-screenshots&tab=advanced" 
                   class="nav-tab <?php echo $current_tab === 'advanced' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Advanced', 'startempire-wire-network-screenshots'); ?>
                </a>
            </nav>

            <!-- Settings Content -->
            <div class="sewn-settings-content">
                <form action="options.php" method="post">
                    <?php
                    settings_fields($this->options_group);
                    
                    switch ($current_tab) {
                        case 'services':
                            $this->render_services_section();
                            break;
                        case 'security':
                            $this->render_security_settings_section();
                            break;
                        case 'installation':
                            $this->render_installation_status_section();
                            break;
                        case 'advanced':
                            $this->render_advanced_settings();
                            break;
                    }
                    
                    if ($current_tab !== 'installation') {
                        submit_button();
                    }
                    ?>
                </form>
            </div>
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
        $active_service = get_option('sewn_active_service', 'wkhtmltoimage'); // Default to wkhtmltoimage
        ?>
        <div class="sewn-screenshot-services-wrapper">
            <h3><?php _e('Screenshot Service Configuration', 'startempire-wire-network-screenshots'); ?></h3>
            
            <!-- Service Selection Tabs -->
            <div class="sewn-service-tabs">
                <button type="button" 
                        class="sewn-tab-button <?php echo $active_service !== 'none' ? 'active' : ''; ?>"
                        data-tab="local">
                    <span class="dashicons dashicons-desktop"></span>
                    <?php _e('Local Service (wkhtmltoimage)', 'startempire-wire-network-screenshots'); ?>
                </button>
                <button type="button" 
                        class="sewn-tab-button <?php echo $active_service === 'none' ? 'active' : ''; ?>"
                        data-tab="fallback">
                    <span class="dashicons dashicons-cloud"></span>
                    <?php _e('Cloud Service', 'startempire-wire-network-screenshots'); ?>
                </button>
            </div>

            <!-- Local Service Configuration -->
            <div class="sewn-tab-content" id="local-service-tab" 
                 <?php echo $active_service !== 'none' ? '' : 'style="display: none;"'; ?>>
                <div class="sewn-service-description">
                    <p>
                        <?php _e('Configure local wkhtmltoimage installation for screenshot generation.', 'startempire-wire-network-screenshots'); ?>
                        <a href="https://wkhtmltopdf.org/downloads.html" target="_blank">
                            <?php _e('Download wkhtmltoimage', 'startempire-wire-network-screenshots'); ?>
                            <span class="dashicons dashicons-external"></span>
                        </a>
                    </p>
                </div>
                <?php $this->render_wkhtmltopdf_path_field(); ?>
            </div>

            <!-- Fallback Service Configuration -->
            <div class="sewn-tab-content" id="fallback-service-tab"
                 <?php echo $active_service === 'none' ? '' : 'style="display: none;"'; ?>>
                <div class="sewn-service-description">
                    <p>
                        <?php _e('Configure a cloud-based screenshot service as an alternative to local installation.', 'startempire-wire-network-screenshots'); ?>
                    </p>
                </div>
                <?php $this->render_fallback_service_config(); ?>
            </div>

            <!-- Service Status Summary -->
            <div class="sewn-service-status-summary">
                <h4><?php _e('Active Configuration', 'startempire-wire-network-screenshots'); ?></h4>
                <?php
                $wkhtmltopdf_status = $this->detect_wkhtmltopdf_path();
                $fallback_configured = $this->is_fallback_configured();
                
                if ($active_service === 'none') {
                    if ($fallback_configured) {
                        echo '<div class="notice notice-success inline">';
                        echo '<p><span class="dashicons dashicons-cloud"></span> ' . 
                             __('Using cloud service for screenshots', 'startempire-wire-network-screenshots') . '</p>';
                        echo '</div>';
                    } else {
                        echo '<div class="notice notice-error inline">';
                        echo '<p><span class="dashicons dashicons-warning"></span> ' . 
                             __('No screenshot service configured', 'startempire-wire-network-screenshots') . '</p>';
                        echo '</div>';
                    }
                } else {
                    if ($wkhtmltopdf_status['status'] === 'valid') {
                        echo '<div class="notice notice-success inline">';
                        echo '<p><span class="dashicons dashicons-desktop"></span> ' . 
                             __('Using local wkhtmltoimage installation', 'startempire-wire-network-screenshots') . '</p>';
                        echo '</div>';
                    } else {
                        echo '<div class="notice notice-warning inline">';
                        echo '<p><span class="dashicons dashicons-warning"></span> ' . 
                             __('Local service not properly configured', 'startempire-wire-network-screenshots') . '</p>';
                        echo '</div>';
                    }
                }
                ?>
            </div>
        </div>
        <?php
    }

    public function render_wkhtmltopdf_path_field() {
        $path = get_option('sewn_wkhtmltopdf_path', '');
        $detection_result = $this->detect_wkhtmltopdf_path();
        
        ?>
        <div class="sewn-wkhtmltopdf-settings">
            <input type="text" 
                   id="sewn_wkhtmltopdf_path" 
                   name="sewn_wkhtmltopdf_path" 
                   value="<?php echo esc_attr($path); ?>" 
                   class="regular-text" 
                   <?php echo $detection_result['status'] === 'valid' ? 'placeholder="' . esc_attr($detection_result['path']) . '"' : ''; ?>
            />
            
            <div class="sewn-wkhtmltopdf-status">
                <?php if ($detection_result['status'] === 'valid'): ?>
                    <div class="notice notice-success inline">
                        <p>
                            <span class="dashicons dashicons-yes"></span>
                            wkhtmltoimage detected at: <code><?php echo esc_html($detection_result['path']); ?></code>
                            <?php if ($detection_result['version']): ?>
                                <br>
                                <small>Version: <?php echo esc_html($detection_result['version']); ?></small>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="notice notice-warning inline">
                        <p>
                            <span class="dashicons dashicons-warning"></span>
                            wkhtmltoimage not found. Please install it or manually specify the path.
                            <br>
                            <small>Common installation paths checked:</small>
                            <div class="paths-list">
                                <?php foreach ($this->possible_paths as $possible_path): ?>
                                    <code><?php echo esc_html($possible_path); ?></code>
                                <?php endforeach; ?>
                            </div>
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($path)): ?>
                <div class="sewn-custom-path-status">
                    <?php if (file_exists($path) && is_executable($path)): ?>
                        <p class="description success">
                            <span class="dashicons dashicons-yes"></span>
                            Custom path is valid and executable
                        </p>
                    <?php else: ?>
                        <p class="description error">
                            <span class="dashicons dashicons-no"></span>
                            Custom path is invalid or not executable
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <p class="description">
                <?php _e('Path to wkhtmltoimage binary. Leave empty to use auto-detected path.', 'startempire-wire-network-screenshots'); ?>
            </p>
        </div>
        <?php
    }

    private function detect_wkhtmltopdf_path() {
        $paths_to_check = $this->possible_paths;

        // Try to detect using 'which' command on Unix-like systems
        if (function_exists('exec') && !in_array('exec', explode(',', ini_get('disable_functions')))) {
            try {
                $output = [];
                $return_var = null;
                exec('which wkhtmltoimage 2>/dev/null', $output, $return_var);
                
                if ($return_var === 0 && !empty($output[0])) {
                    $paths_to_check[] = trim($output[0]);
                }
            } catch (Exception $e) {
                $this->logger->debug('Exec detection failed', ['error' => $e->getMessage()]);
            }
        }

        // Check version and path for each possible location
        foreach ($paths_to_check as $path) {
            if (file_exists($path) && is_executable($path)) {
                try {
                    // Try to get version info
                    $version_output = [];
                    $return_var = null;
                    exec(escapeshellcmd($path) . ' --version 2>&1', $version_output, $return_var);
                    
                    if ($return_var === 0) {
                        return [
                            'path' => $path,
                            'version' => !empty($version_output[0]) ? trim($version_output[0]) : 'Unknown',
                            'status' => 'valid'
                        ];
                    }
                } catch (Exception $e) {
                    $this->logger->debug('Version check failed', [
                        'path' => $path,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return [
            'path' => '',
            'version' => '',
            'status' => 'not_found'
        ];
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

    public function render_advanced_settings() {
        ?>
        <div class="sewn-settings-section">
            <h3>Advanced Settings</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="sewn_cleanup_on_deactivate">
                            <?php _e('Cleanup on Deactivation', 'startempire-wire-network-screenshots'); ?>
                        </label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="sewn_cleanup_on_deactivate" 
                                   id="sewn_cleanup_on_deactivate"
                                   value="1"
                                   <?php checked(get_option('sewn_cleanup_on_deactivate', false)); ?>>
                            <?php _e('Delete all plugin data when deactivating', 'startempire-wire-network-screenshots'); ?>
                        </label>
                        <p class="description">
                            <?php _e('Warning: This will permanently delete all screenshots, logs, and settings.', 'startempire-wire-network-screenshots'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    public function render_services_section() {
        $services = $this->service_detector->detect_services();
        $active_service = get_option('sewn_active_service', 'wkhtmltoimage');
        $fallback_configured = $this->is_fallback_configured();
        
        ?>
        <div class="sewn-services-status">
            <div class="sewn-services-header">
                <h3><?php _e('Screenshot Service Configuration', 'startempire-wire-network-screenshots'); ?></h3>
                <button type="button" 
                        class="button sewn-refresh-services" 
                        data-nonce="<?php echo wp_create_nonce('sewn_refresh_services'); ?>">
                    <span class="dashicons dashicons-update"></span>
                    <?php _e('Refresh Detection', 'startempire-wire-network-screenshots'); ?>
                </button>
            </div>

            <div class="sewn-service-selection">
                <h4><?php _e('Select Service Mode', 'startempire-wire-network-screenshots'); ?></h4>
                <fieldset>
                    <legend class="screen-reader-text">
                        <?php _e('Select Screenshot Service Mode', 'startempire-wire-network-screenshots'); ?>
                    </legend>
                    
                    <!-- Fallback-only option -->
                    <label class="sewn-service-option">
                        <input type="radio" 
                               name="sewn_active_service" 
                               value="none"
                               <?php checked($active_service, 'none'); ?>
                               <?php disabled(!$fallback_configured); ?>>
                        <span class="service-name">
                            <?php _e('Use Fallback Service Only', 'startempire-wire-network-screenshots'); ?>
                        </span>
                        <?php if (!$fallback_configured): ?>
                            <span class="sewn-badge warning">
                                <?php _e('Fallback Not Configured', 'startempire-wire-network-screenshots'); ?>
                            </span>
                        <?php endif; ?>
                    </label>

                    <!-- Local services options -->
                    <?php foreach ($services['services'] as $id => $service): ?>
                        <?php if ($service['available']): ?>
                            <label class="sewn-service-option">
                                <input type="radio" 
                                       name="sewn_active_service" 
                                       value="<?php echo esc_attr($id); ?>"
                                       <?php checked($active_service, $id); ?>>
                                <span class="service-name">
                                    <?php echo esc_html($service['name']); ?>
                                    <?php if ($service['version']): ?>
                                        <span class="version">(<?php echo esc_html($service['version']); ?>)</span>
                                    <?php endif; ?>
                                </span>
                                <?php if ($service['type'] === 'primary'): ?>
                                    <span class="sewn-badge primary">
                                        <?php _e('Primary', 'startempire-wire-network-screenshots'); ?>
                                    </span>
                                <?php endif; ?>
                            </label>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </fieldset>

                <?php if ($active_service === 'none' && !$fallback_configured): ?>
                    <div class="notice notice-warning inline">
                        <p>
                            <?php _e('Warning: Fallback service is not configured. Please configure a fallback service or select a local service.', 'startempire-wire-network-screenshots'); ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Service status table -->
            <h4><?php _e('Available Local Services', 'startempire-wire-network-screenshots'); ?></h4>
            <table class="widefat sewn-services-table">
                <thead>
                    <tr>
                        <th><?php _e('Service', 'startempire-wire-network-screenshots'); ?></th>
                        <th><?php _e('Type', 'startempire-wire-network-screenshots'); ?></th>
                        <th><?php _e('Status', 'startempire-wire-network-screenshots'); ?></th>
                        <th><?php _e('Version', 'startempire-wire-network-screenshots'); ?></th>
                        <th><?php _e('Path', 'startempire-wire-network-screenshots'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($services['services'] as $id => $service): ?>
                        <tr class="<?php echo $service['available'] ? 'service-available' : 'service-unavailable'; ?>">
                            <td>
                                <strong><?php echo esc_html($service['name']); ?></strong>
                                <?php if ($service['type'] === 'primary'): ?>
                                    <span class="sewn-badge primary"><?php _e('Primary', 'startempire-wire-network-screenshots'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(ucfirst($service['type'])); ?></td>
                            <td>
                                <?php if ($service['available']): ?>
                                    <span class="sewn-status available">
                                        <span class="dashicons dashicons-yes"></span>
                                        <?php _e('Available', 'startempire-wire-network-screenshots'); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="sewn-status unavailable">
                                        <span class="dashicons dashicons-no"></span>
                                        <?php _e('Not Available', 'startempire-wire-network-screenshots'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo $service['version'] ? esc_html($service['version']) : '—'; ?>
                            </td>
                            <td>
                                <?php if ($service['type'] === 'primary'): ?>
                                    <input type="text" 
                                           name="sewn_<?php echo esc_attr($id); ?>_path" 
                                           value="<?php echo esc_attr($service['path'] ?? ''); ?>"
                                           class="regular-text"
                                           placeholder="<?php esc_attr_e('Auto-detected or enter custom path', 'startempire-wire-network-screenshots'); ?>">
                                <?php else: ?>
                                    <?php echo $service['path'] ? esc_html($service['path']) : '—'; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($active_service !== 'none' && isset($services['services'][$active_service])): ?>
                <!-- Active service configuration -->
                <div class="sewn-active-service-details">
                    <h4><?php _e('Active Service Configuration', 'startempire-wire-network-screenshots'); ?></h4>
                    <?php $active_details = $services['services'][$active_service]; ?>
                    <div class="service-config">
                        <label>
                            <?php _e('Custom Path (Optional):', 'startempire-wire-network-screenshots'); ?>
                            <input type="text" 
                                   name="sewn_<?php echo esc_attr($active_service); ?>_path" 
                                   value="<?php echo esc_attr($active_details['path'] ?? ''); ?>"
                                   class="regular-text"
                                   placeholder="<?php esc_attr_e('Auto-detected or enter custom path', 'startempire-wire-network-screenshots'); ?>">
                        </label>
                        <p class="description">
                            <?php _e('Leave blank to use auto-detected path', 'startempire-wire-network-screenshots'); ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Fallback service configuration -->
            <div class="sewn-fallback-service-config">
                <h4><?php _e('Fallback Service Configuration', 'startempire-wire-network-screenshots'); ?></h4>
                <!-- Add your fallback service configuration fields here -->
            </div>
        </div>
        <?php
    }

    private function is_fallback_configured() {
        // Add your fallback configuration check logic here
        $fallback_key = get_option('sewn_fallback_api_key');
        return !empty($fallback_key);
    }

    public function sanitize_service_selection($value) {
        if ($value === 'none') {
            if (!$this->is_fallback_configured()) {
                add_settings_error(
                    'sewn_active_service',
                    'invalid_service',
                    __('Cannot disable local services without configuring a fallback service.', 'startempire-wire-network-screenshots')
                );
                return get_option('sewn_active_service', 'wkhtmltoimage');
            }
            return 'none';
        }

        $services = $this->service_detector->detect_services();
        
        if (!isset($services['services'][$value]) || !$services['services'][$value]['available']) {
            add_settings_error(
                'sewn_active_service',
                'invalid_service',
                __('Selected screenshot service is not available. Please choose an available service or configure fallback.', 'startempire-wire-network-screenshots')
            );
            return get_option('sewn_active_service', 'wkhtmltoimage');
        }
        
        return $value;
    }

    public function handle_refresh_services() {
        try {
            check_ajax_referer('sewn_refresh_services', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Permission denied', 'startempire-wire-network-screenshots'));
            }

            $this->service_detector->clear_cache();
            $services = $this->service_detector->detect_services(true);

            ob_start();
            $this->render_services_section();
            $html = ob_get_clean();

            wp_send_json_success([
                'html' => $html,
                'services' => $services
            ]);

        } catch (Exception $e) {
            $this->logger->error('Service refresh failed', [
                'error' => $e->getMessage()
            ]);
            
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function sanitize_fallback_service($value) {
        if ($value === '') {
            return '';
        }

        $services = $this->service_detector->detect_services();
        
        if (!isset($services['services'][$value]) || !$services['services'][$value]['available']) {
            add_settings_error(
                'sewn_fallback_service',
                'invalid_service',
                __('Selected fallback service is not available. Please choose an available service or configure fallback.', 'startempire-wire-network-screenshots')
            );
            return get_option('sewn_fallback_service', '');
        }
        
        return $value;
    }

    public function render_fallback_service_config() {
        $current_service = get_option('sewn_fallback_service', '');
        $api_key = get_option('sewn_fallback_api_key', '');
        $api_secret = get_option('sewn_fallback_api_secret', '');
        $quota_info = $this->get_fallback_quota_info();
        
        ?>
        <div class="sewn-fallback-service-config">
            <h3><?php _e('Fallback Service Configuration', 'startempire-wire-network-screenshots'); ?></h3>
            
            <div class="sewn-fallback-service-selection">
                <label for="sewn_fallback_service">
                    <?php _e('Select Fallback Service:', 'startempire-wire-network-screenshots'); ?>
                </label>
                <select name="sewn_fallback_service" id="sewn_fallback_service">
                    <option value=""><?php _e('Select a service...', 'startempire-wire-network-screenshots'); ?></option>
                    <?php foreach ($this->fallback_services as $id => $service): ?>
                        <option value="<?php echo esc_attr($id); ?>" 
                                <?php selected($current_service, $id); ?>>
                            <?php echo esc_html($service['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php foreach ($this->fallback_services as $id => $service): ?>
                <div class="sewn-fallback-service-details" 
                     data-service="<?php echo esc_attr($id); ?>" 
                     <?php echo $current_service === $id ? '' : 'style="display: none;"'; ?>>
                    
                    <div class="service-info">
                        <h4><?php echo esc_html($service['name']); ?> Configuration</h4>
                        <?php if ($service['docs_url']): ?>
                            <a href="<?php echo esc_url($service['docs_url']); ?>" 
                               target="_blank" 
                               class="button button-secondary">
                                <?php _e('View Documentation', 'startempire-wire-network-screenshots'); ?>
                            </a>
                        <?php endif; ?>
                    </div>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="sewn_fallback_api_key">
                                    <?php _e('API Key:', 'startempire-wire-network-screenshots'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" 
                                       id="sewn_fallback_api_key" 
                                       name="sewn_fallback_api_key" 
                                       value="<?php echo esc_attr($api_key); ?>" 
                                       class="regular-text"
                                       autocomplete="off">
                            </td>
                        </tr>
                        
                        <?php if ($service['requires_secret']): ?>
                            <tr>
                                <th scope="row">
                                    <label for="sewn_fallback_api_secret">
                                        <?php _e('API Secret:', 'startempire-wire-network-screenshots'); ?>
                                    </label>
                                </th>
                                <td>
                                    <input type="password" 
                                           id="sewn_fallback_api_secret" 
                                           name="sewn_fallback_api_secret" 
                                           value="<?php echo esc_attr($api_secret); ?>" 
                                           class="regular-text"
                                           autocomplete="off">
                                </td>
                            </tr>
                        <?php endif; ?>
                    </table>

                    <?php if ($current_service === $id && !empty($api_key)): ?>
                        <div class="sewn-fallback-status">
                            <h4><?php _e('Service Status', 'startempire-wire-network-screenshots'); ?></h4>
                            
                            <?php if ($quota_info): ?>
                                <div class="notice notice-info inline">
                                    <p>
                                        <?php if (isset($quota_info['remaining'])): ?>
                                            <?php printf(
                                                __('Remaining credits: %s', 'startempire-wire-network-screenshots'),
                                                number_format($quota_info['remaining'])
                                            ); ?>
                                        <?php endif; ?>
                                        
                                        <?php if (isset($quota_info['reset_date'])): ?>
                                            <br>
                                            <?php printf(
                                                __('Quota resets: %s', 'startempire-wire-network-screenshots'),
                                                date_i18n(get_option('date_format'), $quota_info['reset_date'])
                                            ); ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            <?php endif; ?>

                            <button type="button" 
                                    class="button sewn-test-fallback" 
                                    data-service="<?php echo esc_attr($id); ?>"
                                    data-nonce="<?php echo wp_create_nonce('sewn_test_fallback'); ?>">
                                <?php _e('Test Configuration', 'startempire-wire-network-screenshots'); ?>
                            </button>
                        </div>
                    <?php endif; ?>

                    <?php if ($service['pricing_url']): ?>
                        <p class="description">
                            <?php printf(
                                __('Need an API key? <a href="%s" target="_blank">View pricing plans</a>', 'startempire-wire-network-screenshots'),
                                esc_url($service['pricing_url'])
                            ); ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#sewn_fallback_service').on('change', function() {
                var service = $(this).val();
                $('.sewn-fallback-service-details').hide();
                if (service) {
                    $('[data-service="' + service + '"]').show();
                }
            });
        });
        </script>
        <?php
    }

    private function get_fallback_quota_info() {
        $service = get_option('sewn_fallback_service');
        if (empty($service)) {
            return null;
        }

        switch ($service) {
            case 'screenshotmachine':
                return $this->quota_checker->check_screenshotmachine_quota();
            case 'url2png':
                return $this->quota_checker->check_url2png_quota();
            default:
                return null;
        }
    }

    public function handle_test_fallback() {
        try {
            check_ajax_referer('sewn_test_fallback', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Permission denied', 'startempire-wire-network-screenshots'));
            }

            $service = $_POST['service'] ?? '';
            if (empty($service)) {
                throw new Exception(__('No service specified', 'startempire-wire-network-screenshots'));
            }

            // Test the service
            $this->quota_checker->test_service($service);

            // Get fresh quota info
            delete_transient('sewn_quota_' . $service . '_' . md5(get_option('sewn_fallback_api_key')));
            $quota_info = $this->get_fallback_quota_info();

            wp_send_json_success([
                'message' => __('Service test successful', 'startempire-wire-network-screenshots'),
                'quota' => $quota_info
            ]);

        } catch (Exception $e) {
            $this->logger->error('Fallback service test failed', [
                'error' => $e->getMessage()
            ]);
            
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function handle_refresh_quota() {
        try {
            check_ajax_referer('sewn_refresh_quota', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Permission denied', 'startempire-wire-network-screenshots'));
            }

            $service = get_option('sewn_fallback_service');
            if (empty($service)) {
                throw new Exception(__('No fallback service configured', 'startempire-wire-network-screenshots'));
            }

            // Clear quota cache
            delete_transient('sewn_quota_' . $service . '_' . md5(get_option('sewn_fallback_api_key')));
            
            // Get fresh quota info
            $quota_info = $this->get_fallback_quota_info();
            if (!$quota_info) {
                throw new Exception(__('Failed to retrieve quota information', 'startempire-wire-network-screenshots'));
            }

            wp_send_json_success([
                'quota' => $quota_info
            ]);

        } catch (Exception $e) {
            $this->logger->error('Quota refresh failed', [
                'error' => $e->getMessage()
            ]);
            
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    private function render_fallback_quota_info($quota_info) {
        if (!$quota_info) {
            return '';
        }

        $html = '<div class="sewn-quota-info">';
        
        if (isset($quota_info['remaining'])) {
            $html .= sprintf(
                '<p><strong>%s:</strong> %s</p>',
                __('Remaining Credits', 'startempire-wire-network-screenshots'),
                number_format($quota_info['remaining'])
            );
        }

        if (isset($quota_info['total'])) {
            $html .= sprintf(
                '<p><strong>%s:</strong> %s</p>',
                __('Total Credits', 'startempire-wire-network-screenshots'),
                number_format($quota_info['total'])
            );
        }

        if (isset($quota_info['reset_date'])) {
            $html .= sprintf(
                '<p><strong>%s:</strong> %s</p>',
                __('Next Reset', 'startempire-wire-network-screenshots'),
                date_i18n(get_option('date_format'), $quota_info['reset_date'])
            );
        }

        if (isset($quota_info['plan'])) {
            $html .= sprintf(
                '<p><strong>%s:</strong> %s</p>',
                __('Current Plan', 'startempire-wire-network-screenshots'),
                esc_html($quota_info['plan'])
            );
        }

        $html .= sprintf(
            '<button type="button" class="button sewn-refresh-quota" data-nonce="%s">
                <span class="dashicons dashicons-update"></span> %s
            </button>',
            wp_create_nonce('sewn_refresh_quota'),
            __('Refresh Quota', 'startempire-wire-network-screenshots')
        );

        $html .= '</div>';

        return $html;
    }

    public function render_health_status_section() {
        $health_check = new SEWN_Health_Check($this->logger);
        ?>
        <div class="sewn-health-status-wrapper">
            <div class="sewn-status-header">
                <h3><?php _e('System Health', 'startempire-wire-network-screenshots'); ?></h3>
                <button type="button" class="button sewn-run-health-check">
                    <span class="dashicons dashicons-heart"></span>
                    <?php _e('Run Health Check', 'startempire-wire-network-screenshots'); ?>
                </button>
            </div>
            <div id="sewn-health-results">
                <!-- Results will be loaded here via AJAX -->
            </div>
        </div>
        <?php
    }

    public function render_security_settings_section() {
        $security_manager = new SEWN_Security_Manager($this->logger);
        ?>
        <div class="sewn-security-settings-wrapper">
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="sewn_rate_limiting">
                            <?php _e('Enable Rate Limiting', 'startempire-wire-network-screenshots'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="checkbox" 
                               name="sewn_rate_limiting" 
                               id="sewn_rate_limiting"
                               value="1"
                               <?php checked(get_option('sewn_rate_limiting', true)); ?>>
                        <p class="description">
                            <?php _e('Limit the number of screenshot requests per minute', 'startempire-wire-network-screenshots'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="sewn_verify_sources">
                            <?php _e('Verify Screenshot Sources', 'startempire-wire-network-screenshots'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="checkbox" 
                               name="sewn_verify_sources" 
                               id="sewn_verify_sources"
                               value="1"
                               <?php checked(get_option('sewn_verify_sources', true)); ?>>
                        <p class="description">
                            <?php _e('Verify the source of screenshot requests', 'startempire-wire-network-screenshots'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    public function render_installation_status_section() {
        $installation_tracker = new SEWN_Installation_Tracker($this->logger);
        ?>
        <div class="sewn-installation-status-wrapper">
            <div class="sewn-status-header">
                <h3><?php _e('Installation Progress', 'startempire-wire-network-screenshots'); ?></h3>
                <div id="sewn-installation-progress">
                    <!-- Progress will be updated via AJAX -->
                </div>
            </div>
            <div class="sewn-installation-log">
                <h4><?php _e('Recent Installation Activities', 'startempire-wire-network-screenshots'); ?></h4>
                <div id="sewn-installation-log-entries">
                    <!-- Log entries will be loaded via AJAX -->
                </div>
            </div>
        </div>
        <?php
    }
}