<?php
class SEWN_Screenshot_Bridge {
    private $node_script;
    private $logger;
    
    public function __construct($logger) {
        $this->node_script = SEWN_SCREENSHOTS_PATH . 'includes/services/screenshot.mjs';
        $this->logger = $logger;
    }
    
    public function take_screenshot($url, $options = []) {
        $cmd = sprintf(
            'node %s --url="%s" --output="%s" %s',
            escapeshellarg($this->node_script),
            escapeshellarg($url),
            escapeshellarg(SEWN_SCREENSHOTS_PATH . 'screenshots'),
            $this->build_options_string($options)
        );
        
        exec($cmd, $output, $return_var);
        
        if ($return_var !== 0) {
            $this->logger->error('Screenshot capture failed', [
                'url' => $url,
                'error' => implode("\n", $output)
            ]);
            throw new Exception('Screenshot capture failed');
        }
        
        return json_decode($output[0], true);
    }
    
    private function build_options_string($options) {
        $flags = [];
        foreach ($options as $key => $value) {
            $flags[] = sprintf('--%s=%s', $key, escapeshellarg($value));
        }
        return implode(' ', $flags);
    }
} 