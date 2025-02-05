<?php
/**
 * Location: Frontend/Admin AJAX
 * Dependencies: SEWN_Screenshot_Service
 * Variables & Classes: $nonce_action, SEWN_Ajax_Handler
 * 
 * Processes screenshot capture requests from both admin and frontend interfaces. Validates user
 * permissions and request nonces for security. Handles error logging and JSON response formatting
 * for client-side error handling.
 */
class SEWN_Ajax_Handler {
    private $screenshot_service;
    private $logger;
    private $nonce_action = 'sewn_ajax_nonce';

    public function __construct() {
        $this->logger = new SEWN_Logger();
        $this->screenshot_service = new SEWN_Screenshot_Service($this->logger);
        
        // Add AJAX actions
        add_action('wp_ajax_sewn_run_all_tests', array($this, 'handle_run_all_tests'));
        
        // Add nonce to admin footer
        add_action('admin_footer', array($this, 'add_nonce_to_footer'));
    }

    public function add_nonce_to_footer() {
        ?>
        <script type="text/javascript">
            var sewnAjaxNonce = '<?php echo wp_create_nonce($this->nonce_action); ?>';
        </script>
        <?php
    }

    public function handle_run_all_tests() {
        try {
            // Verify nonce
            if (!check_ajax_referer($this->nonce_action, 'nonce', false)) {
                $this->logger->error('Invalid nonce in test execution request');
                wp_send_json_error(['message' => 'Invalid security token'], 403);
                return;
            }

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'Insufficient permissions'], 403);
                return;
            }

            $this->logger->info('Starting test execution');
            
            // Load test class
            require_once SEWN_PLUGIN_DIR . 'tests/test-screenshot-service.php';
            
            // Initialize test suite with logger
            $test_suite = new Test_Screenshot_Service();
            $test_suite->setUp();
            
            // Get test methods
            $test_methods = array_filter(
                get_class_methods($test_suite),
                function($method) {
                    return strpos($method, 'test_') === 0;
                }
            );

            if (empty($test_methods)) {
                throw new Exception('No test methods found');
            }

            $results = [];
            foreach ($test_methods as $test_method) {
                try {
                    $test_suite->$test_method();
                    $results[$test_method] = [
                        'status' => 'passed',
                        'message' => 'Test passed successfully'
                    ];
                    $this->logger->info("Test passed: $test_method");
                } catch (Exception $e) {
                    $results[$test_method] = [
                        'status' => 'failed',
                        'message' => $e->getMessage()
                    ];
                    $this->logger->error("Test failed: $test_method - " . $e->getMessage());
                }
            }

            $test_suite->tearDown();

            wp_send_json_success([
                'results' => $results,
                'total' => count($test_methods),
                'passed' => count(array_filter($results, function($r) { 
                    return $r['status'] === 'passed'; 
                }))
            ]);
            
        } catch (Exception $e) {
            $this->logger->error('Test execution failed: ' . $e->getMessage());
            wp_send_json_error([
                'message' => $e->getMessage()
            ], 500);
        }
    }
} 