(function($) {
    'use strict';

    const ApiTester = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $('#run-api-test').on('click', this.runTest.bind(this));
        },

        runTest: async function() {
            const $button = $('#run-api-test');
            const $spinner = $('.spinner');
            const $results = $('#test-results');
            const $preview = $('#screenshot-preview');

            // Get test parameters
            const testData = {
                url: $('#test-url').val(),
                width: $('#test-width').val(),
                height: $('#test-height').val(),
                quality: $('#test-quality').val(),
                action: 'sewn_test_screenshot',
                nonce: sewnApiTester.nonce
            };

            // Validate URL
            if (!testData.url) {
                this.showError('Please enter a valid URL');
                return;
            }

            // UI feedback
            $button.prop('disabled', true);
            $spinner.addClass('is-active');
            $results.html('<div class="testing">Running API test...</div>');
            $preview.html('<div class="loading">Generating screenshot...</div>');

            try {
                const response = await $.ajax({
                    url: sewnApiTester.ajaxurl,
                    method: 'POST',
                    data: testData
                });

                if (response.success) {
                    this.showSuccess(response.data);
                } else {
                    this.showError(response.data.message);
                }
            } catch (error) {
                this.showError('API test failed: ' + error.message);
            } finally {
                $button.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        },

        showSuccess: function(data) {
            const $results = $('#test-results');
            const $preview = $('#screenshot-preview');

            // Show test results
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

            // Show preview
            $preview.html(`
                <img src="${data.screenshot_url}" alt="Screenshot preview">
                <div class="preview-actions">
                    <a href="${data.screenshot_url}" class="button" target="_blank">
                        View Full Size
                    </a>
                </div>
            `);
        },

        showError: function(message) {
            const $results = $('#test-results');
            $results.html(`
                <div class="test-error">
                    <h3>❌ Test Failed</h3>
                    <p>${message}</p>
                </div>
            `);
        }
    };

    $(document).ready(function() {
        ApiTester.init();
    });
})(jQuery); 