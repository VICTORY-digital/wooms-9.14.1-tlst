<?php

declare(strict_types=1);

use WooMS\OrderUpdateFromMoySklad;
use WooMS\OrderWebhookLock;
use WooMS\Orders;

$storage = array();

beforeEach(function () use (&$storage): void {
    $storage = array(
        'actions' => array(),
        'queued' => array(),
        'options' => array(),
        'request_ids' => array(),
        'http_calls' => 0,
        'on_http' => null,
        'provider_sum' => 0,
        'order_id' => 0,
    );
    $storage['missing'] = new stdClass();
    foreach (array('wooms_enable_webhooks', 'wooms_webhook_secret', 'wooms_order_shipment_item_code', 'wooms_orders_sender_enable', OrderUpdateFromMoySklad::$state_key) as $key) {
        $storage['options'][$key] = get_option($key, $storage['missing']);
    }
    $storage['rate_key'] = 'wooms_webhook_rate_' . gmdate('YmdHi');
    $storage['rate_value'] = get_transient($storage['rate_key']);
    $storage['secret'] = str_repeat('h', 48);
    $storage['uuid'] = wp_generate_uuid4();

    update_option('wooms_enable_webhooks', 1);
    update_option('wooms_webhook_secret', $storage['secret'], false);
    delete_option('wooms_order_shipment_item_code');
    update_option('wooms_orders_sender_enable', 0);
    delete_transient($storage['rate_key']);

    $storage['http_filter'] = static function ($preempt, $args, $url) use (&$storage) {
        $storage['http_calls']++;
        if (is_callable($storage['on_http'])) {
            ($storage['on_http'])();
        }
        $path = wp_parse_url($url, PHP_URL_PATH);
        $order_suffix = '/customerorder/' . $storage['uuid'];
        if (is_string($path) && '/positions' === substr($path, -10)) {
            $data = array('rows' => array(), 'meta' => array('size' => 0));
        } elseif (is_string($path) && $order_suffix === substr($path, -strlen($order_suffix))) {
            $data = array(
                'id' => $storage['uuid'],
                'sum' => $storage['provider_sum'],
                'positions' => array('meta' => array('href' => 'https://api.moysklad.ru/api/remap/1.2/entity/customerorder/' . $storage['uuid'] . '/positions')),
            );
        } else {
            return new WP_Error('unexpected_storage_http', 'External HTTP is blocked in the storage regression fixture: ' . $url);
        }

        return array(
            'headers' => array(),
            'body' => wp_json_encode($data),
            'response' => array('code' => 200, 'message' => 'OK'),
            'cookies' => array(),
            'filename' => null,
        );
    };
    $storage['queue_filter'] = static function ($preempt, $hook, $args, $group) use (&$storage) {
        if (!in_array($hook, array(
            OrderUpdateFromMoySklad::$hook_process_webhook,
            OrderUpdateFromMoySklad::$hook_order_update_from_moysklad,
        ), true)) {
            return $preempt;
        }
        $action_id = as_schedule_single_action(time() + 3600, $hook, $args, $group);
        if (!is_int($action_id) || $action_id <= 0) {
            throw new RuntimeException('Could not persist storage regression action');
        }
        $storage['actions'][] = $action_id;
        $storage['queued'][] = compact('hook', 'args', 'group');
        return $action_id;
    };
    $storage['mail_filter'] = static function () { return true; };
    add_filter('pre_http_request', $storage['http_filter'], 10, 3);
    add_filter('pre_as_enqueue_async_action', $storage['queue_filter'], 10, 4);
    add_filter('pre_wp_mail', $storage['mail_filter']);

    rest_get_server();
    OrderUpdateFromMoySklad::add_route();

    $order = wc_create_order();
    $order->read_meta_data(true);
    $order->update_meta_data('wooms_id', $storage['uuid']);
    $order->delete_meta_data('lock_callback_update');
    $order->save_meta_data();
    $storage['order_id'] = $order->get_id();
});

afterEach(function () use (&$storage): void {
    foreach ($storage['actions'] as $action_id) {
        ActionScheduler::store()->delete_action($action_id);
    }
    foreach ($storage['request_ids'] as $request_id) {
        delete_transient('wooms_webhook_replay_' . hash('sha256', $request_id));
    }
    if (false === $storage['rate_value']) {
        delete_transient($storage['rate_key']);
    } else {
        set_transient($storage['rate_key'], $storage['rate_value'], 2 * MINUTE_IN_SECONDS);
    }
    if ($order = wc_get_order($storage['order_id'])) {
        $order->delete(true);
    }
    foreach ($storage['options'] as $key => $value) {
        if ($storage['missing'] === $value) {
            delete_option($key);
        } else {
            update_option($key, $value, false);
        }
    }
    remove_filter('pre_http_request', $storage['http_filter'], 10);
    remove_filter('pre_as_enqueue_async_action', $storage['queue_filter'], 10);
    remove_filter('pre_wp_mail', $storage['mail_filter']);
});

$receive_storage_webhook = static function () use (&$storage) {
    $request_id = wp_generate_uuid4();
    $storage['request_ids'][] = $request_id;
    $request = new WP_REST_Request('POST', '/wooms/v1/order-update');
    $request->set_query_params(array('key' => $storage['secret'], 'requestId' => $request_id));
    $request->set_body(wp_json_encode(array('events' => array(array(
        'meta' => array('href' => 'https://api.moysklad.ru/api/remap/1.2/entity/customerorder/' . $storage['uuid']),
        'action' => 'UPDATE',
    )))));
    return rest_do_request($request);
};

$write_storage_marker = static function (array $marker) use (&$storage): void {
    $order = wc_get_order($storage['order_id']);
    $order->read_meta_data(true);
    $order->update_meta_data(OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, $marker);
    $order->save_meta_data();
};

it('accepts a linked CRUD order through the authenticated REST webhook', function () use (&$storage, $receive_storage_webhook): void {
    $response = $receive_storage_webhook();

    expect($response->get_status())->toBe(204);
    expect($storage['http_calls'])->toBe(0);
    expect($storage['queued'])->toBe(array(array(
        'hook' => OrderUpdateFromMoySklad::$hook_process_webhook,
        'args' => array(
            'order_id' => $storage['order_id'],
            'order_uuid' => $storage['uuid'],
            'request_id' => $storage['request_ids'][0],
        ),
        'group' => 'WooMS',
    )));
});

it('consumes a callback echo lock saved through WooCommerce CRUD', function () use (&$storage, $receive_storage_webhook): void {
    $order = wc_get_order($storage['order_id']);
    $order->read_meta_data(true);
    $order->set_customer_note('Changed on the website');
    $order->save();

    $response = $receive_storage_webhook();
    $fresh = wc_get_order($storage['order_id']);
    $fresh->read_meta_data(true);

    expect($response->get_status())->toBe(200);
    expect($storage['queued'])->toBe(array());
    expect($storage['http_calls'])->toBe(0);
    expect($fresh->get_meta('lock_callback_update', true))->toBe('');
});

it('refreshes a cached order before deciding whether outbound sync is blocked', function () use (&$storage, $write_storage_marker): void {
    $cached = wc_get_order($storage['order_id']);
    $pending = array(
        'request_id' => wp_generate_uuid4(),
        'order_uuid' => $storage['uuid'],
        'generation' => wp_generate_uuid4(),
        'status' => 'pending',
    );
    $write_storage_marker($pending);

    expect(OrderUpdateFromMoySklad::skip_order_update_from_site(false, $cached))->toBeTrue();

    $failed = $pending;
    $failed['status'] = 'failed';
    $write_storage_marker($failed);

    expect(OrderUpdateFromMoySklad::skip_order_update_from_site(false, $cached))->toBeFalse();
});

it('finds a pending CRUD order in the repair scanner', function () use (&$storage, $write_storage_marker): void {
    $marker = array(
        'request_id' => wp_generate_uuid4(),
        'order_uuid' => $storage['uuid'],
        'generation' => wp_generate_uuid4(),
        'status' => 'pending',
    );
    $write_storage_marker($marker);

    OrderUpdateFromMoySklad::batch_handler();

    expect($storage['queued'])->toBe(array(array(
        'hook' => OrderUpdateFromMoySklad::$hook_order_update_from_moysklad,
        'args' => array('data' => array(
            'post_id' => $storage['order_id'],
            'request_id' => $marker['request_id'],
            'order_uuid' => $storage['uuid'],
            'generation' => $marker['generation'],
            'attempt' => 0,
        )),
        'group' => 'WooMS',
    )));
});

it('does not clear a newer CRUD generation created after the final save', function () use (&$storage, $write_storage_marker): void {
    $marker = array(
        'request_id' => wp_generate_uuid4(),
        'order_uuid' => $storage['uuid'],
        'generation' => wp_generate_uuid4(),
        'status' => 'pending',
    );
    $next = $marker;
    $next['request_id'] = wp_generate_uuid4();
    $next['generation'] = wp_generate_uuid4();
    $write_storage_marker($marker);
    $listener = static function () use (&$storage, $next): void {
        $order = wc_get_order($storage['order_id']);
        $order->read_meta_data(true);
        $order->update_meta_data(OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, $next);
        $order->save_meta_data();
    };
    add_action('wooms_update_order_from_moysklad_after_save', $listener, 20);

    try {
        do_action(OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, array(
            'post_id' => $storage['order_id'],
            'request_id' => $marker['request_id'],
            'order_uuid' => $storage['uuid'],
            'generation' => $marker['generation'],
            'attempt' => 0,
        ));
    } finally {
        remove_action('wooms_update_order_from_moysklad_after_save', $listener, 20);
    }

    $fresh = wc_get_order($storage['order_id']);
    $fresh->read_meta_data(true);
    expect($storage['http_calls'])->toBe(2);
    expect($fresh->get_meta(OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true))->toBe($next);
});

it('does not hold the order state lease during provider reads', function () use (&$storage, $write_storage_marker): void {
    $marker = array(
        'request_id' => wp_generate_uuid4(),
        'order_uuid' => $storage['uuid'],
        'generation' => wp_generate_uuid4(),
        'status' => 'pending',
    );
    $write_storage_marker($marker);
    $lease_checks = array();
    $storage['on_http'] = static function () use (&$storage, &$lease_checks): void {
        $lease = OrderWebhookLock::acquire('order-state:' . $storage['order_id']);
        $lease_checks[] = !is_wp_error($lease);
        if (!is_wp_error($lease)) {
            $lease_checks[] = $lease->release();
        }
    };

    do_action(OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, array(
        'post_id' => $storage['order_id'],
        'request_id' => $marker['request_id'],
        'order_uuid' => $storage['uuid'],
        'generation' => $marker['generation'],
        'attempt' => 0,
    ));

    $fresh = wc_get_order($storage['order_id']);
    $fresh->read_meta_data(true);
    expect($lease_checks)->toBe(array(true, true, true, true));
    expect($fresh->get_meta(OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true))->toBe('');
});

it('consumes a website save during preparation without blocking the next webhook', function () use (&$storage): void {
    $storage['on_http'] = static function () use (&$storage): void {
        $storage['on_http'] = null;
        $order = wc_get_order($storage['order_id']);
        $order->set_customer_note('Concurrent website change');
        $order->save();
    };

    OrderUpdateFromMoySklad::process_order_webhook($storage['order_id'], $storage['uuid'], wp_generate_uuid4());
    $fresh = wc_get_order($storage['order_id']);
    $fresh->read_meta_data(true);
    expect($storage['http_calls'])->toBe(1);
    expect($storage['queued'])->toBe(array());
    expect($fresh->get_meta('lock_callback_update', true))->toBe('');
    expect($fresh->get_meta(OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true))->toBe('');

    $request_id = wp_generate_uuid4();
    OrderUpdateFromMoySklad::process_order_webhook($storage['order_id'], $storage['uuid'], $request_id);
    expect($storage['http_calls'])->toBe(2);
    expect(count($storage['queued']))->toBe(1);
    expect($storage['queued'][0]['args']['data']['request_id'])->toBe($request_id);
});

it('adopts a scalar CRUD marker without a full order save or provider request', function () use (&$storage): void {
    $order = wc_get_order($storage['order_id']);
    $order->read_meta_data(true);
    $order->update_meta_data(OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, 1);
    $order->save_meta_data();
    $order->set_date_modified(time() - MINUTE_IN_SECONDS);
    $order->save();
    $full_saves = 0;
    $save_listener = static function ($candidate) use (&$storage, &$full_saves): void {
        if ($candidate instanceof WC_Order && $candidate->get_id() === $storage['order_id']) {
            $full_saves++;
        }
    };
    add_action('woocommerce_before_order_object_save', $save_listener);

    try {
        OrderUpdateFromMoySklad::batch_handler();
    } finally {
        remove_action('woocommerce_before_order_object_save', $save_listener);
    }

    expect($full_saves)->toBe(0);
    expect($storage['http_calls'])->toBe(0);
    expect(count($storage['queued']))->toBe(1);
    $args = $storage['queued'][0]['args']['data'];
    expect($args['request_id'])->toStartWith('legacy-');
    expect($args['order_uuid'])->toBe($storage['uuid']);

    do_action(OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, $args);

    $fresh = wc_get_order($storage['order_id']);
    $fresh->read_meta_data(true);
    expect($storage['http_calls'])->toBe(2);
    expect($fresh->get_meta(OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true))->toBe('');
});

it('parks an exhausted invalid final update and allows a manual recovery', function () use (&$storage, $write_storage_marker): void {
    $marker = array(
        'request_id' => wp_generate_uuid4(),
        'order_uuid' => $storage['uuid'],
        'generation' => wp_generate_uuid4(),
        'status' => 'pending',
    );
    $write_storage_marker($marker);
    $args = array(
        'post_id' => $storage['order_id'],
        'request_id' => $marker['request_id'],
        'order_uuid' => $storage['uuid'],
        'generation' => $marker['generation'],
        'attempt' => 3,
    );
    $storage['provider_sum'] = 'invalid';
    $failure = null;
    try {
        do_action(OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, $args);
    } catch (RuntimeException $exception) {
        $failure = $exception;
    }

    $failed = wc_get_order($storage['order_id']);
    $failed->read_meta_data(true);
    expect($failure)->toBeInstanceOf(RuntimeException::class);
    expect($failed->get_meta(OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true)['status'] ?? null)->toBe('failed');
    expect(OrderUpdateFromMoySklad::skip_order_update_from_site(false, $failed))->toBeFalse();
    expect($storage['actions'])->toBe(array());

    $storage['provider_sum'] = 0;
    do_action(OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, $args);

    $recovered = wc_get_order($storage['order_id']);
    $recovered->read_meta_data(true);
    expect($recovered->get_meta(OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true))->toBe('');
});

it('preserves unsaved custom metadata while checking a fresh pending marker', function () use (&$storage, $write_storage_marker): void {
    $cached = wc_get_order($storage['order_id']);
    $cached->update_meta_data('storage_unsaved_meta', 'keep-me');
    $write_storage_marker(array(
        'request_id' => wp_generate_uuid4(),
        'order_uuid' => $storage['uuid'],
        'generation' => wp_generate_uuid4(),
        'status' => 'pending',
    ));

    expect(OrderUpdateFromMoySklad::skip_order_update_from_site(false, $cached))->toBeTrue();
    expect($cached->get_meta('storage_unsaved_meta', true))->toBe('keep-me');
});

it('clears outbound sync metadata without saving or requesting a pending order', function () use (&$storage): void {
    $order = wc_get_order($storage['order_id']);
    $item = new WC_Order_Item_Product();
    $item->set_name('Storage regression item');
    $item->set_quantity(1);
    $order->add_item($item);
    $order->save();
    $order->read_meta_data(true);
    $order->delete_meta_data('lock_callback_update');
    $order->update_meta_data('wooms_order_sync', 1);
    $order->update_meta_data(OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, array(
        'request_id' => wp_generate_uuid4(),
        'order_uuid' => $storage['uuid'],
        'generation' => wp_generate_uuid4(),
        'status' => 'pending',
    ));
    $order->save_meta_data();
    $order->set_date_modified(time() - MINUTE_IN_SECONDS);
    $order->save();
    $order->update_meta_data('storage_unsaved_outbound', 'keep-staged');
    $full_saves = 0;
    $save_listener = static function ($candidate) use (&$storage, &$full_saves): void {
        if ($candidate instanceof WC_Order && $candidate->get_id() === $storage['order_id']) {
            $full_saves++;
        }
    };
    add_action('woocommerce_before_order_object_save', $save_listener);

    try {
        expect(Orders::order_update_to_moysklad($storage['order_id'], $order))->toBeFalse();
    } finally {
        remove_action('woocommerce_before_order_object_save', $save_listener);
    }

    $fresh = wc_get_order($storage['order_id']);
    $fresh->read_meta_data(true);
    expect($full_saves)->toBe(0);
    expect($storage['http_calls'])->toBe(0);
    expect($fresh->get_meta('wooms_order_sync', true))->toBe('');
    expect($fresh->get_meta(OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true)['status'] ?? null)->toBe('pending');
    expect($fresh->get_meta('storage_unsaved_outbound', true))->toBe('');
    expect($order->get_meta('storage_unsaved_outbound', true))->toBe('keep-staged');
});
