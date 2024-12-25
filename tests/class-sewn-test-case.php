<?php
if (!class_exists('SEWN_Test_Case')) {
    class SEWN_Test_Case {
        protected $assertions = [];
        protected $failed = false;
        
        public function setUp(): void {
            // Setup code
        }
        
        public function tearDown(): void {
            // Cleanup code
        }
        
        protected function assertTrue($condition, $message = '') {
            $this->assertions[] = [
                'type' => 'assertTrue',
                'result' => $condition === true,
                'message' => $message
            ];
            
            if ($condition !== true) {
                $this->failed = true;
            }
        }
        
        protected function assertEquals($expected, $actual, $message = '') {
            $this->assertions[] = [
                'type' => 'assertEquals',
                'result' => $expected === $actual,
                'message' => $message,
                'expected' => $expected,
                'actual' => $actual
            ];
            
            if ($expected !== $actual) {
                $this->failed = true;
            }
        }
        
        protected function assertStringContainsString($needle, $haystack, $message = '') {
            $result = strpos($haystack, $needle) !== false;
            $this->assertions[] = [
                'type' => 'assertStringContainsString',
                'result' => $result,
                'message' => $message
            ];
            
            if (!$result) {
                $this->failed = true;
            }
        }
        
        public function run() {
            $this->setUp();
            
            try {
                $reflection = new ReflectionClass($this);
                $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
                
                foreach ($methods as $method) {
                    if (strpos($method->getName(), 'test') === 0) {
                        $this->{$method->getName()}();
                    }
                }
            } catch (Exception $e) {
                $this->failed = true;
                $this->assertions[] = [
                    'type' => 'error',
                    'message' => $e->getMessage()
                ];
            }
            
            $this->tearDown();
            
            return [
                'success' => !$this->failed,
                'assertions' => $this->assertions
            ];
        }
    }
} 