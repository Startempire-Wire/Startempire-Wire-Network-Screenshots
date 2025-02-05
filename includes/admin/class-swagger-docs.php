<?php
/**
 * Location: Admin → API Documentation
 * Dependencies: OpenAPI PHP Library
 * Variables & Classes: OA\OpenApi, SEWN_Swagger_Docs
 * 
 * Generates interactive API documentation using OpenAPI specification. Handles dynamic documentation
 * updates through AJAX endpoints. Secures API spec access with WordPress capability checks and
 * nonce validation.
 */

// Early exit if accessed directly
if (!defined('ABSPATH')) exit;

use OpenApi\Annotations as OA;
use OpenApi\Generator;

class SEWN_Swagger_Docs {
    /**
     * Logger instance
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct($logger = null) {
        $this->logger = $logger;
        add_action('wp_ajax_sewn_get_swagger_docs', array($this, 'handle_ajax_request'));
    }

    /**
     * Render the documentation page
     */
    public function render_docs_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        // Enqueue Swagger UI assets
        wp_enqueue_style(
            'swagger-ui-css',
            plugins_url('assets/swagger-ui/swagger-ui.css', dirname(dirname(__FILE__))),
            array(),
            '4.15.5'
        );
        
        wp_enqueue_script(
            'swagger-ui-bundle',
            plugins_url('assets/swagger-ui/swagger-ui-bundle.js', dirname(dirname(__FILE__))),
            array(),
            '4.15.5',
            true
        );
        
        wp_enqueue_script(
            'swagger-ui-standalone',
            plugins_url('assets/swagger-ui/swagger-ui-standalone-preset.js', dirname(dirname(__FILE__))),
            array('swagger-ui-bundle'),
            '4.15.5',
            true
        );

        // Generate nonce
        $nonce = wp_create_nonce('sewn_swagger_docs');
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('API Documentation', 'sewn-screenshots'); ?></h1>
            
            <!-- Loading indicator -->
            <div id="swagger-loading" class="notice notice-info">
                <p><?php echo esc_html__('Loading API documentation...', 'sewn-screenshots'); ?></p>
            </div>
            
            <!-- Error container -->
            <div id="swagger-error" class="notice notice-error" style="display:none;"></div>
            
            <!-- Swagger UI container -->
            <div id="swagger-ui"></div>
            
            <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function() {
                console.log('DOM loaded, initializing Swagger UI...');
                
                // Get references to DOM elements
                const loadingEl = document.getElementById('swagger-loading');
                const errorEl = document.getElementById('swagger-error');
                const swaggerEl = document.getElementById('swagger-ui');
                
                if (!swaggerEl) {
                    console.error('Swagger UI container not found');
                    return;
                }
                
                // Configuration
                const ajaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                const requestUrl = `${ajaxUrl}?action=sewn_get_swagger_docs&_ajax_nonce=<?php echo $nonce; ?>`;
                
                console.log('Request URL:', requestUrl);
                
                // Fetch the OpenAPI spec
                fetch(requestUrl, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json'
                    },
                    credentials: 'same-origin'
                })
                .then(response => {
                    console.log('Response received:', {
                        status: response.status,
                        statusText: response.statusText,
                        headers: Object.fromEntries(response.headers.entries())
                    });
                    
                    if (!response.ok) {
                        return response.text().then(text => {
                            throw new Error(`HTTP ${response.status}: ${text}`);
                        });
                    }
                    return response.json();
                })
                .then(spec => {
                    console.log('Spec loaded, initializing UI...');
                    loadingEl.style.display = 'none';
                    
                    // Initialize Swagger UI
                    const ui = SwaggerUIBundle({
                        spec: spec,
                        dom_id: '#swagger-ui',
                        deepLinking: true,
                        presets: [
                            SwaggerUIBundle.presets.apis,
                            SwaggerUIStandalonePreset
                        ],
                        plugins: [
                            SwaggerUIBundle.plugins.DownloadUrl
                        ],
                        layout: "StandaloneLayout"
                    });
                    
                    console.log('Swagger UI initialized');
                })
                .catch(error => {
                    console.error('Error:', error);
                    loadingEl.style.display = 'none';
                    errorEl.style.display = 'block';
                    errorEl.innerHTML = `
                        <p><strong>Error loading API documentation:</strong></p>
                        <p>${error.message}</p>
                        <pre style="overflow: auto; max-height: 200px;">${error.stack || ''}</pre>
                    `;
                });
            });
            </script>
        </div>
        <?php
    }

    public function handle_ajax_request() {
        try {
            // Enable error reporting for debugging
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
            
            check_ajax_referer('sewn_swagger_docs');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Permission denied', 403);
                return;
            }

            // Verify composer autoload exists
            $autoload_path = plugin_dir_path(dirname(dirname(__FILE__))) . 'vendor/autoload.php';
            if (!file_exists($autoload_path)) {
                throw new Exception('Composer autoload.php not found. Please run composer install.');
            }
            require_once $autoload_path;

            // Get path to REST controller
            $controller_path = plugin_dir_path(dirname(__FILE__)) . 'class-rest-controller.php';
            if (!file_exists($controller_path)) {
                throw new Exception('REST controller file not found: ' . $controller_path);
            }

            // Generate OpenAPI spec
            $openapi = Generator::scan([
                $controller_path
            ]);
            
            if (!$openapi) {
                throw new Exception('Failed to generate OpenAPI specification');
            }

            // Convert to array and validate
            $spec = json_decode($openapi->toJson(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Failed to encode OpenAPI spec: ' . json_last_error_msg());
            }
            
            // Log the spec for debugging
            if ($this->logger) {
                $this->logger->debug('Generated OpenAPI spec from annotations', [
                    'spec' => $spec
                ]);
            }
            
            // Send JSON response
            wp_send_json($spec);
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('AJAX request failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
            
            wp_send_json_error([
                'message' => 'Failed to generate API documentation: ' . $e->getMessage()
            ], 500);
        }
    }
} 