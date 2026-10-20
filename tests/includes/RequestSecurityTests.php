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

it('rejects an unauthenticated retry before any provider request', function (): void {
    $missing = new stdClass();
    $original_webhook_option = get_option('wooms_enable_webhooks', $missing);
    $original_secret = get_option('wooms_webhook_secret', $missing);
    $replay_key = 'wooms_webhook_replay_' . hash('sha256', 'unsigned-request-12345678');
    $original_replay = get_transient($replay_key);
    $http_calls = 0;
    $filter = static function ($preempt, $args, $url) use (&$http_calls) {
        $http_calls++;

        return new WP_Error('unexpected_http_request', $url);
    };

    update_option('wooms_enable_webhooks', 1);
    update_option('wooms_webhook_secret', str_repeat('a', 48), false);
    set_transient($replay_key, 'processed', DAY_IN_SECONDS);
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
        if (false === $original_replay) {
            delete_transient($replay_key);
        } else {
            set_transient($replay_key, $original_replay, DAY_IN_SECONDS);
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

it('enqueues an authenticated webhook once and acknowledges accepted retries', function (): void {
    $missing = new stdClass();
    $original_webhook_option = get_option('wooms_enable_webhooks', $missing);
    $original_secret = get_option('wooms_webhook_secret', $missing);
    $secret = str_repeat('b', 48);
    $request_id = 'provider-request-12345678';
    $order_uuid = 'c0a8012e-1234-4abc-8def-1234567890ab';
    $api_calls = 0;
    $sync_calls = 0;
    $order_id = 0;
    $queued_actions = array();
    $queue_filter = static function ($preempt, $hook, $args, $group) use (&$queued_actions) {
        if ('wooms_process_order_webhook' !== $hook) {
            return $preempt;
        }
        $queued_actions[] = array('hook' => $hook, 'args' => $args, 'group' => $group);
        return 123;
    };
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
    add_filter('pre_as_enqueue_async_action', $queue_filter, 10, 4);
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

        // REST permission checks can run more than once before the callback.
        expect(OrderUpdateFromMoySklad::authorize_webhook($request))->toBeTrue();

        $response = OrderUpdateFromMoySklad::get_data_order_from_moysklad($request);
        expect(OrderUpdateFromMoySklad::authorize_webhook($request))->toBeTrue();
        $replay_response = OrderUpdateFromMoySklad::get_data_order_from_moysklad($request);

        expect($response)->toBeInstanceOf(WP_REST_Response::class);
        expect($response->get_status())->toBe(204);
        expect($response->get_data())->toBeNull();
        expect($replay_response)->toBeInstanceOf(WP_REST_Response::class);
        expect($replay_response->get_status())->toBe(204);
        expect($replay_response->get_data())->toBeNull();
        expect($api_calls)->toBe(0);
        expect($sync_calls)->toBe(0);
        expect($queued_actions)->toBe(array(array(
            'hook' => 'wooms_process_order_webhook',
            'args' => array('order_id' => $order_id, 'order_uuid' => $order_uuid, 'request_id' => $request_id),
            'group' => 'WooMS',
        )));
    } finally {
        remove_filter('pre_http_request', $http_filter, 10);
        remove_filter('pre_as_enqueue_async_action', $queue_filter, 10);
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

it('acknowledges unlinked orders and their retries without a provider request', function (): void {
    $missing = new stdClass();
    $original_options = array();
    foreach (array('wooms_enable_webhooks', 'wooms_webhook_secret', OrderUpdateFromMoySklad::$state_key) as $option) {
        $original_options[$option] = get_option($option, $missing);
    }
    $request_id = wp_generate_uuid4();
    $order_uuid = wp_generate_uuid4();
    $secret = str_repeat('c', 48);
    $rate_key = 'wooms_webhook_rate_' . gmdate('YmdHi');
    $original_rate = get_transient($rate_key);
    $replay_key = 'wooms_webhook_replay_' . hash('sha256', $request_id);
    $api_calls = 0;
    $sync_calls = 0;
    $http_filter = static function ($preempt, $args, $url) use (&$api_calls) {
        $api_calls++;
        return new WP_Error('unexpected_http_request', $url);
    };
    $sync_action = static function () use (&$sync_calls): void {
        $sync_calls++;
    };

    update_option('wooms_enable_webhooks', 1);
    update_option('wooms_webhook_secret', $secret, false);
    OrderUpdateFromMoySklad::set_state('is_wait', 1);
    delete_transient($rate_key);
    add_filter('pre_http_request', $http_filter, 10, 3);
    add_action('wooms_order_update_from_moysklad_action', $sync_action, 10, 3);

    $request = new WP_REST_Request('POST', '/wooms/v1/order-update/');
    $request->set_query_params(array('key' => $secret, 'requestId' => $request_id));
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

        expect($response->get_status())->toBe(204);
        expect($response->get_data())->toBeNull();
        expect(get_transient($replay_key))->toBe('processed');
        expect(OrderUpdateFromMoySklad::get_state('is_wait'))->toBe(1);

        set_transient($rate_key, 120, 2 * MINUTE_IN_SECONDS);
        expect(OrderUpdateFromMoySklad::authorize_webhook($request))->toBeTrue();
        $retry = OrderUpdateFromMoySklad::get_data_order_from_moysklad($request);

        expect($retry->get_status())->toBe(204);
        expect($retry->get_data())->toBeNull();
        expect($api_calls)->toBe(0);
        expect($sync_calls)->toBe(0);
        expect(get_transient($rate_key))->toBe(120);

        $valid_body = $request->get_body();
        $invalid_bodies = array(
            '{',
            wp_json_encode(array('events' => array())),
            wp_json_encode(array('events' => array(array('meta' => array(
                'href' => 'https://attacker.example/api/remap/1.2/entity/customerorder/' . $order_uuid,
            ))))),
        );
        foreach ($invalid_bodies as $body) {
            $request->set_body($body);
            expect(OrderUpdateFromMoySklad::authorize_webhook($request))->toBeTrue();
            $invalid_retry = OrderUpdateFromMoySklad::get_data_order_from_moysklad($request);
            expect($invalid_retry->get_status())->toBe(400);
            expect(get_transient($replay_key))->toBe('processed');
            expect($api_calls)->toBe(0);
            expect($sync_calls)->toBe(0);
        }

        $request->set_body($valid_body);
        expect(OrderUpdateFromMoySklad::authorize_webhook($request))->toBeTrue();
        expect(OrderUpdateFromMoySklad::get_data_order_from_moysklad($request)->get_status())->toBe(204);
        expect($api_calls)->toBe(0);
        expect($sync_calls)->toBe(0);
    } finally {
        remove_filter('pre_http_request', $http_filter, 10);
        remove_action('wooms_order_update_from_moysklad_action', $sync_action, 10);
        delete_transient($replay_key);
        if (false === $original_rate) {
            delete_transient($rate_key);
        } else {
            set_transient($rate_key, $original_rate, 2 * MINUTE_IN_SECONDS);
        }
        foreach ($original_options as $option => $value) {
            if ($missing === $value) {
                delete_option($option);
            } else {
                update_option($option, $value, false);
            }
        }
    }
});

it('defers linked order provider work until the queued action runs', function (string $scenario): void {
    $missing = new stdClass();
    $original_options = array();
    foreach (array('wooms_enable_webhooks', 'wooms_webhook_secret', OrderUpdateFromMoySklad::$state_key) as $option) {
        $original_options[$option] = get_option($option, $missing);
    }
    $request_id = wp_generate_uuid4();
    $order_uuid = wp_generate_uuid4();
    $secret = str_repeat('d', 48);
    $rate_key = 'wooms_webhook_rate_' . gmdate('YmdHi');
    $original_rate = get_transient($rate_key);
    $replay_key = 'wooms_webhook_replay_' . hash('sha256', $request_id);
    $api_calls = 0;
    $sync_calls = 0;
    $sync_payload = array();
    $provider_data = array('id' => $order_uuid, 'name' => 'Contract test order');
    $provider_fails = in_array($scenario, array('provider error', 'retry exhausted', 'retry queue error', 'retry queue exception'), true);
    $order_id = 0;
    $queue_fails = in_array($scenario, array('queue error', 'queue negative id'), true);
    $queue_throws = 'queue exception' === $scenario;
    $queued_actions = array();
    $scheduled_retries = array();
    $error_logs = array();
    $retry_queue_fails = 'retry queue error' === $scenario;
    $retry_queue_throws = 'retry queue exception' === $scenario;
    $retry_filter = static function ($preempt, $timestamp, $hook, $args, $group) use (&$scheduled_retries, &$retry_queue_fails, &$retry_queue_throws) {
        if ('wooms_process_order_webhook' !== $hook) {
            return $preempt;
        }
        if ($retry_queue_throws) {
            throw new RuntimeException('Test retry store is unavailable');
        }
        if ($retry_queue_fails) {
            return 0;
        }
        $scheduled_retries[] = compact('timestamp', 'hook', 'args', 'group');
        return 700 + count($scheduled_retries);
    };
    $error_logger = static function ($source, $title, $context = array()) use (&$error_logs): void {
        if (OrderUpdateFromMoySklad::class === $source) {
            $error_logs[] = compact('title', 'context');
        }
    };
    $queue_filter = static function ($preempt, $hook, $args) use (&$queued_actions, &$queue_fails, &$queue_throws, $scenario) {
        if ('wooms_order_update_from_moysklad' === $hook) {
            return 808; // The final worker has its own persistence and retry contract tests.
        }
        if ('wooms_process_order_webhook' !== $hook) {
            return $preempt;
        }
        if ($queue_throws) {
            throw new RuntimeException('Test action store is unavailable');
        }
        if ($queue_fails) {
            return 'queue negative id' === $scenario ? -1 : 0;
        }
        $queued_actions[] = $args;
        return 456;
    };
    $http_filter = static function ($preempt, $args, $url) use (&$api_calls, &$provider_fails, $provider_data, &$order_id, $scenario) {
        $api_calls++;
        if ($provider_fails) {
            return new WP_Error('test_provider_unavailable', 'Provider is temporarily unavailable');
        }
        if ('mapping changes during request' === $scenario) {
            update_post_meta($order_id, 'wooms_id', wp_generate_uuid4());
        }
        if ('lock appears during request' === $scenario) {
            update_post_meta($order_id, 'lock_callback_update', 1);
        }
        return array(
            'headers' => array(),
            'body' => wp_json_encode($provider_data),
            'response' => array('code' => 200, 'message' => 'OK'),
            'cookies' => array(),
            'filename' => null,
        );
    };
    $sync_action = static function ($order_id, $data, $uuid) use (&$sync_calls, &$sync_payload): void {
        $sync_calls++;
        $sync_payload = array($order_id, $data, $uuid);
    };

    update_option('wooms_enable_webhooks', 1);
    update_option('wooms_webhook_secret', $secret, false);
    OrderUpdateFromMoySklad::set_state('is_wait', 1);
    delete_transient($rate_key);
    $order_id = wp_insert_post(array(
        'post_title' => 'Webhook regression test order',
        'post_status' => 'publish',
        'post_type' => 'shop_order',
    ));
    update_post_meta($order_id, 'wooms_id', $order_uuid);
    delete_post_meta($order_id, 'lock_callback_update');
    if ('locked order' === $scenario) {
        update_post_meta($order_id, 'lock_callback_update', 1);
    }
    add_filter('pre_http_request', $http_filter, 10, 3);
    add_filter('pre_as_enqueue_async_action', $queue_filter, 10, 3);
    add_filter('pre_as_schedule_single_action', $retry_filter, 10, 5);
    add_action('wooms_order_update_from_moysklad_action', $sync_action, 10, 3);
    add_action('wooms_logger_error', $error_logger, 10, 3);

    $request = new WP_REST_Request('POST', '/wooms/v1/order-update/');
    $request->set_query_params(array('key' => $secret, 'requestId' => $request_id));
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

        expect($api_calls)->toBe(0);
        expect($sync_calls)->toBe(0);
        expect(get_post_meta($order_id, OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true))->toBe('');
        expect(OrderUpdateFromMoySklad::get_state('is_wait'))->toBe(1);

        if ($queue_fails || $queue_throws) {
            expect($response->get_status())->toBe(503);
            expect(get_transient($replay_key))->toBeFalse();
            expect($queued_actions)->toBe(array());
            $queue_log = end($error_logs);
            expect($queue_log['context']['request_id'] ?? null)->toBe($request_id);
            expect($queue_log['context']['phase'] ?? null)->toBe('enqueue');
            $queue_fails = $queue_throws = false;
            expect(OrderUpdateFromMoySklad::authorize_webhook($request))->toBeTrue();
            $response = OrderUpdateFromMoySklad::get_data_order_from_moysklad($request);
        }

        expect(get_transient($replay_key))->toBe('processed');
        expect(get_post_meta($order_id, 'lock_callback_update', true))->toBe('');

        if ('locked order' === $scenario) {
            expect($response->get_status())->toBe(200);
            expect($queued_actions)->toBe(array());
            expect($api_calls)->toBe(0);
            expect($sync_calls)->toBe(0);
        } else {
            expect($response->get_status())->toBe(204);
            expect(count($queued_actions))->toBe(1);
            $job_args = array_values($queued_actions[0]);
            if ('mapping changes before execution' === $scenario) {
                update_post_meta($order_id, 'wooms_id', wp_generate_uuid4());
            }
            if ('lock appears before execution' === $scenario) {
                update_post_meta($order_id, 'lock_callback_update', 1);
            }
            if ($provider_fails) {
                $failure = null;
                try {
                    do_action('wooms_process_order_webhook', ...$job_args);
                } catch (RuntimeException $exception) {
                    $failure = $exception;
                }
                expect($failure)->toBeInstanceOf(RuntimeException::class);
                expect($sync_calls)->toBe(0);
                expect(get_post_meta($order_id, OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true))->toBe('');
                expect(get_transient($replay_key))->toBe('processed');

                $failure_log = end($error_logs);
                expect($failure_log['context']['request_id'] ?? null)->toBe($request_id);
                expect($failure_log['context']['order_id'] ?? null)->toBe($order_id);
                expect($failure_log['context']['order_uuid'] ?? null)->toBe($order_uuid);
                expect($failure_log['context']['attempt'] ?? null)->toBe(0);
                expect(strpos($failure->getMessage(), $request_id) !== false)->toBeTrue();

                if ($retry_queue_fails || $retry_queue_throws) {
                    expect($scheduled_retries)->toBe(array());
                    expect($failure_log['context']['retry_error_code'] ?? null)->toBe('wooms_webhook_retry_queue_failed');
                    expect($api_calls)->toBe(1);
                    return;
                }

                expect(count($scheduled_retries))->toBe(1);
                expect($scheduled_retries[0]['args']['attempt'])->toBe(1);
                expect($failure_log['context']['retry_action_id'] ?? null)->toBe(701);

                if ('retry exhausted' === $scenario) {
                    for ($attempt = 1; $attempt <= 3; $attempt++) {
                        expect($scheduled_retries[$attempt - 1]['args']['attempt'])->toBe($attempt);
                        $failure = null;
                        try {
                            do_action('wooms_process_order_webhook', ...array_values($scheduled_retries[$attempt - 1]['args']));
                        } catch (RuntimeException $exception) {
                            $failure = $exception;
                        }
                        expect($failure)->toBeInstanceOf(RuntimeException::class);
                    }
                    $final_log = end($error_logs);
                    expect($api_calls)->toBe(4);
                    expect(count($scheduled_retries))->toBe(3);
                    expect(count($error_logs))->toBe(4);
                    expect($final_log['context']['attempt'] ?? null)->toBe(3);
                    expect($final_log['context']['retry_error_code'] ?? null)->toBe('wooms_webhook_retries_exhausted');
                    expect($sync_calls)->toBe(0);
                    expect(get_post_meta($order_id, OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true))->toBe('');
                    return;
                }

                $job_args = array_values($scheduled_retries[0]['args']);
                $provider_fails = false;
            }
            do_action('wooms_process_order_webhook', ...$job_args);
            $skipped_before_request = in_array($scenario, array('mapping changes before execution', 'lock appears before execution'), true);
            $skipped_after_request = in_array($scenario, array('mapping changes during request', 'lock appears during request'), true);
            expect($api_calls)->toBe($skipped_before_request ? 0 : ('provider error' === $scenario ? 2 : 1));
            if ($skipped_before_request || $skipped_after_request) {
                expect(get_post_meta($order_id, OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true))->toBe('');
                expect($sync_calls)->toBe(0);
                expect(get_post_meta($order_id, 'lock_callback_update', true))->toBe('');
            } else {
                $pending = get_post_meta($order_id, OrderUpdateFromMoySklad::$hook_order_update_from_moysklad, true);
                expect($pending['request_id'] ?? null)->toBe($request_id);
                expect($pending['order_uuid'] ?? null)->toBe($order_uuid);
                expect($pending['status'] ?? null)->toBe('pending');
                expect(is_string($pending['generation'] ?? null))->toBeTrue();
                expect($sync_calls)->toBe(1);
                expect($sync_payload)->toBe(array($order_id, $provider_data, $order_uuid));
            }
        }
    } finally {
        remove_filter('pre_http_request', $http_filter, 10);
        remove_filter('pre_as_enqueue_async_action', $queue_filter, 10);
        remove_filter('pre_as_schedule_single_action', $retry_filter, 10);
        remove_action('wooms_order_update_from_moysklad_action', $sync_action, 10);
        remove_action('wooms_logger_error', $error_logger, 10);
        wp_delete_post($order_id, true);
        delete_transient($replay_key);
        if (false === $original_rate) {
            delete_transient($rate_key);
        } else {
            set_transient($rate_key, $original_rate, 2 * MINUTE_IN_SECONDS);
        }
        foreach ($original_options as $option => $value) {
            if ($missing === $value) {
                delete_option($option);
            } else {
                update_option($option, $value, false);
            }
        }
    }
})->with(array(
    'normal order',
    'locked order',
    'provider error',
    'retry exhausted',
    'retry queue error',
    'retry queue exception',
    'queue error',
    'queue negative id',
    'queue exception',
    'mapping changes before execution',
    'mapping changes during request',
    'lock appears before execution',
    'lock appears during request',
));
