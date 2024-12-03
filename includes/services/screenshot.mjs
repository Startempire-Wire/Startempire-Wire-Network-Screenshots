import { exec } from 'child_process';
import { promisify } from 'util';
import sharp from 'sharp';
import path from 'path';
import fs from 'fs/promises';

const execAsync = promisify(exec);

export class ScreenshotService {
    constructor(outputDir = 'screenshots') {
        this.outputDir = outputDir;
        this.init().catch(console.error);
    }

    async init() {
        // Ensure screenshots directory exists
        await fs.mkdir(this.outputDir, { recursive: true });
    }

    async takeScreenshot(url, filename, options = {}) {
        const {
            width = 1024,
            height = 768,
            quality = 90,
            delay = 1000,
            format = 'png'
        } = options;

        try {
            const outputPath = path.join(this.outputDir, filename);
            const tempPath = `${outputPath}.temp.${format}`;

            // Build wkhtmltoimage options
            const wkOptions = [
                `--quality ${quality}`,
                `--width ${width}`,
                `--height ${height}`,
                '--enable-javascript',
                `--javascript-delay ${delay}`,
                '--no-stop-slow-scripts',
                '--disable-smart-width'
            ].join(' ');

            const cmd = `wkhtmltoimage ${wkOptions} "${url}" "${tempPath}"`;
            await execAsync(cmd);

            // Optimize with sharp
            await sharp(tempPath)
                .resize(width, height)
                .png({ quality: Math.min(quality, 80) }) // Ensure reasonable file size
                .toFile(outputPath);

            // Clean up temp file
            await fs.unlink(tempPath);

            // Get file stats
            const stats = await fs.stat(outputPath);

            return {
                success: true,
                path: outputPath,
                url: url,
                timestamp: new Date().toISOString(),
                size: stats.size,
                dimensions: {
                    width,
                    height
                }
            };
        } catch (error) {
            return {
                success: false,
                error: error.message,
                url: url,
                timestamp: new Date().toISOString()
            };
        }
    }

    async checkDependencies() {
        try {
            const { stdout } = await execAsync('wkhtmltoimage --version');
            const dirExists = await fs.access(this.outputDir)
                .then(() => true)
                .catch(() => false);

            return {
                available: true,
                version: stdout.trim(),
                outputDir: this.outputDir,
                dirExists,
                sharp: {
                    version: sharp.versions.sharp
                }
            };
        } catch (error) {
            return {
                available: false,
                error: error.message
            };
        }
    }

    async cleanup(olderThan = 24 * 60 * 60 * 1000) { // 24 hours in milliseconds
        try {
            const files = await fs.readdir(this.outputDir);
            const now = Date.now();
            
            for (const file of files) {
                const filePath = path.join(this.outputDir, file);
                const stats = await fs.stat(filePath);
                
                if (now - stats.mtimeMs > olderThan) {
                    await fs.unlink(filePath);
                }
            }
            
            return { success: true, cleaned: files.length };
        } catch (error) {
            return { success: false, error: error.message };
        }
    }
}