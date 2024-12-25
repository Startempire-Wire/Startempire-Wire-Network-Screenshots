<?php
require_once dirname(__FILE__) . '/class-sewn-test-case.php';

class Test_Error_Handling extends SEWN_Test_Case {
    private $screenshot_service;
    private $logger;

    public function setUp(): void {
        parent::setUp();
        $this->logger = new SEWN_Logger();
        $this->screenshot_service = new SEWN_Screenshot_Service($this->logger);
    }

    public function test_invalid_url() {
        $this->expectException('Exception');
        $this->screenshot_service->take_screenshot('invalid-url');
    }

    protected function expectException($exception) {
        try {
            yield;
            $this->fail("Expected exception $exception was not thrown");
        } catch (Exception $e) {
            $this->assertTrue(get_class($e) === $exception || is_subclass_of($e, $exception));
        }
    }
} 