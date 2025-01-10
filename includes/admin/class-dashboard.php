<?php
if (!defined('ABSPATH')) exit;

class SEWN_Dashboard {
    private $plugin_slug = 'sewn-screenshots';
    private $logger;
    private $settings;
    private $api_tester;
    private $api_manager;
    private $screenshot_service;

    public function __construct($logger, $settings, $api_tester, $api_manager) {
        $this->logger = $logger;
        $this->settings = $settings;
        $this->api_tester = $api_tester;
        $this->api_manager = $api_manager;
        
        // Ensure the screenshots table exists
        add_action('admin_init', [$this, 'create_screenshots_table']);

        add_action('wp_ajax_sewn_delete_screenshot', [$this, 'handle_delete_screenshot']);

        // Register carousel assets
        add_action('admin_enqueue_scripts', [$this, 'register_carousel_assets']);
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

            // Fetch existing stats
            $cache_stats = $this->get_cache_stats(); // already calls calculate_cache_hit_rate()
            $storage_stats = $this->get_storage_stats(); // for total files, size, etc.

            // Gather API key info
            $current_service = $this->api_manager ? $this->api_manager->get_current_service() : '';
            $current_api_key = $this->api_manager ? $this->api_manager->get_current_api_key() : '';

            // Determine screenshot types (local plus any fallback)
            $available_types = [];
            if ($this->screenshot_service && $this->screenshot_service->is_local_enabled()) {
                $available_types[] = 'Local (wkhtmltoimage)';
            }
            if ($this->api_manager) {
                foreach ($this->api_manager->get_available_fallback_services() as $slug => $service) {
                    if (!$service['requires_key'] || get_option('sewn_fallback_api_key', '')) {
                        $available_types[] = $service['name'] . ' (' . $slug . ')';
                    }
                }
            }

            // Fetch recent activity
            $recent_requests = [];
            if ($this->api_manager && method_exists($this->api_manager, 'get_recent_requests')) {
                $recent_requests = $this->api_manager->get_recent_requests(5);
            }

            $stats = $this->get_usage_statistics();
            $api_status = $this->get_api_key_status();
            $types = $this->get_screenshot_types();
            $recent = $this->get_recent_activity();

            ?>
            <div class="wrap">
                <h1>Screenshot Service Dashboard</h1>

                <div class="sewn-dashboard-grid">
                    <!-- Quick Stats -->
                    <?php $this->logger->debug('Rendering quick stats section'); ?>
                    <div class="sewn-card">
                        <h2>Quick Statistics</h2>
                        <div class="stats-grid">
                            <div class="stat-item">
                                <span class="stat-value"><?php echo esc_html($stats['total_screenshots']); ?></span>
                                <span class="stat-label">Total Screenshots</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value"><?php echo esc_html($stats['success_rate']); ?>%</span>
                                <span class="stat-label">Success Rate</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value"><?php echo esc_html($stats['api_usage']); ?></span>
                                <span class="stat-label">API Calls This Month</span>
                            </div>
                        </div>
                    </div>

                    <!-- API Key Status -->
                    <?php $this->logger->debug('Rendering API key section'); ?>
                    <div class="sewn-card">
                        <h2>API Key Status</h2>
                        <div class="api-status">
                            <div class="key-status <?php echo esc_attr($api_status['primary_key']['status']); ?>">
                                <span class="status-dot"></span>
                                <span>Primary API Key: <?php echo $api_status['primary_key']['exists'] ? 'Active' : 'Not Set'; ?></span>
                            </div>
                            <div class="key-status <?php echo esc_attr($api_status['fallback_key']['status']); ?>">
                                <span class="status-dot"></span>
                                <span>Fallback Key (<?php echo esc_html($api_status['fallback_key']['provider']); ?>): 
                                      <?php echo $api_status['fallback_key']['exists'] ? 'Active' : 'Not Set'; ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Screenshot Types -->
                    <?php $this->logger->debug('Rendering screenshot types section', ['stats' => $usage_stats]); ?>
                    <div class="sewn-card">
                        <h2>Screenshot Types</h2>
                        <div class="types-grid">
                            <?php foreach ($types as $type): ?>
                            <div class="type-item">
                                <h3><?php echo esc_html(ucfirst($type['type'])); ?></h3>
                                <div class="type-stats">
                                    <span>Count: <?php echo esc_html($type['count']); ?></span>
                                    <span>Success: <?php echo esc_html($type['success_rate']); ?>%</span>
                                    <span>Avg Time: <?php echo esc_html($type['avg_time']); ?>s</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <?php $this->logger->debug('Rendering recent activity section', ['log_count' => count($recent_logs)]); ?>
                    <div class="sewn-card">
                        <h2>Recent Activity</h2>
                        <div class="activity-list">
                            <?php foreach ($recent as $activity): ?>
                            <div class="activity-item <?php echo esc_attr($activity['status']); ?>">
                                <span class="activity-time"><?php echo esc_html(human_time_diff(strtotime($activity['created_at']))); ?> ago</span>
                                <span class="activity-url"><?php echo esc_html($activity['url']); ?></span>
                                <span class="activity-type"><?php echo esc_html($activity['type']); ?></span>
                                <span class="activity-status"><?php echo esc_html(ucfirst($activity['status'])); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Recent Screenshots -->
                    <?php $this->logger->debug('Rendering recent screenshots section'); ?>
                    <?php $this->render_screenshots_carousel(); ?>
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

    public function create_screenshots_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sewn_screenshots';
        
        // First, check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        
        if (!$table_exists) {
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                url varchar(2083) NOT NULL,
                type varchar(50) NOT NULL DEFAULT 'full',
                status varchar(20) NOT NULL DEFAULT 'pending',
                processing_time float DEFAULT 0,
                error_message text DEFAULT NULL,
                screenshot_path varchar(255) DEFAULT NULL,
                thumbnail_path varchar(255) DEFAULT NULL,
                image_path varchar(255) DEFAULT NULL,
                file_size bigint(20) DEFAULT 0,
                cached tinyint(1) DEFAULT 0,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY url (url(191)),
                KEY type (type),
                KEY status (status),
                KEY created_at (created_at),
                KEY cached (cached)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            
            $this->logger->debug('Screenshots table created');
        }
        
        // Check and add missing columns regardless of whether table was just created
        $columns = $wpdb->get_col("DESCRIBE {$table_name}");
        
        $required_columns = [
            'error_message' => "ADD COLUMN error_message text DEFAULT NULL",
            'cached' => "ADD COLUMN cached tinyint(1) DEFAULT 0",
            'processing_time' => "ADD COLUMN processing_time float DEFAULT 0",
            'file_size' => "ADD COLUMN file_size bigint(20) DEFAULT 0",
            'screenshot_path' => "ADD COLUMN screenshot_path varchar(255) DEFAULT NULL",
            'thumbnail_path' => "ADD COLUMN thumbnail_path varchar(255) DEFAULT NULL",
            'image_path' => "ADD COLUMN image_path varchar(255) DEFAULT NULL",
            'type' => "ADD COLUMN type varchar(50) NOT NULL DEFAULT 'full'",
            'status' => "ADD COLUMN status varchar(20) NOT NULL DEFAULT 'pending'"
        ];
        
        foreach ($required_columns as $column => $definition) {
            if (!in_array($column, $columns)) {
                $wpdb->query("ALTER TABLE {$table_name} {$definition}");
                $this->logger->debug("Added column {$column} to screenshots table");
            }
        }
        
        // Verify all columns were added successfully
        $final_columns = $wpdb->get_col("DESCRIBE {$table_name}");
        $missing_columns = array_diff(array_keys($required_columns), $final_columns);
        
        if (!empty($missing_columns)) {
            $this->logger->error('Failed to add some columns', [
                'missing_columns' => $missing_columns
            ]);
        } else {
            $this->logger->debug('All required columns verified');
        }
    }

    private function insert_sample_data() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sewn_screenshots';
        
        // Only insert if table is empty
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        if ($count == 0) {
            $sample_data = [
                [
                    'url' => 'https://example.com',
                    'type' => 'full',
                    'status' => 'success',
                    'processing_time' => 2.5,
                    'created_at' => current_time('mysql')
                ],
                [
                    'url' => 'https://example.org',
                    'type' => 'preview',
                    'status' => 'success',
                    'processing_time' => 1.8,
                    'created_at' => current_time('mysql')
                ],
                [
                    'url' => 'https://test.com',
                    'type' => 'full',
                    'status' => 'failed',
                    'processing_time' => 0,
                    'error_message' => 'Connection timeout',
                    'created_at' => current_time('mysql')
                ]
            ];
            
            foreach ($sample_data as $data) {
                $wpdb->insert($table_name, $data);
            }
        }
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
        global $wpdb;
        $table_name = $wpdb->prefix . 'sewn_api_logs';
        $hits = (int) $wpdb->get_var("SELECT SUM(cache_hits) FROM {$table_name}");
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        if ($total === 0) {
            return 0;
        }
        return round(($hits / $total) * 100, 2);
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
        
        $stats = [
            'total_screenshots' => 0,
            'cached_screenshots' => 0,
            'failed_requests' => 0,
            'success_rate' => 0,
            'api_usage' => 0
        ];
        
        try {
            // Get total screenshots
            $stats['total_screenshots'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE 1=%d", 
                1
            ));
            
            // Get cached screenshots
            $stats['cached_screenshots'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE cached = %d",
                1
            ));
            
            // Get failed requests
            $stats['failed_requests'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE status = %s",
                'failed'
            ));
            
            // Calculate success rate
            if ($stats['total_screenshots'] > 0) {
                $successful = $stats['total_screenshots'] - $stats['failed_requests'];
                $stats['success_rate'] = round(($successful / $stats['total_screenshots']) * 100);
            }
            
            // Get current month's API usage
            $stats['api_usage'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} 
                WHERE MONTH(created_at) = MONTH(%s) 
                AND YEAR(created_at) = YEAR(%s)",
                current_time('mysql'),
                current_time('mysql')
            ));
            
        } catch (Exception $e) {
            $this->logger->error('Error fetching usage statistics', [
                'error' => $e->getMessage()
            ]);
        }
        
        return $stats;
    }

    private function get_cache_hits() {
        // Wrap the query to avoid errors if 'cache_hits' column is missing
        global $wpdb;
        $table_name = $wpdb->prefix . 'sewn_api_logs';
        $col_exists = $wpdb->get_row("SHOW COLUMNS FROM `{$table_name}` LIKE 'cache_hits'");
        if (!$col_exists) {
            return 0;
        }
        return (int) $wpdb->get_var("SELECT SUM(cache_hits) FROM `{$table_name}`");
    }

    private function get_api_key_status() {
        $api_key = get_option('sewn_api_key', '');
        $fallback_key = get_option('sewn_fallback_api_key', '');
        $active_mode = $this->api_manager->get_active_api_mode();
        
        return [
            'primary_key' => [
                'exists' => !empty($api_key),
                'last_used' => get_option('sewn_api_key_last_used', ''),
                'status' => ($active_mode === 'primary' && $this->api_manager->verify_api_key($api_key)) ? 'active' : 'inactive'
            ],
            'fallback_key' => [
                'exists' => !empty($fallback_key),
                'provider' => get_option('sewn_fallback_service', 'none'),
                'status' => ($active_mode === 'fallback' && $this->api_manager->verify_fallback_key($fallback_key)) ? 'active' : 'inactive'
            ]
        ];
    }

    public function get_screenshot_types() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sewn_screenshots';

        try {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT 
                    type,
                    COUNT(*) as count,
                    SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) as successful,
                    AVG(CASE WHEN processing_time > 0 THEN processing_time ELSE NULL END) as avg_time
                 FROM {$table_name}
                 WHERE created_at >= DATE_SUB(%s, INTERVAL 30 DAY)
                 GROUP BY type",
                'success',
                current_time('mysql')
            ), ARRAY_A);

            // Add success_rate to each row
            foreach ($results as &$row) {
                $count = (int) $row['count'];
                $successful = (int) $row['successful'];
                $row['success_rate'] = $count > 0 ? round(($successful / $count) * 100, 2) : 0;
            }

            return $results;
        } catch (Exception $e) {
            $this->logger->error('Error fetching screenshot types', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    private function get_recent_activity($limit = 5) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sewn_screenshots';
        
        try {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT 
                    url,
                    type,
                    status,
                    created_at,
                    processing_time,
                    error_message,
                    file_size
                FROM {$table_name}
                ORDER BY created_at DESC
                LIMIT %d",
                $limit
            ), ARRAY_A);
        } catch (Exception $e) {
            $this->logger->error('Error fetching recent activity', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    private function get_recent_screenshots_gallery() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sewn_screenshots';
        
        $screenshots = $wpdb->get_results(
            "SELECT * FROM {$table_name} 
             WHERE status = 'success' 
             AND thumbnail_path IS NOT NULL 
             ORDER BY created_at DESC 
             LIMIT 50"
        );

        return array_map(function($screenshot) {
            return [
                'id' => $screenshot->id,
                'url' => $screenshot->url,
                'thumbnail_url' => plugin_dir_url(SEWN_SCREENSHOTS_FILE) . 'screenshots/' . basename($screenshot->thumbnail_path),
                'full_image_url' => plugin_dir_url(SEWN_SCREENSHOTS_FILE) . 'screenshots/' . basename($screenshot->image_path),
                'created_at' => $screenshot->created_at
            ];
        }, $screenshots);
    }

    public function render_screenshots_carousel() {
        try {
            global $wpdb;
            $table_name = $wpdb->prefix . 'sewn_screenshots';

            // Get total count
            $total_screenshots = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
            
            // Get screenshots with service info
            $screenshots = $wpdb->get_results("
                SELECT * FROM {$table_name} 
                WHERE screenshot_path IS NOT NULL 
                ORDER BY created_at DESC 
                LIMIT 50
            ");

            if (empty($screenshots)) {
                echo '<div class="notice notice-info"><p>No screenshots available yet.</p></div>';
                return;
            }

            ?>
            <div class="sewn-card screenshots-card" id="sewn-screenshots-widget">
                <h2>Recent Screenshots (<?php echo number_format($total_screenshots); ?> Total)</h2>
                <div class="screenshots-carousel-wrapper" 
                     data-total="<?php echo esc_attr($total_screenshots); ?>"
                     data-page="1">
                    <button class="carousel-arrow prev" type="button">
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                    </button>
                    
                    <div class="screenshots-carousel horizontal" data-infinite="true">
                        <?php foreach ($screenshots as $screenshot): 
                            $file_path = $screenshot->screenshot_path;
                            $file_exists = file_exists($file_path);
                            
                            if (!$file_exists) {
                                continue;
                            }

                            $thumbnail_url = $this->get_screenshot_url($file_path);
                            $full_image_url = $this->get_screenshot_url($file_path);
                        ?>
                            <div class="screenshot-item" 
                                 data-id="<?php echo esc_attr($screenshot->id); ?>"
                                 data-url="<?php echo esc_url($screenshot->url); ?>"
                                 data-image="<?php echo esc_url($full_image_url); ?>">
                                <img src="<?php echo esc_url($thumbnail_url); ?>" 
                                     alt="Screenshot of <?php echo esc_attr($screenshot->url); ?>"
                                     class="screenshot-thumbnail"
                                     loading="lazy">
                                <div class="screenshot-meta">
                                    <span class="screenshot-date">
                                        <?php echo human_time_diff(strtotime($screenshot->created_at), current_time('timestamp')); ?> ago
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button class="carousel-arrow next" type="button">
                        <span class="dashicons dashicons-arrow-right-alt2"></span>
                    </button>
                </div>

                <!-- Screenshots Table -->
                <div class="screenshots-table-wrapper">
                    <table class="wp-list-table widefat fixed striped screenshots-table">
                        <thead>
                            <tr>
                                <th class="column-thumbnail">Preview</th>
                                <th class="column-service">Service</th>
                                <th class="column-date">Date Created</th>
                                <th class="column-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($screenshots as $screenshot): 
                                $thumbnail_url = $this->get_screenshot_url($screenshot->screenshot_path);
                                $service = !empty($screenshot->service) ? $screenshot->service : 'Local';
                                $date = human_time_diff(strtotime($screenshot->created_at), current_time('timestamp')) . ' ago';
                            ?>
                                <tr data-id="<?php echo esc_attr($screenshot->id); ?>">
                                    <td class="column-thumbnail">
                                        <img src="<?php echo esc_url($thumbnail_url); ?>" 
                                             alt="Thumbnail" 
                                             width="30" 
                                             height="30"
                                             class="screenshot-mini-thumb">
                                    </td>
                                    <td class="column-service">
                                        <?php echo esc_html($service); ?>
                                    </td>
                                    <td class="column-date">
                                        <?php echo esc_html($date); ?>
                                    </td>
                                    <td class="column-actions">
                                        <button class="button button-small action-button copy-url" 
                                                data-url="<?php echo esc_url($screenshot->url); ?>"
                                                title="Copy URL">
                                            <span class="dashicons dashicons-admin-links"></span>
                                            <span class="sewn-tooltip">Copy screenshot URL</span>
                                        </button>
                                        <button class="button button-small action-button view-screenshot"
                                                data-image="<?php echo esc_url($this->get_screenshot_url($screenshot->screenshot_path)); ?>"
                                                title="View">
                                            <span class="dashicons dashicons-visibility"></span>
                                            <span class="sewn-tooltip">View full screenshot</span>
                                        </button>
                                        <button class="button button-small action-button delete-screenshot"
                                                data-id="<?php echo esc_attr($screenshot->id); ?>"
                                                title="Delete">
                                            <span class="dashicons dashicons-trash"></span>
                                            <span class="sewn-tooltip">Delete screenshot</span>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Screenshots Modal -->
                <div id="screenshot-modal" class="sewn-modal">
                    <div class="sewn-modal-content">
                        <div class="sewn-modal-header">
                            <h3>Screenshot Preview</h3>
                            <div class="sewn-modal-actions">
                                <button class="button copy-url" title="Copy URL">
                                    <span class="dashicons dashicons-admin-links"></span>
                                </button>
                                <button class="button delete-screenshot" title="Delete">
                                    <span class="dashicons dashicons-trash"></span>
                                </button>
                                <button class="button close-modal" title="Close">
                                    <span class="dashicons dashicons-no"></span>
                                </button>
                            </div>
                        </div>
                        <div class="sewn-modal-body">
                            <img src="" alt="Full Screenshot" class="full-screenshot">
                        </div>
                    </div>
                </div>
            </div>
            <?php

        } catch (Exception $e) {
            $this->logger->error('Failed to render screenshots carousel', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function get_screenshot_url($file_path) {
        if (empty($file_path)) {
            $this->logger->warning('Empty file path provided to get_screenshot_url');
            return '';
        }

        $upload_dir = wp_upload_dir();
        $base_path = $upload_dir['basedir'];
        $base_url = $upload_dir['baseurl'];
        
        // Check if file is in uploads directory
        if (strpos($file_path, $base_path) === false) {
            // If not, check if it's in the plugin's screenshots directory
            $screenshots_dir = SEWN_SCREENSHOTS_PATH . 'screenshots';
            $screenshots_url = SEWN_SCREENSHOTS_URL . 'screenshots';
            $url = str_replace($screenshots_dir, $screenshots_url, $file_path);
        } else {
            $url = str_replace($base_path, $base_url, $file_path);
        }
        
        $this->logger->debug('URL conversion', [
            'file_path' => $file_path,
            'base_path' => $base_path,
            'base_url' => $base_url,
            'resulting_url' => $url
        ]);
        
        return $url;
    }

    public function handle_delete_screenshot() {
        try {
            check_ajax_referer('sewn_api_management', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception('Insufficient permissions');
            }

            $screenshot_id = intval($_POST['screenshot_id']);
            
            global $wpdb;
            $table_name = $wpdb->prefix . 'sewn_screenshots';
            
            // Get screenshot info before deletion
            $screenshot = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE id = %d",
                $screenshot_id
            ));

            if (!$screenshot) {
                throw new Exception('Screenshot not found');
            }

            // Delete physical files
            if (!empty($screenshot->screenshot_path) && file_exists($screenshot->screenshot_path)) {
                unlink($screenshot->screenshot_path);
            }
            if (!empty($screenshot->thumbnail_path) && file_exists($screenshot->thumbnail_path)) {
                unlink($screenshot->thumbnail_path);
            }

            // Delete database record
            $result = $wpdb->delete($table_name, ['id' => $screenshot_id], ['%d']);

            if ($result === false) {
                throw new Exception('Failed to delete database record');
            }

            // Log deletion
            $this->logger->info('Screenshot deleted', [
                'screenshot_id' => $screenshot_id,
                'url' => $screenshot->url,
                'user_id' => get_current_user_id()
            ]);

            wp_send_json_success([
                'message' => 'Screenshot deleted successfully',
                'id' => $screenshot_id
            ]);

        } catch (Exception $e) {
            $this->logger->error('Screenshot deletion failed', [
                'error' => $e->getMessage(),
                'screenshot_id' => $screenshot_id ?? null
            ]);
            
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function register_carousel_assets() {
        // Register styles
        wp_register_style(
            'sewn-dashboard-carousel',
            SEWN_SCREENSHOTS_URL . 'assets/css/admin.css',
            [],
            SEWN_SCREENSHOTS_VERSION
        );

        // Register scripts with proper dependencies
        wp_register_script(
            'sewn-dashboard-carousel',
            SEWN_SCREENSHOTS_URL . 'assets/js/dashboard-carousel.js',
            ['jquery', 'wp-util'],
            SEWN_SCREENSHOTS_VERSION,
            true
        );

        // Localize script with necessary data
        wp_localize_script('sewn-dashboard-carousel', 'sewnDashboard', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sewn_api_management'),
            'debug' => WP_DEBUG
        ]);

        // Enqueue both on our admin page
        wp_enqueue_style('sewn-dashboard-carousel');
        wp_enqueue_script('sewn-dashboard-carousel');
    }
}