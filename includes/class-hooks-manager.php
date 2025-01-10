<?php
/**
 * Hooks Manager Class
 * Registers all hooks for the plugin
 */

if (!defined('ABSPATH')) exit;

class SEWN_Hooks_Manager {
    public static function register_hooks() {
        // Installation hooks
        add_action('sewn_before_tool_installation', 'sewn_before_installation', 10, 2);
        add_action('sewn_after_tool_installation', 'sewn_after_installation', 10, 2);
        add_action('sewn_installation_failed', 'sewn_installation_failed', 10, 3);
        
        // Detection hooks
        add_filter('sewn_tool_detection_results', 'sewn_filter_detection_results', 10, 1);
        add_action('sewn_tool_detected', 'sewn_tool_detected', 10, 2);
        
        // Security hooks
        add_filter('sewn_verify_installation_source', 'sewn_verify_source', 10, 2);
        add_action('sewn_security_violation', 'sewn_log_violation', 10, 3);
        
        // Health check hooks
        add_filter('sewn_health_check_results', 'sewn_filter_health_results', 10, 1);
        add_action('sewn_health_check_failed', 'sewn_health_check_failed', 10, 2);
    }
    
    public static function register_callback_functions() {
        // Installation callbacks
        function sewn_before_installation($tool_id, $config) {
            do_action('sewn_log_event', 'installation_started', [
                'tool' => $tool_id,
                'config' => $config
            ]);
        }
        
        function sewn_after_installation($tool_id, $success) {
            do_action('sewn_log_event', 'installation_completed', [
                'tool' => $tool_id,
                'success' => $success
            ]);
        }
        
        function sewn_installation_failed($tool_id, $error, $context) {
            do_action('sewn_log_event', 'installation_failed', [
                'tool' => $tool_id,
                'error' => $error,
                'context' => $context
            ]);
        }
        
        // Detection callbacks
        function sewn_filter_detection_results($results) {
            return apply_filters('sewn_modify_detection_results', $results);
        }
        
        function sewn_tool_detected($tool_id, $status) {
            do_action('sewn_log_event', 'tool_detected', [
                'tool' => $tool_id,
                'status' => $status
            ]);
        }
        
        // Security callbacks
        function sewn_verify_source($source, $tool_id) {
            return apply_filters('sewn_verify_tool_source', $source, $tool_id);
        }
        
        function sewn_log_violation($type, $context, $severity) {
            do_action('sewn_log_event', 'security_violation', [
                'type' => $type,
                'context' => $context,
                'severity' => $severity
            ]);
        }
        
        // Health check callbacks
        function sewn_filter_health_results($results) {
            return apply_filters('sewn_modify_health_results', $results);
        }
        
        function sewn_health_check_failed($check_id, $error) {
            do_action('sewn_log_event', 'health_check_failed', [
                'check' => $check_id,
                'error' => $error
            ]);
        }
    }
} 