<?php
if (!defined('ABSPATH')) exit;

class SEWN_Menu_Manager {
    private $plugin_slug = 'sewn-screenshots';
    private $logger;
    private $dashboard;
    private $api_tester;
    private $api_manager;
    private $settings;
    private $test_results;
    private $swagger_docs;

    public function __construct($logger, $dashboard, $api_tester, $api_manager, $settings, $test_results, $swagger_docs = null) {
        $this->logger = $logger;
        $this->dashboard = $dashboard;
        $this->api_tester = $api_tester;
        $this->api_manager = $api_manager;
        $this->settings = $settings;
        $this->test_results = $test_results;
        $this->swagger_docs = $swagger_docs;

        add_action('admin_menu', [$this, 'register_menus']);
        
        $this->logger->debug('Menu manager initialized');
    }

    public function register_menus() {
        $this->logger->debug('Registering menus');
        
        // Main menu
        add_menu_page(
            'Screenshot Service',
            'Screenshot Service',
            'manage_options',
            $this->plugin_slug,
            [$this->dashboard, 'render_dashboard'],
            'dashicons-camera',
            30
        );

        // Ensure first submenu matches parent
        add_submenu_page(
            $this->plugin_slug,
            'Dashboard',
            'Dashboard',
            'manage_options',
            $this->plugin_slug,
            [$this->dashboard, 'render_dashboard']
        );

        // Other submenus
        add_submenu_page(
            $this->plugin_slug,
            'API Tester',
            'API Tester',
            'manage_options',
            $this->plugin_slug . '-tester',
            [$this->api_tester, 'render_test_interface']
        );

        add_submenu_page(
            $this->plugin_slug,
            'API Management',
            'API Management',
            'manage_options',
            $this->plugin_slug . '-api',
            [$this->api_manager, 'render_api_dashboard']
        );

        add_submenu_page(
            $this->plugin_slug,
            'Settings',
            'Settings',
            'manage_options',
            $this->plugin_slug . '-settings',
            [$this->settings, 'render_settings_page']
        );

        add_submenu_page(
            $this->plugin_slug,
            'Test Results',
            'Test Results',
            'manage_options',
            $this->plugin_slug . '-results',
            [$this->test_results, 'render_test_page']
        );

        // Only add Swagger docs menu if the service is available
        if ($this->swagger_docs !== null) {
            add_submenu_page(
                $this->plugin_slug,
                'API Documentation',
                'API Documentation',
                'manage_options',
                $this->plugin_slug . '-docs',
                [$this->swagger_docs, 'render_docs_page']
            );
        }

        $this->logger->debug('Menu registration complete', [
            'main_menu' => $this->plugin_slug,
            'submenus' => ['Dashboard', 'API Tester', 'API Management', 'Settings', 'Test Results', 
                          $this->swagger_docs !== null ? 'API Documentation' : null]
        ]);
    }
} 