<?php

declare(strict_types=1);

use WooMS\OrderUpdateFromMoySklad;

$batch = array();

beforeEach(function () use (&$batch): void {
    $missing = new stdClass();
    $batch = array(
        'missing' => $missing,
        'options' => array(),
        'request_id' => wp_generate_uuid4(),
        'uuids' => array(wp_generate_uuid4(), wp_generate_uuid4()),
        'unlinked_uuid' => wp_generate_uuid4(),
        'post_ids' => array(),
        'actions' => array(),
        'enqueued' => array(),
        'retries' => array(),
        'errors' => array(),
        'http_calls' => 0,
        'failure' => '',
        'parent_failure' => '',
        'parent_fail_at' => 0,
        'parent_calls' => 0,
        'failure_used' => false,
        'retry_failure' => false,
    );
    foreach (array('wooms_enable_webhooks', 'wooms_webhook_secret', OrderUpdateFromMoySklad::$state_key) as $option) {
        $batch['options'][$option] = get_option($option, $missing);
    }
    $batch['rate_key'] = 'wooms_webhook_rate_' . gmdate('YmdHi');
    $batch['original_rate'] = get_transient($batch['rate_key']);
    $batch['replay_key'] = 'wooms_webhook_replay_' . hash('sha256', $batch['request_id']);
    update_option('wooms_enable_webhooks', 1);
    update_option('wooms_webhook_secret', str_repeat('e', 48), false);
    OrderUpdateFromMoySklad::set_state('is_wait', 1);
    delete_transient($batch['rate_key']);

    foreach ($batch['uuids'] as $uuid) {
        $id = wp_insert_post(array('post_type' => 'shop_order', 'post_status' => 'publish', 'post_title' => 'Webhook batch fixture'));
        $batch['post_ids'][] = $id;
        update_post_meta($id, 'wooms_id', $uuid);
        delete_post_meta($id, 'lock_callback_update');
    }

    // Use future native AS records so unrelated queue runners cannot execute test jobs.
    $persist = static function ($hook, $args, $group) use (&$batch) {
        $id = as_schedule_single_action(time() + 3600, $hook, $args, $group);
        if (!is_int($id) || $id <= 0) {
            throw new RuntimeException('Could not persist an isolated test action');
        }
        $batch['actions'][] = $id;
        return $id;
    };
    $batch['queue_filter'] = static function ($preempt, $hook, $args, $group) use (&$batch, $persist) {
        if (!in_array($hook, array('wooms_process_order_webhook', 'wooms_dispatch_order_webhook_batch'), true)) {
            return $preempt;
        }
        $is_child = 'wooms_process_order_webhook' === $hook;
        if (!$is_child) {
            $batch['parent_calls']++;
        }
        $parent_fails_here = !$is_child && '' !== $batch['parent_failure']
            && (0 === $batch['parent_fail_at'] || $batch['parent_calls'] === $batch['parent_fail_at']);
        if ($parent_fails_here && 'persist then throw' !== $batch['parent_failure']) {
            if ('exception' === $batch['parent_failure']) {
                throw new RuntimeException('Test dispatcher store failure');
            }
            return 'negative id' === $batch['parent_failure'] ? -1 : 0;
        }
        $fails_here = $is_child && $args['order_uuid'] === $batch['uuids'][1]
            && (!$batch['failure_used'] || 'always' === $batch['failure']);
        if ($fails_here && in_array($batch['failure'], array('zero', 'exception', 'always'), true)) {
            $batch['failure_used'] = true;
            if ('exception' === $batch['failure']) {
                throw new RuntimeException('Test child store failure');
            }
            return 0;
        }
        $id = $persist($hook, $args, $group);
        $batch['enqueued'][] = array('id' => $id, 'hook' => $hook, 'args' => $args);
        if ($parent_fails_here && 'persist then throw' === $batch['parent_failure']) {
            throw new RuntimeException('Test exception after a durable dispatcher insert');
        }
        if ($fails_here && 'persist then throw' === $batch['failure']) {
            $batch['failure_used'] = true;
            throw new RuntimeException('Test exception after a durable insert');
        }
        return $id;
    };
    $batch['retry_filter'] = static function ($preempt, $timestamp, $hook, $args, $group) use (&$batch, $persist) {
        if ('wooms_dispatch_order_webhook_batch' !== $hook || $timestamp >= time() + 600) {
            return $preempt;
        }
        if ($batch['retry_failure']) {
            return 0;
        }
        $id = $persist($hook, $args, $group);
        $batch['retries'][] = array('id' => $id, 'hook' => $hook, 'args' => $args, 'timestamp' => $timestamp);
        return $id;
    };
    $batch['http_filter'] = static function () use (&$batch) {
        $batch['http_calls']++;
        return new WP_Error('unexpected_batch_http', 'Batch fan-out must not call the provider');
    };
    $batch['logger'] = static function ($source, $title, $context = array()) use (&$batch): void {
        if (OrderUpdateFromMoySklad::class === $source) {
            $batch['errors'][] = compact('title', 'context');
        }
    };
    add_filter('pre_as_enqueue_async_action', $batch['queue_filter'], 10, 4);
    add_filter('pre_as_schedule_single_action', $batch['retry_filter'], 10, 5);
    add_filter('pre_http_request', $batch['http_filter']);
    add_action('wooms_logger_error', $batch['logger'], 10, 3);
});

afterEach(function () use (&$batch): void {
    remove_filter('pre_as_enqueue_async_action', $batch['queue_filter'], 10);
    remove_filter('pre_as_schedule_single_action', $batch['retry_filter'], 10);
    remove_filter('pre_http_request', $batch['http_filter']);
    remove_action('wooms_logger_error', $batch['logger'], 10);
    foreach (array_unique($batch['actions']) as $id) {
        ActionScheduler::store()->delete_action($id);
    }
    foreach ($batch['post_ids'] as $id) {
        wp_delete_post($id, true);
    }
    delete_transient($batch['replay_key']);
    if (false === $batch['original_rate']) {
        delete_transient($batch['rate_key']);
    } else {
        set_transient($batch['rate_key'], $batch['original_rate'], 2 * MINUTE_IN_SECONDS);
    }
    foreach ($batch['options'] as $option => $value) {
        if ($value === $batch['missing']) {
            delete_option($option);
        } else {
            update_option($option, $value, false);
        }
    }
});

$receive_batch = static function ($uuids, $invalid_tail = false) use (&$batch) {
    $events = array_map(static function ($uuid) {
        return array('meta' => array('href' => 'https://api.moysklad.ru/api/remap/1.2/entity/customerorder/' . $uuid), 'action' => 'UPDATE');
    }, $uuids);
    if ($invalid_tail) {
        $events[] = array('meta' => array('href' => 'https://attacker.example/customerorder/1'));
    }
    $request = new WP_REST_Request('POST', '/wooms/v1/order-update/');
    $request->set_query_params(array('key' => str_repeat('e', 48), 'requestId' => $batch['request_id']));
    $request->set_body(wp_json_encode(array('events' => $events)));
    expect(OrderUpdateFromMoySklad::authorize_webhook($request))->toBeTrue();
    return OrderUpdateFromMoySklad::get_data_order_from_moysklad($request);
};
$children = static function () use (&$batch) {
    return array_values(array_filter($batch['enqueued'], static function ($job) {
        return 'wooms_process_order_webhook' === $job['hook'];
    }));
};

it('accepts all batch orders through one durable dispatcher', function (string $scenario) use (&$batch, $receive_batch, $children): void {
    $uuids = array($batch['uuids'][0], $batch['unlinked_uuid'], $batch['uuids'][1]);
    if ('duplicates' === $scenario) {
        $uuids = array($batch['uuids'][0], $batch['uuids'][0], $batch['uuids'][1], $batch['uuids'][1]);
    }
    if ('all unlinked' === $scenario) {
        $uuids = array($batch['unlinked_uuid'], wp_generate_uuid4());
    }
    if ('locked child' === $scenario) {
        update_post_meta($batch['post_ids'][1], 'lock_callback_update', 1);
    }
    expect($receive_batch($uuids)->get_status())->toBe(204);
    expect(count($batch['enqueued']))->toBe(1);
    expect($children())->toBe(array());
    $job = $batch['enqueued'][0];
    expect($job['hook'])->toBe('wooms_dispatch_order_webhook_batch');
    expect($job['args'])->toBe(array('request_id' => $batch['request_id'], 'order_uuids' => array_values(array_unique($uuids)), 'attempt' => 0));
    expect($batch['http_calls'])->toBe(0);
    expect(OrderUpdateFromMoySklad::get_state('is_wait'))->toBe(1);
    expect($receive_batch($uuids)->get_status())->toBe(204);
    expect(count($batch['enqueued']))->toBe(1);

    do_action($job['hook'], ...array_values($job['args']));
    $jobs = $children();
    expect(count($jobs))->toBe('all unlinked' === $scenario ? 0 : 2);
    if ('all unlinked' !== $scenario) {
        expect(array_column(array_column($jobs, 'args'), 'order_uuid'))->toBe($batch['uuids']);
    }
    if ('locked child' === $scenario) {
        expect(get_post_meta($batch['post_ids'][1], 'lock_callback_update', true))->toBe(1);
        do_action($jobs[1]['hook'], ...array_values($jobs[1]['args']));
        expect(get_post_meta($batch['post_ids'][1], 'lock_callback_update', true))->toBe('');
    }
    expect($batch['http_calls'])->toBe(0);
    expect($batch['retries'])->toBe(array());
})->with(array('mixed orders', 'duplicates', 'all unlinked', 'locked child'));

it('validates every event before accepting new or completed batches', function (bool $completed) use (&$batch, $receive_batch): void {
    if ($completed) {
        expect($receive_batch($batch['uuids'])->get_status())->toBe(204);
    }
    $before = count($batch['enqueued']);
    expect($receive_batch($batch['uuids'], true)->get_status())->toBe(400);
    expect(count($batch['enqueued']))->toBe($before);
    expect(get_transient($batch['replay_key']))->toBe($completed ? 'processed' : false);
    expect($batch['http_calls'])->toBe(0);
})->with(array(false, true));

it('splits a large notification into bounded dispatcher arguments', function () use (&$batch, $receive_batch, $children): void {
    $batch['request_id'] = str_repeat('r', 128);
    $batch['replay_key'] = 'wooms_webhook_replay_' . hash('sha256', $batch['request_id']);
    $uuids = $batch['uuids'];
    for ($index = 0; $index < 418; $index++) {
        $uuids[] = wp_generate_uuid4();
    }
    // Every event is valid, and the body fits the receiver's existing 64 KiB cap.
    $payload = array('events' => array_map(static function ($uuid) {
        return array('meta' => array('href' => 'https://api.moysklad.ru/api/remap/1.2/entity/customerorder/' . $uuid), 'action' => 'UPDATE');
    }, $uuids));
    expect(strlen(wp_json_encode($payload)) <= 65536)->toBeTrue();
    expect($receive_batch($uuids)->get_status())->toBe(204);
    $jobs = $batch['enqueued'];
    expect(count($jobs) > 1)->toBeTrue();
    $accepted = array();
    foreach ($jobs as $job) {
        expect($job['hook'])->toBe('wooms_dispatch_order_webhook_batch');
        expect(strlen(wp_json_encode($job['args'])) < 8000)->toBeTrue();
        $retry_args = $job['args'];
        $retry_args['attempt'] = 3;
        expect(strlen(wp_json_encode($retry_args)) < 8000)->toBeTrue();
        $accepted = array_merge($accepted, $job['args']['order_uuids']);
    }
    expect($accepted)->toBe($uuids);
    $batch['failure'] = 'zero';
    $exception = null;
    try {
        do_action($jobs[0]['hook'], ...array_values($jobs[0]['args']));
    } catch (RuntimeException $caught) {
        $exception = $caught;
    }
    expect($exception)->toBeInstanceOf(RuntimeException::class);
    expect(count($batch['retries']))->toBe(1);
    $retry = $batch['retries'][0];
    expect(strlen(wp_json_encode($retry['args'])) < 8000)->toBeTrue();
    expect($retry['args']['order_uuids'])->toBe(array_slice($jobs[0]['args']['order_uuids'], 1));
    do_action($retry['hook'], ...array_values($retry['args']));
    foreach (array_slice($jobs, 1) as $job) {
        do_action($job['hook'], ...array_values($job['args']));
    }
    expect(count($children()))->toBe(2);
    expect($batch['http_calls'])->toBe(0);
});

it('resumes a partially persisted dispatcher plan on provider retry', function (string $status) use (&$batch, $receive_batch): void {
    $uuids = $batch['uuids'];
    for ($index = 0; $index < 210; $index++) {
        $uuids[] = wp_generate_uuid4();
    }
    $batch['parent_failure'] = 'zero';
    $batch['parent_fail_at'] = 2;
    expect($receive_batch($uuids)->get_status())->toBe(503);
    expect(count($batch['enqueued']))->toBe(1);
    expect(get_transient($batch['replay_key']))->toBe(false);
    $first_id = $batch['enqueued'][0]['id'];
    if ('complete' === $status) {
        ActionScheduler::store()->mark_complete($first_id);
    } elseif ('failed' === $status) {
        ActionScheduler::store()->mark_failure($first_id);
    } elseif ('running' === $status) {
        ActionScheduler::store()->log_execution($first_id);
    } elseif ('canceled' === $status) {
        ActionScheduler::store()->cancel_action($first_id);
    }
    $batch['parent_failure'] = '';
    expect($receive_batch($uuids)->get_status())->toBe(204);
    expect(count($batch['enqueued']))->toBe(2);
    expect(array_merge(...array_column(array_column($batch['enqueued'], 'args'), 'order_uuids')))->toBe($uuids);
    expect($batch['http_calls'])->toBe(0);
})->with(array('pending', 'running', 'complete', 'failed', 'canceled'));

it('validates the entire large notification before saving any dispatcher chunk', function () use (&$batch, $receive_batch): void {
    $uuids = $batch['uuids'];
    for ($index = 0; $index < 210; $index++) {
        $uuids[] = wp_generate_uuid4();
    }
    expect($receive_batch($uuids, true)->get_status())->toBe(400);
    expect($batch['enqueued'])->toBe(array());
    expect(get_transient($batch['replay_key']))->toBe(false);
});

it('recognizes a dispatcher persisted before an enqueue exception', function () use (&$batch, $receive_batch): void {
    $batch['parent_failure'] = 'persist then throw';
    expect($receive_batch($batch['uuids'])->get_status())->toBe(204);
    expect(count($batch['enqueued']))->toBe(1);
    expect(get_transient($batch['replay_key']))->toBe('processed');
});

it('collapses duplicate-only notifications onto the existing single-order path', function () use (&$batch, $receive_batch): void {
    expect($receive_batch(array($batch['uuids'][0], $batch['uuids'][0]))->get_status())->toBe(204);
    expect(count($batch['enqueued']))->toBe(1);
    expect($batch['enqueued'][0]['hook'])->toBe('wooms_process_order_webhook');
    expect($batch['http_calls'])->toBe(0);
});

it('releases the notification when its dispatcher cannot be persisted', function (string $failure) use (&$batch, $receive_batch): void {
    $batch['parent_failure'] = $failure;
    expect($receive_batch($batch['uuids'])->get_status())->toBe(503);
    expect($batch['enqueued'])->toBe(array());
    expect(get_transient($batch['replay_key']))->toBe(false);
    $error = end($batch['errors']);
    expect($error['context']['phase'] ?? null)->toBe('enqueue');
    expect($error['context']['order_uuids'] ?? null)->toBe($batch['uuids']);
    $batch['parent_failure'] = '';
    expect($receive_batch($batch['uuids'])->get_status())->toBe(204);
    expect(count($batch['enqueued']))->toBe(1);
    expect($batch['http_calls'])->toBe(0);
})->with(array('zero', 'negative id', 'exception'));

it('rejects malformed event collections without a queue write', function ($events) use (&$batch): void {
    $request = new WP_REST_Request('POST', '/wooms/v1/order-update/');
    $request->set_query_params(array('key' => str_repeat('e', 48), 'requestId' => $batch['request_id']));
    $request->set_body(wp_json_encode(array('events' => $events)));
    expect(OrderUpdateFromMoySklad::authorize_webhook($request))->toBeTrue();
    expect(OrderUpdateFromMoySklad::get_data_order_from_moysklad($request)->get_status())->toBe(400);
    expect($batch['enqueued'])->toBe(array());
    expect(get_transient($batch['replay_key']))->toBe(false);
})->with(array(
    'empty events' => array(array()),
    'string events' => array('invalid'),
    'associative events' => array(array('unexpected' => array('meta' => array('href' => 'invalid')))),
    'scalar event' => array(array('invalid')),
));

it('retries only the unaccepted tail after a partial batch dispatch', function (string $failure, string $child_status) use (&$batch, $receive_batch, $children): void {
    expect($receive_batch($batch['uuids'])->get_status())->toBe(204);
    $job = $batch['enqueued'][0];
    $batch['failure'] = $failure;
    $exception = null;
    try {
        do_action($job['hook'], ...array_values($job['args']));
    } catch (RuntimeException $caught) {
        $exception = $caught;
    }
    expect($exception)->toBeInstanceOf(RuntimeException::class);
    expect(count($children()))->toBe(1);
    expect(count($batch['retries']))->toBe(1);
    $retry = $batch['retries'][0];
    expect($retry['args'])->toBe(array('request_id' => $batch['request_id'], 'order_uuids' => array($batch['uuids'][1]), 'attempt' => 1));
    if ('complete' === $child_status) {
        ActionScheduler::store()->mark_complete($children()[0]['id']);
    } else {
        ActionScheduler::store()->mark_failure($children()[0]['id']);
    }
    do_action($retry['hook'], ...array_values($retry['args']));
    expect(count($children()))->toBe(2);
    // Manual replay must recognize accepted children even after they leave the pending state.
    do_action($job['hook'], ...array_values($job['args']));
    expect(count($children()))->toBe(2);
    expect($batch['http_calls'])->toBe(0);
})->with(array(array('zero', 'complete'), array('exception', 'failed')));

it('recognizes a child persisted before an enqueue exception', function () use (&$batch, $receive_batch, $children): void {
    expect($receive_batch($batch['uuids'])->get_status())->toBe(204);
    $job = $batch['enqueued'][0];
    $batch['failure'] = 'persist then throw';
    do_action($job['hook'], ...array_values($job['args']));
    expect(count($children()))->toBe(2);
    expect($batch['retries'])->toBe(array());
    expect($batch['http_calls'])->toBe(0);
});

it('bounds dispatcher retries and reports exhaustion or retry-store failure', function (bool $retry_store_fails) use (&$batch, $receive_batch, $children): void {
    expect($receive_batch($batch['uuids'])->get_status())->toBe(204);
    $job = $batch['enqueued'][0];
    $batch['failure'] = 'always';
    $batch['retry_failure'] = $retry_store_fails;
    $exception = null;
    $attempts = $retry_store_fails ? 1 : 4;
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        if ($attempt > 0) {
            $job = $batch['retries'][$attempt - 1];
            expect($job['args']['attempt'])->toBe($attempt);
            expect($job['args']['order_uuids'])->toBe(array($batch['uuids'][1]));
        }
        $exception = null;
        try {
            do_action($job['hook'], ...array_values($job['args']));
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }
        expect($exception)->toBeInstanceOf(RuntimeException::class);
    }
    expect(count($children()))->toBe(1);
    expect(count($batch['retries']))->toBe($retry_store_fails ? 0 : 3);
    $error = end($batch['errors']);
    expect($error['context']['retry_error_code'] ?? null)->toBe($retry_store_fails ? 'wooms_webhook_retry_queue_failed' : 'wooms_webhook_retries_exhausted');
    expect($error['context']['order_uuids'] ?? null)->toBe(array($batch['uuids'][1]));
    expect($batch['http_calls'])->toBe(0);
})->with(array(false, true));
