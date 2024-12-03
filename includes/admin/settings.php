<?php
if (!defined('ABSPATH')) exit;

class SEWN_Settings {
    public function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_settings() {
        register_setting('sewn_screenshots_options', 'sewn_api_key');
        register_setting('sewn_screenshots_options', 'sewn_rate_limit');
        // ... additional settings
    }
}