const puppeteer = require('puppeteer');
const path = require('path');

async function captureScreenshot(url) {
    const browser = await puppeteer.launch({
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });
    
    try {
        const page = await browser.newPage();
        await page.setViewport({ width: 1280, height: 720 });
        await page.goto(url, { waitUntil: 'networkidle0' });
        
        const screenshotPath = path.join(__dirname, '../../screenshots', `${Date.now()}.jpg`);
        await page.screenshot({
            path: screenshotPath,
            type: 'jpeg',
            quality: 80
        });
        
        return screenshotPath;
    } finally {
        await browser.close();
    }
}

module.exports = captureScreenshot;