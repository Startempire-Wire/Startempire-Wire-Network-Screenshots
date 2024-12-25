<?php
require_once dirname(__FILE__) . '/class-sewn-test-case.php';

class Test_Screenshot_Service extends SEWN_Test_Case {
    private $screenshot_service;
    private $logger;

    public function setUp(): void {
        parent::setUp();
        $this->logger = new SEWN_Logger();
        $this->screenshot_service = new SEWN_Screenshot_Service($this->logger);
    }

    public function test_basic_screenshot() {
        $url = 'https://example.com';
        $result = $this->screenshot_service->take_screenshot($url);
        
        $this->assertTrue(is_array($result), 'Result should be an array');
        $this->assertTrue($result['success'], 'Screenshot should be successful');
        $this->assertEquals($url, $result['url'], 'URL should match input');
    }

    public function test_take_screenshot() {
        $url = 'https://example.com';
        $options = [
            'width' => 1280,
            'height' => 800,
            'quality' => 85
        ];

        try {
            $result = $this->screenshot_service->take_screenshot($url, $options);
            
            $this->assertTrue(is_array($result), 'Result should be an array');
            $this->assertTrue(isset($result['file_path']), 'Result should contain file_path');
            $this->assertTrue(isset($result['url']), 'Result should contain url');
            $this->assertTrue(isset($result['method']), 'Result should contain method');
            $this->assertTrue(file_exists($result['file_path']), 'Screenshot file should exist');
            
        } catch (Exception $e) {
            $this->assertTrue(false, 'Screenshot capture failed: ' . $e->getMessage());
        }
    }

    public function test_service_fallback() {
        // Disable wkhtmltopdf to test fallback
        update_option('sewn_wkhtmltopdf_path', '');
        
        $url = 'https://example.com';
        
        try {
            $result = $this->screenshot_service->take_screenshot($url);
            $this->assertTrue($result['method'] !== 'wkhtmltopdf', 'Method should not be wkhtmltopdf');
        } catch (Exception $e) {
            $this->assertTrue(false, 'Fallback failed: ' . $e->getMessage());
        }
    }

    public function test_option_validation() {
        $invalid_options = [
            'width' => -100,
            'height' => 99999,
            'quality' => 101
        ];

        $validated = $this->screenshot_service->validate_options($invalid_options);
        
        $this->assertTrue($validated['width'] >= 100, 'Width should be at least 100');
        $this->assertTrue($validated['width'] <= 3840, 'Width should be at most 3840');
        $this->assertTrue($validated['height'] >= 100, 'Height should be at least 100');
        $this->assertTrue($validated['height'] <= 2160, 'Height should be at most 2160');
        $this->assertTrue($validated['quality'] >= 0, 'Quality should be at least 0');
        $this->assertTrue($validated['quality'] <= 100, 'Quality should be at most 100');
    }

    public function test_invalid_url_handling() {
        $invalid_urls = [
            '',                          // Empty
            'not-a-url',                // No protocol
            'http://',                  // No domain
            'https://nonexistent.local' // Non-existent domain
        ];

        foreach ($invalid_urls as $url) {
            try {
                $this->screenshot_service->take_screenshot($url);
                $this->assertTrue(false, 'Expected exception for invalid URL: ' . $url);
            } catch (Exception $e) {
                $this->assertStringContainsString('Invalid URL', $e->getMessage());
            }
        }
    }

    public function test_extreme_dimensions() {
        $test_cases = [
            [
                'options' => ['width' => 50, 'height' => 50],
                'expected' => ['width' => 100, 'height' => 100]
            ],
            [
                'options' => ['width' => 5000, 'height' => 5000],
                'expected' => ['width' => 3840, 'height' => 2160]
            ],
            [
                'options' => ['width' => -100, 'height' => -100],
                'expected' => ['width' => 100, 'height' => 100]
            ]
        ];

        foreach ($test_cases as $case) {
            $validated = $this->screenshot_service->validate_options($case['options']);
            $this->assertEquals($case['expected']['width'], $validated['width']);
            $this->assertEquals($case['expected']['height'], $validated['height']);
        }
    }

    public function test_file_permissions() {
        $url = 'https://example.com';
        $result = $this->screenshot_service->take_screenshot($url);
        
        $this->assertTrue(file_exists($result['file_path']), 'File should exist');
        $this->assertTrue(is_readable($result['file_path']), 'File should be readable');
        $perms = fileperms($result['file_path']) & 0777;
        $this->assertEquals(0644, $perms, 'File should have 0644 permissions');
    }

    public function test_concurrent_requests() {
        $urls = [
            'https://example.com',
            'https://example.org',
            'https://example.net'
        ];
        
        $results = [];
        foreach ($urls as $url) {
            $results[] = $this->screenshot_service->take_screenshot($url);
        }
        
        // Verify unique filenames
        $filenames = array_map(function($result) {
            return basename($result['file_path']);
        }, $results);
        
        $this->assertEquals(count($filenames), count(array_unique($filenames)), 'Filenames should be unique');
    }

    public function tearDown(): void {
        // Clean up any screenshots created during tests
        $upload_dir = wp_upload_dir();
        $screenshot_dir = $upload_dir['basedir'] . '/screenshots/';
        
        if (is_dir($screenshot_dir)) {
            $files = glob($screenshot_dir . '*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        
        parent::tearDown();
    }
} 