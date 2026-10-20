<?php

declare(strict_types=1);

use WooMS\OrderUpdateFromMoySklad;

$diagnostics = array();
beforeEach(function () use (&$diagnostics): void {
    $diagnostics = array('records' => array(), 'http' => 0, 'had_start' => isset($_SERVER['REQUEST_TIME_FLOAT']),
        'start' => $_SERVER['REQUEST_TIME_FLOAT'] ?? null, 'request_id' => wp_generate_uuid4());
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 0.1;
    $diagnostics['info'] = static function ($source, $title, $context = array()) use (&$diagnostics): void {
        if (OrderUpdateFromMoySklad::class === $source && 'http_response' === ($context['phase'] ?? '')) {
            $diagnostics['records'][] = array('level' => 'info', 'title' => $title, 'context' => $context);
        }
    };
    $diagnostics['error'] = static function ($source, $title, $context = array()) use (&$diagnostics): void {
        if (OrderUpdateFromMoySklad::class === $source && 'http_response' === ($context['phase'] ?? '')) {
            $diagnostics['records'][] = array('level' => 'error', 'title' => $title, 'context' => $context);
        }
    };
    $diagnostics['http_filter'] = static function () use (&$diagnostics) {
        $diagnostics['http']++;
        return new WP_Error('unexpected_diagnostics_http', 'Response logging must not call a provider');
    };
    add_action('wooms_logger', $diagnostics['info'], 10, 3);
    add_action('wooms_logger_error', $diagnostics['error'], 10, 3);
    add_filter('pre_http_request', $diagnostics['http_filter']);
});
afterEach(function () use (&$diagnostics): void {
    remove_action('wooms_logger', $diagnostics['info'], 10);
    remove_action('wooms_logger_error', $diagnostics['error'], 10);
    remove_filter('pre_http_request', $diagnostics['http_filter']);
    if ($diagnostics['had_start']) { $_SERVER['REQUEST_TIME_FLOAT'] = $diagnostics['start']; }
    else { unset($_SERVER['REQUEST_TIME_FLOAT']); }
});

$diagnostic_request = static function ($route = '/wooms/v1/order-update/') use (&$diagnostics) {
    $request = new WP_REST_Request('POST', $route);
    $request->set_query_params(array('key' => 'never-log-this-webhook-secret', 'requestId' => $diagnostics['request_id']));
    $request->set_body(wp_json_encode(array('auditContext' => array('uid' => 'private-account@example.test'), 'events' => array(array('action' => 'UPDATE')))));
    return $request;
};

it('records an accepted webhook response without changing it', function (int $status) use (&$diagnostics, $diagnostic_request): void {
    $request = $diagnostic_request('/wooms/v1/order-update');
    $response = new WP_REST_Response(null, $status);
    expect(apply_filters('rest_post_dispatch', $response, null, $request))->toBe($response);
    expect(count($diagnostics['records']))->toBe(1);
    $record = $diagnostics['records'][0];
    expect($record['level'])->toBe('info');
    expect($record['context']['request_id'])->toBe($diagnostics['request_id']);
    expect($record['context']['http_status'])->toBe($status);
    expect($record['context']['provider_acceptable_status'])->toBeTrue();
    expect($record['context']['slow'])->toBeFalse();
    expect($record['context']['php_duration_ms'] >= 90)->toBeTrue();
    expect($record['context']['events_count'])->toBe(1);
    expect($record['context']['timing_source'])->toBe('php_request_start');
    expect($record['context']['timing_boundary'])->toBe('prepared_rest_response');
    expect($diagnostics['http'])->toBe(0);
})->with(array(200, 204));

it('records rejected responses including failures before the endpoint callback', function (int $status) use (&$diagnostics, $diagnostic_request): void {
    $response = new WP_REST_Response(array('code' => 'test_rest_failure', 'message' => 'private failure details'), $status);
    expect(apply_filters('rest_post_dispatch', $response, null, $diagnostic_request()))->toBe($response);
    expect(count($diagnostics['records']))->toBe(1);
    $record = $diagnostics['records'][0];
    expect($record['level'])->toBe('error');
    expect($record['context']['http_status'])->toBe($status);
    expect($record['context']['provider_acceptable_status'])->toBeFalse();
    $encoded = wp_json_encode($record);
    foreach (array('never-log-this-webhook-secret', 'private-account@example.test', 'private failure details') as $private) {
        expect(false === strpos($encoded, $private))->toBeTrue();
    }
    expect($diagnostics['http'])->toBe(0);
})->with(array(202, 400, 401, 409, 413, 429, 500, 503));

it('flags a slow successful PHP response without changing its status', function () use (&$diagnostics, $diagnostic_request): void {
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 2;
    $response = new WP_REST_Response(null, 204);
    expect(apply_filters('rest_post_dispatch', $response, null, $diagnostic_request()))->toBe($response);
    $record = $diagnostics['records'][0];
    expect($record['level'])->toBe('error');
    expect($record['context']['provider_acceptable_status'])->toBeTrue();
    expect($record['context']['slow'])->toBeTrue();
    expect($record['context']['php_duration_ms'] >= 1900)->toBeTrue();
    expect($response->get_status())->toBe(204);
});

it('omits malformed identifiers and skips decoding oversized bodies', function () use (&$diagnostics, $diagnostic_request): void {
    $request = $diagnostic_request();
    $request->set_query_params(array('key' => 'never-log-this-webhook-secret', 'requestId' => array('malformed')));
    $request->set_body(str_repeat('x', 65537));
    $response = new WP_REST_Response(array('error' => 'Payload too large'), 413);
    apply_filters('rest_post_dispatch', $response, null, $request);
    expect(count($diagnostics['records']))->toBe(1);
    expect($diagnostics['records'][0]['context']['request_id'])->toBeNull();
    expect($diagnostics['records'][0]['context']['events_count'])->toBeNull();
});

it('leaves other REST routes alone', function () use (&$diagnostics, $diagnostic_request): void {
    $response = new WP_REST_Response(array('ok' => true), 200);
    expect(apply_filters('rest_post_dispatch', $response, null, $diagnostic_request('/wooms/v1/order-update-extra')))->toBe($response);
    expect($diagnostics['records'])->toBe(array());
});

it('handles a response to a webhook request without a body', function () use (&$diagnostics): void {
    $request = new WP_REST_Request('POST', '/wooms/v1/order-update/');
    $response = new WP_REST_Response(array('error' => 'Invalid webhook payload'), 400);
    expect(apply_filters('rest_post_dispatch', $response, null, $request))->toBe($response);
    expect(count($diagnostics['records']))->toBe(1);
    expect($diagnostics['records'][0]['context']['events_count'])->toBeNull();
});

it('uses a labelled fallback timer when PHP request time is unavailable', function () use (&$diagnostics, $diagnostic_request): void {
    unset($_SERVER['REQUEST_TIME_FLOAT']);
    apply_filters('rest_post_dispatch', new WP_REST_Response(null, 204), null, $diagnostic_request());
    expect($diagnostics['records'][0]['context']['timing_source'])->toBe('plugin_start');
    expect($diagnostics['records'][0]['context']['php_duration_ms'] >= 0)->toBeTrue();
});

it('preserves the webhook response if a logging listener throws', function () use (&$diagnostics, $diagnostic_request): void {
    $path = tempnam(sys_get_temp_dir(), 'wooms-response-log-');
    $original = ini_get('error_log');
    $listener = static function ($source): void {
        if (OrderUpdateFromMoySklad::class === $source) { throw new RuntimeException('Test broken log listener'); }
    };
    ini_set('error_log', $path);
    add_action('wooms_logger', $listener, 5);
    try {
        $response = new WP_REST_Response(null, 204);
        expect(apply_filters('rest_post_dispatch', $response, null, $diagnostic_request()))->toBe($response);
        $fallback = file_get_contents($path);
        expect(false !== strpos($fallback, $diagnostics['request_id']))->toBeTrue();
        expect(false === strpos($fallback, 'never-log-this-webhook-secret'))->toBeTrue();
    } finally {
        remove_action('wooms_logger', $listener, 5);
        ini_set('error_log', $original);
        unlink($path);
    }
});
