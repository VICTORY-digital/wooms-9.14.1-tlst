<?php

declare(strict_types=1);

use WooMS\OrderUpdateFromMoySklad;

$final = array();
beforeEach(function () use (&$final): void {
    $final = array('options' => array(), 'products' => array(), 'actions' => array(), 'queued' => array(), 'retries' => array(),
        'http' => 0, 'filters' => 0, 'saved' => 0, 'errors' => array(), 'failure' => '', 'retry_failure' => '', 'on_http' => null);
    $missing = new stdClass();
    $final['missing'] = $missing;
    $final['block_http'] = static function () { return new WP_Error('isolated_final_fixture', 'External HTTP is blocked during fixture setup and cleanup'); };
    $final['block_mail'] = static function () { return true; };
    add_filter('pre_http_request', $final['block_http'], PHP_INT_MAX);
    add_filter('pre_wp_mail', $final['block_mail']);
    foreach (array('wooms_enable_webhooks', 'wooms_order_shipment_item_code', 'wooms_order_statuses_from_moysklad', OrderUpdateFromMoySklad::$state_key) as $key) {
        $final['options'][$key] = get_option($key, $missing);
    }
    update_option('wooms_enable_webhooks', 1);
    update_option('wooms_order_statuses_from_moysklad', array('wc-completed' => 'Отгружен'));
    delete_option('wooms_order_shipment_item_code');
    $final['shipment_href'] = get_transient('wooms_shipment_href');
    delete_transient('wooms_shipment_href');
    OrderUpdateFromMoySklad::set_state('is_wait', 0);
    $order = wc_create_order();
    $final['order_id'] = $order->get_id();
    $final['uuid'] = wp_generate_uuid4();
    update_post_meta($order->get_id(), 'wooms_id', $final['uuid']);
    $final['marker'] = array('request_id' => wp_generate_uuid4(), 'order_uuid' => $final['uuid'], 'generation' => wp_generate_uuid4(), 'status' => 'pending');
    update_post_meta($order->get_id(), OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, $final['marker']);
    for ($index = 0; $index < 2; $index++) {
        $product = new WC_Product_Simple();
        $product->set_name('Final webhook fixture');
        $product->set_regular_price('10');
        $product->set_tax_status('none');
        $product_uuid = wp_generate_uuid4();
        $product->update_meta_data('wooms_id', $product_uuid);
        $product->save();
        $final['products'][] = $product;
        $final['product_uuids'][] = $product_uuid;
        $item = new WC_Order_Item_Product($order->add_product($product, 1));
        $item->update_meta_data('wooms_id', $product_uuid);
        $item->save();
    }
    $order->calculate_totals();
    delete_post_meta($order->get_id(), 'lock_callback_update');
    $final['data'] = array('id' => $final['uuid'], 'sum' => 3000, 'name' => 'Final fixture',
        'state' => array('meta' => array('href' => 'https://api.moysklad.ru/api/remap/1.2/entity/customerorder/metadata/states/' . wp_generate_uuid4())),
        'positions' => array('meta' => array('href' => 'https://api.moysklad.ru/api/remap/1.2/entity/customerorder/' . $final['uuid'] . '/positions')));
    $final['positions'] = array('rows' => array(array('assortment' => array('meta' => array('href' => 'https://api.moysklad.ru/api/remap/1.2/entity/product/' . $final['product_uuids'][0])), 'quantity' => 3, 'price' => 1000, 'discount' => 0)));
    $final['http_filter'] = static function ($preempt, $args, $url) use (&$final) {
        $final['http']++;
        if (is_callable($final['on_http'])) {
            ($final['on_http'])();
        }
        $is_state = false !== strpos($url, '/metadata/states/');
        $is_positions = false !== strpos($url, '/positions');
        if ('provider error' === $final['failure'] || ($is_state && 'state error' === $final['failure']) || ($is_positions && 'positions error' === $final['failure'])) {
            return new WP_Error('test_provider_failure', 'Test provider is unavailable');
        }
        $data = $is_state ? array('name' => 'Отгружен') : ($is_positions ? $final['positions'] : $final['data']);
        if ($is_positions && false !== strpos($url, 'offset=1') && isset($final['second_page'])) {
            $data = $final['second_page'];
        }
        return array('headers' => array(), 'body' => wp_json_encode($data), 'response' => array('code' => 200, 'message' => 'OK'), 'cookies' => array(), 'filename' => null);
    };
    $persist = static function ($hook, $args, $group) use (&$final) {
        $id = as_schedule_single_action(time() + 3600, $hook, $args, $group);
        if (!is_int($id) || $id <= 0) {
            throw new RuntimeException('Could not persist isolated final update action');
        }
        $final['actions'][] = $id;
        return $id;
    };
    $final['queue_filter'] = static function ($preempt, $hook, $args, $group) use (&$final, $persist) {
        if (OrderUpdateFromMoySklad::$hook_order_update_from_moysklad !== $hook) {
            return $preempt;
        }
        $id = $persist($hook, $args, $group);
        $final['queued'][] = array('id' => $id, 'args' => $args);
        return $id;
    };
    $final['retry_filter'] = static function ($preempt, $timestamp, $hook, $args, $group) use (&$final, $persist) {
        if (OrderUpdateFromMoySklad::$hook_order_update_from_moysklad !== $hook || $timestamp >= time() + 600) {
            return $preempt;
        }
        if ('throw' === $final['retry_failure']) {
            throw new RuntimeException('Test retry storage failure');
        }
        if ('zero' === $final['retry_failure']) {
            return 0;
        }
        $id = $persist($hook, $args, $group);
        $final['retries'][] = array('id' => $id, 'args' => $args);
        return $id;
    };
    $final['filter'] = static function ($order, $data) use (&$final) {
        $final['filters']++;
        return $order;
    };
    $final['after'] = static function () use (&$final): void { $final['saved']++; };
    $final['logger'] = static function ($source, $title, $context = array()) use (&$final): void {
        if (OrderUpdateFromMoySklad::class === $source) {
            $final['errors'][] = compact('title', 'context');
        }
    };
    remove_filter('pre_http_request', $final['block_http'], PHP_INT_MAX);
    add_filter('pre_http_request', $final['http_filter'], 10, 3);
    add_filter('pre_as_enqueue_async_action', $final['queue_filter'], 10, 4);
    add_filter('pre_as_schedule_single_action', $final['retry_filter'], 10, 5);
    add_filter('wooms_update_order_from_moysklad', $final['filter'], 1, 2);
    add_action('wooms_update_order_from_moysklad_after_save', $final['after']);
    add_action('wooms_logger_error', $final['logger'], 10, 3);
});

afterEach(function () use (&$final): void {
    remove_filter('pre_http_request', $final['http_filter'], 10);
    add_filter('pre_http_request', $final['block_http'], PHP_INT_MAX);
    remove_filter('pre_as_enqueue_async_action', $final['queue_filter'], 10);
    remove_filter('pre_as_schedule_single_action', $final['retry_filter'], 10);
    remove_filter('wooms_update_order_from_moysklad', $final['filter'], 1);
    remove_action('wooms_update_order_from_moysklad_after_save', $final['after']);
    remove_action('wooms_logger_error', $final['logger'], 10);
    foreach ($final['actions'] as $id) {
        ActionScheduler::store()->delete_action($id);
    }
    if ($order = wc_get_order($final['order_id'])) { $order->delete(true); }
    foreach ($final['products'] as $product) { $product->delete(true); }
    if (false === $final['shipment_href']) { delete_transient('wooms_shipment_href'); }
    else { set_transient('wooms_shipment_href', $final['shipment_href'], MINUTE_IN_SECONDS); }
    foreach ($final['options'] as $key => $value) {
        if ($final['missing'] === $value) { delete_option($key); } else { update_option($key, $value, false); }
    }
    remove_filter('pre_http_request', $final['block_http'], PHP_INT_MAX);
    remove_filter('pre_wp_mail', $final['block_mail']);
});

$run_final = static function ($args = null) use (&$final): void {
    $args = $args ?? array('post_id' => $final['order_id'], 'request_id' => $final['marker']['request_id'], 'order_uuid' => $final['uuid'], 'generation' => $final['marker']['generation'], 'attempt' => 0);
    do_action(OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, $args);
};
$unchanged_order = static function () use (&$final): void {
    $order = wc_get_order($final['order_id']);
    expect($order->get_status())->toBe('pending');
    expect(count($order->get_items()))->toBe(2);
    foreach ($order->get_items() as $item) { expect((float) $item->get_quantity())->toBe(1.0); }
    expect($final['saved'])->toBe(0);
};

it('updates and acknowledges a final generation only once', function () use (&$final, $run_final): void {
    OrderUpdateFromMoySklad::batch_handler();
    OrderUpdateFromMoySklad::batch_handler();
    expect(count($final['queued']))->toBe(1);
    $run_final($final['queued'][0]['args']['data']);
    $order = wc_get_order($final['order_id']);
    expect($order->get_status())->toBe('completed');
    expect(count($order->get_items()))->toBe(1);
    expect((float) $order->get_total())->toBe(30.0);
    expect(get_post_meta($final['order_id'], OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true))->toBe('');
    expect($final['saved'])->toBe(1);
    $before = $final['http'];
    $run_final($final['queued'][0]['args']['data']);
    expect($final['http'])->toBe($before);
});

it('retains final work and avoids writes when any required provider data is invalid', function (string $failure) use (&$final, $run_final, $unchanged_order): void {
    $final['failure'] = $failure;
    if ('wrong id' === $failure) { $final['data']['id'] = wp_generate_uuid4(); }
    if ('missing sum' === $failure) { unset($final['data']['sum']); }
    if ('invalid rows' === $failure) { $final['positions'] = array('rows' => null); }
    if (in_array($failure, array('invalid late row', 'unknown product'), true)) {
        $row = $final['positions']['rows'][0];
        $row['quantity'] = 'invalid late row' === $failure ? 'invalid' : 1;
        $row['assortment']['meta']['href'] = 'https://api.moysklad.ru/api/remap/1.2/entity/product/' . wp_generate_uuid4();
        $final['positions']['rows'][] = $row;
    }
    $exception = null;
    try { $run_final(); } catch (Throwable $caught) { $exception = $caught; }
    expect($exception)->toBeInstanceOf(RuntimeException::class);
    $unchanged_order();
    expect($final['filters'])->toBe(0);
    expect(get_post_meta($final['order_id'], OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true))->toBe($final['marker']);
    expect(count($final['retries']))->toBe(1);
    expect($final['retries'][0]['args']['data']['attempt'])->toBe(1);
})->with(array('provider error', 'wrong id', 'missing sum', 'state error', 'positions error', 'invalid rows', 'invalid late row', 'unknown product'));

it('bounds final retries and parks failed work without scanner restarts', function (string $retry_failure) use (&$final, $run_final, $unchanged_order): void {
    $final['failure'] = 'provider error';
    $final['retry_failure'] = $retry_failure;
    OrderUpdateFromMoySklad::batch_handler();
    $args = $final['queued'][0]['args']['data'];
    $attempts = '' === $retry_failure ? 4 : 1;
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        if ($attempt > 0) { $args = $final['retries'][$attempt - 1]['args']['data']; }
        $error = null;
        try { $run_final($args); } catch (RuntimeException $caught) { $error = $caught; }
        expect($error)->toBeInstanceOf(RuntimeException::class);
    }
    $marker = get_post_meta($final['order_id'], OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true);
    expect($marker['status'] ?? null)->toBe('failed');
    expect(count($final['retries']))->toBe('' === $retry_failure ? 3 : 0);
    $unchanged_order();
    OrderUpdateFromMoySklad::batch_handler();
    expect(count($final['queued']))->toBe(1);
    expect(OrderUpdateFromMoySklad::get_state('is_wait'))->toBe(1);
    expect(OrderUpdateFromMoySklad::skip_order_update_from_site(false, wc_get_order($final['order_id'])))->toBeFalse();
    $final['failure'] = '';
    $run_final($args); // Manual retry of the failed action can recover the same generation.
    expect(get_post_meta($final['order_id'], OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true))->toBe('');
})->with(array('', 'zero', 'throw'));

it('does not apply or clear a newer generation discovered during provider reads', function () use (&$final, $run_final, $unchanged_order): void {
    $next = $final['marker'];
    $next['request_id'] = wp_generate_uuid4();
    $next['generation'] = wp_generate_uuid4();
    $final['on_http'] = static function () use (&$final, $next): void {
        $final['on_http'] = null;
        update_post_meta($final['order_id'], OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, $next);
    };
    $run_final();
    $unchanged_order();
    expect(get_post_meta($final['order_id'], OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true))->toBe($next);
    expect($final['retries'])->toBe(array());
});

it('adopts scalar legacy work without executing stale legacy callbacks against a new marker', function () use (&$final, $run_final): void {
    update_post_meta($final['order_id'], OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, 1);
    $run_final(array('post_id' => $final['order_id'], 'time' => time()));
    expect($final['http'])->toBe(0);
    expect(count($final['queued']))->toBe(1);
    $run_final(array('post_id' => $final['order_id'], 'time' => time()));
    expect($final['http'])->toBe(0);
    expect(count($final['queued']))->toBe(1);
    $run_final($final['queued'][0]['args']['data']);
    expect($final['saved'])->toBe(1);
});

it('preserves valid empty positions and orders without a state', function () use (&$final, $run_final): void {
    unset($final['data']['state']);
    $final['data']['sum'] = 0;
    $final['positions'] = array('rows' => array(), 'meta' => array('size' => 0));
    $run_final();
    $order = wc_get_order($final['order_id']);
    expect(count($order->get_items()))->toBe(0);
    expect($order->get_status())->toBe('pending');
    expect((float) $order->get_total())->toBe(0.0);
});

it('reads every page before applying positions and rejects inconsistent pagination', function (bool $bad_page) use (&$final, $run_final, $unchanged_order): void {
    $final['data']['sum'] = 5000;
    $final['positions']['meta'] = array('size' => 2, 'offset' => 0, 'nextHref' => $final['data']['positions']['meta']['href'] . '?offset=1');
    $row = $final['positions']['rows'][0];
    $row['quantity'] = $bad_page ? 'invalid' : 2;
    $row['assortment']['meta']['href'] = 'https://api.moysklad.ru/api/remap/1.2/entity/product/' . $final['product_uuids'][1];
    $final['second_page'] = array('rows' => array($row), 'meta' => array('size' => 2, 'offset' => 1));
    $exception = null;
    try { $run_final(); } catch (RuntimeException $caught) { $exception = $caught; }
    if ($bad_page) {
        expect($exception)->toBeInstanceOf(RuntimeException::class);
        $unchanged_order();
        expect($final['filters'])->toBe(0);
    } else {
        expect($exception)->toBeNull();
        expect(count(wc_get_order($final['order_id'])->get_items()))->toBe(2);
        expect((float) wc_get_order($final['order_id'])->get_total())->toBe(50.0);
    }
})->with(array(false, true));

it('does not clear a generation queued by an after-save listener', function () use (&$final, $run_final): void {
    $next = $final['marker'];
    $next['generation'] = wp_generate_uuid4();
    $listener = static function () use (&$final, $next): void {
        update_post_meta($final['order_id'], OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, $next);
    };
    add_action('wooms_update_order_from_moysklad_after_save', $listener, 20);
    try { $run_final(); } finally { remove_action('wooms_update_order_from_moysklad_after_save', $listener, 20); }
    expect(get_post_meta($final['order_id'], OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true))->toBe($next);
    expect($final['saved'])->toBe(1);
});

it('retains final work after a save exception and recovers on the persisted retry', function () use (&$final, $run_final): void {
    $listener = static function ($order) use (&$final): void {
        if ($order instanceof WC_Order && $order->get_id() === $final['order_id']) {
            throw new RuntimeException('Test order save failure');
        }
    };
    add_action('woocommerce_before_order_object_save', $listener);
    $exception = null;
    try { $run_final(); } catch (RuntimeException $caught) { $exception = $caught; }
    finally { remove_action('woocommerce_before_order_object_save', $listener); }
    expect($exception)->toBeInstanceOf(RuntimeException::class);
    expect(get_post_meta($final['order_id'], OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true))->toBe($final['marker']);
    expect(count($final['retries']))->toBe(1);
    $run_final($final['retries'][0]['args']['data']);
    expect((float) wc_get_order($final['order_id'])->get_total())->toBe(30.0);
    expect(get_post_meta($final['order_id'], OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true))->toBe('');
});

it('retains the generation when an existing line cannot be saved', function () use (&$final, $run_final, $unchanged_order): void {
    $listener = static function ($item) use (&$final): void {
        if ($item instanceof WC_Order_Item_Product && $item->get_order_id() === $final['order_id'] && $item->get_id()) {
            throw new RuntimeException('Test order item save failure');
        }
    };
    add_action('woocommerce_before_order_item_object_save', $listener);
    $exception = null;
    try { $run_final(); } catch (RuntimeException $caught) { $exception = $caught; }
    finally { remove_action('woocommerce_before_order_item_object_save', $listener); }
    expect($exception)->toBeInstanceOf(RuntimeException::class);
    $unchanged_order();
    expect(get_post_meta($final['order_id'], OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true))->toBe($final['marker']);
    expect(count($final['retries']))->toBe(1);
    $run_final($final['retries'][0]['args']['data']);
    expect((float) wc_get_order($final['order_id'])->get_total())->toBe(30.0);
});

it('creates fresh work for a new webhook without letting an older preparation retry replace it', function () use (&$final): void {
    $failed = $final['marker'];
    $failed['status'] = 'failed';
    update_post_meta($final['order_id'], OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, $failed);
    $request_id = wp_generate_uuid4();
    OrderUpdateFromMoySklad::process_order_webhook($final['order_id'], $final['uuid'], $request_id);
    $fresh = get_post_meta($final['order_id'], OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true);
    expect($fresh['request_id'] ?? null)->toBe($request_id);
    expect($fresh['status'] ?? null)->toBe('pending');
    expect($fresh['generation'] === $failed['generation'])->toBeFalse();
    $before = $final['http'];
    OrderUpdateFromMoySklad::process_order_webhook($final['order_id'], $final['uuid'], $failed['request_id'], 1);
    expect($final['http'])->toBe($before);
    expect(get_post_meta($final['order_id'], OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true))->toBe($fresh);
});

it('scans past terminal failures instead of stranding later pending orders', function () use (&$final): void {
    $failed = $final['marker'];
    $failed['status'] = 'failed';
    update_post_meta($final['order_id'], OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, $failed);
    $ids = array();
    try {
        for ($index = 0; $index < 11; $index++) {
            $id = wp_insert_post(array('post_type' => 'shop_order', 'post_status' => 'publish',
                'post_date' => gmdate('Y-m-d H:i:s', time() - 1000 + $index * 10)));
            $ids[] = $id;
            $marker = $failed;
            $marker['generation'] = wp_generate_uuid4();
            if (5 === $index) { $marker['status'] = 'pending'; }
            update_post_meta($id, OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, $marker);
        }
        OrderUpdateFromMoySklad::batch_handler();
        expect(count($final['queued']))->toBe(1);
        expect($final['queued'][0]['args']['data']['post_id'])->toBe($ids[5]);
        expect(OrderUpdateFromMoySklad::is_wait())->toBeFalse();
    } finally {
        foreach ($ids as $id) { wp_delete_post($id, true); }
    }
});

it('persists new final work even when a stale scanner subsequently enters wait mode', function () use (&$final, $run_final): void {
    $triggered = false;
    $request_id = wp_generate_uuid4();
    $filter = static function ($posts, $query) use (&$final, &$triggered, $request_id) {
        if (!$triggered && 'shop_order' === $query->get('post_type')) {
            $triggered = true;
            OrderUpdateFromMoySklad::process_order_webhook($final['order_id'], $final['uuid'], $request_id);
            return array(); // The scanner's snapshot predates that notification.
        }
        return $posts;
    };
    add_filter('posts_pre_query', $filter, 10, 2);
    try { OrderUpdateFromMoySklad::batch_handler(); }
    finally { remove_filter('posts_pre_query', $filter, 10); }
    expect($triggered)->toBeTrue();
    expect(OrderUpdateFromMoySklad::get_state('is_wait'))->toBe(1);
    expect(count($final['queued']))->toBe(1);
    expect($final['queued'][0]['args']['data']['request_id'])->toBe($request_id);
    $run_final($final['queued'][0]['args']['data']);
    expect(get_post_meta($final['order_id'], OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true))->toBe('');
});
