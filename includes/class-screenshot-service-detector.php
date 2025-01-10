<?php
if (!defined('ABSPATH')) exit;

class SEWN_Screenshot_Service_Detector {
    private $logger;
    private $cache_key = 'sewn_screenshot_services_status';
    private $cache_duration = 300; // 5 minutes
    
    private $server_side_tools = [
        'wkhtmltoimage' => [
            'name' => 'wkhtmltoimage',
            'type' => 'primary',
            'paths' => [
                '/usr/local/bin/wkhtmltoimage',
                '/usr/bin/wkhtmltoimage',
                '/opt/local/bin/wkhtmltoimage',
                '/opt/bin/wkhtmltoimage',
                'C:\\Program Files\\wkhtmltopdf\\bin\\wkhtmltoimage.exe',
                'C:\\Program Files (x86)\\wkhtmltopdf\\bin\\wkhtmltoimage.exe'
            ],
            'command' => 'wkhtmltoimage --version',
            'required' => true
        ],
        'puppeteer' => [
            'name' => 'Puppeteer',
            'type' => 'alternative',
            'command' => 'node -v && npm list puppeteer',
            'required' => false
        ],
        'chrome' => [
            'name' => 'Google Chrome/Chromium',
            'type' => 'alternative',
            'command' => ['google-chrome --version', 'chromium --version'],
            'required' => false
        ],
        'imagemagick' => [
            'name' => 'ImageMagick',
            'type' => 'alternative',
            'command' => 'convert -version',
            'required' => false
        ],
        'gd' => [
            'name' => 'GD Library',
            'type' => 'alternative',
            'check_function' => 'extension_loaded',
            'required' => false
        ],
        'phantomjs' => [
            'name' => 'PhantomJS',
            'type' => 'alternative',
            'command' => 'phantomjs --version',
            'required' => false
        ],
        'browsershot' => [
            'name' => 'Browsershot',
            'type' => 'alternative',
            'composer_package' => 'spatie/browsershot',
            'required' => false
        ]
    ];

    public function __construct($logger) {
        $this->logger = $logger;
    }

    public function detect_services($force_check = false) {
        try {
            if (!$force_check) {
                $cached = get_transient($this->cache_key);
                if ($cached !== false) {
                    return $cached;
                }
            }

            $results = [
                'timestamp' => time(),
                'services' => [],
                'installation_status' => $this->get_installation_status(),
                'quota_info' => $this->get_quota_info()
            ];

            foreach ($this->server_side_tools as $id => $tool) {
                if (get_option("sewn_installing_{$id}")) {
                    $results['services'][$id] = [
                        'available' => false,
                        'status' => 'installing'
                    ];
                    continue;
                }

                $status = $this->check_service($tool);
                $results['services'][$id] = $status;
            }

            set_transient($this->cache_key, $results, $this->cache_duration);
            return $results;

        } catch (Exception $e) {
            $this->logger->error('Service detection failed', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function check_service($tool) {
        $result = [
            'name' => $tool['name'],
            'type' => $tool['type'],
            'available' => false,
            'version' => null,
            'path' => null,
            'error' => null
        ];

        try {
            // Check for specific paths if defined
            if (isset($tool['paths'])) {
                foreach ($tool['paths'] as $path) {
                    if (file_exists($path) && is_executable($path)) {
                        $result['available'] = true;
                        $result['path'] = $path;
                        $version = $this->get_version($path);
                        if ($version) {
                            $result['version'] = $version;
                        }
                        break;
                    }
                }
            }

            // If no path found or no paths specified, try command
            if (!$result['available'] && isset($tool['command'])) {
                $commands = is_array($tool['command']) ? $tool['command'] : [$tool['command']];
                
                foreach ($commands as $command) {
                    $output = [];
                    $return_var = null;
                    
                    if (function_exists('exec') && !in_array('exec', explode(',', ini_get('disable_functions')))) {
                        @exec($command . ' 2>&1', $output, $return_var);
                        
                        if ($return_var === 0) {
                            $result['available'] = true;
                            if (!empty($output[0])) {
                                $result['version'] = trim($output[0]);
                            }
                            break;
                        }
                    }
                }
            }

        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
            $this->logger->error('Service detection failed', [
                'service' => $tool['name'],
                'error' => $e->getMessage()
            ]);
        }

        return $result;
    }

    private function get_version($path) {
        try {
            $output = [];
            $return_var = null;
            @exec(escapeshellcmd($path) . ' --version 2>&1', $output, $return_var);
            
            if ($return_var === 0 && !empty($output[0])) {
                return trim($output[0]);
            }
        } catch (Exception $e) {
            $this->logger->error('Version detection failed', [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
        }
        return null;
    }

    public function get_primary_service() {
        $services = $this->detect_services();
        
        foreach ($services['services'] as $id => $service) {
            if ($service['type'] === 'primary' && $service['available']) {
                return [
                    'id' => $id,
                    'details' => $service
                ];
            }
        }
        
        return null;
    }

    public function get_available_alternatives() {
        $services = $this->detect_services();
        $alternatives = [];
        
        foreach ($services['services'] as $id => $service) {
            if ($service['type'] === 'alternative' && $service['available']) {
                $alternatives[$id] = $service;
            }
        }
        
        return $alternatives;
    }

    public function clear_cache() {
        delete_transient($this->cache_key);
    }

    private function check_composer_package($package) {
        $composer_file = ABSPATH . 'vendor/composer/installed.json';
        if (!file_exists($composer_file)) {
            return false;
        }
        
        $installed = json_decode(file_get_contents($composer_file), true);
        $packages = $installed['packages'] ?? $installed;
        
        foreach ($packages as $installed_package) {
            if ($installed_package['name'] === $package) {
                return [
                    'version' => $installed_package['version'],
                    'path' => ABSPATH . 'vendor/' . $package
                ];
            }
        }
        
        return false;
    }

    public function enqueue_detection_script() {
        wp_enqueue_script(
            'sewn-tool-detector',
            SEWN_SCREENSHOTS_URL . 'assets/js/tool-detector.js',
            [],
            SEWN_SCREENSHOTS_VERSION,
            true
        );
        
        wp_localize_script('sewn-tool-detector', 'sewnDetector', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sewn_detect_tools')
        ]);
    }

    private function get_installation_status() {
        global $wpdb;
        $options = $wpdb->get_results(
            "SELECT option_name FROM {$wpdb->options} 
             WHERE option_name LIKE 'sewn_installing_%'"
        );
        
        $status = [];
        foreach ($options as $option) {
            $tool = str_replace('sewn_installing_', '', $option->option_name);
            $status[$tool] = 'installing';
        }
        return $status;
    }

    private function get_quota_info() {
        $quota_checker = new SEWN_API_Quota_Checker($this->logger);
        return [
            'screenshotmachine' => $quota_checker->check_screenshotmachine_quota(),
            'url2png' => $quota_checker->check_url2png_quota()
        ];
    }
} 