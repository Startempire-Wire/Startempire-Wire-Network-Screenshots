<?php
/**
 * Screenshot Service Class
 *
 * @package SEWN_Screenshots
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEWN_Screenshot_Service {
    private $logger;
    private $default_options;
    private $wkhtmltoimage_path;
    private $screenshot_dir;
    
    // Add fallback service configuration
    private $fallback_services = [
        'screenshotmachine' => [
            'url' => 'https://api.screenshotmachine.com/',
            'params' => [
                'key' => '',
                'url' => '',
                'dimension' => '1280x720',
                'format' => 'jpg',
                'cacheLimit' => '14400'
            ]
        ]
    ];

    public function __construct($logger = null) {
        $this->logger = $logger;
        $this->default_options = [
            'width' => 1280,
            'height' => 720,
            'quality' => 90,
            'javascript-delay' => 1000
        ];
        
        $upload_dir = wp_upload_dir();
        $this->screenshot_dir = $upload_dir['basedir'] . '/screenshots/';
        wp_mkdir_p($this->screenshot_dir);
        
        $this->wkhtmltoimage_path = $this->detect_wkhtmltoimage();
    }

    private function detect_wkhtmltoimage() {
        $possible_paths = [
            '/usr/local/bin/wkhtmltoimage',
            '/usr/bin/wkhtmltoimage',
            'C:\\Program Files\\wkhtmltopdf\\bin\\wkhtmltoimage.exe',
            'C:\\Program Files (x86)\\wkhtmltopdf\\bin\\wkhtmltoimage.exe'
        ];

        foreach ($possible_paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                if ($this->logger) {
                    $this->logger->info('Found wkhtmltoimage at: ' . $path);
                }
                return $path;
            }
        }

        if ($this->logger) {
            $this->logger->warning('wkhtmltoimage not found, using fallback service');
        }
        return false;
    }

    public function take_screenshot($url, $type = 'full', $options = []) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sewn_screenshots';
        $start_time = microtime(true);
        
        try {
            // Log the initial attempt
            $wpdb->insert($table_name, [
                'url' => $url,
                'type' => $type,
                'status' => 'pending',
                'created_at' => current_time('mysql')
            ]);
            $screenshot_id = $wpdb->insert_id;
            
            $options = $this->validate_options($options);
            $output_file = $this->generate_file_path($url);
            
            // Check for API key first
            if (!empty($options['api_key'])) {
                $result = $this->take_screenshot_with_api($url, $output_file, $options);
            } else if ($this->wkhtmltoimage_path) {
                $result = $this->take_screenshot_with_wkhtmltoimage($url, $output_file, $options);
            } else {
                $result = $this->take_screenshot_with_fallback($url, $output_file, $options);
            }
            
            // Update the record with success info
            $processing_time = microtime(true) - $start_time;
            $wpdb->update(
                $table_name,
                [
                    'status' => 'success',
                    'processing_time' => $processing_time,
                    'screenshot_path' => $output_file,
                    'file_size' => filesize($output_file),
                    'updated_at' => current_time('mysql'),
                    'type' => $type
                ],
                ['id' => $screenshot_id]
            );
            
            $this->logger->debug('Screenshot taken successfully', [
                'url' => $url,
                'result' => $result
            ]);
            
            return array_merge($result, [
                'type' => $type,
                'url' => $this->get_screenshot_url($output_file),
                'size' => filesize($output_file)
            ]);
            
        } catch (Exception $e) {
            // Log the failure
            if (isset($screenshot_id)) {
                $wpdb->update(
                    $table_name,
                    [
                        'status' => 'failed',
                        'error_message' => $e->getMessage(),
                        'processing_time' => microtime(true) - $start_time,
                        'updated_at' => current_time('mysql')
                    ],
                    ['id' => $screenshot_id]
                );
            }
            
            $this->logger->error('Screenshot failed', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    private function take_screenshot_with_wkhtmltoimage($url, $output_file, $options) {
        $cmd = sprintf(
            '%s --quality %d --width %d --height %d --javascript-delay %d "%s" "%s"',
            escapeshellarg($this->wkhtmltoimage_path),
            $options['quality'],
            $options['width'],
            $options['height'],
            $options['javascript-delay'],
            escapeshellarg($url),
            escapeshellarg($output_file)
        );

        exec($cmd, $output, $return_var);

        if ($return_var !== 0) {
            throw new Exception('wkhtmltoimage failed: ' . implode("\n", $output));
        }

        return [
            'success' => true,
            'method' => 'wkhtmltoimage',
            'timestamp' => current_time('mysql')
        ];
    }

    private function take_screenshot_with_fallback($url, $output_file, $options) {
        $service = get_option('sewn_fallback_service', 'screenshotmachine');
        $api_key = get_option('sewn_fallback_api_key', '');

        if (empty($api_key)) {
            throw new Exception('Fallback service API key not configured');
        }

        // Configure service parameters
        $params = $this->fallback_services[$service]['params'];
        $params['key'] = $api_key;
        $params['url'] = urlencode($url);
        $params['dimension'] = $options['width'] . 'x' . $options['height'];

        // Debug API request
        if ($this->logger) {
            $this->logger->debug('Making fallback service request', [
                'service' => $service,
                'url' => $url,
                'has_key' => !empty($api_key)
            ]);
        }

        // Make API request
        $response = wp_remote_get(add_query_arg($params, $this->fallback_services[$service]['url']), [
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            throw new Exception('Fallback service failed: ' . $response->get_error_message());
        }

        $image_data = wp_remote_retrieve_body($response);
        if (empty($image_data)) {
            throw new Exception('No image data received from fallback service');
        }

        if (!file_put_contents($output_file, $image_data)) {
            throw new Exception('Failed to save screenshot file');
        }

        return [
            'success' => true,
            'method' => 'fallback_service',
            'service' => $service,
            'path' => $output_file,
            'url' => str_replace($this->screenshot_dir, wp_upload_dir()['baseurl'] . '/screenshots/', $output_file),
            'timestamp' => current_time('mysql')
        ];
    }

    private function generate_file_path($url) {
        $upload_dir = wp_upload_dir();
        $screenshot_dir = $upload_dir['basedir'] . '/screenshots/';
        
        if (!file_exists($screenshot_dir)) {
            wp_mkdir_p($screenshot_dir);
        }

        return $screenshot_dir . md5($url . microtime()) . '.png';
    }

    public function validate_options($options) {
        $options = wp_parse_args($options, $this->default_options);
        
        // Validate dimensions
        $options['width'] = max(100, min(3840, intval($options['width'])));
        $options['height'] = max(100, min(2160, intval($options['height'])));
        
        // Validate quality
        $options['quality'] = max(0, min(100, intval($options['quality'])));
        
        // Validate delay
        $options['javascript-delay'] = max(0, min(30000, intval($options['javascript-delay'])));
        
        return $options;
    }

    public function is_local_enabled() {
        $path = get_option('sewn_wkhtmltopdf_path', '');
        return !empty($path) && file_exists($path);
    }

    private function take_screenshot_with_api($url, $output_file, $options) {
        // Implementation for primary API service
        $api_key = $options['api_key'];
        
        // Make API request
        $response = wp_remote_post('https://api.screenshots.com/v1/take', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'url' => $url,
                'width' => $options['width'],
                'height' => $options['height'],
                'quality' => $options['quality']
            ])
        ]);

        if (is_wp_error($response)) {
            throw new Exception('API request failed: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data['screenshot_url'])) {
            throw new Exception('No screenshot URL in API response');
        }

        // Download the screenshot
        $image_response = wp_remote_get($data['screenshot_url']);
        if (is_wp_error($image_response)) {
            throw new Exception('Failed to download screenshot: ' . $image_response->get_error_message());
        }

        $image_data = wp_remote_retrieve_body($image_response);
        if (!file_put_contents($output_file, $image_data)) {
            throw new Exception('Failed to save screenshot file');
        }

        return [
            'success' => true,
            'method' => 'api',
            'timestamp' => current_time('mysql')
        ];
    }

    public function get_screenshot_url($file_path) {
        $this->logger->debug('Getting screenshot URL', [
            'file_path' => $file_path
        ]);
        
        $upload_dir = wp_upload_dir();
        $url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $file_path);
        
        $this->logger->debug('Generated screenshot URL', [
            'file_path' => $file_path,
            'url' => $url
        ]);
        
        return $url;
    }
} 