<?php
require_once dirname(__FILE__) . '/class-sewn-test-case.php';

class Test_Ajax_Handler extends SEWN_Test_Case {
    private $ajax_handler;
    private $logger;

    public function setUp(): void {
        parent::setUp();
        $this->logger = new SEWN_Logger();
        $screenshot_service = new SEWN_Screenshot_Service($this->logger);
        $test_results = new SEWN_Test_Results($this->logger);
        $this->ajax_handler = new SEWN_Ajax_Handler($screenshot_service, $this->logger, $test_results);
    }

    public function test_handle_ajax() {
        $_POST['nonce'] = wp_create_nonce('sewn_screenshot_nonce');
        $_POST['url'] = 'https://example.com';
        
        ob_start();
        $this->ajax_handler->handle_screenshot_request();
        $response = ob_get_clean();
        
        $this->assertTrue(strpos($response, 'success') !== false);
    }
} 