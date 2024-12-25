jQuery(document).ready(function($) {
    console.log('Document ready, initializing SewnAdmin...');
    
    const SewnAdmin = {
        init: function() {
            console.log('Initializing SewnAdmin...');
            this.bindEvents();
            console.log('Admin interface initialized successfully');
        },

        bindEvents: function() {
            console.log('Binding events...');
            $('#sewn-run-all-tests').on('click', this.handleTestExecution);
        },

        handleTestExecution: function(e) {
            e.preventDefault();
            console.log('Test Execution');
            
            const button = $(this);
            const testLog = $('#test-log');
            
            // Use the globally available nonce
            const nonce = window.sewnAjaxNonce;
            
            // Log the nonce for debugging
            console.log('Using nonce:', nonce);
            
            // Disable button and show loading state
            button.prop('disabled', true);
            testLog.html('<p>Running tests...</p>');
            
            console.log('Making AJAX request', { 
                url: ajaxurl, 
                nonce: nonce, 
                action: 'sewn_run_all_tests' 
            });
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sewn_run_all_tests',
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        let html = '<h3>Test Results</h3>';
                        html += `<p>Total Tests: ${response.data.total}</p>`;
                        html += `<p>Passed: ${response.data.passed}</p>`;
                        
                        // Display individual test results
                        Object.entries(response.data.results).forEach(([test, result]) => {
                            html += `<div class="test-result ${result.status}">`;
                            html += `<h4>${test}</h4>`;
                            html += `<p>${result.message}</p>`;
                            html += '</div>';
                        });
                        
                        testLog.html(html);
                    } else {
                        testLog.html(`<p class="error">Error: ${response.data.message || 'Unknown error'}</p>`);
                    }
                },
                error: function(xhr, status, error) {
                    testLog.html(`<p class="error">Failed to run tests: ${error || 'Unknown error'}</p>`);
                    console.error('AJAX request failed', {xhr, status, error});
                },
                complete: function() {
                    button.prop('disabled', false);
                }
            });
        }
    };

    SewnAdmin.init();
}); 