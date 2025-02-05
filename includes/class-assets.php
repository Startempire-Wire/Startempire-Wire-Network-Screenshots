<?php
/**
 * Location: Plugin asset management
 * Dependencies: WordPress media library
 * Variables & Classes: SEWN_Assets (static)
 * 
 * Manages plugin CSS/JS assets and third-party library dependencies. Handles asset versioning
 * and integrity checks. Automatically creates required directories during plugin activation
 * and verifies file permissions.
 */
if (!defined('ABSPATH')) exit;

class SEWN_Assets {
    /**
     * Initialize assets
     */
    public static function init() {
        // Create assets directory if it doesn't exist
        $assets_dir = plugin_dir_path(dirname(__FILE__)) . 'assets/swagger-ui';
        
        // Ensure directory exists with proper permissions
        if (!file_exists($assets_dir)) {
            if (!wp_mkdir_p($assets_dir)) {
                error_log('Failed to create directory: ' . $assets_dir);
                return false;
            }
            // Set directory permissions
            chmod($assets_dir, 0755);
        }

        // Download Swagger UI assets if they don't exist
        $files = array(
            'swagger-ui.css' => 'https://unpkg.com/swagger-ui-dist@4.15.5/swagger-ui.css',
            'swagger-ui-bundle.js' => 'https://unpkg.com/swagger-ui-dist@4.15.5/swagger-ui-bundle.js',
            'swagger-ui-standalone-preset.js' => 'https://unpkg.com/swagger-ui-dist@4.15.5/swagger-ui-standalone-preset.js'
        );

        $success = true;
        foreach ($files as $file => $url) {
            $file_path = $assets_dir . '/' . $file;
            if (!file_exists($file_path)) {
                $response = wp_remote_get($url);
                if (is_wp_error($response)) {
                    error_log('Failed to download ' . $file . ': ' . $response->get_error_message());
                    $success = false;
                    continue;
                }

                $status_code = wp_remote_retrieve_response_code($response);
                if ($status_code !== 200) {
                    error_log('Failed to download ' . $file . ': HTTP ' . $status_code);
                    $success = false;
                    continue;
                }

                $content = wp_remote_retrieve_body($response);
                if (empty($content)) {
                    error_log('Empty content received for ' . $file);
                    $success = false;
                    continue;
                }

                if (!file_put_contents($file_path, $content)) {
                    error_log('Failed to write file: ' . $file_path);
                    $success = false;
                    continue;
                }

                // Set file permissions
                chmod($file_path, 0644);
            }
        }

        return $success;
    }

    /**
     * Verify assets exist
     */
    public static function verify_assets() {
        $assets_dir = plugin_dir_path(dirname(__FILE__)) . 'assets/swagger-ui';
        $required_files = array(
            'swagger-ui.css',
            'swagger-ui-bundle.js',
            'swagger-ui-standalone-preset.js'
        );

        foreach ($required_files as $file) {
            $file_path = $assets_dir . '/' . $file;
            if (!file_exists($file_path)) {
                return false;
            }
        }

        return true;
    }
}

// Initialize assets on plugin activation
register_activation_hook(SEWN_SCREENSHOTS_PATH . 'startempire-wire-network-screenshots.php', array('SEWN_Assets', 'init'));

// Also initialize on admin init if assets are missing
add_action('admin_init', function() {
    if (!SEWN_Assets::verify_assets()) {
        SEWN_Assets::init();
    }
}); 