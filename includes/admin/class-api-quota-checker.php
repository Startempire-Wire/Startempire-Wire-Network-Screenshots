<?php
if (!defined('ABSPATH')) exit;

class SEWN_API_Quota_Checker {
    private $logger;
    private $cache_duration = 3600; // 1 hour

    public function __construct($logger) {
        $this->logger = $logger;
    }

    /**
     * Check quota for Screenshot Machine
     */
    public function check_screenshotmachine_quota() {
        try {
            $api_key = get_option('sewn_fallback_api_key');
            $api_secret = get_option('sewn_fallback_api_secret');

            if (empty($api_key)) {
                throw new Exception('API key not configured');
            }

            // Check cache first
            $cache_key = 'sewn_quota_screenshotmachine_' . md5($api_key);
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return $cached;
            }

            $url = 'https://api.screenshotmachine.com/v2/account';
            $args = [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($api_key . ':' . ($api_secret ?? '')),
                    'Accept' => 'application/json'
                ],
                'timeout' => 15
            ];

            $response = wp_remote_get($url, $args);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $code = wp_remote_retrieve_response_code($response);

            if ($code !== 200 || !is_array($body)) {
                throw new Exception('Invalid response from Screenshot Machine API');
            }

            $this->logger->debug('Screenshot Machine quota response', [
                'response' => $body
            ]);

            // Format the response
            $quota_info = [
                'remaining' => $body['remaining_credits'] ?? 0,
                'reset_date' => strtotime($body['next_reset'] ?? ''),
                'plan' => $body['plan_name'] ?? 'Unknown',
                'total' => $body['total_credits'] ?? 0
            ];

            // Cache the result
            set_transient($cache_key, $quota_info, $this->cache_duration);

            return $quota_info;

        } catch (Exception $e) {
            $this->logger->error('Failed to check Screenshot Machine quota', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Check quota for URL2PNG
     */
    public function check_url2png_quota() {
        try {
            $api_key = get_option('sewn_fallback_api_key');
            $api_secret = get_option('sewn_fallback_api_secret');

            if (empty($api_key) || empty($api_secret)) {
                throw new Exception('API credentials not configured');
            }

            // Check cache first
            $cache_key = 'sewn_quota_url2png_' . md5($api_key);
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return $cached;
            }

            $url = 'https://api.url2png.com/v6/account';
            $token = hash('sha256', $api_secret . $api_key);
            
            $args = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json'
                ],
                'timeout' => 15
            ];

            $response = wp_remote_get($url, $args);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $code = wp_remote_retrieve_response_code($response);

            if ($code !== 200 || !is_array($body)) {
                throw new Exception('Invalid response from URL2PNG API');
            }

            $this->logger->debug('URL2PNG quota response', [
                'response' => $body
            ]);

            // Format the response
            $quota_info = [
                'remaining' => $body['remaining_requests'] ?? 0,
                'reset_date' => strtotime($body['next_reset_date'] ?? ''),
                'plan' => $body['subscription_type'] ?? 'Unknown',
                'total' => $body['total_requests'] ?? 0
            ];

            // Cache the result
            set_transient($cache_key, $quota_info, $this->cache_duration);

            return $quota_info;

        } catch (Exception $e) {
            $this->logger->error('Failed to check URL2PNG quota', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Test service configuration
     */
    public function test_service($service) {
        switch ($service) {
            case 'screenshotmachine':
                return $this->test_screenshotmachine();
            case 'url2png':
                return $this->test_url2png();
            default:
                throw new Exception('Unsupported service');
        }
    }

    /**
     * Test Screenshot Machine configuration
     */
    private function test_screenshotmachine() {
        try {
            $api_key = get_option('sewn_fallback_api_key');
            $api_secret = get_option('sewn_fallback_api_secret');

            if (empty($api_key)) {
                throw new Exception('API key not configured');
            }

            // Test URL (WordPress.org is a safe choice)
            $test_url = 'https://wordpress.org';
            $url = add_query_arg([
                'key' => $api_key,
                'url' => $test_url,
                'dimension' => '100x100',
                'format' => 'json'
            ], 'https://api.screenshotmachine.com/v2/capture');

            $args = [
                'headers' => [
                    'Accept' => 'application/json'
                ],
                'timeout' => 30
            ];

            if (!empty($api_secret)) {
                $args['headers']['Authorization'] = 'Basic ' . base64_encode($api_key . ':' . $api_secret);
            }

            $response = wp_remote_get($url, $args);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                throw new Exception('Service returned error code: ' . $code);
            }

            return true;

        } catch (Exception $e) {
            $this->logger->error('Screenshot Machine test failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Test URL2PNG configuration
     */
    private function test_url2png() {
        try {
            $api_key = get_option('sewn_fallback_api_key');
            $api_secret = get_option('sewn_fallback_api_secret');

            if (empty($api_key) || empty($api_secret)) {
                throw new Exception('API credentials not configured');
            }

            // Test URL
            $test_url = 'https://wordpress.org';
            $token = hash('sha256', $api_secret . $test_url);
            
            $url = add_query_arg([
                'apikey' => $api_key,
                'token' => $token,
                'url' => $test_url,
                'thumbnail_max_width' => 100
            ], 'https://api.url2png.com/v6/test');

            $response = wp_remote_get($url, [
                'timeout' => 30
            ]);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                throw new Exception('Service returned error code: ' . $code);
            }

            return true;

        } catch (Exception $e) {
            $this->logger->error('URL2PNG test failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
} 