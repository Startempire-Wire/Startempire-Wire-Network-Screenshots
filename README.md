# Startempire Wire Network Screenshots Plugin - Technical Summary

This WordPress plugin (“startempire-wire-network-screenshots”) provides a robust solution to capture, cache, and deliver screenshots through both local and external fallback methods. Its central purpose is to serve as part of the Startempire Wire software ecosystem, particularly integrating with and supporting the “Startempire Wire Network Chrome Extension” for capturing and displaying website screenshots.

---

## 1. Plugin Purpose

• Provide REST API endpoints for external services and front-end modules (including the Chrome Extension) to request screenshots of URLs.  
• Offer local screenshot generation using wkhtmltoimage (or potentially the Chrome-php library) for faster, direct captures.  
• Support third-party fallback APIs (such as Screenshot Machine) for situations where local generation fails or is disabled.  
• Secure screenshot requests and usage through API Key or membership token verification (including partial or advanced premium membership checks).  
• Cache and store the generated screenshots in a local directory or remote storage, serving them quickly on future requests.  
• Maintain an administrative dashboard within WordPress to configure the screenshot service, fallback settings, API keys, and test functionality.

---

## 2. Installation and Activation

1. **Obtain the Plugin**  
   Download or clone the “startempire-wire-network-screenshots” plugin code into your WordPress “/wp-content/plugins/” directory.

2. **Activate in WordPress**  
   • Log in to your WordPress Admin interface.  
   • Navigate to “Plugins” → “Installed Plugins”.  
   • Locate “Startempire Wire Network Screenshots” and click “Activate”.  

3. **Database Table Creation**  
   Upon activation, the plugin automatically creates additional database tables (such as “wp_sewn_api_logs”) via WordPress’s “dbDelta()” method for logging and analytics.  

4. **Composer Dependencies (Optional)**  
   • If you are developing or using advanced features, run “composer install” in the plugin directory to ensure the Chrome-php library or other dependencies are installed (if you are using local screenshot generation with Chrome).  

---

## 3. Configuration

After activation, a new menu and submenus appear in WordPress Admin. The main plugin slug is usually “sewn-screenshots”. Configuration options include:

• **Wkhtmltopdf / Wkhtmltoimage Path**  
  Enter the path to the local binary if available; the plugin detects it automatically or you can configure it manually.  

• **Enable / Disable Fallback Services**  
  Provide API credentials (e.g., Screenshot Machine key or other fallback providers).  

• **Premium Token or Membership Integration**  
  If you have a membership or premium system, you may set the membership token here so that screenshot requests can be validated properly.  

• **Caching and Purging**  
  Configure how screenshots should be cached locally. You can also purge the entire existing screenshot cache.  

• **API Logs**  
  Review logs for recent screenshot requests, successes, or failures.  

---

## 4. Usage and Available REST APIs

The plugin exposes REST API routes under “/wp-json/sewn-screenshots/v1/”. Below are the primary endpoints, along with brief notes about authentication and rate limiting:

1. **POST /wp-json/sewn-screenshots/v1/screenshot**  
   • Purpose: Creates a new screenshot of the specified URL.  
   • Parameters:  
     - “url” (required): The URL to capture.  
     - “type” (optional): Screenshot type, e.g., "full" or "preview". Defaults to "full".  
     - “options” (optional): An object containing “width”, “height”, “quality”, etc.  
   • Headers:  
     - “X-API-Key” (or JWT / OAuth2 alternatives). Authentication is required.  
   • Rate Limited: Yes (free tier: 60/hour, premium tier: 300/hour).  
   • Cache Enabled: Yes (screenshot results are cached for performance).  
   • Response:  
     - On success: { "success": true, "screenshot_url": "…", "message": "Screenshot captured", … }  
     - On error: { "success": false, "message": "Error details" }

2. **GET /wp-json/sewn-screenshots/v1/preview/screenshot**  
   • Purpose: Retrieves an optimized preview screenshot for a given URL.  
   • Parameters:  
     - “url” (required): The URL to capture.  
     - “options” (optional): Width, height, quality, and format (png, jpg, webp).  
   • Auth Required: No.  
   • Rate Limited: No.  
   • Cache Enabled: Yes (24h by default).  
   • Response:  
     - On success: Standard screenshot data, similar to the “/screenshot” endpoint.  
     - On error: { "success": false, "message": "Error details" }

3. **GET /wp-json/sewn-screenshots/v1/status**  
   • Purpose: Provides plugin environment status, including local binary detection, fallback availability, cache stats, and rate limit usage.  
   • Auth Required: Yes (“X-API-Key” or equivalent).  
   • Rate Limited: Yes (free tier: 60/hour, premium tier: 300/hour).  
   • Response Example:  
     {  
       "wkhtmltoimage_found": bool,  
       "fallback_available": bool,  
       "cache_size": number,  
       "rate_limits": {...},  
       …  
     }

4. **POST /wp-json/sewn-screenshots/v1/cache/purge**  
   • Purpose: Clears out or selectively purges locally cached screenshots.  
   • Auth Required: Administrator privileges only.  
   • Rate Limited: No.  
   • Headers:  
     - “X-API-Key” (Admin key or user with manage_options capability).  
   • Response:  
     - On success: { "success": true, "message": "Cache purged", "statistics": {...} }  
     - On error: { "success": false, "message": "Error details" }

5. **GET /wp-json/sewn-screenshots/v1/auth/connect**  
   • Purpose: Initiates network authentication (OAuth2) with a secure PKCE flow.  
   • Auth Required: No.  
   • Rate Limited: No.  
   • Response:  
     - On success: { "success": true, "auth_url": "…", "state": "…", "code_challenge": "…" }  
     - On error: Standard error response.

6. **POST /wp-json/sewn-screenshots/v1/auth/exchange**  
   • Purpose: Exchanges a temporary token for an API key (completes OAuth2 flow).  
   • Auth Required: No.  
   • Rate Limited: No.  
   • Parameters:  
     - “token”: Temporary token from OAuth2 provider.  
     - “state”: Used to validate the in-progress authentication.  
   • Response:  
     - On success: { "success": true, "message": "Token exchanged successfully", … }  
     - On error: Standard error response.

7. **Optional: Additional Routes**  
   If you enable fallback logic, premium membership checks, or other advanced features, the plugin can provide extra routes for regenerating tokens, retrieving logs, handling large batch screenshot requests, or further integration with membership tiers. Refer to the admin dashboard and code documentation for details on enabling these routes.

---

## 5. Admin Dashboard and Features

After configuration, administrators can access:

• **Dashboard**  
  Summaries of recent screenshot requests, caching stats, fallback usage, and space used.  

• **API Tester**  
  Allows administrators to manually trigger screenshot captures for testing from within WordPress.  

• **API Management**  
  Key regeneration, fallback service configuration, and reporting on recent usage.  

• **Settings**  
  Interface to manage paths, caching, membership tokens, local or fallback toggles, and more.  

• **Test Results**  
  The plugin includes a test runner to confirm generation success. Administrators can run test suites to verify screenshots, caching, and membership checks.  

---

## 6. Example Workflow

1. **Initial Setup**  
   - Install and activate plugin.  
   - Provide “wkhtmltoimage” path if you have the binary. Enable fallback if necessary.  
   - Configure membership token or API key (if you wish to require it).  

2. **Generate a Screenshot (Portal or API)**  
   - API users call “/wp-json/sewn-screenshots/v1/screenshot” with the “url” parameter.  
   - The plugin checks local cache first. If no valid screenshot is cached, it attempts local image generation.  
   - If local generation fails and fallback is enabled, the plugin attempts the configured fallback service.  
   - The plugin returns a JSON response with the final screenshot URL (or an error message).  

3. **View Logs and Dashboards**  
   - Administrators see request logs and statuses in the “API Management” or “Dashboard” admin sections.  
   - The plugin can also show fallback usage statistics, caching info, and membership checks.  

4. **Maintenance**  
   - Administrators can purge old screenshots from the cache.  
   - Key or token regeneration can be performed if the membership or fallback providers require updated credentials.  

---

## 7. Security Considerations

1. **API Key / Membership Token**  
   - Sensitive routes require the “X-API-Key” or membership token in headers.  
   - The plugin verifies these credentials to restrict usage of screenshot endpoints.  

2. **Local Binaries**  
   - If using “wkhtmltoimage”, ensure your server is secured and the binary is installed from reputable sources.  

3. **Fallback API Usage**  
   - Only store and use fallback credentials from trusted providers.  

4. **Caching and File Permissions**  
   - Ensure the plugin’s “screenshots” directory is secure with proper file permissions to avoid unauthorized access.  

---

## 8. Concluding Notes

The “Startempire Wire Network Screenshots” plugin integrates seamlessly with the broader Startempire Wire ecosystem, especially the “Startempire Wire Network Chrome Extension”. It streamlines screenshot capture, caching, and distribution while offering flexible configuration, membership validation, fallback services, and testing capabilities.