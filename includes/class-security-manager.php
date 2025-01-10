<?php
/**
 * Security Manager Class
 * Handles security checks, rate limiting, and package verification
 */

if (!defined('ABSPATH')) exit;

class SEWN_Security_Manager {
    private $logger;
    private $rate_limiter;
    
    public function __construct($logger) {
        $this->logger = $logger;
        $this->rate_limiter = new SEWN_Rate_Limiter($logger);
        
        add_filter('sewn_verify_installation_request', [$this, 'verify_request']);
        add_action('sewn_log_security_event', [$this, 'log_event']);
    }
    
    public function verify_request($request) {
        if (!current_user_can('manage_options')) {
            $this->log_event('unauthorized_access_attempt', [
                'user_id' => get_current_user_id(),
                'request' => $request
            ]);
            throw new Exception('Insufficient permissions');
        }
        
        if (!check_ajax_referer('sewn_installation_nonce', 'nonce', false)) {
            $this->log_event('invalid_nonce', [
                'request' => $request
            ]);
            throw new Exception('Invalid security token');
        }
        
        if ($this->rate_limiter->is_limited('installation')) {
            $this->log_event('rate_limit_exceeded', [
                'user_id' => get_current_user_id()
            ]);
            throw new Exception('Rate limit exceeded');
        }
        
        return true;
    }
    
    public function verify_package_signature($package_url, $signature) {
        // Verify package signature
        $valid = $this->verify_signature($package_url, $signature);
        
        if (!$valid) {
            $this->log_event('invalid_package_signature', [
                'package_url' => $package_url
            ]);
            throw new Exception('Invalid package signature');
        }
        
        return true;
    }
    
    public function log_event($event_type, $data = []) {
        $this->logger->warning('Security event: ' . $event_type, array_merge([
            'time' => current_time('mysql'),
            'ip' => $this->get_client_ip()
        ], $data));
    }
    
    private function verify_signature($url, $signature) {
        // Implementation for signature verification
        // This is a placeholder - implement actual verification logic
        return true;
    }
    
    private function get_client_ip() {
        $ip = '';
        
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP);
    }
} 