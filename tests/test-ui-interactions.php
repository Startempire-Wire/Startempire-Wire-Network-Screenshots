<?php
require_once dirname(__FILE__) . '/class-sewn-test-case.php';

class Test_UI_Interactions extends SEWN_Test_Case {
    private $screenshot_service;
    private $logger;
    private $admin;

    public function setUp(): void {
        parent::setUp();
        $this->logger = new SEWN_Logger();
        $this->screenshot_service = new SEWN_Screenshot_Service($this->logger);
        $this->admin = new SEWN_Admin($this->screenshot_service, $this->logger);
    }

    public function test_render_tester_page() {
        ob_start();
        $this->admin->render_tester_page();
        $output = ob_get_clean();
        
        $this->assertTrue(strpos($output, 'Screenshot Tester') !== false);
    }

    public function test_admin_page_render() {
        ob_start();
        $this->admin->render_tester_page();
        $output = ob_get_clean();

        // Check for required UI elements
        $this->assertStringContainsString('sewn-test-section', $output);
        $this->assertStringContainsString('sewn-test-url', $output);
        $this->assertStringContainsString('sewn-test-button', $output);
        $this->assertStringContainsString('sewn-test-options', $output);
    }

    public function test_api_key_field_render() {
        ob_start();
        $this->admin->render_api_key_section();
        $output = ob_get_clean();

        $services = ['screenshotmachine', 'browserless', 'urlbox'];
        foreach ($services as $service) {
            $this->assertStringContainsString("sewn_{$service}_key", $output);
            $this->assertStringContainsString('regenerate-api-key', $output);
            $this->assertStringContainsString('toggle-visibility', $output);
        }
    }

    public function test_service_status_indicators() {
        ob_start();
        $this->admin->render_service_status();
        $output = ob_get_clean();

        // Test status dots
        $this->assertStringContainsString('status-dot', $output);
        $this->assertStringContainsString('active', $output);
        $this->assertStringContainsString('inactive', $output);
    }

    public function test_error_message_display() {
        $error_types = [
            'invalid_url' => 'Invalid URL provided',
            'service_unavailable' => 'Screenshot service unavailable',
            'permission_denied' => 'Permission denied',
            'api_error' => 'API request failed'
        ];

        foreach ($error_types as $type => $message) {
            ob_start();
            $this->admin->render_error_message($type, $message);
            $output = ob_get_clean();

            $this->assertStringContainsString('notice-error', $output);
            $this->assertStringContainsString($message, $output);
        }
    }
} 