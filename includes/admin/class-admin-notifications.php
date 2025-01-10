<?php
/**
 * Admin Notifications Class
 * Handles WordPress admin notifications
 */

if (!defined('ABSPATH')) exit;

class SEWN_Admin_Notifications {
    private $logger;
    private $notification_key = 'sewn_admin_notifications';
    private $dismissed_key = 'sewn_dismissed_notifications';
    
    public function __construct($logger) {
        $this->logger = $logger;
        add_action('admin_notices', [$this, 'display_notifications']);
        add_action('wp_ajax_sewn_dismiss_notification', [$this, 'handle_dismissal']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }
    
    public function add_notification($message, $type = 'info', $dismissible = true, $expiry = null) {
        $notifications = get_option($this->notification_key, []);
        
        $notification = [
            'id' => wp_generate_uuid4(),
            'message' => $message,
            'type' => $type,
            'dismissible' => $dismissible,
            'time' => time(),
            'expiry' => $expiry
        ];
        
        $notifications[] = $notification;
        
        update_option($this->notification_key, $notifications);
        
        $this->logger->info('Added admin notification', [
            'notification' => $notification
        ]);
        
        return $notification['id'];
    }
    
    public function display_notifications() {
        $notifications = $this->get_active_notifications();
        $dismissed = get_option($this->dismissed_key, []);
        
        foreach ($notifications as $notification) {
            if (in_array($notification['id'], $dismissed)) {
                continue;
            }
            
            $this->render_notification($notification);
        }
    }
    
    public function handle_dismissal() {
        check_ajax_referer('sewn_dismiss_notification', 'nonce');
        
        $notification_id = sanitize_text_field($_POST['notification_id']);
        
        if (empty($notification_id)) {
            wp_send_json_error(['message' => 'Invalid notification ID']);
        }
        
        $dismissed = get_option($this->dismissed_key, []);
        $dismissed[] = $notification_id;
        
        update_option($this->dismissed_key, array_unique($dismissed));
        
        $this->logger->info('Dismissed notification', [
            'notification_id' => $notification_id
        ]);
        
        wp_send_json_success();
    }
    
    public function enqueue_scripts() {
        wp_enqueue_script(
            'sewn-admin-notifications',
            SEWN_SCREENSHOTS_URL . 'assets/js/admin-notifications.js',
            ['jquery'],
            SEWN_SCREENSHOTS_VERSION,
            true
        );
        
        wp_localize_script('sewn-admin-notifications', 'sewnNotifications', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sewn_dismiss_notification')
        ]);
    }
    
    private function get_active_notifications() {
        $notifications = get_option($this->notification_key, []);
        $current_time = time();
        
        return array_filter($notifications, function($notification) use ($current_time) {
            if (!empty($notification['expiry']) && $current_time > $notification['expiry']) {
                return false;
            }
            return true;
        });
    }
    
    private function render_notification($notification) {
        $class = 'notice notice-' . $notification['type'];
        if ($notification['dismissible']) {
            $class .= ' is-dismissible';
        }
        
        printf(
            '<div class="%1$s" data-notification-id="%2$s">
                <p>%3$s</p>
            </div>',
            esc_attr($class),
            esc_attr($notification['id']),
            wp_kses_post($notification['message'])
        );
    }
    
    public function clear_all_notifications() {
        delete_option($this->notification_key);
        delete_option($this->dismissed_key);
        
        $this->logger->info('Cleared all notifications');
    }
} 