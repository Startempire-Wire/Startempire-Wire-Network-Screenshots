class ScreenshotToolDetector {
    constructor() {
        this.status = {
            detecting: false,
            error: null
        };
    }

    async detectClientTools() {
        try {
            this.status.detecting = true;
            this.updateUI('detecting');

            const results = {
                html2canvas: typeof html2canvas !== 'undefined',
                puppeteer: await this.checkNpmPackage('puppeteer'),
                chrome: await this.detectChrome(),
                capabilities: await this.detectCapabilities()
            };

            await this.reportResults(results);
            this.updateUI('complete', results);
            return results;

        } catch (error) {
            this.status.error = error.message;
            this.updateUI('error', error);
            throw error;
        } finally {
            this.status.detecting = false;
        }
    }

    async reportResults(results) {
        try {
            const response = await fetch(sewnDetector.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': sewnDetector.nonce
                },
                body: JSON.stringify({
                    action: 'sewn_report_client_tools',
                    results: results
                })
            });

            if (!response.ok) {
                throw new Error('Failed to report results');
            }

            return await response.json();
        } catch (error) {
            console.error('Failed to report results:', error);
            throw error;
        }
    }

    async detectCapabilities() {
        return {
            webp: await this.checkWebP(),
            canvas: !!document.createElement('canvas').getContext,
            webgl: await this.checkWebGL()
        };
    }

    updateUI(status, data = null) {
        const event = new CustomEvent('sewn-detection-update', {
            detail: { status, data }
        });
        window.dispatchEvent(event);
    }
} 