<?php
if (!defined('ABSPATH')) exit;

class SEWN_Cache {
    private $prefix = 'sewn_screenshot_';
    private $logger;
    private $cache_dir;
    private $expiration;
    
    /**
     * Constructor
     * 
     * @param SEWN_Logger $logger Logger instance
     */
    public function __construct($logger = null) {
        $this->logger = $logger ?? new SEWN_Logger();
        $this->cache_dir = WP_CONTENT_DIR . '/cache/screenshots/';
        $this->expiration = apply_filters('sewn_cache_expiration', 24 * HOUR_IN_SECONDS);
        
        if (!file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
        }
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
    
    /**
     * Get the full path to a cache file
     *
     * @param string $key Cache key
     * @return string Full path to cache file
     */
    private function get_cache_file($key) {
        return trailingslashit($this->cache_dir) . $this->prefix . $key . '.jpg';
    }
    
    /**
     * Delete a cached item
     *
     * @param string $key Cache key to delete
     * @return boolean True if successful, false otherwise
     */
    public function delete($key) {
        try {
            $cache_file = $this->get_cache_file($key);
            
            if (file_exists($cache_file)) {
                if (unlink($cache_file)) {
                    $this->logger->debug('Cache file deleted', [
                        'key' => $key,
                        'file' => $cache_file
                    ]);
                    return true;
                } else {
                    $this->logger->error('Failed to delete cache file', [
                        'key' => $key,
                        'file' => $cache_file
                    ]);
                }
            } else {
                $this->logger->debug('Cache file not found for deletion', [
                    'key' => $key,
                    'file' => $cache_file
                ]);
            }
            
            return false;
        } catch (Exception $e) {
            $this->logger->error('Cache deletion error', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}