import { ScreenshotService } from '../includes/services/screenshot.mjs';

async function test() {
    try {
        console.log('Starting screenshot test...');
        
        const service = new ScreenshotService();
        console.log('Service initialized');
        
        // Check dependencies
        console.log('Checking dependencies...');
        const status = await service.checkDependencies();
        console.log('Dependencies Status:', JSON.stringify(status, null, 2));

        if (status.available) {
            // Take a test screenshot with custom options
            console.log('\nTaking screenshot with options:');
            const options = {
                width: 1280,
                height: 800,
                quality: 85,
                delay: 1500
            };
            console.log(JSON.stringify(options, null, 2));

            const result = await service.takeScreenshot(
                'https://example.com',
                'test-screenshot.png',
                options
            );
            console.log('\nScreenshot Result:', JSON.stringify(result, null, 2));

            // Clean up old screenshots
            console.log('\nCleaning up old screenshots...');
            const cleanup = await service.cleanup();
            console.log('Cleanup Result:', JSON.stringify(cleanup, null, 2));
        } else {
            console.error('Service not available:', status.error);
        }
    } catch (error) {
        console.error('Test failed:', error);
    }
}

// Run the test
console.log('=== Screenshot Service Test ===');
test()
    .then(() => console.log('Test completed'))
    .catch(error => console.error('Test failed:', error));