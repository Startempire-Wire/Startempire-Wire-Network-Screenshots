<?php
if (!defined('ABSPATH')) exit;

class SEWN_Dashboard {
    private $plugin_slug = 'sewn-screenshots';
    private $logger;
    private $settings;
    private $api_tester;
    private $api_manager;

    public function __construct($logger, $settings, $api_tester = null, $api_manager = null) {
        $this->logger = $logger;
        $this->settings = $settings;
        $this->api_tester = $api_tester;
        $this->api_manager = $api_manager;
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    public function enqueue_admin_scripts($hook) {
        $this->logger->debug('Enqueuing admin scripts', ['hook' => $hook]);
        
        if (strpos($hook, $this->plugin_slug) === false) {
            return;
        }

        wp_enqueue_style(
            'sewn-admin',
            SEWN_SCREENSHOTS_URL . 'assets/css/admin.css',
            [],
            SEWN_SCREENSHOTS_VERSION
        );

        wp_enqueue_script(
            'sewn-admin',
            SEWN_SCREENSHOTS_URL . 'assets/js/admin.js',
            ['jquery'],
            SEWN_SCREENSHOTS_VERSION,
            true
        );

        wp_localize_script('sewn-admin', 'sewnAdmin', [
            'nonce' => wp_create_nonce('sewn_api_management'),
            'ajaxurl' => admin_url('admin-ajax.php')
        ]);
    }

    public function render_dashboard() {
        try {
            $this->logger->debug('Starting dashboard render');
            
            if (!$this->settings) {
                throw new Exception('Settings dependency not initialized');
            }
            
            $all_settings = $this->settings->get_all_settings();
            $storage_stats = $this->get_storage_stats();
            $cache_stats = $this->get_cache_stats();
            $recent_logs = $this->logger->get_recent_logs(10);
            $recent_screenshots = $this->get_recent_screenshots();
            $usage_stats = $this->get_usage_statistics();

            $this->logger->debug('Dashboard data retrieved', [
                'storage_stats' => $storage_stats,
                'cache_stats' => $cache_stats,
                'recent_screenshots_count' => count($recent_screenshots),
                'usage_stats' => $usage_stats
            ]);

            ?>
            <div class="wrap">
                <h1>Screenshot Service Dashboard</h1>

                <div class="sewn-dashboard-grid">
                    <!-- Quick Stats -->
                    <?php $this->logger->debug('Rendering quick stats section'); ?>
                    <div class="sewn-card">
                        <h2>Quick Statistics</h2>
                        <div class="sewn-stats-grid">
                            <div class="stat-item">
                                <span class="stat-label">Storage Used</span>
                                <span class="stat-value"><?php echo esc_html($storage_stats['used_formatted']); ?></span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">Total Screenshots</span>
                                <span class="stat-value"><?php echo esc_html($storage_stats['total_files']); ?></span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">Cache Hit Rate</span>
                                <span class="stat-value"><?php echo esc_html($storage_stats['cache_hit_rate']); ?>%</span>
                            </div>
                        </div>
                    </div>

                    <!-- API Key Section -->
                    <?php $this->logger->debug('Rendering API key section'); ?>
                    <div class="sewn-card">
                        <h2>API Key</h2>
                        <div class="sewn-api-key-wrapper">
                            <input type="text" readonly value="<?php echo esc_attr($all_settings['api_key']); ?>" class="regular-text">
                            <button type="button" class="button button-secondary" id="sewn-regenerate-key">
                                Regenerate Key
                            </button>
                            <?php wp_nonce_field('sewn_api_management', 'sewn_api_nonce'); ?>
                        </div>
                    </div>

                    <!-- Screenshot Types Stats -->
                    <?php $this->logger->debug('Rendering screenshot types section', ['stats' => $usage_stats]); ?>
                    <div class="sewn-card">
                        <h2>Screenshot Types</h2>
                        <div class="sewn-stats-grid">
                            <div class="stat-item">
                                <span class="stat-label">Preview Screenshots</span>
                                <span class="stat-value"><?php echo esc_html($usage_stats['preview_count']); ?></span>
                                <small>Lower quality with watermark</small>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">Full Screenshots</span>
                                <span class="stat-value"><?php echo esc_html($usage_stats['full_count']); ?></span>
                                <small>Premium high quality</small>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <?php $this->logger->debug('Rendering recent activity section', ['log_count' => count($recent_logs)]); ?>
                    <div class="sewn-card">
                        <h2>Recent Activity</h2>
                        <div class="sewn-log-viewer">
                            <?php foreach ($recent_logs as $log): ?>
                                <div class="sewn-log-entry">
                                    <?php echo esc_html($log); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php
            $this->logger->debug('Dashboard render completed successfully');
        } catch (Exception $e) {
            $this->logger->error('Dashboard render failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            echo '<div class="notice notice-error"><p>Error loading dashboard: ' . 
                 esc_html($e->getMessage()) . '</p></div>';
        }
    }

    public function render_settings() {
        $all_settings = $this->settings->get_all_settings();
        ?>
        <div class="wrap">
            <h1>Screenshot Service Settings</h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('sewn_screenshots_options'); ?>
                
                <div class="sewn-settings-grid">
                    <!-- Screenshot Settings -->
                    <div class="sewn-card">
                        <h2>Screenshot Settings</h2>
                        <table class="form-table">
                            <tr>
                                <th>Default Width</th>
                                <td><input type="number" name="sewn_default_width" 
                                         value="<?php echo esc_attr($all_settings['screenshot']['width']); ?>" min="100" max="2560"></td>
                            </tr>
                            <tr>
                                <th>Default Height</th>
                                <td><input type="number" name="sewn_default_height" 
                                         value="<?php echo esc_attr($all_settings['screenshot']['height']); ?>" min="100" max="2560"></td>
                            </tr>
                            <tr>
                                <th>Quality</th>
                                <td><input type="number" name="sewn_default_quality" 
                                         value="<?php echo esc_attr($all_settings['screenshot']['quality']); ?>" min="1" max="100"></td>
                            </tr>
                        </table>
                    </div>

                    <!-- Storage Settings -->
                    <div class="sewn-card">
                        <h2>Storage Settings</h2>
                        <table class="form-table">
                            <tr>
                                <th>Storage Limit (MB)</th>
                                <td><input type="number" name="sewn_storage_limit" 
                                         value="<?php echo esc_attr($all_settings['storage']['limit']); ?>" min="100"></td>
                            </tr>
                            <tr>
                                <th>Retention Period (Days)</th>
                                <td><input type="number" name="sewn_retention_days" 
                                         value="<?php echo esc_attr($all_settings['storage']['retention_days']); ?>" min="1"></td>
                            </tr>
                        </table>
                    </div>

                    <!-- Cache Settings -->
                    <div class="sewn-card">
                        <h2>Cache Settings</h2>
                        <table class="form-table">
                            <tr>
                                <th>Enable Caching</th>
                                <td><input type="checkbox" name="sewn_cache_enabled" 
                                         <?php checked($all_settings['cache']['enabled']); ?>></td>
                            </tr>
                            <tr>
                                <th>Cache Duration (Hours)</th>
                                <td><input type="number" name="sewn_cache_duration" 
                                         value="<?php echo esc_attr($all_settings['cache']['duration']); ?>" min="1"></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function render_statistics() {
        $storage_stats = $this->get_storage_stats();
        $cache_stats = $this->get_cache_stats();
        ?>
        <div class="wrap">
            <h1>Screenshot Service Statistics</h1>

            <div class="sewn-dashboard-grid">
                <!-- Storage Statistics -->
                <div class="sewn-card">
                    <h2>Storage Statistics</h2>
                    <div class="sewn-stats-grid">
                        <div class="stat-item">
                            <span class="stat-label">Total Storage Used</span>
                            <span class="stat-value"><?php echo esc_html($storage_stats['used_formatted']); ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Total Screenshots</span>
                            <span class="stat-value"><?php echo esc_html($storage_stats['total_files']); ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Average Size</span>
                            <span class="stat-value"><?php echo esc_html($storage_stats['avg_size_formatted']); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Cache Statistics -->
                <div class="sewn-card">
                    <h2>Cache Performance</h2>
                    <div class="sewn-stats-grid">
                        <div class="stat-item">
                            <span class="stat-label">Cache Hit Rate</span>
                            <span class="stat-value"><?php echo esc_html($cache_stats['hit_rate']); ?>%</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Cached Items</span>
                            <span class="stat-value"><?php echo esc_html($cache_stats['total_items']); ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Cache Size</span>
                            <span class="stat-value"><?php echo esc_html($cache_stats['size_formatted']); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Historical Data -->
                <div class="sewn-card">
                    <h2>Screenshot History</h2>
                    <div id="screenshot-history-chart"></div>
                </div>
            </div>
        </div>
        <?php
    }

    private function get_cache_stats() {
        global $wpdb;
        
        // Get cache statistics from WordPress transients
        $total_items = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE %s",
                $wpdb->esc_like('_transient_sewn_screenshot_') . '%'
            )
        );

        $cache_size = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(LENGTH(option_value)) FROM $wpdb->options WHERE option_name LIKE %s",
                $wpdb->esc_like('_transient_sewn_screenshot_') . '%'
            )
        );

        return [
            'hit_rate' => $this->calculate_cache_hit_rate(),
            'total_items' => (int)$total_items,
            'size_formatted' => size_format((int)$cache_size)
        ];
    }

    private function get_storage_stats() {
        $screenshots_dir = SEWN_SCREENSHOTS_PATH . 'screenshots';
        $total_size = 0;
        $total_files = 0;
        
        if (is_dir($screenshots_dir)) {
            $files = glob($screenshots_dir . '/*.[jJ][pP][gG]');
            $total_files = count($files);
            
            foreach ($files as $file) {
                $total_size += filesize($file);
            }
        }
        
        $avg_size = $total_files > 0 ? $total_size / $total_files : 0;
        
        return [
            'used_bytes' => $total_size,
            'used_formatted' => size_format($total_size),
            'total_files' => $total_files,
            'avg_size_formatted' => size_format($avg_size),
            'cache_hit_rate' => $this->calculate_cache_hit_rate()
        ];
    }

    private function calculate_cache_hit_rate() {
        $hits = get_option('sewn_cache_hits', 0);
        $misses = get_option('sewn_cache_misses', 0);
        $total = $hits + $misses;
        
        return $total > 0 ? round(($hits / $total) * 100, 2) : 0;
    }

    private function get_recent_screenshots($limit = 10) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sewn_screenshots';
        
        $screenshots = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT url, type, size, created_at, status 
                FROM {$table_name} 
                WHERE type IN ('preview', 'full') 
                ORDER BY created_at DESC 
                LIMIT %d",
                $limit
            )
        );

        if (!$screenshots) {
            $this->logger->debug('No recent screenshots found');
            return [];
        }

        $this->logger->debug('Retrieved recent screenshots', [
            'count' => count($screenshots),
            'types' => array_column($screenshots, 'type')
        ]);

        return $screenshots;
    }

    private function get_usage_statistics() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sewn_screenshots';
        
        // Create table if it doesn't exist
        $this->create_screenshots_table();
        
        $stats = [
            'total_screenshots' => 0,
            'preview_count' => 0,
            'full_count' => 0,
            'total_size' => 0,
            'cache_hits' => 0
        ];
        
        try {
            $stats['total_screenshots'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table_name");
            $stats['preview_count'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE type = 'preview'");
            $stats['full_count'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE type = 'full'");
            $stats['total_size'] = (int)$wpdb->get_var("SELECT SUM(size) FROM $table_name");
            $stats['cache_hits'] = (int)$wpdb->get_var("SELECT SUM(cache_hits) FROM $table_name");
        } catch (Exception $e) {
            $this->logger->error('Failed to get usage statistics', ['error' => $e->getMessage()]);
        }
        
        return $stats;
    }

    private function create_screenshots_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sewn_screenshots';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            url varchar(2083) NOT NULL,
            type varchar(10) NOT NULL,
            size bigint(20) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            status varchar(20) NOT NULL,
            cache_hits int(11) DEFAULT 0,
            file_path varchar(255) NOT NULL,
            PRIMARY KEY  (id),
            KEY url (url(191)),
            KEY type (type),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}