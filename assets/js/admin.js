(function($) {
    'use strict';

    // Service selection handling
    function handleServiceSelection() {
        const $serviceSelect = $('#sewn_fallback_service');
        const $details = $('.sewn-fallback-service-details');

        $serviceSelect.on('change', function() {
            const service = $(this).val();
            $details.removeClass('active').hide();
            
            if (service) {
                $details.filter(`[data-service="${service}"]`)
                    .addClass('active')
                    .fadeIn(300);
            }
        });
    }

    // Test configuration handling
    function handleTestConfiguration() {
        $('.sewn-test-fallback').on('click', function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const service = $button.data('service');
            const nonce = $button.data('nonce');

            if ($button.hasClass('loading')) {
                return;
            }

            $button.addClass('loading')
                   .prop('disabled', true);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sewn_test_fallback',
                    service: service,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Update quota information if available
                        if (response.data.quota) {
                            updateQuotaDisplay(response.data.quota);
                        }
                        
                        // Show success message
                        showNotice('success', response.data.message);
                    } else {
                        showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    showNotice('error', sewn_admin.strings.test_failed);
                },
                complete: function() {
                    $button.removeClass('loading')
                           .prop('disabled', false);
                }
            });
        });
    }

    // Service refresh handling
    function handleServiceRefresh() {
        $('.sewn-refresh-services').on('click', function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const nonce = $button.data('nonce');

            if ($button.hasClass('loading')) {
                return;
            }

            $button.addClass('loading')
                   .prop('disabled', true);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sewn_refresh_services',
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Replace the services section with updated content
                        $('.sewn-services-status').replaceWith(response.data.html);
                        showNotice('success', sewn_admin.strings.refresh_success);
                    } else {
                        showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    showNotice('error', sewn_admin.strings.refresh_failed);
                },
                complete: function() {
                    $button.removeClass('loading')
                           .prop('disabled', false);
                }
            });
        });
    }

    // Helper function to update quota display
    function updateQuotaDisplay(quota) {
        const $status = $('.sewn-fallback-status');
        if (!$status.length || !quota) return;

        let html = '';
        if (quota.remaining) {
            html += `<p>${sewn_admin.strings.remaining_credits}: ${quota.remaining}</p>`;
        }
        if (quota.reset_date) {
            html += `<p>${sewn_admin.strings.quota_reset}: ${quota.reset_date}</p>`;
        }

        $status.find('.notice').html(html);
    }

    // Helper function to show admin notices
    function showNotice(type, message) {
        const $notice = $('<div>')
            .addClass(`notice notice-${type} is-dismissible`)
            .append($('<p>').text(message));

        const $existing = $('.sewn-admin-notice');
        if ($existing.length) {
            $existing.replaceWith($notice);
        } else {
            $('.wrap h1').after($notice);
        }

        // Initialize WordPress dismissible notices
        if (window.wp && window.wp.notices) {
            window.wp.notices.removeDismissible($notice);
        }
    }

    // Add this to your existing admin.js file
    function handleServiceTabs() {
        $('.sewn-tab-button').on('click', function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const tabId = $button.data('tab');
            
            // Update buttons
            $('.sewn-tab-button').removeClass('active');
            $button.addClass('active');
            
            // Update content
            $('.sewn-tab-content').hide();
            $(`#${tabId}-service-tab`).fadeIn(300);
            
            // Update service selection if needed
            if (tabId === 'fallback') {
                $('input[name="sewn_active_service"][value="none"]').prop('checked', true);
            } else {
                const defaultService = $('.sewn-service-option input[type="radio"]').not('[value="none"]').first().val();
                $(`input[name="sewn_active_service"][value="${defaultService}"]`).prop('checked', true);
            }
        });
    }

    // Installation progress handling
    function handleInstallationProgress() {
        let progressTimer = null;
        
        $('.sewn-install-tool').on('click', function(e) {
            e.preventDefault();
            const $button = $(this);
            const toolId = $button.data('tool');
            const nonce = $button.data('nonce');
            
            if ($button.hasClass('loading')) return;
            
            $button.addClass('loading').prop('disabled', true);
            startProgressTracking(toolId);
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sewn_install_tool',
                    tool: toolId,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        showNotice('success', response.data.message);
                    } else {
                        showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    showNotice('error', sewn_admin.strings.installation_failed);
                },
                complete: function() {
                    $button.removeClass('loading').prop('disabled', false);
                    stopProgressTracking();
                }
            });
        });
        
        function startProgressTracking(toolId) {
            progressTimer = setInterval(() => {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'sewn_get_installation_progress',
                        tool: toolId,
                        nonce: sewn_admin.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            updateProgressUI(response.data);
                        }
                    }
                });
            }, 2000);
        }
        
        function stopProgressTracking() {
            if (progressTimer) {
                clearInterval(progressTimer);
                progressTimer = null;
            }
        }
        
        function updateProgressUI(data) {
            const $progress = $('.sewn-installation-progress');
            if (!$progress.length) return;
            
            $progress.find('.progress-bar').css('width', data.progress + '%');
            $progress.find('.current-step').text(data.current_step);
            
            if (data.status === 'complete') {
                stopProgressTracking();
            }
        }
    }

    // Health check handling
    function handleHealthCheck() {
        $('.sewn-run-health-check').on('click', function(e) {
            e.preventDefault();
            const $button = $(this);
            const nonce = $button.data('nonce');
            
            if ($button.hasClass('loading')) return;
            
            $button.addClass('loading').prop('disabled', true);
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sewn_run_health_check',
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('.sewn-health-status').html(response.data.html);
                    } else {
                        showNotice('error', response.data.message);
                    }
                },
                complete: function() {
                    $button.removeClass('loading').prop('disabled', false);
                }
            });
        });
    }

    // Initialize everything when document is ready
    $(document).ready(function() {
        handleServiceSelection();
        handleTestConfiguration();
        handleServiceRefresh();
        handleServiceTabs();
        
        // New initializations
        handleInstallationProgress();
        handleHealthCheck();
    });

})(jQuery);