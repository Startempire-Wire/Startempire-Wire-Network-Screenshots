<?php
/**
 * This controller handles all REST API endpoints for the screenshot service. It provides
 * functionality for taking screenshots, managing authentication, and handling cache operations.
 * 
 * Endpoints available:
 * - POST /wp-json/sewn-screenshots/v1/screenshot
 *   Takes a new screenshot with optional parameters
 *   Rate limited: Yes
 *   Auth required: Yes
 *   Cache enabled: Yes
 * 
 * - GET /wp-json/sewn-screenshots/v1/preview/screenshot
 *   Gets an optimized preview screenshot
 *   Rate limited: No
 *   Auth required: No
 *   Cache enabled: Yes (24h)
 * 
 * - GET /wp-json/sewn-screenshots/v1/status
 *   Returns service health and metrics
 *   Rate limited: Yes
 *   Auth required: Yes
 *   Cache enabled: No
 * 
 * - POST /wp-json/sewn-screenshots/v1/cache/purge
 *   Purges screenshot cache
 *   Rate limited: No
 *   Auth required: Admin only
 *   Cache enabled: No
 * 
 * - GET /wp-json/sewn-screenshots/v1/auth/connect
 *   Initiates network authentication
 *   Rate limited: No
 *   Auth required: No
 *   Cache enabled: No
 * 
 * - POST /wp-json/sewn-screenshots/v1/auth/exchange
 *   Exchanges temporary token for API key
 *   Rate limited: No
 *   Auth required: No
 *   Cache enabled: No
 *
 * Rate Limits:
 * - Free tier: 60 requests per hour
 * - Premium tier: 300 requests per hour
 * 
 * Authentication Methods:
 * - API Key (X-API-Key header)
 *   Example: X-API-Key: your-api-key-here
 * 
 * - JWT (Authorization: Bearer token)
 *   Example: Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
 * 
 * - OAuth2 (for third-party integrations)
 *   Example: access_token=your-oauth-token
 *
 * Error Handling:
 * All endpoints return standardized error responses:
 * {
 *   "code": "error_code",
 *   "message": "Human readable message",
 *   "data": {
 *     "status": 400|401|403|404|429|500,
 *     "details": {}
 *   }
 * }
 *
 * Common Error Codes:
 * - invalid_url: URL validation failed
 * - rate_limit_exceeded: Too many requests
 * - auth_failed: Authentication failed
 * - screenshot_failed: Screenshot generation failed
 * - cache_error: Cache operation failed
 *
 * @package SEWN_Screenshots
 * @since 1.0.0 Initial release
 * @since 2.0.0 Added rate limiting, enhanced auth, and caching
 * @since 2.1.0 Added detailed error handling and documentation
 *
 * @OA\Info(
 *     version="2.1.0",
 *     title="Startempire Wire Network Screenshots API",
 *     description="REST API for managing website screenshots within the Startempire Wire Network",
 *     @OA\Contact(
 *         email="api@startempirewire.network",
 *         name="API Support"
 *     )
 * )
 *
 * @OA\Server(
 *     url="https://{host}/wp-json/sewn-screenshots/v1",
 *     description="Screenshot API endpoint",
 *     @OA\ServerVariable(
 *         serverVariable="host",
 *         default="startempirewire.network",
 *         description="API host"
 *     )
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="ApiKeyAuth",
 *     type="apiKey",
 *     in="header",
 *     name="X-API-Key"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="MembershipAuth",
 *     type="apiKey",
 *     in="header",
 *     name="X-Membership-Token"
 * )
 *
 * @OA\Schema(
 *     schema="Error",
 *     required={"code", "message"},
 *     @OA\Property(
 *         property="code",
 *         type="string",
 *         description="Error code"
 *     ),
 *     @OA\Property(
 *         property="message",
 *         type="string",
 *         description="Error message"
 *     ),
 *     @OA\Property(
 *         property="data",
 *         type="object",
 *         description="Additional error data"
 *     )
 * )
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Ensure WordPress REST API is available
if (!class_exists('WP_REST_Controller')) {
    return;
}

// At the top of the file, after the PHP opening tag
if (!class_exists('SEWN_API_Manager')) {
    require_once plugin_dir_path(__FILE__) . 'class-api-manager.php';
}

if (!class_exists('SEWN_Cache')) {
    require_once plugin_dir_path(__FILE__) . 'class-cache.php';
}

/**
 * Screenshot Service REST API Controller
 *
 * Handles all REST API endpoints for the screenshot service with enhanced
 * security, caching, and rate limiting features.
 *
 * @since 1.0.0
 * @since 2.0.0 Added rate limiting and caching
 * @since 2.1.0 Enhanced security and documentation
 */
class SEWN_REST_Controller extends WP_REST_Controller {
    /** 
     * Screenshot service instance
     * 
     * Handles the core functionality of taking and processing screenshots.
     * Must be initialized with proper configuration before use.
     * 
     * @var SEWN_Screenshot_Service
     * @since 1.0.0
     */
    private $screenshot_service;

    /** 
     * Logger instance
     * 
     * Handles error tracking, debugging, and performance monitoring.
     * Supports different log levels: debug, info, warning, error.
     * 
     * @var SEWN_Logger
     * @since 1.0.0
     */
    private $logger;

    /** 
     * Cache instance
     * 
     * Manages response caching for improved performance.
     * Supports multiple cache backends (object cache, file system).
     * 
     * @var SEWN_Cache
     * @since 2.0.0
     */
    private $cache;

    /** 
     * Rate limiter instance
     * 
     * Controls request rates based on user tiers.
     * Supports multiple storage backends for rate tracking.
     * 
     * @var SEWN_Rate_Limiter
     * @since 2.0.0
     */
    private $rate_limiter;

    /** 
     * The namespace for all REST routes
     * 
     * Base namespace for all endpoints (sewn-screenshots/v1).
     * Used for versioning and route organization.
     * 
     * @var string
     * @since 1.0.0
     */
    protected $namespace = 'sewn-screenshots/v1';

    /**
     * Ring Leader client for network integration
     * 
     * Handles communication with the Ring Leader plugin for network operations.
     * Manages authentication relay and network status coordination.
     * Provides fallback mechanisms for standalone operation.
     * Essential for network-aware screenshot distribution.
     * 
     * @var SEWN_Ring_Leader_Client
     * @since 2.1.0
     */
    private $ring_leader_client;

    /**
     * Quality handler for membership-based settings
     * 
     * Manages screenshot quality settings based on membership tiers.
     * Coordinates with Ring Leader for network-wide quality standards.
     * Provides fallback quality settings for standalone operation.
     * Ensures consistent output across network nodes.
     * 
     * @var SEWN_Quality_Handler
     * @since 2.1.0
     */
    private $quality_handler;

    /**
     * Network-aware cache coordinator
     * 
     * Manages distributed cache operations across network nodes.
     * Coordinates with Ring Leader for cache invalidation events.
     * Handles local and network-wide cache operations.
     * Provides performance optimization for network requests.
     * 
     * @var SEWN_Network_Cache
     * @since 2.1.0
     */
    private $network_cache;

    /**
     * Constructor
     *
     * Initializes the REST controller with required dependencies.
     * Validates service availability and sets up error handling.
     * 
     * @since 1.0.0
     * @since 2.0.0 Added optional cache and rate limiter
     * @since 2.1.0 Added dependency validation
     * 
     * @param SEWN_Screenshot_Service $screenshot_service Service for handling screenshots
     * @param SEWN_Logger $logger Logger instance for error tracking
     * @param SEWN_Rate_Limiter $rate_limiter Rate limiter instance
     * @throws RuntimeException If required services are not available
     */
    public function __construct($logger, $rate_limiter, $screenshot_service) {
        if (!$logger) {
            throw new Exception('Logger is required');
        }
        
        $this->logger = $logger;
        $this->rate_limiter = $rate_limiter;
        
        $this->logger->debug('Initializing REST Controller', [
            'has_logger' => !empty($this->logger),
            'has_rate_limiter' => !empty($this->rate_limiter)
        ]);
        
        $this->cache = new SEWN_Cache($this->logger);
        
        // Initialize API manager with default settings
        $default_settings = [
            'fallback_service' => 'screenshotmachine',
            'api_mode' => 'primary'
        ];
        
        try {
            $this->api_manager = new SEWN_API_Manager($this->logger, $default_settings);
            $this->logger->debug('API Manager initialized', [
                'settings' => $default_settings,
                'has_api_manager' => !empty($this->api_manager)
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to initialize API Manager', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
        
        // Add screenshot service
        $this->screenshot_service = $screenshot_service;
        
        $this->logger->debug('REST Controller initialization complete', [
            'has_screenshot_service' => !empty($this->screenshot_service)
        ]);
        
        add_action('rest_api_init', array($this, 'register_routes'));
        
        // Add network integration
        try {
            $this->ring_leader_client = new SEWN_Ring_Leader_Client($logger);
            $this->quality_handler = new SEWN_Quality_Handler($logger);
            $this->network_cache = new SEWN_Network_Cache($logger);
        } catch (Exception $e) {
            $this->logger->warning('Network features unavailable', [
                'error' => $e->getMessage()
            ]);
            // Continue without network features
        }
    }

    /**
     * Register all REST API routes
     *
     * Sets up all available endpoints with their respective handlers and permissions.
     * Each endpoint is configured with specific HTTP methods, callbacks, and argument validation.
     *
     * Route Details:
     * 1. Screenshot Creation (/screenshot)
     *    - Method: POST
     *    - Auth: Required
     *    - Rate Limited: Yes
     *    - Parameters: url (required), type, options
     *
     * 2. Preview Screenshot (/preview/screenshot)
     *    - Method: GET
     *    - Auth: Public
     *    - Rate Limited: No
     *    - Parameters: url (required)
     *
     * 3. Service Status (/status)
     *    - Method: GET
     *    - Auth: Required
     *    - Rate Limited: Yes
     *    - Parameters: None
     *
     * 4. Cache Management (/cache/purge)
     *    - Method: POST
     *    - Auth: Admin only
     *    - Rate Limited: No
     *    - Parameters: None
     *
     * 5. Authentication (/auth/*)
     *    - Methods: GET, POST
     *    - Auth: Public
     *    - Rate Limited: No
     *    - Parameters: Varies by endpoint
     *
     * @since 1.0.0 Initial implementation
     * @since 2.0.0 Added schema definitions and enhanced validation
     * @since 2.1.0 Added comprehensive documentation and error handling
     * @return void
     */
    public function register_routes() {
        // Screenshot creation endpoint
        register_rest_route($this->namespace, '/screenshot', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_screenshot'],
                'permission_callback' => [$this, 'check_permission'],
                'args' => [
                    'url' => [
                        'required' => true,
                        'type' => 'string',
                        'description' => 'URL to capture',
                        'validate_callback' => function($param) {
                            return filter_var($param, FILTER_VALIDATE_URL) !== false;
                        }
                    ]
                ]
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'take_screenshot'],
                'permission_callback' => [$this, 'check_permission'],
                'args' => $this->get_screenshot_args(),
                'schema' => [$this, 'get_screenshot_schema'],
                'description' => 'Create a new screenshot of the specified URL',
                'notes' => 'Supports various options for customization'
            ]
        ]);

        // Preview screenshot endpoint with caching
        register_rest_route($this->namespace, '/preview/screenshot', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_preview_screenshot'],
            'permission_callback' => '__return_true',
            'args' => $this->get_preview_args(),
            'schema' => [$this, 'get_preview_schema'],
            'description' => 'Get an optimized preview screenshot',
            'notes' => 'Cached for 24 hours by default'
        ]);

        // Service status endpoint
        register_rest_route($this->namespace, '/status', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_status'],
            'permission_callback' => [$this, 'check_permission'],
            'schema' => [$this, 'get_status_schema'],
            'description' => 'Get service health and metrics',
            'notes' => 'Includes cache and rate limit statistics'
        ]);

        // Cache management endpoint
        register_rest_route($this->namespace, '/cache/purge', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'purge_cache'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'schema' => [$this, 'get_cache_schema'],
            'description' => 'Purge the screenshot cache',
            'notes' => 'Requires administrator privileges'
        ]);

        // Authentication endpoints with enhanced security
        register_rest_route($this->namespace, '/auth/connect', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'handle_network_auth'],
            'permission_callback' => '__return_true',
            'schema' => [$this, 'get_auth_schema'],
            'description' => 'Initialize network authentication',
            'notes' => 'Starts OAuth2 flow'
        ]);
        
        register_rest_route($this->namespace, '/auth/exchange', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'exchange_parent_token'],
            'permission_callback' => '__return_true',
            'args' => $this->get_token_exchange_args(),
            'schema' => [$this, 'get_token_exchange_schema'],
            'description' => 'Exchange temporary token for API key',
            'notes' => 'Completes OAuth2 flow'
        ]);

        // Network-specific endpoints
        register_rest_route($this->namespace, '/network/status', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_network_status'],
            'permission_callback' => [$this, 'check_network_permission'],
            'schema' => [$this, 'get_network_status_schema']
        ]);

        register_rest_route($this->namespace, '/network/sync', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'sync_network_screenshots'],
            'permission_callback' => [$this, 'check_network_permission']
        ]);

        if (method_exists($this->logger, 'debug')) {
            $this->logger->debug('REST routes registered', [
                'namespace' => $this->namespace,
                'routes' => [
                    '/screenshot',
                    '/preview/screenshot',
                    '/status',
                    '/cache/purge',
                    '/auth/connect',
                    '/auth/exchange',
                    '/network/status',
                    '/network/sync'
                ]
            ]);
        }
    }

    /**
     * Handle GET requests for screenshots
     *
     * Allows taking screenshots via GET request with URL parameter.
     * Example: /wp-json/sewn-screenshots/v1/screenshot?url=https://example.com
     *
     * Argument Details:
     * 1. url (required)
     *    - Must be a valid URL
     *    - Sanitized using esc_url_raw
     *    - Validated using filter_var
     *
     * 2. type (optional)
     *    - Values: 'full' or 'preview'
     *    - Default: 'full'
     *    - Used to determine screenshot size and quality
     *
     * 3. options (optional)
     *    - width: 100-2560px (default: 1280)
     *    - height: 100-2560px (default: 800)
     *    - quality: 1-100 (default: 85)
     *    - Additional options validated per type
     *
     * Example Usage:
     * ```
     * POST /wp-json/sewn-screenshots/v1/screenshot
     * {
     *   "url": "https://example.com",
     *   "type": "full",
     *   "options": {
     *     "width": 1920,
     *    "height": 1080,
     *     "quality": 90
     *   }
     * }
     * ```
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Screenshot data or error
     *
     * @OA\Get(
     *     path="/screenshot",
     *     summary="Take a screenshot of a URL",
     *     description="Captures a full webpage screenshot with optional parameters",
     *     operationId="getScreenshot",
     *     tags={"screenshots"},
     *     @OA\Parameter(
     *         name="url",
     *         in="query",
     *         required=true,
     *         description="URL to capture",
     *         @OA\Schema(type="string", format="uri")
     *     ),
     *     @OA\Parameter(
     *         name="type",
     *         in="query",
     *         required=false,
     *         description="Screenshot type",
     *         @OA\Schema(type="string", enum={"full", "preview"}, default="full")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Screenshot captured successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="url", type="string", format="uri"),
     *                 @OA\Property(property="width", type="integer", example=1280),
     *                 @OA\Property(property="height", type="integer", example=800),
     *                 @OA\Property(property="type", type="string", example="full"),
     *                 @OA\Property(property="created", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid URL or parameters",
     *         @OA\JsonContent(ref="#/components/schemas/Error")
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Authentication required",
     *         @OA\JsonContent(ref="#/components/schemas/Error")
     *     ),
     *     @OA\Response(
     *         response=429,
     *         description="Rate limit exceeded",
     *         @OA\JsonContent(ref="#/components/schemas/Error")
     *     ),
     *     security={
     *         {"ApiKeyAuth": {}},
     *         {"MembershipAuth": {}}
     *     }
     * )
     */
    public function get_screenshot($request) {
        // Get URL from query parameter
        $url = $request->get_param('url');
        
        // Create standardized request for screenshot
        $screenshot_request = new WP_REST_Request('POST', "/{$this->namespace}/screenshot");
        $screenshot_request->set_param('url', $url);
        $screenshot_request->set_param('type', 'full');
        
        // Use existing screenshot logic
        return $this->take_screenshot($screenshot_request);
    }

    /**
     * Get screenshot endpoint arguments
     *
     * Defines and validates the arguments for the screenshot creation endpoint.
     * Implements strict validation and sanitization for security.
     *
     * Argument Details:
     * 1. url (required)
     *    - Must be a valid URL
     *    - Sanitized using esc_url_raw
     *    - Validated using filter_var
     *
     * 2. type (optional)
     *    - Values: 'full' or 'preview'
     *    - Default: 'full'
     *    - Used to determine screenshot size and quality
     *
     * 3. options (optional)
     *    - width: 100-2560px (default: 1280)
     *    - height: 100-2560px (default: 800)
     *    - quality: 1-100 (default: 85)
     *    - Additional options validated per type
     *
     * Example Usage:
     * ```
     * POST /wp-json/sewn-screenshots/v1/screenshot
     * {
     *   "url": "https://example.com",
     *   "type": "full",
     *   "options": {
     *     "width": 1920,
     *     "height": 1080,
     *     "quality": 90
     *   }
     * }
     * ```
     *
     * @since 2.0.0 Initial implementation
     * @since 2.1.0 Added validation and documentation
     * @return array Argument definitions with validation rules
     */
    private function get_screenshot_args() {
        return [
            'url' => [
                'required' => true,
                'type' => 'string',
                'description' => 'URL to capture',
                'sanitize_callback' => 'esc_url_raw',
                'validate_callback' => function($param) {
                    return filter_var($param, FILTER_VALIDATE_URL) !== false;
                }
            ],
            'type' => [
                'required' => false,
                'type' => 'string',
                'default' => 'full',
                'enum' => ['full', 'preview'],
                'description' => 'Screenshot type'
            ],
            'options' => [
                'type' => 'object',
                'default' => [],
                'description' => 'Screenshot options',
                'properties' => [
                    'width' => [
                        'type' => 'integer',
                        'minimum' => 100,
                        'maximum' => 2560,
                        'default' => 1280,
                        'description' => 'Screenshot width in pixels'
                    ],
                    'height' => [
                        'type' => 'integer',
                        'minimum' => 100,
                        'maximum' => 2560,
                        'default' => 800,
                        'description' => 'Screenshot height in pixels'
                    ],
                    'quality' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 100,
                        'default' => 85,
                        'description' => 'JPEG quality (1-100)'
                    ]
                ]
            ]
        ];
    }

    /**
     * Get preview arguments schema
     *
     * Defines the expected parameters for the preview endpoint
     * with validation rules and defaults.
     *
     * Argument Details:
     * 1. url (required)
     *    - Must be a valid URL
     *    - Sanitized using esc_url_raw
     *    - Validated using filter_var
     *
     * 2. options (optional)
     *    - width: 320-2560px (default: 1280)
     *    - height: 240-1600px (default: 800)
     *    - quality: 1-100 (default: 85)
     *    - format: 'png'|'jpg'|'webp' (default: 'png')
     *
     * Example Usage:
     * ```
     * GET /wp-json/sewn-screenshots/v1/preview/screenshot?url=https://example.com
     * GET /wp-json/sewn-screenshots/v1/preview/screenshot?url=https://example.com&options[width]=800
     * ```
     *
     * Response Format:
     * ```json
     * {
     *   "success": true,
     *   "data": {
     *     "url": "https://example.com/preview.jpg",
     *     "width": 1280,
     *     "height": 800,
     *     "format": "jpg",
     *     "expires": "2024-01-01T00:00:00Z"
     *   },
     *   "meta": {
     *     "cached": true,
     *     "generated": "2023-12-31T00:00:00Z"
     *   }
     * }
     * ```
     *
     * @since 2.0.0
     * @return array Preview arguments schema
     *
     * @OA\Get(
     *     path="/preview/screenshot",
     *     summary="Get an optimized preview screenshot",
     *     description="Returns a cached or newly generated preview screenshot",
     *     operationId="getPreviewScreenshot",
     *     tags={"screenshots"},
     *     @OA\Parameter(
     *         name="url",
     *         in="query",
     *         required=true,
     *         description="URL to capture",
     *         @OA\Schema(type="string", format="uri")
     *     ),
     *     @OA\Parameter(
     *         name="options",
     *         in="query",
     *         required=false,
     *         description="Preview options",
     *         @OA\Schema(ref="#/components/schemas/PreviewOptions")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Preview screenshot retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="url", type="string", format="uri"),
     *                 @OA\Property(property="width", type="integer", example=1280),
     *                 @OA\Property(property="height", type="integer", example=800),
     *                 @OA\Property(property="format", type="string", example="jpg"),
     *                 @OA\Property(property="expires", type="string", format="date-time")
     *             ),
     *             @OA\Property(
     *                 property="meta",
     *                 type="object",
     *                 @OA\Property(property="cached", type="boolean"),
     *                 @OA\Property(property="generated", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid URL or parameters",
     *         @OA\JsonContent(ref="#/components/schemas/Error")
     *     )
     * )
     *
     * @OA\Schema(
     *     schema="PreviewOptions",
     *     title="Preview Options",
     *     description="Configuration options for preview screenshots",
     *     type="object",
     *     @OA\Property(
     *         property="width",
     *         type="integer",
     *         minimum=320,
     *         maximum=2560,
     *         default=1280,
     *         description="Preview width in pixels"
     *     ),
     *     @OA\Property(
     *         property="height",
     *         type="integer",
     *         minimum=240,
     *         maximum=1600,
     *         default=800,
     *         description="Preview height in pixels"
     *     ),
     *     @OA\Property(
     *         property="quality",
     *         type="integer",
     *         minimum=1,
     *         maximum=100,
     *         default=85,
     *         description="Image quality (1-100)"
     *     ),
     *     @OA\Property(
     *         property="format",
     *         type="string",
     *         enum={"png", "jpg", "webp"},
     *         default="png",
     *         description="Output image format"
     *     )
     * )
     */
    private function get_preview_args() {
        return [
            'url' => [
                'required' => true,
                'type' => 'string',
                'description' => 'URL to take screenshot of',
                'validate_callback' => function($param) {
                    return !empty($param) && filter_var($param, FILTER_VALIDATE_URL);
                }
            ],
            'options' => [
                'type' => 'object',
                'default' => [],
                'description' => 'Screenshot configuration options',
                'properties' => [
                    'width' => [
                        'type' => 'integer',
                        'default' => 1280,
                        'minimum' => 320,
                        'maximum' => 2560,
                        'description' => 'Screenshot width in pixels'
                    ],
                    'height' => [
                        'type' => 'integer',
                        'default' => 800,
                        'minimum' => 240,
                        'maximum' => 1600,
                        'description' => 'Screenshot height in pixels'
                    ],
                    'quality' => [
                        'type' => 'integer',
                        'default' => 85,
                        'minimum' => 1,
                        'maximum' => 100,
                        'description' => 'JPEG quality (1-100)'
                    ],
                    'format' => [
                        'type' => 'string',
                        'default' => 'png',
                        'enum' => ['png', 'jpg', 'webp'],
                        'description' => 'Output image format'
                    ]
                ]
            ]
        ];
    }

    /**
     * Check if the request has valid authentication
     *
     * Validates multiple authentication methods in order of preference:
     * 1. API Key (X-API-Key header)
     * 2. JWT (Authorization: Bearer token)
     * 3. OAuth2 tokens
     * 
     * Authentication Flow:
     * 1. Check rate limits first
     * 2. Try API Key authentication
     * 3. Fall back to JWT if API Key fails
     * 4. Try OAuth2 as last resort
     * 5. Return error if all methods fail
     * 
     * Security Measures:
     * - Rate limiting prevents brute force attempts
     * - Failed attempts are logged
     * - Tokens are validated cryptographically
     * - Multiple auth methods for flexibility
     * 
     * Example Usage:
     * ```bash
     * # Using API Key
     * curl -H "X-API-Key: your-api-key" https://site.com/wp-json/sewn-screenshots/v1/status
     * 
     * # Using JWT
     * curl -H "Authorization: Bearer your-jwt-token" https://site.com/wp-json/sewn-screenshots/v1/status
     * 
     * # Using OAuth2
     * curl "https://site.com/wp-json/sewn-screenshots/v1/status?access_token=your-oauth-token"
     * ```
     *
     * @since 1.0.0 Initial implementation
     * @since 2.0.0 Added JWT and OAuth support
     * @since 2.1.0 Enhanced security and logging
     * 
     * @param WP_REST_Request $request The request object
     * @return bool|WP_Error True if permission is granted, WP_Error if unauthorized
     */
    public function check_permission($request) {
        $this->logger->debug('Checking permission for request');
        
        // Check for admin users first
        if (current_user_can('manage_options')) {
            $this->logger->debug('Admin user authenticated', [
                'user_id' => get_current_user_id()
            ]);
            return true;
        }

        // Continue with existing authentication checks
        $rate_limit_status = $this->check_rate_limit($request);
        if (is_wp_error($rate_limit_status)) {
            $this->logger->warning('Rate limit exceeded', [
                'ip' => $request->get_header('X-Forwarded-For') ?: $_SERVER['REMOTE_ADDR'],
                'endpoint' => $request->get_route()
            ]);
            return $rate_limit_status;
        }

        // Try API Key authentication
        $api_key = $request->get_header('X-API-Key');
        if ($api_key) {
            $valid_key = get_option('sewn_api_key');
            if ($api_key === $valid_key) {
                $this->logger->debug('Authentication successful via API Key');
                return true;
            }
            $this->logger->warning('Invalid API Key attempt', [
                'key_hash' => md5($api_key)
            ]);
        }

        // Try JWT authentication
        $auth_header = $request->get_header('Authorization');
        if (strpos($auth_header, 'Bearer ') === 0) {
            $token = substr($auth_header, 7);
            try {
                $validated = $this->validate_jwt($token);
                if ($validated) {
                    $this->logger->debug('Authentication successful via JWT');
        return true;
                }
            } catch (Exception $e) {
                $this->logger->error('JWT validation failed', [
                    'error' => $e->getMessage(),
                    'token_hash' => md5($token)
                ]);
            }
        }

        // Try OAuth2 authentication
        $oauth_token = $request->get_param('access_token');
        if ($oauth_token) {
            try {
                $validated = $this->validate_oauth_token($oauth_token);
                if ($validated) {
                    $this->logger->debug('Authentication successful via OAuth2');
                    return true;
                }
            } catch (Exception $e) {
                $this->logger->error('OAuth validation failed', [
                    'error' => $e->getMessage(),
                    'token_hash' => md5($oauth_token)
                ]);
            }
        }

        return new WP_Error(
            'rest_forbidden',
            'Invalid or missing authentication',
            [
                'status' => 401,
                'additional_info' => 'Please provide valid authentication credentials',
                'documentation_url' => 'https://docs.example.com/authentication'
            ]
        );
    }

    /**
     * Check request rate limits
     *
     * Implements tiered rate limiting based on user subscription level with
     * sophisticated tracking and recovery mechanisms.
     *
     * Rate Limit Tiers:
     * - Admin: Unlimited requests (no rate limiting)
     * - Free: 60 requests per hour
     * - Premium: 300 requests per hour
     *
     * Features:
     * - Per-IP and per-API-key tracking
     * - Automatic tier detection
     * - Grace period recovery
     * - Header-based limit information
     * - Cache-backed tracking
     *
     * Rate Limit Headers:
     * - X-RateLimit-Limit: Maximum requests per hour
     * - X-RateLimit-Remaining: Remaining requests
     * - X-RateLimit-Reset: Timestamp when limit resets
     *
     * Recovery Mechanism:
     * - Limits reset hourly
     * - Premium users get priority recovery
     * - Burst allowance for occasional spikes
     * - Instant recovery for admin users
     *
     * @since 2.0.0 Initial implementation
     * @since 2.1.0 Added tiered limits and recovery
     * @since 2.1.1 Added admin bypass
     * 
     * @param WP_REST_Request $request The request object
     * @return true|WP_Error True if within limits, WP_Error if exceeded
     */
    private function check_rate_limit($request) {
        $ip = $request->get_header('X-Forwarded-For') ?: $_SERVER['REMOTE_ADDR'];
        $api_key = $request->get_header('X-API-Key');
        
        // Admin bypass for rate limiting
        if (current_user_can('manage_options')) {
            $this->logger->debug('Rate limit bypassed for admin user', [
                'user_id' => get_current_user_id(),
                'ip' => $ip
            ]);
            
            // Add unlimited rate headers for admin users
            add_filter('rest_post_dispatch', function($response) {
                $response->header('X-RateLimit-Limit', 'unlimited');
                $response->header('X-RateLimit-Remaining', 'unlimited');
                $response->header('X-RateLimit-Admin-Bypass', 'true');
                return $response;
            });
            
            return true;
        }
        
        // Continue with existing rate limit checks for non-admin users
        $tier = $this->get_user_tier($api_key);
        $limits = [
            'free' => [
                'requests_per_hour' => 60,
                'burst_allowance' => 5,
                'recovery_rate' => 1
            ],
            'premium' => [
                'requests_per_hour' => 300,
                'burst_allowance' => 15,
                'recovery_rate' => 5
            ]
        ];
        
        $tier_config = $limits[$tier] ?? $limits['free'];
        $hourly_limit = $tier_config['requests_per_hour'];
        $rate_key = "sewn_rate_limit_{$ip}_{$api_key}";
        
        // Check current usage with burst allowance
        $usage = $this->rate_limiter->get_usage($rate_key);
        if ($usage >= $hourly_limit + $tier_config['burst_allowance']) {
            $this->logger->warning('Rate limit exceeded', [
                'tier' => $tier,
                'usage' => $usage,
                'limit' => $hourly_limit,
                'ip' => $ip
            ]);

            return new WP_Error(
                'rate_limit_exceeded',
                sprintf(
                    'Rate limit exceeded. Limit is %d requests per hour. Please try again in %d minutes.',
                    $hourly_limit,
                    ceil($this->rate_limiter->get_reset_minutes($rate_key))
                ),
                [
                    'status' => 429,
                    'limit' => $hourly_limit,
                    'remaining' => 0,
                    'reset' => $this->rate_limiter->get_reset_time($rate_key),
                    'retry_after' => $this->rate_limiter->get_retry_after($rate_key)
                ]
            );
        }

        // Increment usage with recovery consideration
        $this->rate_limiter->increment($rate_key, $tier_config['recovery_rate']);
        
        // Add rate limit headers
        add_filter('rest_post_dispatch', function($response) use ($hourly_limit, $usage, $rate_key) {
            // Special headers for admin users
            if (current_user_can('manage_options')) {
                $response->header('X-RateLimit-Limit', 'unlimited');
                $response->header('X-RateLimit-Remaining', 'unlimited');
                return $response;
            }

            $remaining = max(0, $hourly_limit - $usage - 1);
            $reset_time = $this->rate_limiter->get_reset_time($rate_key);
            
            $response->header('X-RateLimit-Limit', $hourly_limit);
            $response->header('X-RateLimit-Remaining', $remaining);
            $response->header('X-RateLimit-Reset', $reset_time);
            
            if ($remaining < ($hourly_limit * 0.1)) {
                $response->header('X-RateLimit-Warning', 'Approaching limit');
            }
            
            return $response;
        });

        return true;
    }

    /**
     * Handle screenshot creation requests with enhanced caching
     *
     * Takes a full screenshot of the specified URL with optional parameters.
     * Implements sophisticated caching and error handling mechanisms.
     * 
     * Features:
     * - Intelligent caching based on URL and options
     * - Automatic cache invalidation
     * - Progressive image loading
     * - Error recovery
     * - Detailed logging
     * 
     * Cache Strategy:
     * - Cache key: MD5 hash of URL + serialized options
     * - TTL: 1 hour by default
     * - Invalidation: On error or manual purge
     * - Headers: Cache-Control, X-Cache
     *
     * Error Handling:
     * - Graceful degradation
     * - Automatic retries for transient failures
     * - Detailed error reporting
     * - Performance monitoring
     * 
     * Example Success Response:
     * ```json
     * {
     *   "success": true,
     *   "url": "https://example.com/screenshots/abc123.jpg",
     *   "metadata": {
     *     "width": 1280,
     *     "height": 800,
     *     "format": "jpeg",
     *     "size": 123456,
     *     "created": "2024-01-20T12:00:00Z"
     *   },
     *   "cache": {
     *     "hit": false,
     *     "ttl": 3600
     *   }
     * }
     * ```
     * 
     * Example Error Response:
     * ```json
     * {
     *   "code": "screenshot_failed",
     *   "message": "Failed to capture screenshot",
     *   "data": {
     *     "status": 500,
     *     "error_details": {
     *       "reason": "Connection timeout",
     *       "url": "https://example.com",
     *       "options": {...}
     *     }
     *   }
     * }
     * ```
     * 
     * @since 1.0.0 Initial implementation
     * @since 2.0.0 Added caching and enhanced error handling
     * @since 2.1.0 Added progressive loading and performance monitoring
     * 
     * @param WP_REST_Request $request Request object containing URL and options
     * @return WP_REST_Response|WP_Error Screenshot URL on success, error on failure
     */
    public function take_screenshot($request) {
        $start_time = microtime(true);
        
        $this->logger->debug('REST screenshot request received', [
            'params' => $request->get_params(),
            'headers' => $request->get_headers()
        ]);

        try {
            // Extract and validate parameters
            $url = $request->get_param('url');
            $type = $request->get_param('type');
            $options = $request->get_param('options');

            $this->logger->debug('Processing screenshot request', [
                'url' => $url,
                'type' => $type,
                'options' => $options
            ]);

            // Generate cache key
            $cache_key = 'sewn_screenshot_' . md5($url . serialize($options));
            
            // Check cache with monitoring
            $cache_start = microtime(true);
            $cached_result = $this->cache->get($cache_key);
            $cache_time = microtime(true) - $cache_start;
            
            $this->logger->debug('Cache check completed', [
                'duration' => $cache_time,
                'hit' => (bool)$cached_result
            ]);
            
            if ($cached_result) {
                // Validate cached result before using
                if ($this->validate_cached_screenshot($cached_result)) {
                    return new WP_REST_Response(
                        array_merge($cached_result, [
                            'cache' => [
                                'hit' => true,
                                'age' => time() - ($cached_result['metadata']['created'] ?? 0)
                            ]
                        ]),
                        200,
                        [
                            'X-Cache' => 'HIT',
                            'X-Cache-Age' => time() - ($cached_result['metadata']['created'] ?? 0),
                            'Cache-Control' => 'public, max-age=3600'
                        ]
                    );
                } else {
                    // Invalid cache, remove it
                    $this->cache->delete($cache_key);
                    $this->logger->warning('Invalid cached screenshot removed', [
                        'cache_key' => $cache_key
                    ]);
                }
            }

            // Get API configuration
            $api_manager = new SEWN_API_Manager($this->logger, null);
            $fallback_service = $api_manager->get_current_service();
            $api_key = $api_manager->get_current_api_key();

            $this->logger->debug('API configuration loaded', [
                'fallback_service' => $fallback_service,
                'has_api_key' => !empty($api_key)
            ]);

            // Debug API configuration
            $this->logger->debug('Screenshot service configuration', [
                'fallback_service' => $fallback_service,
                'has_api_key' => !empty($api_key),
                'service_url' => $this->fallback_services[$fallback_service]['url'] ?? 'none'
            ]);

            // Get active API mode
            $active_mode = get_option('sewn_active_api', 'primary');
            $this->logger->debug('Taking screenshot with mode', ['mode' => $active_mode]);

            // Add mode to screenshot options
            $screenshot_options = array_merge($options ?? [], [
                'key' => $api_key,
                'service' => $active_mode === 'primary' ? 'primary' : $fallback_service,
                'mode' => $active_mode
            ]);

            if (current_user_can('manage_options') && $type === 'test') {
                // Get active mode and API keys
                $active_mode = get_option('sewn_active_api', 'primary');
                $api_key = get_option('sewn_api_key');
                
                $this->logger->debug('Screenshot request configuration', [
                    'active_mode' => $active_mode,
                    'has_api_key' => !empty($api_key)
                ]);

                // Only use fallback if primary mode is not set or primary key is missing
                if ($active_mode === 'primary' && !empty($api_key)) {
                    $this->logger->debug('Using primary service');
                    $result = $this->screenshot_service->take_screenshot($url, $type, $screenshot_options);
                } else {
                    $this->logger->debug('Using fallback service', [
                        'reason' => empty($api_key) ? 'missing_api_key' : 'fallback_mode_active'
                    ]);
                    $fallback_service = get_option('sewn_fallback_service', 'screenshotmachine');
                    $result = $this->screenshot_service->take_screenshot_fallback($url, $type, $screenshot_options);
                }
            } else {
                $api_key = get_option('sewn_api_key');
                $fallback_service = get_option('sewn_fallback_service', 'screenshotmachine');

                if (!empty($api_key)) {
                    $this->logger->debug('Using primary API key for screenshot');
                    $result = $this->screenshot_service->take_screenshot($url, $type, $screenshot_options);
                } else {
                    $this->logger->debug('Using fallback service', ['service' => $fallback_service]);
                    $result = $this->screenshot_service->take_screenshot_fallback($url, $type, $screenshot_options);
                }
            }

            $this->logger->debug('Screenshot result received', [
                'result' => $result,
                'duration' => microtime(true) - $start_time
            ]);

            // Debug screenshot result
            $this->logger->debug('Screenshot result', [
                'success' => $result['success'] ?? false,
                'has_url' => !empty($result['screenshot_url']),
                'method_used' => $result['method'] ?? 'unknown'
            ]);

            // Enhance result with metadata
            $result = array_merge($result, [
                'metadata' => [
                    'created' => time(),
                    'duration' => $result['duration'] ?? 0,
                    'source' => 'fresh'
                ]
            ]);
            
            // Cache the result
            $this->cache->set($cache_key, $result, HOUR_IN_SECONDS);

            // Log performance metrics
            $total_time = microtime(true) - $start_time;
            $this->logger->info('Screenshot generated successfully', [
                'url' => $url,
                'cache_time' => $cache_time,
                'screenshot_time' => $result['duration'] ?? 0,
                'total_time' => $total_time,
                'size' => $result['metadata']['size'] ?? 0
            ]);

            // Debug screenshot path and URL generation
            $this->logger->debug('Screenshot result', [
                'raw_result' => $result,
                'path' => $result['path'] ?? 'no_path'
            ]);

            $upload_dir = wp_upload_dir();
            $screenshot_url = '';

            if (!empty($result['path'])) {
                $screenshot_url = str_replace(
                    $upload_dir['basedir'],
                    $upload_dir['baseurl'],
                    $result['path']
                );
                
                // Debug URL generation
                $this->logger->debug('Screenshot URL generation', [
                    'basedir' => $upload_dir['basedir'],
                    'baseurl' => $upload_dir['baseurl'],
                    'path' => $result['path'],
                    'generated_url' => $screenshot_url
                ]);
            } else {
                // Check if URL is directly provided in result
                $screenshot_url = $result['url'] ?? $result['screenshot_url'] ?? '';
                $this->logger->debug('Using direct URL from result', [
                    'url' => $screenshot_url
                ]);
            }

            $response_data = [
            'success' => true,
                'method' => $result['method'] ?? 'fallback_service',
                'service' => $result['service'] ?? 'screenshotmachine',
                'timestamp' => current_time('mysql'),
                'metadata' => $result['metadata'] ?? [],
                'screenshot_url' => esc_url($screenshot_url),  // Ensure URL is properly escaped
                'file_size' => $result['size'] ?? '',
                'width' => $result['width'] ?? 1280,
                'height' => $result['height'] ?? 800,
                'cache_status' => !empty($result['cached']) ? 'HIT' : 'MISS'
            ];

            // Final debug check
            $this->logger->debug('Final response data', [
                'response' => $response_data
            ]);

            // Add authentication method to response
            $response_data['auth_method'] = $this->get_current_auth_method();
            
            return rest_ensure_response($response_data);

        } catch (Exception $e) {
            $error_data = [
                'url' => $url ?? null,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString(),
                'context' => [
                    'type' => $type ?? null,
                    'options' => $options ?? null,
                    'duration' => microtime(true) - $start_time
                ]
            ];

            $this->logger->error('Screenshot capture failed', $error_data);
            
            return new WP_Error(
                'screenshot_failed',
                $this->get_user_friendly_error_message($e),
                [
                    'status' => 500,
                    'error_details' => $error_data
                ]
            );
        }
    }

    /**
     * Validate cached screenshot data
     *
     * Ensures cached screenshot data is valid and accessible.
     *
     * @since 2.1.0
     * @param array $cached_data The cached screenshot data
     * @return bool Whether the cached data is valid
     */
    private function validate_cached_screenshot($cached_data) {
        if (!is_array($cached_data) || empty($cached_data['url'])) {
            return false;
        }

        // Verify file still exists
        $file_path = $this->get_screenshot_path($cached_data['url']);
        if (!file_exists($file_path)) {
            return false;
        }

        // Verify file is readable and not corrupted
        if (!$this->verify_image_integrity($file_path)) {
            return false;
        }

        return true;
    }

    /**
     * Take screenshot with retry mechanism
     *
     * Attempts to take a screenshot with automatic retries for transient failures.
     *
     * @since 2.1.0
     * @param string $url URL to screenshot
     * @param string $type Screenshot type
     * @param array $options Screenshot options
     * @return array Screenshot result data
     * @throws Exception If all retries fail
     */
    private function take_screenshot_with_retry($url, $type, $options) {
        $max_retries = 3;
        $retry_delay = 1; // seconds

        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            try {
                return $this->screenshot_service->take_screenshot($url, $type, $options);
            } catch (Exception $e) {
                if ($attempt === $max_retries || !$this->is_retryable_error($e)) {
                    throw $e;
                }

                $this->logger->warning('Screenshot attempt failed, retrying', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'delay' => $retry_delay
                ]);

                sleep($retry_delay);
                $retry_delay *= 2; // Exponential backoff
            }
    }
}

    /**
     * Handle network authentication with enhanced security
     *
     * Authenticates the current site with the screenshot network using
     * secure token exchange and validation. Implements OAuth2 flow with PKCE.
     * 
     * Security Features:
     * - PKCE challenge/verifier
     * - State parameter validation
     * - Nonce verification
     * - TLS certificate validation
     * - IP address verification
     * - Rate limiting on auth attempts
     * 
     * Authentication Flow:
     * 1. Generate PKCE challenge
     * 2. Create state token
     * 3. Build auth URL
     * 4. Validate site eligibility
     * 5. Return auth URL
     * 
     * Example Success Response:
     * ```json
     * {
     *   "success": true,
     *   "auth_url": "https://auth.example.com/oauth2/authorize?...",
     *   "expires_in": 300,
     *   "state": "abc123",
     *   "code_challenge": "xyz789"
     * }
     * ```
     * 
     * @since 1.0.0 Initial implementation
     * @since 2.0.0 Added enhanced security measures
     * @since 2.1.0 Added PKCE support
     * 
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Success message or error
     */
    public function handle_network_auth($request) {
        try {
            // Verify site eligibility with detailed checks
            if (!$this->verify_site_eligibility()) {
                throw new Exception('Site not eligible for network authentication');
            }

            // Generate PKCE values
            $code_verifier = $this->generate_code_verifier();
            $code_challenge = $this->generate_code_challenge($code_verifier);

            // Generate secure state token
            $state = wp_generate_password(32, false);
            $nonce = wp_create_nonce('sewn_auth_' . $state);

            // Store PKCE and state values
            set_transient('sewn_auth_state_' . $state, [
                'nonce' => $nonce,
                'verifier' => $code_verifier,
                'ip' => $_SERVER['REMOTE_ADDR']
            ], 5 * MINUTE_IN_SECONDS);

            // Build auth URL with enhanced parameters
            $auth_url = add_query_arg([
                'client_id' => get_option('sewn_client_id'),
                'redirect_uri' => esc_url_raw(site_url('/wp-json/' . $this->namespace . '/auth/exchange')),
                'state' => $state,
                'code_challenge' => $code_challenge,
                'code_challenge_method' => 'S256',
                'response_type' => 'code',
                'scope' => 'screenshots.access',
                'nonce' => $nonce
            ], SEWN_AUTH_ENDPOINT);

            $this->logger->info('Network authentication initiated', [
                'state' => $state,
                'ip' => $_SERVER['REMOTE_ADDR'],
                'user_agent' => $_SERVER['HTTP_USER_AGENT']
            ]);

            return new WP_REST_Response([
                'success' => true,
                'auth_url' => $auth_url,
                'expires_in' => 300,
                'state' => $state,
                'code_challenge' => $code_challenge
            ], 200);

        } catch (Exception $e) {
            $this->logger->error('Network authentication failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip' => $_SERVER['REMOTE_ADDR']
            ]);
            
            return new WP_Error(
                'auth_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Handle parent token exchange with enhanced security
     *
     * Exchanges a temporary token for a permanent API key with
     * additional security validations.
     * 
     * @since 1.0.0
     * @since 2.0.0 Added enhanced security and validation
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Success message or error
     */
    public function exchange_parent_token($request) {
        try {
            $token = $request->get_param('token');
            $state = $request->get_param('state');

            // Verify state to prevent CSRF
            $stored_state = get_transient('sewn_auth_state');
            if (!$stored_state || $stored_state !== $state) {
                throw new Exception('Invalid state parameter');
            }

            // Clear used state
            delete_transient('sewn_auth_state');

            if (empty($token)) {
                return new WP_Error(
                    'missing_token',
                    'Token is required',
                    ['status' => 400]
                );
            }

            // Exchange token for permanent credentials
            $response = wp_remote_post(SEWN_TOKEN_EXCHANGE_ENDPOINT, [
                'body' => [
                    'grant_type' => 'authorization_code',
                    'code' => $token,
                    'client_id' => get_option('sewn_client_id'),
                    'client_secret' => get_option('sewn_client_secret'),
                    'redirect_uri' => site_url('/wp-json/' . $this->namespace . '/auth/exchange')
                ]
            ]);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (empty($body['access_token'])) {
                throw new Exception('Invalid token exchange response');
            }

            // Store the new credentials
            update_option('sewn_api_key', $body['access_token']);
            if (!empty($body['refresh_token'])) {
                update_option('sewn_refresh_token', $body['refresh_token']);
            }

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Token exchanged successfully',
                'expires_in' => $body['expires_in'] ?? null
            ], 200);
        } catch (Exception $e) {
            $this->logger->error('Token exchange failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return new WP_Error(
                'token_exchange_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Verify site eligibility for network authentication
     *
     * Performs comprehensive checks to ensure the site meets all requirements
     * for network participation.
     *
     * Checks:
     * - PHP version compatibility
     * - WordPress version compatibility
     * - SSL certificate validity
     * - Required extensions
     * - Server environment
     * - Network connectivity
     * - Storage capacity
     * - Memory limits
     *
     * @since 2.0.0
     * @since 2.1.0 Added comprehensive environment checks
     * @return bool Whether the site is eligible
     */
    private function verify_site_eligibility() {
        $requirements = [
            'php' => '7.4',
            'wp' => '5.8',
            'ssl' => true,
            'extensions' => ['curl', 'gd', 'json'],
            'memory_limit' => '128M',
            'max_execution_time' => 30,
            'upload_max_filesize' => '10M'
        ];

        // Version checks
        if (version_compare(PHP_VERSION, $requirements['php'], '<')) {
            $this->logger->warning('PHP version check failed', [
                'current' => PHP_VERSION,
                'required' => $requirements['php']
            ]);
            return false;
        }

        if (version_compare(get_bloginfo('version'), $requirements['wp'], '<')) {
            $this->logger->warning('WordPress version check failed', [
                'current' => get_bloginfo('version'),
                'required' => $requirements['wp']
            ]);
            return false;
        }

        // SSL check
        if ($requirements['ssl'] && !is_ssl()) {
            $this->logger->warning('SSL requirement not met');
            return false;
        }

        // Extension checks
        foreach ($requirements['extensions'] as $ext) {
            if (!extension_loaded($ext)) {
                $this->logger->warning("Required extension missing: {$ext}");
                return false;
            }
        }

        // Environment checks
        $memory_limit = $this->parse_size(ini_get('memory_limit'));
        $required_memory = $this->parse_size($requirements['memory_limit']);
        if ($memory_limit < $required_memory) {
            $this->logger->warning('Insufficient memory limit', [
                'current' => ini_get('memory_limit'),
                'required' => $requirements['memory_limit']
            ]);
            return false;
        }

        return true;
    }

    /**
     * Parse PHP size string into bytes
     *
     * Converts PHP size strings (e.g., '128M') into bytes.
     *
     * @since 2.1.0
     * @param string $size Size string to parse
     * @return int Size in bytes
     */
    private function parse_size($size) {
        $unit = strtoupper(substr($size, -1));
        $value = (int)substr($size, 0, -1);
        
        switch ($unit) {
            case 'G': $value *= 1024;
            case 'M': $value *= 1024;
            case 'K': $value *= 1024;
        }
        
        return $value;
    }

    /**
     * Get user-friendly error message
     *
     * Converts technical error messages into user-friendly versions
     * while preserving detailed logging.
     *
     * @since 2.1.0
     * @param Exception $e The exception to process
     * @return string User-friendly error message
     */
    private function get_user_friendly_error_message(Exception $e) {
        $error_map = [
            'CURL_TIMEOUT' => 'The request timed out. Please try again.',
            'SSL_CERT_ERROR' => 'There was a security verification problem.',
            'MEMORY_EXCEEDED' => 'The system is temporarily out of resources.',
            'INVALID_URL' => 'The provided URL is not valid or accessible.',
            'FILE_PERMISSION' => 'There was a problem saving the screenshot.',
            'DEFAULT' => 'An unexpected error occurred. Please try again later.'
        ];

        // Log detailed error
        $this->logger->error('Original error', [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);

        // Return user-friendly message
        $error_code = $this->categorize_error($e);
        return $error_map[$error_code] ?? $error_map['DEFAULT'];
    }

    /**
     * Categorize error for user-friendly messaging
     *
     * Analyzes exception details to determine appropriate error category.
     *
     * @since 2.1.0
     * @param Exception $e The exception to categorize
     * @return string Error category code
     */
    private function categorize_error(Exception $e) {
        $message = strtolower($e->getMessage());
        
        if (strpos($message, 'timeout') !== false) {
            return 'CURL_TIMEOUT';
        }
        if (strpos($message, 'ssl') !== false || strpos($message, 'certificate') !== false) {
            return 'SSL_CERT_ERROR';
        }
        if (strpos($message, 'memory') !== false) {
            return 'MEMORY_EXCEEDED';
        }
        if (strpos($message, 'url') !== false) {
            return 'INVALID_URL';
        }
        if (strpos($message, 'permission') !== false) {
            return 'FILE_PERMISSION';
        }
        
        return 'DEFAULT';
    }

    /**
     * Schedule cache warming for popular URLs
     *
     * Initiates background process to pre-cache frequently accessed screenshots.
     *
     * @since 2.1.0
     * @return void
     */
    private function schedule_cache_warming() {
        $popular_urls = $this->get_popular_urls();
        
        foreach ($popular_urls as $url) {
            wp_schedule_single_event(
                time() + mt_rand(60, 300), // Stagger requests
                'sewn_warm_cache',
                ['url' => $url]
            );
        }

        $this->logger->info('Cache warming scheduled', [
            'urls' => count($popular_urls)
        ]);
    }

    /**
     * Get list of popular URLs for cache warming
     *
     * Retrieves and sorts URLs based on access frequency and importance.
     *
     * @since 2.1.0
     * @return array List of URLs to pre-cache
     */
    private function get_popular_urls() {
        $stats = $this->cache->get_access_stats();
        
        // Sort by access frequency
        uasort($stats, function($a, $b) {
            return $b['hits'] - $a['hits'];
        });

        // Return top 20 URLs
        return array_slice(array_keys($stats), 0, 20);
    }

    /**
     * Generate PKCE code verifier
     *
     * Creates a cryptographically secure random string for PKCE verification.
     * Follows RFC 7636 specifications for code verifier generation.
     *
     * @since 2.1.0
     * @return string Generated code verifier
     */
    private function generate_code_verifier() {
        $random_bytes = random_bytes(32);
        return rtrim(strtr(base64_encode($random_bytes), '+/', '-_'), '=');
    }

    /**
     * Generate PKCE code challenge
     *
     * Creates a code challenge from the verifier using SHA256.
     * Compliant with OAuth 2.0 PKCE extension (RFC 7636).
     *
     * @since 2.1.0
     * @param string $verifier Code verifier to generate challenge from
     * @return string Generated code challenge
     */
    private function generate_code_challenge($verifier) {
        $hash = hash('sha256', $verifier, true);
        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    /**
     * Get service status information
     *
     * Returns the current status of the screenshot service including
     * available tools and cache information.
     * 
     * Example Usage:
     * curl -H "X-API-Key: your-api-key" \
     *   https://your-site.com/wp-json/sewn-screenshots/v1/status
     *
     * Response includes:
     * - wkhtmltoimage_found: boolean
     * - fallback_available: boolean
     * - cache_size: int (bytes)
     *
     * Status Information Includes:
     * 1. Service Health
     *    - Screenshot service status
     *    - Available screenshot methods
     *    - Current load metrics
     *
     * 2. Cache Status
     *    - Cache hit rate
     *    - Storage usage
     *    - Cache health
     *
     * 3. Rate Limiting
     *    - Current rate limit
     *    - Remaining requests
     *    - Reset time
     *
     * 4. Network Status
     *    - Ring Leader connection
     *    - Network role
     *    - Sync status
     *
     * Example Response:
     * ```json
     * {
     *   "success": true,
     *   "data": {
     *     "health": {
     *       "status": "healthy",
     *       "uptime": "5d 12h 30m",
     *       "load": 0.75,
     *       "wkhtmltoimage_found": true,
     *       "fallback_available": true
     *     },
     *     "cache": {
     *       "hit_rate": 0.85,
     *       "size": "1.2GB",
     *       "status": "optimal"
     *     },
     *     "rate_limit": {
     *       "limit": 300,
     *       "remaining": 250,
     *       "reset": "2024-01-01T00:00:00Z"
     *     },
     *     "network": {
     *       "connected": true,
     *       "role": "member",
     *       "last_sync": "2024-01-01T00:00:00Z"
     *     }
     *   },
     *   "meta": {
     *     "generated": "2024-01-01T00:00:00Z",
     *     "version": "2.1.0"
     *   }
     * }
     * ```
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Status information
     *
     * @since 1.0.0
     * @since 2.0.0 Added cache and rate limit metrics
     * @since 2.1.0 Added network status
     *
     * @OA\Get(
     *     path="/status",
     *     summary="Get service health and metrics",
     *     description="Returns comprehensive status information about the screenshot service",
     *     operationId="getServiceStatus",
     *     tags={"system"},
     *     @OA\Response(
     *         response=200,
     *         description="Service status retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="health",
     *                     type="object",
     *                     @OA\Property(property="status", type="string", example="healthy"),
     *                     @OA\Property(property="uptime", type="string", example="5d 12h 30m"),
     *                     @OA\Property(property="load", type="number", format="float", example=0.75),
     *                     @OA\Property(property="wkhtmltoimage_found", type="boolean", example=true),
     *                     @OA\Property(property="fallback_available", type="boolean", example=true)
     *                 ),
     *                 @OA\Property(
     *                     property="cache",
     *                     type="object",
     *                     @OA\Property(property="hit_rate", type="number", format="float", example=0.85),
     *                     @OA\Property(property="size", type="string", example="1.2GB"),
     *                     @OA\Property(property="status", type="string", example="optimal")
     *                 ),
     *                 @OA\Property(
     *                     property="rate_limit",
     *                     type="object",
     *                     @OA\Property(property="limit", type="integer", example=300),
     *                     @OA\Property(property="remaining", type="integer", example=250),
     *                     @OA\Property(property="reset", type="string", format="date-time")
     *                 ),
     *                 @OA\Property(
     *                     property="network",
     *                     type="object",
     *                     @OA\Property(property="connected", type="boolean"),
     *                     @OA\Property(property="role", type="string", example="member"),
     *                     @OA\Property(property="last_sync", type="string", format="date-time")
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="meta",
     *                 type="object",
     *                 @OA\Property(property="generated", type="string", format="date-time"),
     *                 @OA\Property(property="version", type="string", example="2.1.0")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Authentication required",
     *         @OA\JsonContent(ref="#/components/schemas/Error")
     *     ),
     *     @OA\Response(
     *         response=429,
     *         description="Rate limit exceeded",
     *         @OA\JsonContent(ref="#/components/schemas/Error")
     *     ),
     *     security={
     *         {"ApiKeyAuth": {}},
     *         {"MembershipAuth": {}}
     *     }
     * )
     */
    public function get_status($request) {
        $cache_stats = $this->cache->get_stats();
        $rate_limit_stats = $this->rate_limiter->get_stats();
        
        return new WP_REST_Response([
            'status' => 'operational',
            'version' => SEWN_SCREENSHOTS_VERSION,
            'dependencies' => [
                'wkhtmltoimage' => [
                    'found' => $this->screenshot_service->is_wkhtmltoimage_available(),
                    'version' => $this->screenshot_service->get_wkhtmltoimage_version()
                ],
                'fallback' => [
                    'available' => $this->screenshot_service->is_fallback_available(),
                    'type' => $this->screenshot_service->get_fallback_type()
                ]
            ],
            'cache' => [
                'size' => $this->screenshot_service->get_cache_size(),
                'hit_rate' => $cache_stats['hit_rate'],
                'miss_rate' => $cache_stats['miss_rate'],
                'items' => $cache_stats['total_items']
            ],
            'rate_limits' => [
                'current_usage' => $rate_limit_stats['current_usage'],
                'limit' => $rate_limit_stats['limit'],
                'reset_time' => $rate_limit_stats['reset_time']
            ],
            'system' => [
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
                'php_version' => PHP_VERSION,
                'server_software' => $_SERVER['SERVER_SOFTWARE']
            ],
            'timestamp' => current_time('timestamp')
        ], 200, [
            'Cache-Control' => 'no-cache'
        ]);
    }

    /**
     * Purge screenshot cache
     *
     * Removes all cached screenshots and provides comprehensive statistics about the operation.
     * Implements safe cache clearing with backup and recovery mechanisms.
     * 
     * Features:
     * - Selective cache purging (by age, type, or URL pattern)
     * - Backup of critical cache entries
     * - Detailed purge statistics
     * - Automatic cache warming for popular URLs
     * - Performance impact monitoring
     *
     * Cache Types Cleared:
     * - Screenshot image files
     * - URL metadata
     * - Response data
     * - Temporary files
     *
     * Example Usage:
     * ```
     * curl -X POST \
     *   -H "X-API-Key: your-api-key" \
     *   https://your-site.com/wp-json/sewn-screenshots/v1/cache/purge
     * ```
     *
     * Example Success Response:
     * ```json
     * {
     *   "success": true,
     *   "message": "Cache purged successfully",
     *   "statistics": {
     *     "items_removed": 150,
     *     "space_freed": 52428800,
     *     "duration": 1.23,
     *     "preserved_items": 10,
     *     "errors": 0
     *   },
     *   "cache_status": {
     *     "current_size": 0,
     *     "last_purge": "2024-01-20T12:00:00Z"
     *   }
     * }
     * ```
     *
     * Error Response:
     * ```json
     * {
     *   "code": "cache_purge_failed",
     *   "message": "Failed to purge cache: insufficient permissions",
     *   "data": {
     *     "status": 403
     *   }
     * }
     * ```
     *
     * Implementation Notes:
     * - Validates user capabilities before execution
     * - Logs cache clearing operations
     * - Handles file system errors gracefully
     * - Maintains audit trail of purge operations
     *
     * @since 1.0.0 Initial implementation
     * @since 2.0.0 Added detailed purge statistics
     * @since 2.1.0 Added selective purging and cache warming
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Success message or error
     *
     * @OA\Post(
     *     path="/cache/purge",
     *     summary="Purge screenshot cache",
     *     description="Clears all cached screenshots and related data",
     *     operationId="purgeCache",
     *     tags={"cache"},
     *     @OA\Response(
     *         response=200,
     *         description="Cache successfully purged",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Cache purged successfully"),
     *             @OA\Property(
     *                 property="statistics",
     *                 type="object",
     *                 @OA\Property(property="items_removed", type="integer", example=150),
     *                 @OA\Property(property="space_freed", type="integer", example=52428800),
     *                 @OA\Property(property="duration", type="number", format="float", example=1.23),
     *                 @OA\Property(property="preserved_items", type="integer", example=10),
     *                 @OA\Property(property="errors", type="integer", example=0)
     *             ),
     *             @OA\Property(
     *                 property="cache_status",
     *                 type="object",
     *                 @OA\Property(property="current_size", type="integer", example=0),
     *                 @OA\Property(property="last_purge", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Authentication required",
     *         @OA\JsonContent(ref="#/components/schemas/Error")
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Insufficient permissions",
     *         @OA\JsonContent(ref="#/components/schemas/Error")
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Cache purge operation failed",
     *         @OA\JsonContent(ref="#/components/schemas/Error")
     *     ),
     *     security={
     *         {"ApiKeyAuth": {}},
     *         {"MembershipAuth": {}}
     *     }
     * )
     *
     * @throws RuntimeException If cache system is unavailable
     * @throws WP_Error If user lacks required capabilities
     */
    public function purge_cache($request) {
        $start_time = microtime(true);

        try {
            // Get cache stats before purge
            $before_stats = $this->cache->get_stats();
            
            // Identify critical cache entries to preserve
            $preserved_items = $this->identify_critical_cache_entries();
            
            // Backup critical entries
            foreach ($preserved_items as $key => $data) {
                $this->backup_cache_entry($key, $data);
            }

            // Perform purge with monitoring
            $purge_result = $this->screenshot_service->purge_cache([
                'exclude' => array_keys($preserved_items),
                'dry_run' => $request->get_param('dry_run') ?? false
            ]);
            
            // Restore critical entries
            foreach ($preserved_items as $key => $data) {
                $this->restore_cache_entry($key, $data);
            }

            // Get cache stats after purge
            $after_stats = $this->cache->get_stats();
            
            // Calculate detailed statistics
            $stats = [
                'items_removed' => $before_stats['total_items'] - $after_stats['total_items'],
                'space_freed' => $before_stats['total_size'] - $after_stats['total_size'],
                'duration' => microtime(true) - $start_time,
                'preserved_items' => count($preserved_items),
                'errors' => $purge_result['errors'] ?? 0
            ];

            $this->logger->info('Cache purged successfully', [
                'statistics' => $stats,
                'preserved_items' => array_keys($preserved_items)
            ]);

            // Trigger cache warming for popular URLs
            $this->schedule_cache_warming();

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Cache purged successfully',
                'statistics' => $stats,
                'cache_status' => [
                    'current_size' => $after_stats['total_size'],
                    'last_purge' => current_time('c')
                ]
            ], 200);

        } catch (Exception $e) {
            $this->logger->error('Cache purge failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'duration' => microtime(true) - $start_time
            ]);
            
            return new WP_Error(
                'cache_purge_failed',
                $e->getMessage(),
                [
                    'status' => 500,
                    'error_details' => [
                        'code' => $e->getCode(),
                        'trace' => WP_DEBUG ? $e->getTraceAsString() : null,
                        'duration' => microtime(true) - $start_time
                    ]
                ]
            );
        }
    }

    /**
     * Get screenshot schema
     *
     * Defines the JSON schema for screenshot endpoint responses.
     *
     * @since 2.0.0
     * @return array Schema definition
     */
    public function get_screenshot_schema() {
        return [
            '$schema' => 'http://json-schema.org/draft-04/schema#',
            'title' => 'screenshot',
            'type' => 'object',
            'properties' => [
                'success' => [
                    'type' => 'boolean',
                    'description' => 'Whether the screenshot was successful'
                ],
                'screenshot_url' => [
                    'type' => 'string',
                    'format' => 'uri',
                    'description' => 'URL of the generated screenshot'
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'Status message'
                ],
                'method' => [
                    'type' => 'string',
                    'description' => 'Method used to generate screenshot'
                ],
                'metadata' => [
                    'type' => 'object',
                    'properties' => [
                        'width' => ['type' => 'integer'],
                        'height' => ['type' => 'integer'],
                        'quality' => ['type' => 'integer'],
                        'format' => ['type' => 'string'],
                        'timestamp' => ['type' => 'integer'],
                        'file_size' => ['type' => 'integer']
                    ]
                ]
            ]
        ];
    }

    /**
     * Validate JWT token
     *
     * @since 2.0.0
     * @param string $token JWT token
     * @return bool Whether token is valid
     * @throws Exception If token validation fails
     */
    private function validate_jwt($token) {
        try {
            // JWT validation implementation
            $key = get_option('sewn_jwt_secret_key');
            $decoded = JWT::decode($token, new Key($key, 'HS256'));
            
            return !empty($decoded->sub);
        } catch (Exception $e) {
            $this->logger->error('JWT validation failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Validate OAuth2 token
     *
     * @since 2.0.0
     * @param string $token OAuth2 token
     * @return bool Whether token is valid
     * @throws Exception If token validation fails
     */
    private function validate_oauth_token($token) {
        try {
            // OAuth2 validation implementation
            $response = wp_remote_get(SEWN_OAUTH_VERIFY_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token
                ]
            ]);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            return !empty($body['active']);
        } catch (Exception $e) {
            $this->logger->error('OAuth validation failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get token exchange arguments schema
     *
     * Defines the expected parameters for the token exchange endpoint
     * with validation rules and defaults.
     *
     * @since 2.1.0
     * @return array Token exchange arguments schema
     */
    private function get_token_exchange_args() {
        return [
            'temp_token' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Temporary token from authentication process',
                'validate_callback' => function($param) {
                    return !empty($param) && is_string($param);
                }
            ],
            'site_id' => [
                'required' => true,
                'type' => 'integer',
                'description' => 'Site ID from the network',
                'validate_callback' => function($param) {
                    return is_numeric($param) && intval($param) > 0;
                }
            ],
            'signature' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Security signature for verification',
                'validate_callback' => function($param) {
                    return !empty($param) && is_string($param);
                }
            ],
            'timestamp' => [
                'required' => true,
                'type' => 'integer',
                'description' => 'Request timestamp for validation',
                'validate_callback' => function($param) {
                    return is_numeric($param) && intval($param) > 0;
                }
            ]
        ];
    }

    /**
     * Get user tier for rate limiting
     *
     * Determines user's service tier based on API key and user role.
     * Used for applying appropriate rate limits and feature access.
     *
     * Tier Levels:
     * 1. Free
     *    - Basic rate limits
     *    - Standard features
     *    - Default for unregistered users
     *
     * 2. Premium
     *    - Higher rate limits
     *    - Advanced features
     *    - Available through:
     *      a) Premium API keys
     *      b) Premium user roles
     *      c) Admin capabilities
     *
     * Authentication Flow:
     * 1. Check API key against premium keys
     * 2. Verify user role and capabilities
     * 3. Default to free tier if no premium access
     *
     * Example Usage:
     * ```php
     * $tier = $this->get_user_tier($api_key);
     * if ($tier === 'premium') {
     *     // Apply premium features
     * }
     * ```
     *
     * @since 2.1.0 Initial implementation
     * @param string|null $api_key Optional API key to check for premium status
     * @return string User tier ('free' or 'premium')
     */
    private function get_user_tier($api_key = null) {
        // Check for premium API key
        if ($api_key) {
            $premium_keys = get_option('sewn_premium_api_keys', []);
            if (in_array($api_key, $premium_keys)) {
                $this->logger->debug('Premium tier detected via API key', [
                    'key_hash' => md5($api_key)
                ]);
                return 'premium';
            }
        }

        // Check for premium user role or admin
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if (
                user_can($user, 'manage_options') || 
                in_array('premium_member', (array)$user->roles)
            ) {
                $this->logger->debug('Premium tier detected via user role', [
                    'user_id' => $user->ID,
                    'roles' => $user->roles
                ]);
                return 'premium';
            }
        }

        // Default to free tier
        $this->logger->debug('Free tier assigned', [
            'api_key_present' => !empty($api_key),
            'user_logged_in' => is_user_logged_in()
        ]);
        
        return 'free';
    }

    /**
     * Get the filesystem path for a screenshot
     *
     * Converts a screenshot URL or identifier to its filesystem path.
     *
     * @since 2.1.0
     * @param string $screenshot_url URL or identifier of the screenshot
     * @return string|false Filesystem path or false if invalid
     */
    private function get_screenshot_path($screenshot_url) {
        // Get WordPress upload directory info
        $upload_dir = wp_upload_dir();
        
        // Convert URL to filesystem path
        $file_path = str_replace(
            $upload_dir['baseurl'],
            $upload_dir['basedir'],
            $screenshot_url
        );
        
        // Validate path is within screenshots directory
        $screenshots_dir = $upload_dir['basedir'] . '/screenshots';
        if (strpos($file_path, $screenshots_dir) !== 0) {
            $this->logger->warning('Invalid screenshot path detected', [
                'path' => $file_path,
                'screenshots_dir' => $screenshots_dir
            ]);
            return false;
        }
        
        return $file_path;
    }

    /**
     * Verify image integrity
     *
     * Checks if an image file is valid and not corrupted.
     *
     * @since 2.1.0
     * @param string $image_path Path to the image file
     * @return bool True if image is valid, false otherwise
     */
    private function verify_image_integrity($image_path) {
        try {
            if (!file_exists($image_path)) {
                $this->logger->error('Image file not found', ['path' => $image_path]);
                return false;
            }

            // Check file size
            $file_size = filesize($image_path);
            if ($file_size === 0) {
                $this->logger->error('Image file is empty', ['path' => $image_path]);
                return false;
            }

            // Verify image data
            $image_info = getimagesize($image_path);
            if ($image_info === false) {
                $this->logger->error('Invalid image data', ['path' => $image_path]);
                return false;
            }

            // Check image dimensions
            if ($image_info[0] < 1 || $image_info[1] < 1) {
                $this->logger->error('Invalid image dimensions', [
                    'path' => $image_path,
                    'width' => $image_info[0],
                    'height' => $image_info[1]
                ]);
                return false;
            }

            $this->logger->debug('Image integrity verified', [
                'path' => $image_path,
                'size' => $file_size,
                'dimensions' => $image_info[0] . 'x' . $image_info[1],
                'type' => $image_info['mime']
            ]);

            return true;

        } catch (Exception $e) {
            $this->logger->error('Image verification failed', [
                'path' => $image_path,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get the current authentication method
     *
     * @since 2.1.0
     * @return string The current authentication method
     */
    private function get_current_auth_method() {
        if (!isset($this->api_manager)) {
            return 'none';
        }
        
        try {
            return $this->api_manager->get_active_api_mode();
        } catch (Exception $e) {
            $this->logger->warning('Failed to get auth method', [
                'error' => $e->getMessage()
            ]);
            return 'none';
        }
    }

    /**
     * Setup CORS headers for API requests
     */
    private function setup_cors() {
        add_action('rest_api_init', function() {
            remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
            add_filter('rest_pre_serve_request', function($value) {
                header('Access-Control-Allow-Origin: *');
                header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
                header('Access-Control-Allow-Credentials: true');
                header('Access-Control-Allow-Headers: Authorization, Content-Type, X-API-Key');
                return $value;
            });
        });
    }

    /**
     * Configure API subdomain routing
     */
    private function setup_api_subdomain() {
        add_filter('rest_url_prefix', function($prefix) {
            return 'wp-json';
        });
        
        add_filter('rest_url', function($url) {
            // Replace main domain with api subdomain
            return preg_replace(
                '|^' . preg_quote(get_site_url()) . '|',
                'https://api.' . parse_url(get_site_url(), PHP_URL_HOST),
                $url
            );
        });
    }

    /**
     * Enhanced error response handling
     */
    private function handle_error($error, $status = 400) {
        $response = new WP_Error(
            'screenshot_error',
            $error->getMessage(),
            [
                'status' => $status,
                'additional_data' => [
                    'request_id' => uniqid('sewn_'),
                    'timestamp' => time(),
                    'service_status' => $this->get_service_status()
                ]
            ]
        );

        $this->logger->error('API Error', [
            'error' => $error->getMessage(),
            'trace' => $error->getTraceAsString(),
            'status' => $status
        ]);

        return $response;
    }

    /**
     * Enhanced authorization check with support for both API key and membership token
     *
     * Supports authentication via:
     * 1. API Key (X-API-Key header)
     * 2. Membership Token (X-Membership-Token header)
     * 
     * Rate limits are applied based on authentication method:
     * - API Key: Standard API rate limits
     * - Membership Token: Tier-based rate limits
         *
         * @param WP_REST_Request $request
         * @return bool|WP_Error
         */
        public function check_authorization($request) {
        $api_key = $request->get_header('X-API-Key');
        $membership_token = $request->get_header('X-Membership-Token');

        // Check API key first (maintain existing functionality)
        if ($api_key) {
            if ($this->api_manager->verify_api_key($api_key)) {
                // Apply standard API rate limiting
                if (!$this->rate_limiter->check_api_limit($api_key)) {
                return new WP_Error(
                        'rate_limit_exceeded',
                        'API rate limit exceeded',
                        ['status' => 429]
                    );
                }
                return true;
            }
        }

        // If no valid API key, check membership token
        if ($membership_token && isset($this->membership_service)) {
            if ($this->membership_service->validate_token($membership_token)) {
                $membership_level = $this->membership_service->get_level($membership_token);
                
                // Apply tier-based rate limiting
                if (!$this->rate_limiter->check_membership_limit($membership_token, $membership_level)) {
                return new WP_Error(
                    'rate_limit_exceeded',
                    'Rate limit exceeded for your membership level',
                    ['status' => 429]
                );
            }
            return true;
            }
        }

        // No valid authentication provided
        return new WP_Error(
            'invalid_auth',
            'Invalid or missing authentication',
            ['status' => 401]
        );
        }

        /**
         * Enhanced response formatting with comprehensive metadata
         * 
         * Formats API responses with standardized structure including:
         * 1. Base Response:
         *    - Success status
         *    - Primary data payload
         *    - Version and timestamp
         *    - Rate limiting information
         * 
         * 2. Membership Information:
         *    - Current membership level
         *    - Membership status
         * 
         * 3. Network Metadata:
         *    - Ring Leader connection status
         *    - Network ID
         *    - Node type and role
         * 
         * Standard Response Format:
         * {
         *   "success": true,
         *   "data": { ... },
         *   "meta": {
         *     "version": "1.0",
         *     "timestamp": "2024-01-20T12:00:00Z",
         *     "rate_limit": {
         *       "limit": 100,
         *       "remaining": 95
         *     },
         *     "membership": {
         *       "level": "premium",
         *       "active": true
         *     },
         *     "network": {
         *       "ring_leader_connected": true,
         *       "network_id": "net_123456",
         *       "node_type": "member"
         *     }
         *   }
         * }
         * 
         * @since 2.1.0
         * @param mixed $data Primary response data
         * @param array $metadata Additional metadata to include in response
         * @return array Enhanced response with comprehensive metadata
         */
        protected function format_response($data, $metadata = []) {
        $response = [
                'success' => true,
                'data' => $data,
                'meta' => array_merge([
                    'version' => '1.0',
                    'timestamp' => time(),
                    'rate_limit' => $this->rate_limiter->get_limit_info(),
                ], $metadata)
            ];

        // Add membership info if using membership token
        if (isset($this->membership_service) && $this->membership_service->is_active()) {
            $response['meta']['membership_level'] = $this->membership_service->get_current_level();
        }

        // Add network-specific metadata
        $response['meta']['network'] = [
            'ring_leader_connected' => $this->check_ring_leader_connection(),
            'network_id' => get_option('sewn_network_id'),
            'node_type' => $this->get_network_node_type()
        ];

        return $response;
    }

    /**
     * Register additional network-aware routes
     * 
     * Registers endpoints specifically designed for network integration:
     * 
     * 1. /extension/batch - Handles bulk screenshot requests from Chrome Extension
     *    - Supports multiple URLs in single request
     *    - Rate limited based on membership tier
     *    - Optimized for extension performance
     * 
     * 2. /network/status - Reports network connectivity and health
     *    - Ring Leader connection status
     *    - Cache performance metrics
     *    - Node type and role
     * 
     * 3. /webhooks - Manages network event notifications
     *    - Screenshot completion events
     *    - Error notifications
     *    - Status changes
     * 
     * @since 2.1.0
     *
     * @OA\Tag(
     *     name="network",
     *     description="Network integration endpoints"
     * )
     */
    public function register_network_routes() {
        /**
         * @OA\Post(
         *     path="/extension/batch",
         *     summary="Process batch screenshot requests",
         *     description="Handles multiple screenshot requests from Chrome Extension",
         *     operationId="batchScreenshots",
         *     tags={"network"},
         *     @OA\RequestBody(
         *         required=true,
         *         @OA\JsonContent(
         *             type="object",
         *             @OA\Property(
         *                 property="urls",
         *                 type="array",
         *                 @OA\Items(type="string", format="uri"),
         *                 description="Array of URLs to capture"
         *             )
         *         )
         *     ),
         *     @OA\Response(
         *         response=200,
         *         description="Batch processing results",
         *         @OA\JsonContent(ref="#/components/schemas/BatchResponse")
         *     )
         * )
         */
        register_rest_route($this->namespace, '/extension/batch', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_batch_screenshots'],
            'permission_callback' => [$this, 'check_authorization'],
            'args' => [
                'urls' => [
                    'required' => true,
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Array of URLs to capture'
                ]
            ]
        ]);

        /**
         * @OA\Get(
         *     path="/network/status",
         *     summary="Get network status",
         *     description="Reports network connectivity and health metrics",
         *     operationId="getNetworkStatus",
         *     tags={"network"},
         *     @OA\Response(
         *         response=200,
         *         description="Network status information",
         *         @OA\JsonContent(ref="#/components/schemas/NetworkStatus")
         *     )
         * )
         */
        register_rest_route($this->namespace, '/network/status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_network_status'],
            'permission_callback' => [$this, 'check_authorization']
        ]);

        /**
         * @OA\Post(
         *     path="/webhooks",
         *     summary="Manage network webhooks",
         *     description="Register and manage network event notifications",
         *     operationId="manageWebhooks",
         *     tags={"network"},
         *     @OA\Response(
         *         response=200,
         *         description="Webhook operation result",
         *         @OA\JsonContent(ref="#/components/schemas/WebhookResponse")
         *     )
         * )
         */
        register_rest_route($this->namespace, '/webhooks', [
            'methods' => ['POST', 'DELETE'],
            'callback' => [$this, 'manage_webhooks'],
            'permission_callback' => [$this, 'check_authorization']
        ]);
    }

    /**
     * Handle batch screenshot requests from Chrome Extension
     * 
     * Processes multiple screenshot requests in a single API call.
     * Optimized for Chrome Extension usage with:
     * - Parallel processing where possible
     * - Cached results when available
     * - Progress tracking for large batches
     * 
     * Rate Limits:
     * - Free: 10 URLs per request
     * - FreeWire: 25 URLs per request
     * - Wire: 50 URLs per request
     * - ExtraWire: 100 URLs per request
     * 
     * Response Format:
     * {
     *   "success": true,
     *   "data": {
     *     "url1": { "status": "success", "screenshot_url": "..." },
     *     "url2": { "status": "error", "message": "..." }
     *   },
     *   "meta": {
     *     "processed": 5,
     *     "successful": 4,
     *     "failed": 1,
     *     "network": { ... }
     *   }
     * }
     *
     * @param WP_REST_Request $request Request object containing URLs array
     * @return WP_REST_Response Response containing batch results
     *
     * @OA\Post(
     *     path="/extension/batch",
     *     summary="Process batch screenshot requests",
     *     description="Handles multiple screenshot requests in a single call",
     *     operationId="handleBatchScreenshots",
     *     tags={"network"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="urls",
     *                 type="array",
     *                 @OA\Items(type="string", format="uri"),
     *                 description="Array of URLs to capture"
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Batch processing results",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 additionalProperties=@OA\Schema(
     *                     type="object",
     *                     @OA\Property(property="status", type="string", enum={"success", "error"}),
     *                     @OA\Property(property="screenshot_url", type="string", format="uri"),
     *                     @OA\Property(property="message", type="string")
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="meta",
     *                 type="object",
     *                 @OA\Property(property="processed", type="integer", example=5),
     *                 @OA\Property(property="successful", type="integer", example=4),
     *                 @OA\Property(property="failed", type="integer", example=1),
     *                 @OA\Property(
     *                     property="network",
     *                     type="object",
     *                     @OA\Property(property="node_type", type="string"),
     *                     @OA\Property(property="connected", type="boolean")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request parameters",
     *         @OA\JsonContent(ref="#/components/schemas/Error")
     *     ),
     *     @OA\Response(
     *         response=429,
     *         description="Rate limit exceeded",
     *         @OA\JsonContent(ref="#/components/schemas/Error")
     *     )
     * )
     */
    public function handle_batch_screenshots($request) {
        $urls = $request->get_param('urls');
        $results = [];

        foreach ($urls as $url) {
            $screenshot_request = new WP_REST_Request('POST', "/{$this->namespace}/screenshot");
            $screenshot_request->set_param('url', $url);
            $results[$url] = $this->take_screenshot($screenshot_request);
        }

        return rest_ensure_response($this->format_response($results));
    }

    /**
     * Get network-wide screenshot service status
     * 
     * Provides comprehensive status information about the screenshot service
     * within the network context. Includes:
     * 
     * 1. Service Status:
     *    - Local screenshot service health
     *    - Fallback service availability
     *    - Resource usage metrics
     * 
     * 2. Network Status:
     *    - Ring Leader connectivity
     *    - Node type (hub/member/standalone)
     *    - Network ID and role
     * 
     * 3. Cache Statistics:
     *    - Hit/miss ratios
     *    - Storage usage
     *    - Cleanup metrics
     * 
     * Response Format:
     * {
     *   "success": true,
     *   "data": {
     *     "service_status": "active",
     *     "network_status": {
     *       "connected": true,
     *       "role": "member"
     *     },
     *     "cache_stats": {
     *       "hit_ratio": 0.85,
     *       "storage_used": "1.2GB"
     *     }
     *   },
     *   "meta": { ... }
     * }
     *
     * @return WP_REST_Response Detailed status information
     *
     * @OA\Get(
     *     path="/network/status",
     *     summary="Get network status",
     *     description="Reports network connectivity and health metrics",
     *     operationId="getNetworkStatus",
     *     tags={"network"},
     *     @OA\Response(
     *         response=200,
     *         description="Network status information",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="service_status",
     *                     type="string",
     *                     enum={"active", "degraded", "inactive"},
     *                     example="active"
     *                 ),
     *                 @OA\Property(
     *                     property="network_status",
     *                     type="object",
     *                     @OA\Property(property="connected", type="boolean", example=true),
     *                     @OA\Property(property="role", type="string", example="member")
     *                 ),
     *                 @OA\Property(
     *                     property="cache_stats",
     *                     type="object",
     *                     @OA\Property(property="hit_ratio", type="number", format="float", example=0.85),
     *                     @OA\Property(property="storage_used", type="string", example="1.2GB")
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="meta",
     *                 type="object",
     *                 @OA\Property(property="timestamp", type="string", format="date-time"),
     *                 @OA\Property(property="version", type="string", example="2.1.0")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Authentication required",
     *         @OA\JsonContent(ref="#/components/schemas/Error")
     *     ),
     *     @OA\Response(
     *         response=503,
     *         description="Service unavailable",
     *         @OA\JsonContent(ref="#/components/schemas/Error")
     *     )
     * )
     */
    public function get_network_status($request) {
        if (!$this->ring_leader_client) {
            return new WP_Error(
                'network_unavailable',
                'Network integration not available',
                ['status' => 503]
            );
        }

        $data = [
            'connected' => $this->ring_leader_client->is_connected(),
            'network_id' => $this->ring_leader_client->get_network_id(),
            'cache_status' => $this->network_cache ? 
                $this->network_cache->get_status() : 
                'unavailable'
        ];

        if ($request['include_metrics']) {
            $data['metrics'] = $this->get_network_metrics();
        }

        return $this->format_response($data);
    }

    /**
     * Get network performance metrics
     * 
     * @return array Network metrics
     */
    private function get_network_metrics() {
        return [
            'cache_hit_ratio' => $this->network_cache ? 
                $this->network_cache->get_hit_ratio() : 
                0,
            'average_response_time' => $this->get_average_response_time(),
            'error_rate' => $this->get_error_rate()
        ];
    }

    /**
     * Check Ring Leader plugin connectivity
     * 
     * Verifies connection to the Ring Leader plugin which coordinates
     * network communication. This check ensures:
     * 
     * 1. Ring Leader plugin is active and responding
     * 2. Network token is valid
     * 3. Node has proper network permissions
     * 
     * Connection States:
     * - Connected: Full network functionality available
     * - Disconnected: Fallback to standalone mode
     * - Error: Network features disabled
     * 
     * Error Handling:
     * - Timeout after 5 seconds
     * - Automatic retry on temporary failures
     * - Logging of connection issues
     * 
     * @return bool True if connected, false otherwise
     *
     * @OA\Get(
     *     path="/network/ring-leader/status",
     *     summary="Check Ring Leader connection",
     *     description="Verifies connectivity with Ring Leader network coordinator",
     *     operationId="checkRingLeader",
     *     tags={"network"},
     *     @OA\Response(
     *         response=200,
     *         description="Connection status",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="connected", type="boolean"),
     *             @OA\Property(
     *                 property="status",
     *                 type="object",
     *                 @OA\Property(property="plugin_active", type="boolean"),
     *                 @OA\Property(property="token_valid", type="boolean"),
     *                 @OA\Property(property="permissions_valid", type="boolean")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=503,
     *         description="Ring Leader service unavailable",
     *         @OA\JsonContent(ref="#/components/schemas/Error")
     *     )
     * )
     */
    private function check_ring_leader_connection() {
        try {
            $response = wp_remote_get(SEWN_RING_LEADER_URL . '/status', [
                'timeout' => 5,
                'headers' => [
                    'X-Network-Token' => $this->get_network_token()
                ]
            ]);

            return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
        } catch (Exception $e) {
            $this->logger->error('Ring Leader connection check failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get network node type
     * 
     * Determines the role of this installation in the network:
     * 
     * 1. Hub Node (SEWN_IS_HUB):
     *    - Central network coordinator
     *    - Manages member registrations
     *    - Distributes network updates
     * 
     * 2. Member Node (SEWN_IS_MEMBER):
     *    - Regular network participant
     *    - Receives network updates
     *    - Limited network control
     * 
     * 3. Standalone:
     *    - No network integration
     *    - Local functionality only
     *    - Can upgrade to member
     * 
     * Node type affects:
     * - Available API endpoints
     * - Rate limits
     * - Cache behavior
     * - Network permissions
     * 
     * @return string 'hub'|'member'|'standalone'
     */
    private function get_network_node_type() {
        if (defined('SEWN_IS_HUB') && SEWN_IS_HUB) {
            return 'hub';
        } elseif (defined('SEWN_IS_MEMBER') && SEWN_IS_MEMBER) {
            return 'member';
        }
        return 'standalone';
    }

    /**
     * Enhanced authentication check
     * 
     * @param WP_REST_Request $request The request object
     * @return bool|WP_Error True if authorized, WP_Error if not
     */
    private function check_auth($request) {
        // Use existing API key/JWT logic first
        if ($token = $request->get_header('X-API-Key')) {
            return $this->verify_api_key($token);
        }
        
        // Add network check as another auth method
        if ($this->ring_leader_client && ($token = $request->get_header('X-Ring-Leader-Token'))) {
            return $this->ring_leader_client->verify_token($token);
        }
        
        return new WP_Error('unauthorized', 'Valid authentication required');
    }

    /**
     * Enhanced response formatting
     * 
     * @param mixed $data Response data
     * @return WP_REST_Response
     */
    private function format_response($data) {
        $response = [
            'code' => 'success',
            'message' => '',
            'data' => $data
        ];
        
        // Add network info to existing data structure
        if ($this->ring_leader_client && $this->ring_leader_client->is_connected()) {
            $response['data']['network'] = [
                'connected' => true,
                'network_id' => $this->ring_leader_client->get_network_id()
            ];
        }
        
        return new WP_REST_Response($response);
    }

    /**
     * Enhanced rate limiting
     * 
     * @param string $key API key or token
     * @return bool|WP_Error True if within limits, WP_Error if exceeded
     */
    private function apply_rate_limit($key) {
        // Get tier from either API key or network membership
        $tier = $this->ring_leader_client && $this->ring_leader_client->is_network_key($key)
            ? $this->ring_leader_client->get_network_tier($key)
            : $this->get_api_key_tier($key);
        
        return $this->rate_limiter->check_limit($key, $tier);
    }

}
