<?php
if (!defined('ABSPATH')) exit;

class SEWN_Cache {
    public function get($key) {
        return get_transient('sewn_screenshot_' . $key);
    }

    public function set($key, $value, $expiration = null) {
        if (null === $expiration) {
            $expiration = SEWN_SCREENSHOTS_CACHE_TIME;
        }
        return set_transient('sewn_screenshot_' . $key, $value, $expiration);
    }

    public function delete($key) {
        return delete_transient('sewn_screenshot_' . $key);
    }
}