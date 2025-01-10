document.addEventListener('DOMContentLoaded', function() {
    console.log('API Tester initialized with config:', sewnApiTesterData);
    console.log('Binding API Tester events');

    const testButton = document.getElementById('run-api-test');
    const urlInput = document.getElementById('test-url');
    const widthInput = document.getElementById('test-width');
    const heightInput = document.getElementById('test-height');
    const qualityInput = document.getElementById('test-quality');
    const resultsDiv = document.getElementById('test-results');
    const spinner = document.querySelector('.spinner');

    if (testButton) {
        testButton.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('Screenshot test initiated');
            
            testButton.disabled = true;
            spinner.style.visibility = 'visible';
            resultsDiv.innerHTML = `<p>${sewnApiTesterData.strings.generating}</p>`;

            const data = {
                url: urlInput.value,
                options: {
                    width: parseInt(widthInput.value),
                    height: parseInt(heightInput.value),
                    quality: parseInt(qualityInput.value)
                }
            };

            fetch(sewnApiTesterData.rest_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': sewnApiTesterData.rest_nonce
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(response => {
                console.log('Screenshot response:', response);
                
                const data = response.data || response;
                const metadata = data.metadata || {};
                const created = new Date(metadata.created * 1000).toLocaleString();
                const screenshotUrl = data.screenshot_url || data.url;
                
                resultsDiv.innerHTML = `
                    <div class="sewn-test-success">
                        <h3>${sewnApiTesterData.strings.success}</h3>
                        
                        <div class="screenshot-preview">
                            <img src="${screenshotUrl}" alt="Screenshot preview" 
                                 onerror="this.onerror=null; this.src='${sewnApiTesterData.plugin_url}assets/images/fallback.png';" />
                        </div>

                        <div class="screenshot-metadata">
                            <h4>Screenshot Details:</h4>
                            <div class="metadata-grid">
                                <div class="metadata-section">
                                    <h5>Service Information</h5>
                                    <ul>
                                        <li><strong>Method:</strong> ${data.method || 'N/A'}</li>
                                        <li><strong>Service:</strong> ${data.service || 'N/A'}</li>
                                        <li><strong>Auth Method:</strong> ${data.auth_method || 'N/A'}</li>
                                    </ul>
                                </div>
                                
                                <div class="metadata-section">
                                    <h5>Image Details</h5>
                                    <ul>
                                        <li><strong>Dimensions:</strong> ${data.width || 1280}x${data.height || 800}px</li>
                                        <li><strong>File Size:</strong> ${formatFileSize(data.file_size)}</li>
                                        <li><strong>Format:</strong> ${data.format || 'PNG'}</li>
                                    </ul>
                                </div>
                                
                                <div class="metadata-section">
                                    <h5>Performance</h5>
                                    <ul>
                                        <li><strong>Processing Time:</strong> ${metadata.duration || 0}ms</li>
                                        <li><strong>Cache Status:</strong> ${(data.cache && data.cache.hit) ? '<span class="cache-hit">HIT</span>' : '<span class="cache-miss">MISS</span>'}</li>
                                        <li><strong>Cache Age:</strong> ${formatCacheAge(data.cache?.age)}</li>
                                    </ul>
                                </div>

                                <div class="metadata-section">
                                    <h5>Request Details</h5>
                                    <ul>
                                        <li><strong>Created:</strong> ${created}</li>
                                        <li><strong>Source:</strong> ${metadata.source || 'N/A'}</li>
                                        <li><strong>Request ID:</strong> ${data.request_id || 'N/A'}</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>`;

                function formatFileSize(bytes) {
                    if (!bytes) return 'N/A';
                    const units = ['B', 'KB', 'MB', 'GB'];
                    let size = bytes;
                    let unitIndex = 0;
                    while (size >= 1024 && unitIndex < units.length - 1) {
                        size /= 1024;
                        unitIndex++;
                    }
                    return `${size.toFixed(2)} ${units[unitIndex]}`;
                }

                function formatCacheAge(seconds) {
                    if (!seconds) return 'N/A';
                    if (seconds < 60) return `${seconds}s`;
                    if (seconds < 3600) return `${Math.floor(seconds/60)}m`;
                    return `${Math.floor(seconds/3600)}h`;
                }
            })
            .catch(error => {
                resultsDiv.innerHTML = `
                    <div class="sewn-test-error">
                        <p>${sewnApiTesterData.strings.error}</p>
                        <p>${error.message}</p>
                    </div>`;
            })
            .finally(() => {
                spinner.style.visibility = 'hidden';
                testButton.disabled = false;
            });
        });
    }
}); 