<?php
/**
 * Health Check Class
 * Handles system health monitoring and reporting
 */

if (!defined('ABSPATH')) exit;

class SEWN_Health_Check {
    /** @var SEWN_Logger */
    private $logger;

    /** @var array */
    private $health_status = [];

    /**
     * Constructor
     * 
     * @param SEWN_Logger $logger
     */
    public function __construct(SEWN_Logger $logger) {
        $this->logger = $logger;
    }

    /**
     * Get service health status
     * 
     * @param string $service_name Service identifier
     * @return array Health status data
     */
    public function get_service_health($service_name = null) {
        $health_data = [
            'wkhtmltoimage' => [
                'status' => $this->check_wkhtmltoimage_health(),
                'last_check' => get_option('sewn_wkhtmltoimage_last_check', ''),
                'details' => $this->get_service_details('wkhtmltoimage'),
                'message' => $this->get_status_message('wkhtmltoimage')
            ],
            'chrome-php' => [
                'status' => $this->check_chrome_php_health(),
                'last_check' => get_option('sewn_chrome_php_last_check', ''),
                'details' => $this->get_service_details('chrome-php'),
                'message' => $this->get_status_message('chrome-php')
            ],
            'screenshot-machine' => [
                'status' => $this->check_screenshot_machine_health(),
                'last_check' => get_option('sewn_screenshot_machine_last_check', ''),
                'details' => $this->get_service_details('screenshot-machine'),
                'message' => $this->get_status_message('screenshot-machine')
            ]
        ];

        $this->logger->debug('Health check performed', [
            'service' => $service_name,
            'health_data' => $service_name ? ($health_data[$service_name] ?? null) : $health_data
        ]);

        return $service_name ? ($health_data[$service_name] ?? null) : $health_data;
    }

    /**
     * Check wkhtmltoimage health
     * 
     * @return string Status: 'healthy', 'warning', or 'error'
     */
    private function check_wkhtmltoimage_health() {
        exec('which wkhtmltoimage', $output, $return_var);
        if ($return_var !== 0) {
            return 'error';
        }

        exec('wkhtmltoimage --version', $output, $return_var);
        update_option('sewn_wkhtmltoimage_last_check', current_time('mysql'));
        
        return $return_var === 0 ? 'healthy' : 'warning';
    }

    /**
     * Check Chrome PHP health
     * 
     * @return string Status: 'healthy', 'warning', or 'error'
     */
    private function check_chrome_php_health() {
        if (!class_exists('HeadlessChromium\Browser')) {
            return 'error';
        }

        exec('google-chrome --version', $output, $return_var);
        update_option('sewn_chrome_php_last_check', current_time('mysql'));
        
        return $return_var === 0 ? 'healthy' : 'warning';
    }

    /**
     * Check Screenshot Machine API health
     * 
     * @return string Status: 'healthy', 'warning', or 'error'
     */
    private function check_screenshot_machine_health() {
        $api_key = get_option('sewn_screenshot_machine_key');
        if (empty($api_key)) {
            return 'error';
        }

        // Perform a test API call
        $test_url = 'https://api.screenshotmachine.com/?key=' . $api_key . '&url=https://example.com&test=1';
        $response = wp_remote_get($test_url);
        
        update_option('sewn_screenshot_machine_last_check', current_time('mysql'));
        
        if (is_wp_error($response)) {
            return 'error';
        }

        $code = wp_remote_retrieve_response_code($response);
        return ($code >= 200 && $code < 300) ? 'healthy' : 'warning';
    }

    /**
     * Get service details
     * 
     * @param string $service Service identifier
     * @return array Service details
     */
    private function get_service_details($service) {
        $details = [
            'version' => null,
            'config' => [],
            'errors' => []
        ];

        switch ($service) {
            case 'wkhtmltoimage':
                exec('wkhtmltoimage --version', $output, $return_var);
                $details['version'] = $return_var === 0 ? trim($output[0]) : null;
                $details['config'] = [
                    'path' => exec('which wkhtmltoimage'),
                    'enabled' => get_option('sewn_wkhtmltoimage_enabled', true)
                ];
                break;

            case 'chrome-php':
                exec('google-chrome --version', $output, $return_var);
                $details['version'] = $return_var === 0 ? trim($output[0]) : null;
                $details['config'] = [
                    'enabled' => get_option('sewn_chrome_php_enabled', true)
                ];
                break;

            case 'screenshot-machine':
                $details['version'] = 'API';
                $details['config'] = [
                    'api_key' => get_option('sewn_screenshot_machine_key') ? 'Configured' : 'Not configured',
                    'enabled' => get_option('sewn_screenshot_machine_enabled', true)
                ];
                break;
        }

        return $details;
    }

    /**
     * Get status message for service
     * 
     * @param string $service Service identifier
     * @return string Status message
     */
    private function get_status_message($service) {
        $status = $this->{'check_' . str_replace('-', '_', $service) . '_health'}();
        
        $messages = [
            'wkhtmltoimage' => [
                'healthy' => __('Service is running normally', 'startempire-wire-network-screenshots'),
                'warning' => __('Service is available but may have issues', 'startempire-wire-network-screenshots'),
                'error' => __('Service is not installed or not accessible', 'startempire-wire-network-screenshots')
            ],
            'chrome-php' => [
                'healthy' => __('Chrome PHP is properly configured', 'startempire-wire-network-screenshots'),
                'warning' => __('Chrome PHP is available but may have issues', 'startempire-wire-network-screenshots'),
                'error' => __('Chrome PHP is not installed or not accessible', 'startempire-wire-network-screenshots')
            ],
            'screenshot-machine' => [
                'healthy' => __('API is responding normally', 'startempire-wire-network-screenshots'),
                'warning' => __('API is accessible but may have issues', 'startempire-wire-network-screenshots'),
                'error' => __('API key is missing or invalid', 'startempire-wire-network-screenshots')
            ]
        ];

        return $messages[$service][$status] ?? __('Status unknown', 'startempire-wire-network-screenshots');
    }
} 