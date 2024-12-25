<?php
if (!defined('ABSPATH')) exit;

class SEWN_Cache {
    private $prefix = 'sewn_screenshot_';
    private $logger;
    
    public function __construct($logger) {
        $this->logger = $logger;
    }
    
    public function get($key, $default = null) {
        $value = get_transient($this->prefix . $key);
        return $value !== false ? $value : $default;
    }
    
    public function set($key, $value, $expiration = null) {
        $expiration = $expiration ?? SEWN_SCREENSHOTS_CACHE_TIME;
        
        $success = set_transient($this->prefix . $key, $value, $expiration);
        
        if (!$success) {
            $this->logger->warning('Failed to cache screenshot', [
                'key' => $key,
                'size' => strlen(serialize($value))
            ]);
        }
        
        return $success;
    }
    
    public function generate_key($url, $options = []) {
        return md5($url . serialize($options));
    }
}