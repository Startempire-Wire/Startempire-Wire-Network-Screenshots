<?php
require_once dirname(__FILE__) . '/class-sewn-test-case.php';

class Test_Integration extends SEWN_Test_Case {
    private $screenshot_service;
    private $logger;
    private $admin;

    public function setUp(): void {
        parent::setUp();
        $this->logger = new SEWN_Logger();
        $this->screenshot_service = new SEWN_Screenshot_Service($this->logger);
        $this->admin = new SEWN_Admin($this->screenshot_service, $this->logger);
    }

    public function test_service_status() {
        $status = $this->screenshot_service->get_service_status();
        $this->assertTrue(is_array($status));
        $this->assertTrue(isset($status['status']));
    }
} 