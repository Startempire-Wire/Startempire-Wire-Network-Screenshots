<?php
if (!defined('ABSPATH')) exit;

class SEWN_Settings {
    /** @var string */
    private $options_group = 'sewn_screenshots_options';
    
    /** @var string */
    private $page_slug = 'sewn-screenshots-settings';
    
    /** @var SEWN_Logger */
    private $logger;
    
    /** @var SEWN_Screenshot_Service_Detector */
    private $service_detector;
    
    /** @var SEWN_Health_Check */
    private $health_check;
    
    /** @var SEWN_Installation_Tracker */
    private $installation_tracker;
    
    /** @var SEWN_Admin */
    private $admin;
    
    /** @var array */
    private $tabs = [];

    /**
     * Initialize settings
     * 
     * @param SEWN_Logger $logger
     * @param SEWN_Screenshot_Service_Detector $service_detector
     * @param SEWN_Health_Check $health_check
     * @param SEWN_Installation_Tracker $installation_tracker
     * @param SEWN_Admin $admin
     */
    public function __construct(
        SEWN_Logger $logger,
        SEWN_Screenshot_Service_Detector $service_detector = null,
        SEWN_Health_Check $health_check = null,
        SEWN_Installation_Tracker $installation_tracker = null,
        SEWN_Admin $admin = null
    ) {
        $this->logger = $logger;
        $this->service_detector = $service_detector ?? new SEWN_Screenshot_Service_Detector($logger);
        $this->health_check = $health_check ?? new SEWN_Health_Check($logger);
        $this->installation_tracker = $installation_tracker;
        $this->admin = $admin;
        
        $this->page_slug = 'sewn-screenshots-settings';
        $this->options_group = 'sewn_screenshots_options';
        
        $this->init_tabs();
        $this->init_hooks();
    }

    /**
     * Initialize admin hooks
     */
    private function init_hooks() {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_sewn_refresh_service', [$this, 'handle_service_refresh']);
    }

    /**
     * Initialize settings tabs
     */
    private function init_tabs() {
        $this->tabs = [
            'general' => [
                'title' => __('General Settings', 'startempire-wire-network-screenshots'),
                'callback' => [$this, 'render_general_settings']
            ],
            'services' => [
                'title' => __('Screenshot Services', 'startempire-wire-network-screenshots'),
                'callback' => [$this, 'render_services_settings']
            ],
            'health' => [
                'title' => __('Health & Status', 'startempire-wire-network-screenshots'),
                'callback' => [$this, 'render_health_settings']
            ]
        ];
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        // Register general settings section
        add_settings_section(
            'sewn_general_section',
            __('General Settings', 'startempire-wire-network-screenshots'),
            [$this, 'render_general_section_description'],
            $this->page_slug
        );

        // Register screenshot service settings
        register_setting(
            $this->options_group,
            'sewn_primary_service',
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_service_selection'],
                'default' => 'wkhtmltoimage'
            ]
        );

        add_settings_field(
            'sewn_primary_service',
            __('Primary Screenshot Service', 'startempire-wire-network-screenshots'),
            [$this, 'render_service_selection'],
            $this->page_slug,
            'sewn_general_section',
            ['label_for' => 'sewn_primary_service']
        );

        // Register service detection settings
        add_settings_section(
            'sewn_services_section',
            __('Available Services', 'startempire-wire-network-screenshots'),
            [$this, 'render_services_section_description'],
            $this->page_slug
        );

        // Register Screenshot Machine API settings
        register_setting(
            $this->options_group,
            'sewn_screenshot_machine_key',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => ''
            ]
        );

        add_settings_field(
            'sewn_screenshot_machine_key',
            __('Screenshot Machine API Key', 'startempire-wire-network-screenshots'),
            [$this, 'render_api_key_field'],
            $this->page_slug,
            'sewn_services_section',
            ['label_for' => 'sewn_screenshot_machine_key']
        );
    }

    /**
     * Sanitize service selection
     * 
     * @param string $service
     * @return string
     */
    public function sanitize_service_selection($service) {
        $available_services = $this->service_detector->get_available_services();
        return in_array($service, array_keys($available_services)) ? $service : 'wkhtmltoimage';
    }

    /**
     * Render general section description
     */
    public function render_general_section_description() {
        echo '<p>' . esc_html__('Configure the primary screenshot service and general settings.', 'startempire-wire-network-screenshots') . '</p>';
    }

    /**
     * Render services section description
     */
    public function render_services_section_description() {
        echo '<p>' . esc_html__('Available screenshot services detected on your system.', 'startempire-wire-network-screenshots') . '</p>';
    }

    /**
     * Render service selection field
     * 
     * @param array $args Field arguments
     */
    public function render_service_selection($args) {
        $current_service = get_option('sewn_primary_service', 'wkhtmltoimage');
        $available_services = $this->service_detector->get_available_services();
        
        echo '<div class="sewn-service-selection-wrapper">';
        
        // Service dropdown with availability status
        echo '<select id="' . esc_attr($args['label_for']) . '" 
                      name="sewn_primary_service" 
                      class="sewn-service-select">';
        
        foreach ($available_services as $service_id => $service) {
            $status_indicator = $service['available'] ? 
                ' (' . esc_html__('Available', 'startempire-wire-network-screenshots') . ')' : 
                ' (' . esc_html__('Not Available', 'startempire-wire-network-screenshots') . ')';
            
            printf(
                '<option value="%s" %s %s>%s%s</option>',
                esc_attr($service_id),
                selected($current_service, $service_id, false),
                $service['available'] ? '' : 'disabled',
                esc_html($service['name']),
                esc_html($status_indicator)
            );
        }
        echo '</select>';
        
        // Refresh button with loading state
        echo '<button type="button" 
                      class="button sewn-refresh-services" 
                      data-nonce="' . wp_create_nonce('sewn_refresh_services') . '">
                      <span class="dashicons dashicons-refresh"></span> ' . 
                      esc_html__('Refresh Services', 'startempire-wire-network-screenshots') . 
              '</button>';
        
        echo '<div class="sewn-service-status"></div>';
        echo '</div>';
    }

    /**
     * Handle service refresh AJAX request
     */
    public function handle_service_refresh() {
        // Verify nonce
        if (!check_ajax_referer('sewn_admin_nonce', 'nonce', false)) {
            wp_send_json_error([
                'message' => __('Security check failed.', 'startempire-wire-network-screenshots')
            ]);
        }

        // Verify user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Insufficient permissions.', 'startempire-wire-network-screenshots')
            ]);
        }

        try {
            // Refresh services
            $services = $this->get_service_status();
            
            $this->logger->info('Services refreshed successfully', [
                'services' => $services
            ]);

            wp_send_json_success([
                'message' => __('Services refreshed successfully.', 'startempire-wire-network-screenshots'),
                'services' => $services
            ]);

        } catch (Exception $e) {
            $this->logger->error('Service refresh failed', [
                'error' => $e->getMessage()
            ]);

            wp_send_json_error([
                'message' => __('Failed to refresh services.', 'startempire-wire-network-screenshots')
            ]);
        }
    }

    /**
     * Render service information
     */
    public function render_services_section() {
        $services = $this->service_detector->get_available_services();
        $health_status = $this->health_check->get_service_health();
        
        echo '<div class="sewn-services-grid">';
        
        foreach ($services as $service_id => $service) {
            $status_class = $service['available'] ? 'available' : 'unavailable';
            $health_info = isset($health_status[$service_id]) ? $health_status[$service_id] : null;
            
            echo '<div class="sewn-service-card">';
            
            // Service Header
            printf(
                '<div class="sewn-service-header">
                    <h3>%s</h3>
                    <span class="sewn-service-indicator %s">
                        <span class="dashicons %s"></span>
                    </span>
                </div>',
                esc_html($service['name']),
                esc_attr($status_class),
                $service['available'] ? 'dashicons-yes-alt' : 'dashicons-warning'
            );
            
            // Service Details
            echo '<div class="sewn-service-details">';
            
            // Version Info
            if (!empty($service['version'])) {
                printf(
                    '<div class="sewn-service-version">%s: %s</div>',
                    esc_html__('Version', 'startempire-wire-network-screenshots'),
                    esc_html($service['version'])
                );
            }
            
            // Health Status - Add proper checks
            if ($health_info && isset($health_info['message'])) {
                printf(
                    '<div class="sewn-service-health %s">
                        <span class="dashicons %s"></span>
                        %s
                    </div>',
                    esc_attr($health_info['status'] ?? 'unknown'),
                    isset($health_info['status']) && $health_info['status'] === 'healthy' ? 'dashicons-heart' : 'dashicons-warning',
                    esc_html($health_info['message'])
                );
            }
            
            echo '</div>'; // .sewn-service-details
            
            echo '</div>'; // .sewn-service-card
        }
        
        echo '</div>'; // .sewn-services-grid
    }

    /**
     * Render the settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields($this->options_group);
                do_settings_sections($this->page_slug);
                submit_button();
                ?>
            </form>

            <h2><?php _e('Screenshot Services', 'startempire-wire-network-screenshots'); ?></h2>
            <?php $this->render_services(); ?>
        </div>
        <?php
    }

    /**
     * Render API key field
     * 
     * @param array $args Field arguments
     */
    public function render_api_key_field($args) {
        $api_key = get_option('sewn_screenshot_machine_key', '');
        
        printf(
            '<input type="text" 
                   id="%s" 
                   name="sewn_screenshot_machine_key" 
                   value="%s" 
                   class="regular-text"
                   placeholder="%s">
             <p class="description">%s</p>',
            esc_attr($args['label_for']),
            esc_attr($api_key),
            esc_attr__('Enter your Screenshot Machine API key', 'startempire-wire-network-screenshots'),
            esc_html__('Required for Screenshot Machine service. Get your API key from screenshotmachine.com', 'startempire-wire-network-screenshots')
        );
    }

    /**
     * Render individual service card
     * 
     * @param array $service Service configuration
     * @param array $health_info Health check information
     */
    private function render_service_card($service, $health_info) {
        $status_class = isset($health_info['status']) ? 'sewn-status-' . esc_attr($health_info['status']) : 'sewn-status-unknown';
        $status_icon = $health_info['status'] === 'healthy' ? 'dashicons-yes-alt' : 'dashicons-warning';
        
        ?>
        <div class="sewn-service-card" data-service="<?php echo esc_attr($service['id']); ?>">
            <div class="sewn-service-header">
                <h3><?php echo esc_html($service['name']); ?></h3>
                <div class="sewn-service-controls">
                    <span class="sewn-service-indicator <?php echo $status_class; ?>">
                        <span class="dashicons <?php echo $status_icon; ?>"></span>
                        <?php echo esc_html(ucfirst($health_info['status'] ?? 'Unknown')); ?>
                    </span>
                    <?php if ($this->admin): ?>
                    <button type="button" 
                            class="button sewn-refresh-service" 
                            data-nonce="<?php echo wp_create_nonce('sewn_admin_nonce'); ?>"
                            title="<?php esc_attr_e('Refresh service status', 'startempire-wire-network-screenshots'); ?>">
                        <span class="dashicons dashicons-update"></span>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="sewn-service-details">
                <?php if (!empty($service['description'])): ?>
                    <div class="sewn-service-description">
                        <?php echo esc_html($service['description']); ?>
                    </div>
                <?php endif; ?>
                
                <div class="sewn-service-health">
                    <span class="health-message">
                        <?php echo esc_html($health_info['message']); ?>
                    </span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render services grid
     */
    private function render_services() {
        $services = [
            'wkhtmltopdf' => [
                'id' => 'wkhtmltopdf',
                'name' => __('wkhtmltopdf', 'startempire-wire-network-screenshots'),
                'description' => __('Local screenshot generation service', 'startempire-wire-network-screenshots')
            ],
            'screenshotmachine' => [
                'id' => 'screenshotmachine',
                'name' => __('Screenshot Machine', 'startempire-wire-network-screenshots'),
                'description' => __('External screenshot API service', 'startempire-wire-network-screenshots')
            ]
        ];
        ?>
        <div class="sewn-services-grid">
            <?php foreach ($services as $service) {
                $health_info = $this->get_service_health($service['id']);
                $this->render_service_card($service, $health_info);
            } ?>
        </div>
        <?php
    }

    /**
     * Get service health information
     * 
     * @param string $service_id Service identifier
     * @return array Health status information
     */
    private function get_service_health($service_id) {
        $status = $this->get_service_status();
        $service_info = $status[$service_id] ?? [];
        
        if (!isset($service_info['available'])) {
            return [
                'status' => 'unknown',
                'message' => __('Service status cannot be determined', 'startempire-wire-network-screenshots')
            ];
        }

        if (!$service_info['available']) {
            return [
                'status' => 'error',
                'message' => $service_id === 'screenshotmachine' 
                    ? __('API key not configured', 'startempire-wire-network-screenshots')
                    : __('Service not available on system', 'startempire-wire-network-screenshots')
            ];
        }

        return [
            'status' => 'healthy',
            'message' => sprintf(
                /* translators: %s: version number */
                __('Service is healthy (Version %s)', 'startempire-wire-network-screenshots'),
                $service_info['version'] ?: __('unknown', 'startempire-wire-network-screenshots')
            )
        ];
    }

    /**
     * Get service status information
     * 
     * @return array Status of all services
     */
    private function get_service_status() {
        try {
            $services = [];
            
            // Check wkhtmltopdf service
            $services['wkhtmltopdf'] = $this->check_wkhtmltopdf_service();
            
            // Check Screenshot Machine service
            $services['screenshotmachine'] = $this->check_screenshot_machine_service();
            
            $this->logger->debug('Service status check completed', [
                'services' => $services
            ]);
            
            return $services;
            
        } catch (Exception $e) {
            $this->logger->error('Service status check failed', [
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }

    /**
     * Check wkhtmltopdf service availability and version
     * 
     * @return array Service status and version information
     */
    private function check_wkhtmltopdf_service() {
        try {
            $version_output = shell_exec('wkhtmltoimage --version');
            $is_available = !empty($version_output);
            
            // Extract version number if available
            $version = '';
            if ($is_available && preg_match('/(\d+\.\d+\.\d+)/', $version_output, $matches)) {
                $version = $matches[1];
            }

            return [
                'available' => $is_available,
                'version' => $version,
                'type' => 'local'
            ];

        } catch (Exception $e) {
            $this->logger->error('wkhtmltopdf check failed', [
                'error' => $e->getMessage()
            ]);
            return [
                'available' => false,
                'version' => '',
                'type' => 'local'
            ];
        }
    }

    /**
     * Check Screenshot Machine service availability and version
     * 
     * @return array Service status and version information
     */
    private function check_screenshot_machine_service() {
        $api_key = get_option('sewn_screenshot_machine_key', '');
        return [
            'available' => !empty($api_key),
            'version' => 'API v2', // API version is fixed
            'type' => 'api'
        ];
    }

    // ... existing render methods stay the same for now ...
}