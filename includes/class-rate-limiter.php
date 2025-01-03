<?php
/**
 * Rate Limiter Class
 *
 * Handles request rate limiting with sophisticated tracking and caching.
 *
 * @package SEWN_Screenshots
 * @subpackage Rate_Limiting
 * @since 2.1.0
 */

class SEWN_Rate_Limiter {
    /**
     * Cache group for rate limiting
     *
     * @since 2.1.0
     * @var string
     */
    private $cache_group = 'sewn_rate_limits';

    /**
     * Default expiration time in seconds (1 hour)
     *
     * @since 2.1.0
     * @var int
     */
    private $default_expiration = 3600;

    /**
     * Logger instance
     *
     * @since 2.1.0
     * @var SEWN_Logger
     */
    private $logger;

    /**
     * Constructor
     *
     * Initializes the rate limiter with logging capability
     *
     * @since 2.1.0
     */
    public function __construct() {
        $this->logger = new SEWN_Logger();
    }

    /**
     * Get current usage count
     *
     * Retrieves the number of requests made within the current period
     *
     * @since 2.1.0
     * @param string $key Unique identifier for the rate limit
     * @return int Current usage count
     */
    public function get_usage($key) {
        $usage = wp_cache_get($key, $this->cache_group);
        return $usage ? intval($usage) : 0;
    }

    /**
     * Increment usage counter
     *
     * Increases the usage count for the given key, with recovery rate consideration
     *
     * @since 2.1.0
     * @param string $key Unique identifier for the rate limit
     * @param int $recovery_rate Number of requests that recover per minute
     * @return int New usage count
     */
    public function increment($key, $recovery_rate = 1) {
        $current = $this->get_usage($key);
        $last_access = wp_cache_get("{$key}_last_access", $this->cache_group);
        
        if ($last_access) {
            $minutes_passed = (time() - $last_access) / 60;
            $recovery = floor($minutes_passed * $recovery_rate);
            $current = max(0, $current - $recovery);
        }

        $new_count = $current + 1;
        
        wp_cache_set($key, $new_count, $this->cache_group, $this->default_expiration);
        wp_cache_set("{$key}_last_access", time(), $this->cache_group, $this->default_expiration);

        $this->logger->debug('Rate limit incremented', [
            'key' => $key,
            'previous' => $current,
            'new' => $new_count,
            'recovery_rate' => $recovery_rate
        ]);

        return $new_count;
    }

    /**
     * Get reset time
     *
     * Calculates when the rate limit will reset
     *
     * @since 2.1.0
     * @param string $key Unique identifier for the rate limit
     * @return int Unix timestamp when limit resets
     */
    public function get_reset_time($key) {
        $created = wp_cache_get("{$key}_created", $this->cache_group);
        if (!$created) {
            $created = time();
            wp_cache_set("{$key}_created", $created, $this->cache_group, $this->default_expiration);
        }
        
        return $created + $this->default_expiration;
    }

    /**
     * Get minutes until reset
     *
     * Calculates minutes remaining until rate limit resets
     *
     * @since 2.1.0
     * @param string $key Unique identifier for the rate limit
     * @return int Minutes until reset
     */
    public function get_reset_minutes($key) {
        $reset_time = $this->get_reset_time($key);
        return max(0, ceil(($reset_time - time()) / 60));
    }

    /**
     * Get retry after header value
     *
     * Calculates when the client should retry the request
     *
     * @since 2.1.0
     * @param string $key Unique identifier for the rate limit
     * @return int Seconds until retry is allowed
     */
    public function get_retry_after($key) {
        return max(0, $this->get_reset_time($key) - time());
    }

    /**
     * Reset rate limit
     *
     * Clears the rate limit for a given key
     *
     * @since 2.1.0
     * @param string $key Unique identifier for the rate limit
     * @return bool True on successful reset
     */
    public function reset($key) {
        $this->logger->info('Rate limit reset', ['key' => $key]);
        
        wp_cache_delete($key, $this->cache_group);
        wp_cache_delete("{$key}_last_access", $this->cache_group);
        wp_cache_delete("{$key}_created", $this->cache_group);
        
        return true;
    }
} 