<?php

declare(strict_types=1);

use WooMS\OrderWebhookLock;

it('allows one webhook lock owner and releases only the matching lease', function (): void {
    $resource = 'test:' . wp_generate_uuid4();
    $lease = OrderWebhookLock::acquire($resource);
    expect($lease)->toBeInstanceOf(OrderWebhookLock::class);
    try {
        expect(OrderWebhookLock::acquire($resource))->toBeInstanceOf(WP_Error::class);
    } finally {
        $lease->release();
    }
    $next = OrderWebhookLock::acquire($resource);
    expect($next)->toBeInstanceOf(OrderWebhookLock::class);
    expect($lease->release())->toBeFalse();
    expect(OrderWebhookLock::acquire($resource))->toBeInstanceOf(WP_Error::class);
    $next->release();
});

it('takes over an expired lease without letting the old owner release it', function (): void {
    global $wpdb;
    $resource = 'test:' . wp_generate_uuid4();
    $key = 'wooms_webhook_lock_' . hash('sha256', $resource);
    $old = OrderWebhookLock::acquire($resource);
    $wpdb->update($wpdb->options, array('option_value' => (time() - 1) . '|old'), array('option_name' => $key));
    $lease = OrderWebhookLock::acquire($resource);
    expect($lease)->toBeInstanceOf(OrderWebhookLock::class);
    try {
        expect($old->renew())->toBeInstanceOf(WP_Error::class);
        expect($old->release())->toBeFalse();
        expect(OrderWebhookLock::acquire($resource))->toBeInstanceOf(WP_Error::class);
    } finally {
        $lease->release();
    }
});

it('does not overwrite a concurrent owner during insert or expired takeover', function (bool $expired): void {
    global $wpdb;
    $resource = 'test:' . wp_generate_uuid4();
    $key = 'wooms_webhook_lock_' . hash('sha256', $resource);
    if ($expired) {
        $wpdb->insert($wpdb->options, array('option_name' => $key, 'option_value' => (time() - 1) . '|old', 'autoload' => 'no'));
    }
    $rival = null;
    $triggered = false;
    $filter = static function ($query) use ($resource, $key, $expired, &$rival, &$triggered) {
        $operation = $expired ? 'UPDATE' : 'INSERT';
        if (!$triggered && 0 === stripos(ltrim($query), $operation) && false !== strpos($query, $key)) {
            // The competing request commits after our read and before our write.
            $triggered = true;
            $rival = OrderWebhookLock::acquire($resource);
        }
        return $query;
    };
    add_filter('query', $filter);
    try {
        $loser = OrderWebhookLock::acquire($resource);
        expect($triggered)->toBeTrue();
        expect($rival)->toBeInstanceOf(OrderWebhookLock::class);
        expect($loser)->toBeInstanceOf(WP_Error::class);
        expect($loser->get_error_code())->toBe('wooms_webhook_busy');
    } finally {
        remove_filter('query', $filter);
        if ($rival instanceof OrderWebhookLock) {
            $rival->release();
        }
    }
})->with(array(false, true));

it('cleans abandoned expired leases without deleting active or unrelated options', function (): void {
    global $wpdb;
    $suffix = wp_generate_uuid4();
    $expired_key = 'wooms_webhook_lock_expired_' . $suffix;
    $active_key = 'wooms_webhook_lock_active_' . $suffix;
    $unrelated_key = 'woomsXwebhookXlockXother_' . $suffix;
    $fixtures = array($expired_key => time() - 1, $active_key => time() + 300, $unrelated_key => time() - 1);
    foreach ($fixtures as $key => $expiry) {
        $wpdb->insert($wpdb->options, array('option_name' => $key, 'option_value' => $expiry . '|test', 'autoload' => 'no'));
    }
    try {
        OrderWebhookLock::cleanup();
        expect($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $expired_key)))->toBeNull();
        expect($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $active_key)))->toBe($fixtures[$active_key] . '|test');
        expect($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $unrelated_key)))->toBe($fixtures[$unrelated_key] . '|test');
    } finally {
        foreach (array($expired_key, $active_key, $unrelated_key) as $key) {
            $wpdb->delete($wpdb->options, array('option_name' => $key));
        }
    }
});

it('schedules only one fixed webhook lease cleanup event', function (): void {
    $before = wp_next_scheduled(OrderWebhookLock::CLEANUP_HOOK);
    try {
        OrderWebhookLock::schedule_cleanup();
        $scheduled = wp_next_scheduled(OrderWebhookLock::CLEANUP_HOOK);
        expect(is_int($scheduled))->toBeTrue();
        OrderWebhookLock::schedule_cleanup();
        expect(wp_next_scheduled(OrderWebhookLock::CLEANUP_HOOK))->toBe($scheduled);
    } finally {
        if (false === $before && isset($scheduled)) {
            wp_unschedule_event($scheduled, OrderWebhookLock::CLEANUP_HOOK);
        }
    }
});

it('reports database failures instead of granting a webhook lock', function (string $operation): void {
    global $wpdb;
    $resource = 'test:' . wp_generate_uuid4();
    $key = 'wooms_webhook_lock_' . hash('sha256', $resource);
    $expired = (time() - 1) . '|old';
    if ('UPDATE' === $operation) {
        $wpdb->insert($wpdb->options, array('option_name' => $key, 'option_value' => $expired, 'autoload' => 'no'));
    }
    $filter = static function ($query) use ($operation, $key) {
        return 0 === stripos(ltrim($query), $operation) && false !== strpos($query, $key)
            ? 'INVALID WEBHOOK LOCK TEST QUERY' : $query;
    };
    add_filter('query', $filter);
    try {
        $result = OrderWebhookLock::acquire($resource);
        expect($result)->toBeInstanceOf(WP_Error::class);
        expect($result->get_error_code())->toBe('wooms_webhook_lock_failed');
    } finally {
        remove_filter('query', $filter);
        $wpdb->delete($wpdb->options, array('option_name' => $key));
    }
})->with(array('SELECT', 'INSERT', 'UPDATE'));

it('reports failed lease renewal or release while preserving the owner', function (bool $release): void {
    $resource = 'test:' . wp_generate_uuid4();
    $key = 'wooms_webhook_lock_' . hash('sha256', $resource);
    $owner = OrderWebhookLock::acquire($resource);
    $filter = static function ($query) use ($key, $release) {
        return 0 === stripos(ltrim($query), $release ? 'DELETE' : 'UPDATE') && false !== strpos($query, $key)
            ? 'INVALID WEBHOOK LOCK TEST QUERY' : $query;
    };
    add_filter('query', $filter);
    try {
        $result = $release ? $owner->release() : $owner->renew();
        expect($result)->toBeInstanceOf(WP_Error::class);
        expect($result->get_error_code())->toBe('wooms_webhook_lock_failed');
    } finally {
        remove_filter('query', $filter);
    }
    try {
        expect(OrderWebhookLock::acquire($resource))->toBeInstanceOf(WP_Error::class);
        expect($owner->renew())->toBeTrue();
    } finally {
        $owner->release();
    }
})->with(array(false, true));
