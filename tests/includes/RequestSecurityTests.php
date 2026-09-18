<?php

declare(strict_types=1);

use WooMS\MoySkladApiUrl;
use WooMS\OrderUpdateFromMoySklad;

it('rejects unsafe request targets before the HTTP client is called', function (): void {
    $httpCalls = 0;
    $filter    = static function ($preempt, $args, $url) use (&$httpCalls) {
        $httpCalls++;

        return new WP_Error('unexpected_http_request', $url);
    };

    add_filter('pre_http_request', $filter, 10, 3);

    $unsafeTargets = array(
        'https://attacker.example/https://api.moysklad.ru/api/remap/1.2/entity/customerorder/1',
        'https://api.moysklad.ru.attacker.example/api/remap/1.2/entity/customerorder/1',
        'https://api.moysklad.ru@attacker.example/api/remap/1.2/entity/customerorder/1',
        'http://api.moysklad.ru/api/remap/1.2/entity/customerorder/1',
        'https://api.moysklad.ru:8443/api/remap/1.2/entity/customerorder/1',
        '//attacker.example/api/remap/1.2/entity/customerorder/1',
        'https://api.moysklad.ru\\@attacker.example/api/remap/1.2/entity/customerorder/1',
        'https://api.moysklad.ru/api/remap/1.2/../entity/customerorder/1',
        'https://api.moysklad.ru/api/remap/1.2/%252e%252e/entity/customerorder/1',
        'https://api.moysklad.ru/api/remap/1.2/entity/customerorder/1#@attacker.example',
    );

    try {
        foreach ($unsafeTargets as $target) {
            expect(WooMS\request($target))->toBeFalse();
        }
    } finally {
        remove_filter('pre_http_request', $filter, 10);
    }

    expect($httpCalls)->toBe(0);
});

it('uses a canonical API URL and secure transport options', function (): void {
    $capturedUrl  = '';
    $capturedArgs = array();
    $filter       = static function ($preempt, $args, $url) use (&$capturedUrl, &$capturedArgs) {
        $capturedUrl  = $url;
        $capturedArgs = $args;

        return array(
            'headers'  => array(),
            'body'     => '{"id":"response-id"}',
            'response' => array('code' => 200, 'message' => 'OK'),
            'cookies'  => array(),
            'filename' => null,
        );
    };

    add_filter('pre_http_request', $filter, 10, 3);

    try {
        $response = WooMS\request(
            'https://online.moysklad.ru/api/remap/1.2/entity/customerorder/123?expand=attributes'
        );
    } finally {
        remove_filter('pre_http_request', $filter, 10);
    }

    expect($response['id'] ?? null)->toBe('response-id');
    expect($capturedUrl)->toBe(
        'https://api.moysklad.ru/api/remap/1.2/entity/customerorder/123?expand=attributes'
    );
    expect($capturedArgs['redirection'] ?? null)->toBe(0);
    expect($capturedArgs['reject_unsafe_urls'] ?? null)->toBeTrue();
    expect($capturedArgs['sslverify'] ?? null)->toBeTrue();
    expect($capturedArgs['headers']['Authorization'] ?? '')
        ->toStartWith('Basic ')
        ->not->toBe('Basic ');
});

it('builds official API URLs from relative paths', function (): void {
    expect(MoySkladApiUrl::normalize('entity/product?limit=10'))->toBe(
        'https://api.moysklad.ru/api/remap/1.2/entity/product?limit=10'
    );
});

it('only accepts a customer order entity URL from webhook data', function (): void {
    $uuid = '7944ef04-f831-11e5-7a69-971100188b19';

    expect(MoySkladApiUrl::get_customer_order_path(
        'https://api.moysklad.ru/api/remap/1.2/entity/customerorder/'.$uuid
    ))->toBe('entity/customerorder/'.$uuid);

    expect(MoySkladApiUrl::get_customer_order_path(
        'https://online.moysklad.ru/api/remap/1.2/entity/customerorder/'.$uuid.'/'
    ))->toBe('entity/customerorder/'.$uuid);

    expect(MoySkladApiUrl::get_customer_order_path(
        'https://api.moysklad.ru/api/remap/1.2/entity/product/'.$uuid
    ))->toBeFalse();

    expect(MoySkladApiUrl::get_customer_order_path(
        'https://api.moysklad.ru/api/remap/1.2/entity/customerorder/'.$uuid.'?expand=agent'
    ))->toBeFalse();

    expect(MoySkladApiUrl::get_customer_order_path(
        'https://attacker.example/api/remap/1.2/entity/customerorder/'.$uuid
    ))->toBeFalse();
});

it('rejects an unsafe webhook without an API request or state change', function (): void {
    $missing               = new stdClass();
    $originalWebhookOption = get_option('wooms_enable_webhooks', $missing);
    $originalState         = get_option(OrderUpdateFromMoySklad::$state_key, $missing);
    $httpCalls             = 0;
    $filter                = static function ($preempt, $args, $url) use (&$httpCalls) {
        $httpCalls++;

        return new WP_Error('unexpected_http_request', $url);
    };

    update_option('wooms_enable_webhooks', 1);
    OrderUpdateFromMoySklad::set_state('is_wait', 1);
    add_filter('pre_http_request', $filter, 10, 3);

    $request = new WP_REST_Request('POST', '/wooms/v1/order-update/');
    $request->set_body(wp_json_encode(array(
        'events' => array(
            array(
                'meta' => array(
                    'href' => 'https://attacker.example/api/remap/1.2/entity/customerorder/'
                        .'c0a8012e-1234-4abc-8def-1234567890ab',
                ),
            ),
        ),
    )));

    try {
        $response = OrderUpdateFromMoySklad::get_data_order_from_moysklad($request);

        expect($response)->toBeInstanceOf(WP_REST_Response::class);
        expect($response->get_status())->toBe(400);
        expect($httpCalls)->toBe(0);
        expect(OrderUpdateFromMoySklad::get_state('is_wait'))->toBe(1);
    } finally {
        remove_filter('pre_http_request', $filter, 10);

        if ($missing === $originalWebhookOption) {
            delete_option('wooms_enable_webhooks');
        } else {
            update_option('wooms_enable_webhooks', $originalWebhookOption);
        }

        if ($missing === $originalState) {
            delete_option(OrderUpdateFromMoySklad::$state_key);
        } else {
            update_option(OrderUpdateFromMoySklad::$state_key, $originalState);
        }
    }
});

it('rejects an unauthenticated webhook before any provider request', function (): void {
    $missing = new stdClass();
    $original_webhook_option = get_option('wooms_enable_webhooks', $missing);
    $original_secret = get_option('wooms_webhook_secret', $missing);
    $http_calls = 0;
    $filter = static function ($preempt, $args, $url) use (&$http_calls) {
        $http_calls++;

        return new WP_Error('unexpected_http_request', $url);
    };

    update_option('wooms_enable_webhooks', 1);
    update_option('wooms_webhook_secret', str_repeat('a', 48), false);
    add_filter('pre_http_request', $filter, 10, 3);

    $request = new WP_REST_Request('POST', '/wooms/v1/order-update/');
    $request->set_query_params(array('requestId' => 'unsigned-request-12345678'));

    try {
        $result = OrderUpdateFromMoySklad::authorize_webhook($request);

        expect($result)->toBeInstanceOf(WP_Error::class);
        expect($result->get_error_data()['status'] ?? null)->toBe(401);
        expect($http_calls)->toBe(0);
    } finally {
        remove_filter('pre_http_request', $filter, 10);

        if ($missing === $original_webhook_option) {
            delete_option('wooms_enable_webhooks');
        } else {
            update_option('wooms_enable_webhooks', $original_webhook_option);
        }

        if ($missing === $original_secret) {
            delete_option('wooms_webhook_secret');
        } else {
            update_option('wooms_webhook_secret', $original_secret, false);
        }
    }
});

it('processes an authenticated webhook once and rejects its replay', function (): void {
    $missing = new stdClass();
    $original_webhook_option = get_option('wooms_enable_webhooks', $missing);
    $original_secret = get_option('wooms_webhook_secret', $missing);
    $secret = str_repeat('b', 48);
    $request_id = 'provider-request-12345678';
    $order_uuid = 'c0a8012e-1234-4abc-8def-1234567890ab';
    $api_calls = 0;
    $sync_calls = 0;
    $order_id = 0;
    $http_filter = static function ($preempt, $args, $url) use (&$api_calls, $order_uuid) {
        $api_calls++;

        return array(
            'headers'  => array(),
            'body'     => wp_json_encode(array('id' => $order_uuid)),
            'response' => array('code' => 200, 'message' => 'OK'),
            'cookies'  => array(),
            'filename' => null,
        );
    };
    $sync_action = static function () use (&$sync_calls): void {
        $sync_calls++;
    };

    update_option('wooms_enable_webhooks', 1);
    update_option('wooms_webhook_secret', $secret, false);
    delete_transient('wooms_webhook_replay_' . hash('sha256', $request_id));

    $order_id = wp_insert_post(array(
        'post_title'  => 'Webhook security test order',
        'post_status' => 'publish',
        'post_type'   => 'shop_order',
    ));
    update_post_meta($order_id, 'wooms_id', $order_uuid);
    delete_post_meta($order_id, 'lock_callback_update');

    add_filter('pre_http_request', $http_filter, 10, 3);
    add_action('wooms_order_update_from_moysklad_action', $sync_action, 10, 3);

    $request = new WP_REST_Request('POST', '/wooms/v1/order-update/');
    $request->set_query_params(array(
        'key'       => $secret,
        'requestId' => $request_id,
    ));
    $request->set_body(wp_json_encode(array(
        'events' => array(
            array(
                'meta' => array(
                    'href' => 'https://api.moysklad.ru/api/remap/1.2/entity/customerorder/' . $order_uuid,
                ),
                'action' => 'UPDATE',
            ),
        ),
    )));

    try {
        expect(OrderUpdateFromMoySklad::authorize_webhook($request))->toBeTrue();

        $response = OrderUpdateFromMoySklad::get_data_order_from_moysklad($request);
        $replay = OrderUpdateFromMoySklad::authorize_webhook($request);

        expect($response)->toBeInstanceOf(WP_REST_Response::class);
        expect($response->get_status())->toBe(200);
        expect($api_calls)->toBe(1);
        expect($sync_calls)->toBe(1);
        expect($replay)->toBeInstanceOf(WP_Error::class);
        expect($replay->get_error_data()['status'] ?? null)->toBe(409);
    } finally {
        remove_filter('pre_http_request', $http_filter, 10);
        remove_action('wooms_order_update_from_moysklad_action', $sync_action, 10);
        delete_transient('wooms_webhook_replay_' . hash('sha256', $request_id));
        delete_transient('wooms_webhook_rate_' . gmdate('YmdHi'));

        if ($order_id) {
            wp_delete_post($order_id, true);
        }

        if ($missing === $original_webhook_option) {
            delete_option('wooms_enable_webhooks');
        } else {
            update_option('wooms_enable_webhooks', $original_webhook_option);
        }

        if ($missing === $original_secret) {
            delete_option('wooms_webhook_secret');
        } else {
            update_option('wooms_webhook_secret', $original_secret, false);
        }
    }
});
