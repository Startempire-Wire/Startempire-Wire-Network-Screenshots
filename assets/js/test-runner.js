jQuery(document).ready(function($) {
    const resultDiv = $('#test-results');
    const logDiv = $('#test-log pre');
    
    $('#run-tests').on('click', function() {
        const button = $(this);
        const suite = $('#test-suite-select').val();
        
        button.prop('disabled', true);
        resultDiv.html('<div class="notice notice-info"><p>Running tests...</p></div>');
        logDiv.empty();

        $.ajax({
            url: sewnTests.ajaxurl,
            type: 'POST',
            data: {
                action: 'sewn_run_tests',
                nonce: sewnTests.nonce,
                suite: suite
            },
            success: function(response) {
                if (response.success) {
                    displayResults(response.data);
                } else {
                    resultDiv.html(
                        '<div class="notice notice-error">' +
                        '<p>Test execution failed: ' + response.data + '</p>' +
                        '</div>'
                    );
                }
            },
            error: function(xhr, status, error) {
                resultDiv.html(
                    '<div class="notice notice-error">' +
                    '<p>Request failed: ' + error + '</p>' +
                    '</div>'
                );
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });

    function displayResults(results) {
        let html = '<div class="sewn-test-summary">';
        let passed = 0;
        let failed = 0;

        results.forEach(result => {
            if (result.status === 'pass') passed++;
            else failed++;

            html += `
                <div class="test-result ${result.status}">
                    <h4>${result.name}</h4>
                    <p>${result.message}</p>
                    ${result.log ? `<pre>${result.log}</pre>` : ''}
                </div>
            `;
        });

        html += `
            <div class="test-summary">
                <p>Total Tests: ${results.length}</p>
                <p>Passed: ${passed}</p>
                <p>Failed: ${failed}</p>
            </div>
        </div>`;

        resultDiv.html(html);
    }
}); 