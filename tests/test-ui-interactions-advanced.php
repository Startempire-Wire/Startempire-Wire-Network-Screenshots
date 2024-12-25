<?php
require_once dirname(__FILE__) . '/class-sewn-test-case.php';

class Test_UI_Interactions_Advanced extends SEWN_Test_Case {
    private $screenshot_service;
    private $logger;
    private $admin;

    public function setUp(): void {
        parent::setUp();
        $this->logger = new SEWN_Logger();
        $this->screenshot_service = new SEWN_Screenshot_Service($this->logger);
        $this->admin = new SEWN_Admin($this->screenshot_service, $this->logger);
    }

    public function test_form_validation_states() {
        ob_start();
        $this->admin->render_tester_page();
        $output = ob_get_clean();

        // Test URL input validation
        $test_urls = [
            'valid' => [
                'https://example.com' => true,
                'http://test.local' => true,
                'https://sub.domain.com/path' => true
            ],
            'invalid' => [
                'not-a-url' => false,
                'ftp://invalid.com' => false,
                '' => false
            ]
        ];

        foreach ($test_urls as $type => $urls) {
            foreach ($urls as $url => $should_pass) {
                $validation_result = $this->admin->validate_test_url($url);
                $this->assertEquals($should_pass, $validation_result, "URL validation failed for: $url");
            }
        }
    }

    public function test_button_states() {
        ob_start();
        $this->admin->render_tester_page();
        $output = ob_get_clean();

        // Test button attributes
        $this->assertStringContainsString('disabled', $output);
        $this->assertStringContainsString('aria-busy', $output);
        
        // Test loading states
        $this->assertStringContainsString('is-busy', $output);
        $this->assertStringContainsString('spinner', $output);
    }

    public function test_option_interactions() {
        ob_start();
        $this->admin->render_test_options();
        $output = ob_get_clean();

        // Test dimension inputs
        $dimensions = [
            'width' => ['min' => 100, 'max' => 3840, 'default' => 1280],
            'height' => ['min' => 100, 'max' => 2160, 'default' => 800]
        ];

        foreach ($dimensions as $dim => $values) {
            $this->assertStringContainsString("min=\"{$values['min']}\"", $output);
            $this->assertStringContainsString("max=\"{$values['max']}\"", $output);
            $this->assertStringContainsString("value=\"{$values['default']}\"", $output);
        }

        // Test quality slider
        $this->assertStringContainsString('quality-slider', $output);
        $this->assertStringContainsString('value="85"', $output);
    }

    public function test_api_key_interactions() {
        ob_start();
        $this->admin->render_api_key_section();
        $output = ob_get_clean();

        $services = ['screenshotmachine', 'browserless', 'urlbox'];
        
        foreach ($services as $service) {
            // Test key visibility toggle
            $this->assertStringContainsString("toggle-{$service}-key", $output);
            
            // Test regenerate button
            $this->assertStringContainsString("regenerate-{$service}-key", $output);
            
            // Test feedback elements
            $this->assertStringContainsString("{$service}-feedback", $output);
        }
    }

    public function test_responsive_behavior() {
        ob_start();
        $this->admin->render_tester_page();
        $output = ob_get_clean();

        // Test responsive classes
        $this->assertStringContainsString('sewn-responsive-wrapper', $output);
        $this->assertStringContainsString('sewn-mobile-stack', $output);
        
        // Test grid layout
        $this->assertStringContainsString('sewn-grid', $output);
        $this->assertStringContainsString('sewn-col', $output);
    }

    public function test_notification_system() {
        $notification_types = [
            'success' => 'Screenshot taken successfully',
            'error' => 'Failed to take screenshot',
            'warning' => 'Service performance degraded',
            'info' => 'Processing screenshot'
        ];

        foreach ($notification_types as $type => $message) {
            ob_start();
            $this->admin->render_notification($type, $message);
            $output = ob_get_clean();

            $this->assertStringContainsString("notice-{$type}", $output);
            $this->assertStringContainsString($message, $output);
            $this->assertStringContainsString('is-dismissible', $output);
        }
    }

    public function test_keyboard_navigation() {
        ob_start();
        $this->admin->render_tester_page();
        $output = ob_get_clean();

        // Test tabindex attributes
        $this->assertStringContainsString('tabindex="0"', $output);
        
        // Test keyboard shortcuts
        $this->assertStringContainsString('data-shortcut', $output);
        
        // Test focus indicators
        $this->assertStringContainsString('focus-visible', $output);
    }

    public function test_loading_states() {
        ob_start();
        $this->admin->render_loading_states();
        $output = ob_get_clean();

        $states = [
            'initial' => 'Ready to take screenshot',
            'processing' => 'Processing screenshot...',
            'uploading' => 'Uploading screenshot...',
            'finalizing' => 'Finalizing...'
        ];

        foreach ($states as $state => $message) {
            $this->assertStringContainsString($message, $output);
            $this->assertStringContainsString("state-{$state}", $output);
        }
    }

    public function test_production_server_check() {
        $result = $this->admin->is_production_server();
        $this->assertIsBool($result);
    }
} 