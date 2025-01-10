document.addEventListener('DOMContentLoaded', function() {
    // Debug log to verify script loading
    console.log('SEWN Admin Settings JS loaded');

    const tabButtons = document.querySelectorAll('.nav-tab');
    const tabPanels = document.querySelectorAll('.sewn-tab-panel');

    function switchTab(targetId) {
        console.log('Switching to tab:', targetId);

        // Update active tab
        tabButtons.forEach(tab => {
            tab.classList.remove('nav-tab-active');
            if (tab.dataset.target === targetId) {
                tab.classList.add('nav-tab-active');
            }
        });

        // Update active panel
        tabPanels.forEach(panel => {
            panel.style.display = 'none';
            panel.classList.remove('is-active');
            if (panel.id === targetId) {
                panel.style.display = 'block';
                panel.classList.add('is-active');
            }
        });

        // Save selection via AJAX
        const formData = new FormData();
        formData.append('action', 'sewn_update_api_mode');
        formData.append('mode', targetId === 'primary-api-panel' ? 'primary' : 'fallback');
        formData.append('nonce', sewn_settings.nonce);

        fetch(ajaxurl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const notice = document.createElement('div');
                notice.className = 'notice notice-success is-dismissible';
                notice.innerHTML = '<p>API mode updated successfully</p>';
                
                const wrapper = document.querySelector('.nav-tab-wrapper');
                wrapper.parentNode.insertBefore(notice, wrapper.nextSibling);
                
                setTimeout(() => {
                    notice.remove();
                }, 3000);
            }
        });
    }

    // Add click handlers to tabs
    tabButtons.forEach(tab => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            const targetId = e.currentTarget.dataset.target;
            switchTab(targetId);
        });
    });

    // Show initial tab
    const activeTab = document.querySelector('.nav-tab-active');
    if (activeTab) {
        switchTab(activeTab.dataset.target);
    } else {
        // Default to first tab
        const firstTab = document.querySelector('.nav-tab');
        if (firstTab) {
            switchTab(firstTab.dataset.target);
        }
    }

    // Add API Key Generation Handler
    const regenerateButton = document.querySelector('.regenerate-api-key[data-service="primary"]');
    if (regenerateButton) {
        regenerateButton.addEventListener('click', function(e) {
            e.preventDefault();
            const button = e.currentTarget;
            const feedback = button.parentNode.querySelector('.api-feedback');
            const input = document.getElementById('sewn_primary_key');

            // Disable button during request
            button.disabled = true;
            button.textContent = 'Generating...';

            // Create and send request
            const formData = new FormData();
            formData.append('action', 'sewn_regenerate_api_key');
            formData.append('nonce', sewn_settings.nonce);
            formData.append('service', 'primary');

            fetch(ajaxurl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update input with new key
                    input.value = data.data.key;
                    
                    // Show success message
                    feedback.innerHTML = '<div class="notice notice-success inline"><p>' + 
                        data.data.message + '</p></div>';
                    
                    // Update button text
                    button.textContent = 'Regenerate Key';
                    
                    // Update status indicator if it exists
                    const statusDot = document.querySelector('.key-status');
                    if (statusDot) {
                        statusDot.classList.remove('inactive');
                        statusDot.classList.add('active');
                        statusDot.querySelector('span:not(.status-dot)').textContent = 'Primary API Key: Active';
                    }
                } else {
                    // Show error message
                    feedback.innerHTML = '<div class="notice notice-error inline"><p>' + 
                        'Error generating API key' + '</p></div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                feedback.innerHTML = '<div class="notice notice-error inline"><p>' + 
                    'Error generating API key' + '</p></div>';
            })
            .finally(() => {
                button.disabled = false;
            });
        });
    }

    // Copy functionality
    document.querySelectorAll('.copy-button').forEach(button => {
        button.addEventListener('click', function() {
            const textToCopy = this.dataset.clipboardText;
            navigator.clipboard.writeText(textToCopy).then(() => {
                // Visual feedback
                const icon = this.querySelector('.dashicons');
                icon.classList.remove('dashicons-clipboard');
                icon.classList.add('dashicons-yes');
                
                setTimeout(() => {
                    icon.classList.remove('dashicons-yes');
                    icon.classList.add('dashicons-clipboard');
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy text: ', err);
            });
        });
    });

    // Add screenshot button handler
    const screenshotButton = document.getElementById('sewn-test-button');
    if (screenshotButton) {
        screenshotButton.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('Screenshot button clicked - initiating request');

            const testUrl = document.getElementById('test-url').value;
            const width = document.getElementById('width').value;
            const height = document.getElementById('height').value;
            const quality = document.getElementById('quality').value;
            const feedback = document.getElementById('test-feedback');
            const spinner = document.querySelector('.spinner');

            console.log('Sending request with data:', {
                url: testUrl,
                width: width,
                height: height,
                quality: quality
            });

            // Show spinner and disable button
            spinner.style.visibility = 'visible';
            screenshotButton.disabled = true;

            // Use REST API instead of admin-ajax
            fetch(`${sewn_settings.rest_url}sewn-screenshots/v1/screenshot`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': sewn_settings.rest_nonce
                },
                body: JSON.stringify({
                    url: testUrl,
                    options: {
                        width: parseInt(width),
                        height: parseInt(height),
                        quality: parseInt(quality)
                    }
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    feedback.innerHTML = `
                        <div class="notice notice-success inline">
                            <p>Screenshot taken successfully</p>
                            <div class="screenshot-preview">
                                <img src="${data.screenshot_url}" alt="Screenshot preview">
                            </div>
                            <p>Size: ${data.metadata.file_size}</p>
                            <p>Dimensions: ${data.metadata.width}x${data.metadata.height}</p>
                        </div>`;
                } else {
                    feedback.innerHTML = `
                        <div class="notice notice-error inline">
                            <p>${data.message || 'Error taking screenshot'}</p>
                        </div>`;
                }
            })
            .catch(error => {
                console.error('Screenshot error:', error);
                feedback.innerHTML = `
                    <div class="notice notice-error inline">
                        <p>Error taking screenshot: ${error.message}</p>
                    </div>`;
            })
            .finally(() => {
                spinner.style.visibility = 'hidden';
                screenshotButton.disabled = false;
            });
        });
    }

    // Add new tab navigation functionality
    const initTabNavigation = () => {
        const tabs = document.querySelectorAll('.sewn-admin-tab');
        const savedTab = localStorage.getItem('sewn_active_tab');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                const tabId = this.dataset.tab;
                
                // Update active states
                document.querySelectorAll('.sewn-admin-tab').forEach(t => 
                    t.classList.remove('active'));
                document.querySelectorAll('.sewn-tab-content').forEach(c => 
                    c.classList.remove('active'));
                
                this.classList.add('active');
                document.getElementById(tabId).classList.add('active');
                
                // Save active tab
                localStorage.setItem('sewn_active_tab', tabId);
                
                // Update URL without reload
                const newUrl = new URL(window.location);
                newUrl.searchParams.set('tab', tabId);
                window.history.pushState({}, '', newUrl);
            });
        });
        
        // Activate saved tab or first tab
        if (savedTab) {
            const savedTabElement = document.querySelector(`[data-tab="${savedTab}"]`);
            if (savedTabElement) savedTabElement.click();
        } else if (tabs.length) {
            tabs[0].click();
        }
    };

    // Add installation progress refresh
    const initInstallationProgress = () => {
        const progressTable = document.querySelector('.sewn-installation-table-wrapper');
        if (!progressTable) return;

        const refreshProgress = async () => {
            try {
                const response = await fetch(ajaxurl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({
                        action: 'sewn_refresh_installation_progress',
                        nonce: sewn_settings.nonce
                    })
                });
                
                const data = await response.json();
                if (data.success) {
                    progressTable.innerHTML = data.data.html;
                }
            } catch (error) {
                console.error('Failed to refresh installation progress:', error);
            }
        };

        // Refresh every 30 seconds if installation tab is active
        setInterval(() => {
            if (document.getElementById('sewn-tab-installation').classList.contains('active')) {
                refreshProgress();
            }
        }, 30000);
    };

    // Initialize new functionality
    initTabNavigation();
    initInstallationProgress();
}); 