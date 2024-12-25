(function($) {
    'use strict';

    // Main admin functionality
    const SewnAdmin = {
        init: function() {
            if (this.isDebug()) {
                console.log('Initializing SewnAdmin...');
            }
            this.initFallbackForm();
        },

        isDebug: function() {
            return typeof window.sewnAdmin !== 'undefined' && window.sewnAdmin.debug;
        },

        log: function(message, data = null) {
            if (this.isDebug()) {
                if (data) {
                    console.log(message, data);
                } else {
                    console.log(message);
                }
            }
        },

        initFallbackForm: function() {
            const fallbackForm = $('#fallback-service-form');
            
            if (!fallbackForm.length) {
                this.log('Fallback form not found on current page');
                return;
            }

            this.log('Initializing fallback form');

            // Handle form submission
            fallbackForm.on('submit', (e) => {
                e.preventDefault();
                
                const form = $(e.currentTarget);
                const submitButton = form.find('button[type="submit"]');
                const messagesDiv = $('#fallback-service-messages');
                const serviceInput = $('#service');
                const apiKeyInput = $('#api_key');
                
                this.log('Processing fallback form submission', {
                    service: serviceInput.val(),
                    hasApiKey: !!apiKeyInput.val()
                });
                
                submitButton.prop('disabled', true).text('Updating...');
                
                $.ajax({
                    url: window.sewnAdmin.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'sewn_update_fallback_service',
                        nonce: $('#fallback_nonce').val(),
                        service: serviceInput.val(),
                        api_key: apiKeyInput.val()
                    },
                    success: (response) => {
                        this.log('Received response:', response);
                        
                        if (response.success) {
                            this.handleSuccessResponse(response, {
                                form,
                                serviceInput,
                                apiKeyInput,
                                messagesDiv
                            });
                        } else {
                            this.handleErrorResponse(response, messagesDiv);
                        }
                    },
                    error: (xhr, status, error) => {
                        this.handleAjaxError(error, messagesDiv);
                    },
                    complete: () => {
                        submitButton.prop('disabled', false).text('Update Fallback Service');
                        this.log('Request completed');
                    }
                });
            });
        },

        handleSuccessResponse: function(response, elements) {
            const { form, serviceInput, apiKeyInput, messagesDiv } = elements;
            
            // Update form values
            serviceInput.val(response.data.service);
            if (response.data.api_key) {
                apiKeyInput.val(response.data.api_key);
            }

            // Show success message
            messagesDiv.html(`
                <div class="notice notice-success is-dismissible">
                    <p>${response.data.message}</p>
                    <p>Service: ${response.data.service}</p>
                    <p>API Key Status: ${response.data.has_key ? 'Set' : 'Not Set'}</p>
                </div>
            `);

            // Update status indicator
            this.updateApiKeyStatus(apiKeyInput, response.data.has_key);

            // Refresh page after delay
            if (response.data.has_key) {
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            }
        },

        handleErrorResponse: function(response, messagesDiv) {
            this.log('Error response:', response);
            messagesDiv.html(`
                <div class="notice notice-error is-dismissible">
                    <p>Error: ${response.data.message}</p>
                </div>
            `);
        },

        handleAjaxError: function(error, messagesDiv) {
            this.log('Ajax error:', error);
            messagesDiv.html(`
                <div class="notice notice-error is-dismissible">
                    <p>Failed to update fallback service configuration</p>
                    <p>Error: ${error}</p>
                </div>
            `);
        },

        updateApiKeyStatus: function(apiKeyInput, hasKey) {
            const keyWrapper = apiKeyInput.parent();
            const existingStatus = keyWrapper.find('.api-key-status');
            
            if (hasKey) {
                if (existingStatus.length) {
                    existingStatus.show();
                } else {
                    apiKeyInput.after('<span class="api-key-status success">✓ API Key Set</span>');
                }
            } else {
                existingStatus.remove();
            }
        }
    };

    // Initialize on document ready
    $(function() {
        SewnAdmin.init();
    });

})(jQuery);