(function($) {
    'use strict';

    const ApiTesterEnhanced = {
        init: function() {
            if (typeof sewnApiTester === 'undefined' || !sewnApiTester.nonce) {
                console.error('API Tester: Missing required configuration');
                return;
            }
            
            this.bindEvents();
            console.log('API Tester Enhanced initialized');
        },

        bindEvents: function() {
            // Log all input changes
            $('[data-log]').on('change', this.logInputChange.bind(this));
            
            // Enhanced test button handling
            $('#run-api-test').on('click', this.runEnhancedTest.bind(this));
            
            // Form validation
            $('#test-url').on('input', this.validateUrl.bind(this));
        },

        logInputChange: function(e) {
            const $input = $(e.target);
            const logType = $input.data('log');
            const value = $input.val();
            
            this.logAction(logType, {
                value: value,
                timestamp: new Date().toISOString()
            });
        },

        runEnhancedTest: async function(e) {
            e.preventDefault();
            const $feedback = $('.test-feedback');
            const startTime = performance.now();

            try {
                $feedback.html('<div class="notice notice-info">Processing screenshot request...</div>');
                
                const url = $('#test-url').val();
                if (!url) {
                    throw new Error('Please enter a URL');
                }

                this.logAction('test-started', {
                    url: url,
                    options: this.getTestOptions()
                });

                const result = await this.performTest();
                const duration = performance.now() - startTime;

                this.logAction('test-success', {
                    duration: duration,
                    result: result
                });

                this.displayResults(result, duration);

            } catch (error) {
                this.logAction('test-error', {
                    error: error.message,
                    url: $('#test-url').val(),
                    options: this.getTestOptions()
                });

                $feedback.html(`
                    <div class="notice notice-error">
                        <p>Screenshot test failed: ${error.message || 'Unknown error occurred'}</p>
                    </div>
                `);
            }
        },

        validateUrl: function(e) {
            const $input = $(e.target);
            const $feedback = $('.test-feedback');
            const url = $input.val();
            
            try {
                new URL(url);
                $feedback.html('');
                return true;
            } catch {
                $feedback.html('<div class="notice notice-warning">Please enter a valid URL</div>');
                return false;
            }
        },

        getTestOptions: function() {
            return {
                width: $('#test-width').val(),
                height: $('#test-height').val(),
                quality: $('#test-quality').val()
            };
        },

        logAction: function(action, data) {
            const logData = {
                action: action,
                data: data,
                timestamp: new Date().toISOString()
            };
            
            console.log('API Tester Log:', logData);
            
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'sewn_log_ui_action',
                    nonce: sewnApiTester.nonce,
                    log_data: logData
                }
            });
        },

        performTest: async function() {
            const url = $('#test-url').val();
            const options = this.getTestOptions();
            
            this.logAction('test-request', {
                url: url,
                options: options
            });

            try {
                const response = await $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'sewn_test_screenshot',
                        nonce: sewnApiTester.nonce,
                        test_url: url,
                        options: options
                    }
                });

                if (!response.success) {
                    throw new Error(response.data || 'Screenshot test failed');
                }

                return response.data;
            } catch (error) {
                this.logAction('test-error', {
                    error: error.responseJSON?.data || error.message || 'Screenshot test failed',
                    url: url,
                    options: options
                });
                
                throw new Error(error.responseJSON?.data || error.message || 'Screenshot test failed');
            }
        },

        displayResults: function(data, duration) {
            const $results = $('#test-results');
            const $feedback = $('.test-feedback');
            
            if (data.error) {
                $feedback.html(`<div class="notice notice-error">
                    <p>Error: ${data.error}</p>
                    ${data.error.includes('API key') ? 
                        '<p>Please configure the API key in the API Management section.</p>' : 
                        ''}
                </div>`);
                return;
            }
            
            $feedback.html('<div class="notice notice-success">Test completed successfully!</div>');
            
            $results.html(`
                <div class="test-success">
                    <h3>✓ Test Completed (${(duration/1000).toFixed(2)}s)</h3>
                    <div class="result-details">
                        <dl>
                            <dt>Screenshot URL</dt>
                            <dd><a href="${data.screenshot_url}" target="_blank">View Screenshot</a></dd>
                            <dt>File Size</dt>
                            <dd>${data.file_size}</dd>
                            <dt>Dimensions</dt>
                            <dd>${data.width} x ${data.height}</dd>
                            <dt>Quality</dt>
                            <dd>${data.quality}%</dd>
                        </dl>
                    </div>
                    <div class="screenshot-preview">
                        <img src="${data.screenshot_url}" alt="Screenshot preview">
                    </div>
                </div>
            `);
        }
    };

    $(document).ready(function() {
        ApiTesterEnhanced.init();
    });
})(jQuery); 