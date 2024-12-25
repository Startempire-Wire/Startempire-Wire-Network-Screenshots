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

    public function take_screenshot($url, $options = []) {
        try {
            $options = $this->validate_options($options);
            
            if ($this->logger) {
                $this->logger->info('Taking screenshot', [
                    'url' => $url,
                    'options' => $options,
                    'using_wkhtmltoimage' => (bool)$this->wkhtmltoimage_path
                ]);
            }

            $output_file = $this->generate_file_path($url);

            if ($this->wkhtmltoimage_path) {
                $result = $this->take_screenshot_with_wkhtmltoimage($url, $output_file, $options);
            } else {
                $result = $this->take_screenshot_with_fallback($url, $output_file, $options);
            }

            // Verify the file exists and is readable
            if (!file_exists($output_file) || !is_readable($output_file)) {
                throw new Exception('Screenshot file not created or not readable');
            }

            $result['file_path'] = $output_file;
            $result['url'] = str_replace(
                wp_get_upload_dir()['basedir'],
                wp_get_upload_dir()['baseurl'],
                $output_file
            );
            $result['size'] = filesize($output_file);
            
            if ($this->logger) {
                $this->logger->info('Screenshot taken successfully', $result);
            }

            return $result;

        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Screenshot failed: ' . $e->getMessage(), [
                    'url' => $url,
                    'options' => $options
                ]);
            }
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

        // Example for Screenshot Machine API
        $api_url = 'https://api.screenshotmachine.com/';
        $params = [
            'key' => $api_key,
            'url' => urlencode($url),
            'dimension' => $options['width'] . 'x' . $options['height'],
            'format' => 'png',
            'cacheLimit' => '0',
            'delay' => '2000'
        ];

        $response = wp_remote_get(add_query_arg($params, $api_url), [
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            throw new Exception('Fallback service failed: ' . $response->get_error_message());
        }

        $image_data = wp_remote_retrieve_body($response);
        if (empty($image_data)) {
            throw new Exception('No image data received from fallback service');
        }

        // Check if the response is an error message instead of image data
        if (strlen($image_data) < 1024) { // Likely an error message if too small
            $possible_error = json_decode($image_data, true);
            if ($possible_error && isset($possible_error['error'])) {
                throw new Exception('Fallback service error: ' . $possible_error['error']);
            }
        }

        if (!file_put_contents($output_file, $image_data)) {
            throw new Exception('Failed to save screenshot file');
        }

        return [
            'success' => true,
            'method' => 'fallback_service',
            'service' => $service,
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
} 