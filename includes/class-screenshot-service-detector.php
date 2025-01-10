<?php
if (!defined('ABSPATH')) exit;

class SEWN_Screenshot_Service_Detector {
    /** @var SEWN_Logger */
    private $logger;

    /** @var array */
    private $available_services = [];

    /**
     * Constructor
     * 
     * @param SEWN_Logger $logger
     */
    public function __construct(SEWN_Logger $logger) {
        $this->logger = $logger;
        $this->detect_services();
    }

    /**
     * Get available screenshot services
     * 
     * @return array
     */
    public function get_available_services() {
        return [
            'wkhtmltoimage' => [
                'name' => 'wkhtmltoimage',
                'available' => $this->check_wkhtmltoimage(),
                'version' => $this->get_wkhtmltoimage_version()
            ],
            'chrome-php' => [
                'name' => 'Chrome PHP',
                'available' => $this->check_chrome_php(),
                'version' => $this->get_chrome_version()
            ],
            'screenshot-machine' => [
                'name' => 'Screenshot Machine',
                'available' => $this->check_screenshot_machine(),
                'version' => 'API'
            ]
        ];
    }

    /**
     * Check if wkhtmltoimage is available
     * 
     * @return bool
     */
    private function check_wkhtmltoimage() {
        exec('which wkhtmltoimage', $output, $return_var);
        $available = $return_var === 0;
        
        $this->logger->debug('Checked wkhtmltoimage availability', [
            'available' => $available,
            'path' => $output[0] ?? null
        ]);
        
        return $available;
    }

    /**
     * Get wkhtmltoimage version
     * 
     * @return string|null
     */
    private function get_wkhtmltoimage_version() {
        exec('wkhtmltoimage --version', $output, $return_var);
        return $return_var === 0 ? trim($output[0]) : null;
    }

    /**
     * Check if Chrome PHP is available
     * 
     * @return bool
     */
    private function check_chrome_php() {
        // Check for Chrome PHP package
        $chrome_php_available = class_exists('HeadlessChromium\Browser');
        
        // Check for Chrome binary
        exec('which google-chrome', $output, $return_var);
        $chrome_binary_available = $return_var === 0;
        
        $this->logger->debug('Checked Chrome PHP availability', [
            'package_available' => $chrome_php_available,
            'binary_available' => $chrome_binary_available,
            'binary_path' => $output[0] ?? null
        ]);
        
        return $chrome_php_available && $chrome_binary_available;
    }

    /**
     * Get Chrome version
     * 
     * @return string|null
     */
    private function get_chrome_version() {
        if (!$this->check_chrome_php()) {
            return null;
        }

        exec('google-chrome --version', $output, $return_var);
        $version = $return_var === 0 ? trim($output[0]) : null;

        $this->logger->debug('Retrieved Chrome version', [
            'version' => $version,
            'return_var' => $return_var
        ]);

        return $version;
    }

    /**
     * Check if Screenshot Machine is available
     * 
     * @return bool
     */
    private function check_screenshot_machine() {
        $api_key = get_option('sewn_screenshot_machine_key');
        $is_configured = !empty($api_key);
        
        $this->logger->debug('Checked Screenshot Machine availability', [
            'is_configured' => $is_configured,
            'has_api_key' => !empty($api_key)
        ]);
        
        return $is_configured;
    }

    /**
     * Test Screenshot Machine API connection
     * 
     * @return bool
     */
    private function test_screenshot_machine_connection() {
        $api_key = get_option('sewn_screenshot_machine_key');
        
        if (empty($api_key)) {
            $this->logger->debug('Screenshot Machine API test failed - No API key');
            return false;
        }

        // Simple test URL
        $test_url = 'https://api.screenshotmachine.com/?key=' . urlencode($api_key) . '&url=https://example.com&dimension=1024x768';
        
        $response = wp_remote_get($test_url);
        $is_valid = !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
        
        $this->logger->debug('Screenshot Machine API test', [
            'success' => $is_valid,
            'response_code' => wp_remote_retrieve_response_code($response)
        ]);
        
        return $is_valid;
    }

    /**
     * Detect available services
     * 
     * @param bool $force Force new detection
     * @return array
     */
    public function detect_services($force = false) {
        if ($force || empty($this->available_services)) {
            $this->available_services = $this->get_available_services();
            
            $this->logger->info('Screenshot services detected', [
                'services' => $this->available_services
            ]);
        }
        
        return $this->available_services;
    }
} 