(function($) {
    'use strict';

    const ApiTesterEnhanced = {
        init: function() {
            console.group('API Tester Initialization');
            try {
                // Verify required data is present
                if (!sewnApiTester || !sewnApiTester.restUrl || !sewnApiTester.apiBase) {
                    throw new Error('Required configuration missing');
                }

                console.log('Configuration:', {
                    apiBase: sewnApiTester.apiBase,
                    restUrl: sewnApiTester.restUrl,
                    noncePresent: !!sewnApiTester.nonce,
                    environment: {
                        userAgent: navigator.userAgent,
                        windowSize: `${window.innerWidth}x${window.innerHeight}`,
                        timestamp: new Date().toISOString()
                    }
                });

                this.bindEvents();
            } catch (error) {
                console.error('Initialization failed:', error);
            }
            console.groupEnd();
        },

        bindEvents: function() {
            console.log('Binding events to API Tester elements');
            $('#run-api-test').on('click', this.runEnhancedTest.bind(this));
            $('#test-url').on('input', this.validateUrl.bind(this));
            $('[data-log]').on('change', this.logInputChange.bind(this));
        },

        runEnhancedTest: function(e) {
            e.preventDefault();
            const startTime = performance.now();
            console.group('Screenshot Test Execution');

            const $button = $('#run-api-test');
            const $spinner = $('.spinner');
            const $feedback = $('.test-feedback');
            const $results = $('#test-results');

            // Show loading state
            $button.prop('disabled', true);
            $spinner.addClass('is-active');

            // Ensure URL is properly formatted
            const testUrl = $('#test-url').val().trim();
            if (!testUrl) {
                $feedback.html('<div class="notice notice-error">Please enter a URL</div>');
                $button.prop('disabled', false);
                $spinner.removeClass('is-active');
                console.groupEnd();
                return;
            }

            // Use REST API endpoint instead of admin-ajax
            const requestData = {
                url: testUrl,
                options: {
                    width: parseInt($('#test-width').val()) || 1280,
                    height: parseInt($('#test-height').val()) || 800,
                    quality: parseInt($('#test-quality').val()) || 85
                }
            };

            // Use the correct REST API endpoint from class-rest-controller.php
            const endpoint = `${sewnApiTester.restUrl}sewn-screenshots/v1/screenshot`;
            
            console.log('Request Configuration:', {
                endpoint: endpoint,
                method: 'POST',
                data: requestData,
                timestamp: new Date().toISOString()
            });

            // Use WordPress REST API
            $.ajax({
                url: endpoint,
                method: 'POST',
                data: JSON.stringify(requestData),
                contentType: 'application/json',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', sewnApiTester.headers['X-WP-Nonce']);
                    xhr.setRequestHeader('Content-Type', 'application/json');
                },
                success: function(response) {
                    console.log('AJAX Success');
                    console.log('Response:', response);
                    
                    // Check if response has data property (WP REST API format)
                    const result = response.data || response;
                    
                    if (result.success) {
                        // Update preview image
                        const previewImg = document.getElementById('screenshot-preview');
                        if (previewImg) {
                            if (result.screenshot_url) {
                                previewImg.src = result.screenshot_url;
                                previewImg.style.display = 'block';
                            } else {
                                previewImg.style.display = 'none';
                            }
                        }

                        // Update results
                        const resultsDiv = document.getElementById('test-results');
                        if (resultsDiv) {
                            resultsDiv.innerHTML = `
                                <div class="sewn-result-success">
                                    <h3>Screenshot Generated Successfully</h3>
                                    <p>Method: ${result.method}</p>
                                    <p>Service: ${result.service}</p>
                                    <p>Time: ${result.timestamp}</p>
                                    ${result.file_size ? `<p>Size: ${result.file_size}</p>` : ''}
                                    ${result.width ? `<p>Dimensions: ${result.width}x${result.height}</p>` : ''}
                                    ${result.cache_status ? `<p>Cache Status: ${result.cache_status}</p>` : ''}
                                    <p>Image: <img src="${result.screenshot_url}" alt="Screenshot Preview" style="max-width: 100%; height: auto;"></p>
                                </div>
                            `;
                        }
                    } else {
                        // Handle error case
                        const resultsDiv = document.getElementById('test-results');
                        if (resultsDiv) {
                            resultsDiv.innerHTML = `
                                <div class="sewn-result-error">
                                    <h3>Screenshot Generation Failed</h3>
                                    <p>${result.message || 'Unknown error occurred'}</p>
                                </div>
                            `;
                        }
                    }
                },
                error: (jqXHR, textStatus, errorThrown) => {
                    console.group('AJAX Error');
                    const duration = performance.now() - startTime;
                    
                    // Detailed error logging
                    console.error('Error Details:', {
                        status: jqXHR.status,
                        statusText: jqXHR.statusText,
                        errorType: textStatus,
                        thrownError: errorThrown,
                        duration: `${duration.toFixed(2)}ms`,
                        timestamp: new Date().toISOString(),
                        endpoint: endpoint,
                        requestData: JSON.stringify(requestData)
                    });

                    // Response analysis
                    const responseHeaders = jqXHR.getAllResponseHeaders();
                    console.error('Response Analysis:', {
                        headers: responseHeaders,
                        contentType: jqXHR.getResponseHeader('Content-Type'),
                        responseJSON: jqXHR.responseJSON,
                        responseText: jqXHR.responseText?.substring(0, 500) // First 500 chars
                    });

                    // Error message display
                    const errorMessage = jqXHR.responseJSON?.message || 'Screenshot test failed';
                    $feedback.html(`<div class="notice notice-error">${errorMessage}</div>`);
                    $results.html('<div class="no-results">Test failed</div>');
                    console.groupEnd();
                },
                complete: () => {
                    const duration = performance.now() - startTime;
                    console.log('Request completed in:', `${duration.toFixed(2)}ms`);
                    $button.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    console.groupEnd();
                }
            });
        },

        showSuccess: function(data) {
            const $results = $('#test-results');
            const $preview = $('#screenshot-preview');

            $results.html(`
                <div class="test-success">
                    <h3>✅ Test Successful</h3>
                    <dl class="test-details">
                        <dt>Response Time</dt>
                        <dd>${data.response_time}ms</dd>
                        <dt>File Size</dt>
                        <dd>${data.file_size}</dd>
                        <dt>Cache Status</dt>
                        <dd>${data.cache_status}</dd>
                    </dl>
                </div>
            `);

            if (data.screenshot_url) {
                $preview.html(`
                    <img src="${data.screenshot_url}" alt="Screenshot preview">
                    <div class="preview-actions">
                        <a href="${data.screenshot_url}" class="button" target="_blank">
                            View Full Size
                        </a>
                    </div>
                `);
            }
        },

        showError: function(message) {
            const $results = $('#test-results');
            $results.html(`
                <div class="test-error">
                    <h3>❌ Test Failed</h3>
                    <p>${message}</p>
                </div>
            `);
        },

        validateUrl: function(e) {
            console.group('URL Validation');
            const $input = $(e.target);
            const $feedback = $('.test-feedback');
            const url = $input.val();
            
            console.log('Validating URL:', url);
            
            try {
                new URL(url);
                console.log('URL is valid');
                $feedback.html('');
                console.groupEnd();
                return true;
            } catch {
                console.warn('Invalid URL provided');
                $feedback.html('<div class="notice notice-warning">Please enter a valid URL</div>');
                console.groupEnd();
                return false;
            }
        },

        logInputChange: function(e) {
            const $input = $(e.target);
            const logData = {
                action: $input.data('log'),
                data: {
                    value: $input.val(),
                    timestamp: new Date().toISOString()
                }
            };
            console.log('Input Change:', logData);
        }
    };

    $(document).ready(function() {
        ApiTesterEnhanced.init();
    });
})(jQuery); 