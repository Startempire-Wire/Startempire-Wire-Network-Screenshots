<?php
/**
 * Installation Tracker Class
 */

if (!defined('ABSPATH')) exit;

class SEWN_Installation_Tracker {
    private $logger;
    private $option_prefix = 'sewn_installation_';
    
    public function __construct($logger) {
        $this->logger = $logger;
        add_action('wp_ajax_sewn_installation_progress', [$this, 'get_progress']);
    }
    
    public function start_tracking($tool_id) {
        $status = [
            'started_at' => time(),
            'status' => 'installing',
            'progress' => 0,
            'current_step' => 'preparing',
            'steps_completed' => [],
            'errors' => []
        ];
        
        update_option($this->option_prefix . $tool_id, $status);
        return $status;
    }
    
    public function update_progress($tool_id, $progress, $step) {
        $status = get_option($this->option_prefix . $tool_id, []);
        $status['progress'] = $progress;
        $status['current_step'] = $step;
        $status['steps_completed'][] = $step;
        
        update_option($this->option_prefix . $tool_id, $status);
        do_action('sewn_installation_progress_updated', $tool_id, $status);
    }
} 