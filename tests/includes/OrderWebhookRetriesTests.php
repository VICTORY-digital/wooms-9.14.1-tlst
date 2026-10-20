<?php

declare(strict_types=1);

use WooMS\OrderWebhookRetries;

it('persists each webhook retry with its delay and next attempt', function (int $attempt, int $delay): void {
    $captured = array();
    $filter = static function ($preempt, $timestamp, $hook, $args, $group, $priority, $unique) use (&$captured) {
        $captured = compact('timestamp', 'hook', 'args', 'group', 'priority', 'unique');
        return 321;
    };
    add_filter('pre_as_schedule_single_action', $filter, 10, 7);

    try {
        $started = time();
        $result = OrderWebhookRetries::schedule_retry(
            123,
            'c0a8012e-1234-4abc-8def-1234567890ab',
            'provider-request-12345678',
            $attempt
        );

        expect($result)->toBe(321);
        expect($captured['hook'])->toBe('wooms_process_order_webhook');
        expect($captured['group'])->toBe('WooMS');
        expect($captured['unique'])->toBeFalse();
        expect($captured['args'])->toBe(array(
            'order_id' => 123,
            'order_uuid' => 'c0a8012e-1234-4abc-8def-1234567890ab',
            'request_id' => 'provider-request-12345678',
            'attempt' => $attempt + 1,
        ));
        expect($captured['timestamp'] >= $started + $delay)->toBeTrue();
        expect($captured['timestamp'] <= time() + $delay)->toBeTrue();
    } finally {
        remove_filter('pre_as_schedule_single_action', $filter, 10);
    }
})->with(array(
    array(0, 30),
    array(1, 120),
    array(2, 300),
));

it('does not schedule exhausted or malformed retry attempts', function ($attempt, string $error_code): void {
    $queue_calls = 0;
    $filter = static function () use (&$queue_calls) {
        $queue_calls++;
        return 321;
    };
    add_filter('pre_as_schedule_single_action', $filter);

    try {
        $result = OrderWebhookRetries::schedule_retry(123, 'order-uuid', 'request-id', $attempt);

        expect($result)->toBeInstanceOf(WP_Error::class);
        expect($result->get_error_code())->toBe($error_code);
        expect($queue_calls)->toBe(0);
    } finally {
        remove_filter('pre_as_schedule_single_action', $filter);
    }
})->with(array(
    array(3, 'wooms_webhook_retries_exhausted'),
    array(4, 'wooms_webhook_retries_exhausted'),
    array(-1, 'wooms_webhook_invalid_attempt'),
    array('1', 'wooms_webhook_invalid_attempt'),
    array(1.5, 'wooms_webhook_invalid_attempt'),
));

it('reports retry queue failures without claiming a persisted action', function (string $failure): void {
    $filter = static function () use ($failure) {
        if ('exception' === $failure) {
            throw new RuntimeException('Test action store is unavailable');
        }
        return 'negative id' === $failure ? -1 : 0;
    };
    add_filter('pre_as_schedule_single_action', $filter);

    try {
        $result = OrderWebhookRetries::schedule_retry(123, 'order-uuid', 'request-id', 0);

        expect($result)->toBeInstanceOf(WP_Error::class);
        expect($result->get_error_code())->toBe('wooms_webhook_retry_queue_failed');
        expect($result->get_error_data())->toBe(array('request_id' => 'request-id', 'attempt' => 1));
    } finally {
        remove_filter('pre_as_schedule_single_action', $filter);
    }
})->with(array('zero', 'negative id', 'exception'));

it('persists the remaining batch with the dispatcher retry delay and argument order', function (int $attempt, int $delay): void {
    $captured = array();
    $filter = static function ($preempt, $timestamp, $hook, $args, $group) use (&$captured) {
        $captured = compact('timestamp', 'hook', 'args', 'group');
        return 321;
    };
    add_filter('pre_as_schedule_single_action', $filter, 10, 5);

    try {
        $started = time();
        $result = OrderWebhookRetries::schedule_batch_retry('request-id', array(2 => 'remaining-order-uuid'), $attempt);

        expect($result)->toBe(321);
        expect($captured['hook'])->toBe('wooms_dispatch_order_webhook_batch');
        expect($captured['group'])->toBe('WooMS');
        expect($captured['args'])->toBe(array(
            'request_id' => 'request-id',
            'order_uuids' => array('remaining-order-uuid'),
            'attempt' => $attempt + 1,
        ));
        expect($captured['timestamp'] >= $started + $delay)->toBeTrue();
        expect($captured['timestamp'] <= time() + $delay)->toBeTrue();
    } finally {
        remove_filter('pre_as_schedule_single_action', $filter, 10);
    }
})->with(array(array(0, 30), array(1, 120), array(2, 300)));
