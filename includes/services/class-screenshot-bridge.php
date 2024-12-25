<?php
class SEWN_Screenshot_Bridge {
    private $node_script;
    private $logger;
    
    public function __construct($logger) {
        $this->node_script = SEWN_SCREENSHOTS_PATH . 'includes/services/screenshot.mjs';
        $this->logger = $logger;
    }
    
    public function take_screenshot($url, $options = []) {
        $this->logger->debug('Taking screenshot', [
            'url' => $url,
            'options' => $options
        ]);

        try {
            // Validate URL
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new Exception('Invalid URL provided');
            }

            // Validate options
            $options = $this->validate_options($options);

            // Check if Node.js script exists
            if (!file_exists($this->node_script)) {
                throw new Exception('Screenshot service script not found');
            }

            $cmd = sprintf(
                'node %s --url="%s" --output="%s" %s',
                escapeshellarg($this->node_script),
                escapeshellarg($url),
                escapeshellarg(SEWN_SCREENSHOTS_PATH . 'screenshots'),
                $this->build_options_string($options)
            );
            
            $this->logger->debug('Executing command', ['cmd' => $cmd]);
            
            exec($cmd, $output, $return_var);
            
            if ($return_var !== 0) {
                throw new Exception('Screenshot capture failed: ' . implode("\n", $output));
            }
            
            $result = json_decode($output[0], true);
            if (!$result) {
                throw new Exception('Invalid response from screenshot service');
            }
            
            $this->logger->debug('Screenshot captured successfully', ['result' => $result]);
            return $result;
        } catch (Exception $e) {
            $this->logger->error('Screenshot capture failed', [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    
    private function build_options_string($options) {
        $flags = [];
        foreach ($options as $key => $value) {
            $flags[] = sprintf('--%s=%s', $key, escapeshellarg($value));
        }
        return implode(' ', $flags);
    }

    private function validate_options($options) {
        $defaults = [
            'width' => get_option('sewn_default_width', 1280),
            'height' => get_option('sewn_default_height', 800),
            'quality' => get_option('sewn_default_quality', 85),
            'format' => get_option('sewn_default_format', 'jpg')
        ];

        $options = wp_parse_args($options, $defaults);

        // Validate dimensions
        $options['width'] = min(max((int)$options['width'], 100), 2560);
        $options['height'] = min(max((int)$options['height'], 100), 2560);
        $options['quality'] = min(max((int)$options['quality'], 1), 100);

        return $options;
    }
} 