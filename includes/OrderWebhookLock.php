<?php

namespace WooMS;

defined('ABSPATH') || exit;

/**
 * Short, owner-scoped leases for local webhook queue mutations.
 *
 * Uses the options table's unique key and compare-and-swap, independently of
 * object caching. Critical sections must contain local work only: a lease is
 * not an exactly-once guarantee if a process stalls beyond its lifetime.
 *
 * @internal
 */
final class OrderWebhookLock
{
    const CLEANUP_HOOK = 'wooms_cleanup_order_webhook_locks';
    private $key;
    private $value;
    private const DURATION = 300;

    public static function init()
    {
        add_action('init', array(__CLASS__, 'schedule_cleanup'));
        add_action(self::CLEANUP_HOOK, array(__CLASS__, 'cleanup'));
    }

    private function __construct($key, $value)
    {
        $this->key = $key;
        $this->value = $value;
    }

    /** @return self|\WP_Error */
    public static function acquire($resource)
    {
        global $wpdb;
        $key = 'wooms_webhook_lock_' . hash('sha256', $resource);
        $value = self::new_value();
        $suppress = $wpdb->suppress_errors(true);
        try {
            $previous = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key));
            if ($wpdb->last_error) {
                return self::storage_error();
            }
            if (null === $previous) {
                $changed = $wpdb->insert($wpdb->options, array('option_name' => $key, 'option_value' => $value, 'autoload' => 'no'));
                if (false === $changed) {
                    // A rival INSERT is expected contention; an absent/unreadable row is a storage failure.
                    $previous = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key));
                    return null !== $previous && !$wpdb->last_error ? self::busy_error() : self::storage_error();
                }
            } else {
                if ((int) $previous >= time()) {
                    return self::busy_error();
                }
                $changed = $wpdb->update($wpdb->options, array('option_value' => $value), array('option_name' => $key, 'option_value' => $previous));
            }
            if (false === $changed) {
                return self::storage_error();
            }
            if (1 !== $changed) {
                return self::busy_error();
            }
            self::clear_cache($key, null === $previous);
            return new self($key, $value);
        } finally {
            $wpdb->suppress_errors($suppress);
        }
    }

    /** Refresh ownership before enqueue or acknowledgement. @return true|\WP_Error */
    public function renew()
    {
        global $wpdb;
        if ((int) $this->value < time()) {
            return self::lost_error();
        }
        $value = self::new_value();
        $suppress = $wpdb->suppress_errors(true);
        try {
            $changed = $wpdb->update($wpdb->options, array('option_value' => $value), array('option_name' => $this->key, 'option_value' => $this->value));
        } finally {
            $wpdb->suppress_errors($suppress);
        }
        if (false === $changed) {
            return self::storage_error();
        }
        if (1 !== $changed) {
            return self::lost_error();
        }
        $this->value = $value;
        self::clear_cache($this->key);
        return true;
    }

    /** A former owner cannot delete a newer lease. @return bool|\WP_Error */
    public function release()
    {
        global $wpdb;
        $suppress = $wpdb->suppress_errors(true);
        try {
            $changed = $wpdb->delete($wpdb->options, array('option_name' => $this->key, 'option_value' => $this->value));
        } finally {
            $wpdb->suppress_errors($suppress);
        }
        if (false === $changed) {
            return self::storage_error();
        }
        self::clear_cache($this->key);
        return 1 === $changed;
    }

    private static function new_value()
    {
        return (time() + self::DURATION) . '|' . wp_generate_uuid4();
    }

    /** Keep abandoned webhook leases from accumulating after process crashes. */
    public static function schedule_cleanup()
    {
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            if (!wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP_HOOK)) {
                do_action('wooms_logger_error', __CLASS__, 'Не удалось назначить очистку блокировок веб-хуков');
            }
        }
    }

    /** Delete only expired leases, in a bounded maintenance job. */
    public static function cleanup()
    {
        global $wpdb;
        $suppress = $wpdb->suppress_errors(true);
        try {
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d LIMIT 1000",
                $wpdb->esc_like('wooms_webhook_lock_') . '%',
                time()
            ));
        } finally {
            $wpdb->suppress_errors($suppress);
        }
        if (false === $deleted) {
            do_action('wooms_logger_error', __CLASS__, 'Не удалось очистить просроченные блокировки веб-хуков');
        }
    }

    private static function clear_cache($key, $inserted = false)
    {
        wp_cache_delete($key, 'options');
        if ($inserted) {
            wp_cache_delete('notoptions', 'options');
        }
    }

    private static function busy_error()
    {
        return new \WP_Error('wooms_webhook_busy', 'Webhook work is already in progress.', array('status' => 409));
    }

    private static function storage_error()
    {
        return new \WP_Error('wooms_webhook_lock_failed', 'Webhook lock could not be persisted.', array('status' => 503));
    }

    private static function lost_error()
    {
        return new \WP_Error('wooms_webhook_lock_lost', 'Webhook lock ownership was lost.', array('status' => 503));
    }
}

OrderWebhookLock::init();
