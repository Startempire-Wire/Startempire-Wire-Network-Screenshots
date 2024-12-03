# Startempire Wire Network Screenshots

WordPress plugin for capturing and managing website screenshots.

## Installation

1. Upload plugin to `/wp-content/plugins/`
2. Install Node.js dependencies:   ```bash
   cd wp-content/plugins/startempire-wire-network-screenshots
   npm install   ```
3. Ensure directories are writable:   ```bash
   chmod 755 screenshots/
   chmod 755 logs/   ```
4. Activate plugin in WordPress admin
5. Configure API key and settings

## Configuration

Access settings via WordPress admin:
Dashboard > Screenshot Service

## API Usage

Endpoint: `/wp-json/startempire/v1/screenshot`
Method: POST
Headers: 
- X-API-Key: {your-api-key}
- Content-Type: application/json

Body:
```json
{
  "url": "https://example.com"
}
```