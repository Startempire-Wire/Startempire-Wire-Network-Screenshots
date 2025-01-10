<?php
/**
 * Health Check Class
 * Handles system health monitoring and reporting
 */

if (!defined('ABSPATH')) exit;

class SEWN_Health_Check {
    private $logger;
    
    public function __construct($logger) {
        $this->logger = $logger;
        add_filter('site_status_tests', [$this, 'register_tests']);
        add_action('wp_ajax_sewn_run_health_check', [$this, 'run_health_check']);
    }
    
    public function register_tests($tests) {
        $tests['direct']['sewn_screenshot_tools'] = [
            'label' => __('Screenshot Tools Status', 'startempire-wire-network-screenshots'),
            'test' => [$this, 'check_screenshot_tools']
        ];
        
        $tests['direct']['sewn_api_connectivity'] = [
            'label' => __('API Connectivity', 'startempire-wire-network-screenshots'),
            'test' => [$this, 'check_api_connectivity']
        ];
        
        return $tests;
    }
    
    public function check_screenshot_tools() {
        $detector = new SEWN_Screenshot_Service_Detector($this->logger);
        $services = $detector->detect_services(true);
        
        return [
            'label' => __('Screenshot tools status', 'startempire-wire-network-screenshots'),
            'status' => $this->get_health_status($services),
            'badge' => [
                'label' => __('Screenshot Service', 'startempire-wire-network-screenshots'),
                'color' => 'blue'
            ],
            'description' => $this->get_health_description($services),
            'actions' => $this->get_health_actions($services),
            'test' => 'sewn_screenshot_tools'
        ];
    }
    
    public function check_api_connectivity() {
        try {
            $api_manager = new SEWN_API_Manager($this->logger);
            $status = $api_manager->check_connectivity();
            
            return [
                'label' => __('API Connectivity', 'startempire-wire-network-screenshots'),
                'status' => $status ? 'good' : 'critical',
                'badge' => [
                    'label' => __('API', 'startempire-wire-network-screenshots'),
                    'color' => $status ? 'green' : 'red'
                ],
                'description' => $status 
                    ? __('API connection is working properly.', 'startempire-wire-network-screenshots')
                    : __('Unable to connect to the API service.', 'startempire-wire-network-screenshots'),
                'actions' => '',
                'test' => 'sewn_api_connectivity'
            ];
        } catch (Exception $e) {
            $this->logger->error('API connectivity check failed', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'label' => __('API Connectivity', 'startempire-wire-network-screenshots'),
                'status' => 'critical',
                'badge' => [
                    'label' => __('API', 'startempire-wire-network-screenshots'),
                    'color' => 'red'
                ],
                'description' => $e->getMessage(),
                'actions' => '',
                'test' => 'sewn_api_connectivity'
            ];
        }
    }
    
    public function run_health_check() {
        check_ajax_referer('sewn_health_check', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'startempire-wire-network-screenshots')]);
        }
        
        try {
            $results = [
                'screenshot_tools' => $this->check_screenshot_tools(),
                'api_connectivity' => $this->check_api_connectivity(),
                'system_info' => $this->get_system_info()
            ];
            
            wp_send_json_success([
                'html' => $this->render_health_results($results)
            ]);
        } catch (Exception $e) {
            $this->logger->error('Health check failed', [
                'error' => $e->getMessage()
            ]);
            
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }
    
    private function get_health_status($services) {
        if (empty($services['services'])) {
            return 'critical';
        }
        
        $active_services = array_filter($services['services'], function($service) {
            return $service['available'] === true;
        });
        
        if (empty($active_services)) {
            return 'critical';
        }
        
        return count($active_services) === count($services['services']) ? 'good' : 'recommended';
    }
    
    private function get_health_description($services) {
        if (empty($services['services'])) {
            return __('No screenshot services detected.', 'startempire-wire-network-screenshots');
        }
        
        $available = [];
        $unavailable = [];
        
        foreach ($services['services'] as $id => $service) {
            if ($service['available']) {
                $available[] = $id;
            } else {
                $unavailable[] = $id;
            }
        }
        
        $description = '';
        
        if (!empty($available)) {
            $description .= sprintf(
                __('Available services: %s', 'startempire-wire-network-screenshots'),
                implode(', ', $available)
            );
        }
        
        if (!empty($unavailable)) {
            $description .= sprintf(
                __('Unavailable services: %s', 'startempire-wire-network-screenshots'),
                implode(', ', $unavailable)
            );
        }
        
        return $description;
    }
    
    private function get_health_actions($services) {
        $actions = '';
        
        foreach ($services['services'] as $id => $service) {
            if (!$service['available']) {
                $actions .= sprintf(
                    '<a href="%s" class="button button-secondary">%s</a> ',
                    admin_url('admin.php?page=sewn-screenshots-settings&action=install&tool=' . $id),
                    sprintf(__('Install %s', 'startempire-wire-network-screenshots'), $id)
                );
            }
        }
        
        return $actions;
    }
    
    private function get_system_info() {
        return [
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size')
        ];
    }
    
    private function render_health_results($results) {
        ob_start();
        include SEWN_SCREENSHOTS_PATH . 'admin/views/health-check-results.php';
        return ob_get_clean();
    }
} 