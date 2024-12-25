jQuery(document).ready(function($) {
    // Toggle API key visibility
    $('.sewn-toggle-visibility').on('click', function() {
        var targetId = $(this).data('target');
        var input = $('#' + targetId);
        
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
        } else {
            input.attr('type', 'password');
        }
    });

    // Make priority list sortable
    if ($.fn.sortable) {
        $('#sewn-priority-list').sortable({
            update: function(event, ui) {
                $(this).find('li').each(function(index) {
                    $(this).find('input[type="hidden"]').val($(this).data('service'));
                });
            }
        });
    }

    // Test screenshot functionality
    $('#sewn-test-button').on('click', function() {
        var button = $(this);
        var url = $('#sewn-test-url').val();
        var resultDiv = $('#sewn-test-result');
        var loadingHtml = '<div class="sewn-loading">' +
                          '<span class="spinner is-active"></span>' +
                          '<p>Taking screenshot...</p>' +
                          '</div>';

        // Get options
        var options = {
            width: parseInt($('#sewn-test-width').val()) || 1280,
            height: parseInt($('#sewn-test-height').val()) || 800,
            quality: parseInt($('#sewn-test-quality').val()) || 85
        };

        if (!url) {
            resultDiv.html('<div class="notice notice-error"><p>Please enter a URL to test.</p></div>');
            return;
        }

        // Validate URL format
        if (!url.match(/^https?:\/\//i)) {
            url = 'https://' + url;
            $('#sewn-test-url').val(url);
        }

        button.prop('disabled', true);
        resultDiv.html(loadingHtml);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sewn_test_screenshot',
                url: url,
                nonce: sewn_settings.nonce,
                width: options.width,
                height: options.height,
                quality: options.quality
            },
            success: function(response) {
                if (response.success) {
                    var successHtml = '<div class="notice notice-success">' +
                        '<p>Screenshot taken successfully!</p>' +
                        '<p>Method used: ' + response.data.method + '</p>' +
                        '<div class="screenshot-preview">' +
                        '<img src="' + response.data.url + '" alt="Screenshot preview">' +
                        '<div class="screenshot-actions">' +
                        '<a href="' + response.data.url + '" class="button" download>Download</a>' +
                        '<button type="button" class="button retry-screenshot">Take Another</button>' +
                        '</div></div></div>';
                        
                    resultDiv.html(successHtml);
                } else {
                    resultDiv.html('<div class="notice notice-error">' +
                        '<p>Error: ' + response.data + '</p>' +
                        '<button type="button" class="button retry-screenshot">Try Again</button>' +
                        '</div>');
                }
            },
            error: function(xhr, status, error) {
                resultDiv.html('<div class="notice notice-error">' +
                    '<p>Request failed: ' + error + '</p>' +
                    '<p>Status: ' + status + '</p>' +
                    '<button type="button" class="button retry-screenshot">Try Again</button>' +
                    '</div>');
                console.error('AJAX Error:', status, error);
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });

    // Add retry button handler
    $(document).on('click', '.retry-screenshot', function() {
        $('#sewn-test-button').click();
    });

    // Add reset button handler
    $(document).on('click', '.reset-options', function() {
        $('#sewn-test-width').val(1280);
        $('#sewn-test-height').val(800);
        $('#sewn-test-quality').val(85);
    });

    // Add input validation
    $('.sewn-test-options input[type="number"]').on('change', function() {
        var min = parseInt($(this).attr('min'));
        var max = parseInt($(this).attr('max'));
        var value = parseInt($(this).val());

        if (value < min) $(this).val(min);
        if (value > max) $(this).val(max);
    });

    // API Key Management
    $('.regenerate-api-key').on('click', function() {
        var button = $(this);
        var serviceId = button.data('service');
        var keyInput = $('#sewn_' + serviceId + '_key');
        var feedbackDiv = button.siblings('.api-feedback');
        
        button.prop('disabled', true);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sewn_regenerate_api_key',
                service: serviceId,
                nonce: sewn_settings.nonce
            },
            beforeSend: function() {
                feedbackDiv.html('<span class="spinner is-active"></span>');
            },
            success: function(response) {
                if (response.success) {
                    keyInput.val(response.data.key);
                    feedbackDiv.html('<div class="notice notice-success inline"><p>' + 
                        response.data.message + '</p></div>');
                    
                    // Update any UI elements showing the key
                    $('.api-key-display[data-service="' + serviceId + '"]').text(response.data.key);
                } else {
                    feedbackDiv.html('<div class="notice notice-error inline"><p>' + 
                        response.data + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                feedbackDiv.html('<div class="notice notice-error inline"><p>Failed to regenerate key: ' + 
                    error + '</p></div>');
            },
            complete: function() {
                button.prop('disabled', false);
                setTimeout(function() {
                    feedbackDiv.empty();
                }, 5000);
            }
        });
    });

    // Log UI action
    function logUIAction(action, data) {
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'sewn_log_ui_action',
                nonce: sewnSettings.nonce,
                log_data: {
                    action: action,
                    data: data,
                    timestamp: Date.now()
                }
            }
        });
    }

    // Log API key visibility toggle
    $('.sewn-toggle-visibility').on('click', function() {
        var targetId = $(this).data('target');
        var isVisible = $('#' + targetId).attr('type') === 'text';
        logUIAction('api_key_visibility_toggle', {
            target: targetId,
            visible: isVisible
        });
    });

    // Log API test
    $('.sewn-test-api').on('click', function() {
        var service = $(this).data('service');
        logUIAction('api_test_started', {
            service: service
        });
    });

    // Log API key regeneration
    $('.regenerate-api-key').on('click', function() {
        var service = $(this).data('service');
        logUIAction('api_key_regeneration_started', {
            service: service
        });
    });

    // Add test runner functionality
    $('#sewn-run-all-tests').on('click', function(e) {
        e.preventDefault();
        const button = $(this);
        const testLog = $('#test-log');
        const nonce = button.data('nonce');

        button.prop('disabled', true);
        testLog.html('<div class="notice notice-info"><p>Running tests...</p></div>');

        const startTime = performance.now();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sewn_run_all_tests',
                nonce: nonce
            },
            success: function(response) {
                const executionTime = performance.now() - startTime;
                console.log('testExecution:', executionTime, 'ms');

                if (response.success) {
                    const results = response.data;
                    displayTestResults(results, testLog);
                } else {
                    testLog.html('<div class="notice notice-error"><p>Test execution failed: ' + 
                        (response.data || 'Unknown error') + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                testLog.html('<div class="notice notice-error"><p>Request failed: ' + error + '</p></div>');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });

    function displayTestResults(results, container) {
        let html = '<div class="sewn-test-results">';
        let totalTests = 0;
        let passedTests = 0;

        // Loop through each test category
        Object.entries(results).forEach(([testName, result]) => {
            const statusClass = result.success ? 'pass' : 'fail';
            if (result.success) passedTests++;
            totalTests++;

            html += `
                <div class="test-result ${statusClass}">
                    <h4>${formatTestName(testName)}</h4>
                    <div class="test-details">`;

            if (result.assertions) {
                html += '<ul class="assertions-list">';
                result.assertions.forEach(assertion => {
                    html += `<li class="${assertion.result ? 'pass' : 'fail'}">
                        ${assertion.message}
                    </li>`;
                });
                html += '</ul>';
            }

            if (result.details) {
                html += `
                    <div class="test-details-extra">
                        <h5>Additional Details:</h5>
                        <pre>${JSON.stringify(result.details, null, 2)}</pre>
                    </div>`;
            }

            html += '</div></div>';
        });

        // Add summary
        html += `
            <div class="test-summary">
                <h4>Test Summary</h4>
                <p>Total Tests: ${totalTests}</p>
                <p>Passed: ${passedTests}</p>
                <p>Failed: ${totalTests - passedTests}</p>
            </div>
        </div>`;

        container.html(html);
    }

    function formatTestName(name) {
        return name
            .split('-')
            .map(word => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ');
    }
}); 