<?php

declare(strict_types=1);

use WooMS\IntegrationDiagnostics;
use WooMS\OrderUpdateFromMoySklad;

$incidents = array();
beforeEach(function () use (&$incidents): void {
    $incidents = array('issues' => array(), 'actions' => array(), 'original_logging' => get_option('wooms_logger_enable'));
    $incidents['original_logger'] = wc_get_logger();
    $incidents['logger'] = new class extends WC_Logger {
        public array $records = array();
        public bool $fail = false;
        public function log($level, $message, $context = array()) {
            if ($this->fail) { throw new RuntimeException('private logger failure'); }
            $this->records[] = compact('level', 'message', 'context');
        }
    };
    $incidents['logger_filter'] = static function () use (&$incidents) { return $incidents['logger']; };
    $incidents['listener'] = static function ($issue) use (&$incidents) { $incidents['issues'][] = $issue; };
    add_filter('woocommerce_logging_class', $incidents['logger_filter']);
    add_action('wooms_integration_issue', $incidents['listener']);
    update_option('wooms_logger_enable', 0);
});
afterEach(function () use (&$incidents): void {
    foreach ($incidents['actions'] as $id) { ActionScheduler::store()->delete_action($id); }
    IntegrationDiagnostics::clear_action(0);
    remove_filter('woocommerce_logging_class', $incidents['logger_filter']);
    remove_action('wooms_integration_issue', $incidents['listener']);
    // Removing the filter alone leaves its mock in WooCommerce's static logger cache.
    $restore_logger = static function () use (&$incidents) { return $incidents['original_logger']; };
    add_filter('woocommerce_logging_class', $restore_logger, PHP_INT_MAX);
    wc_get_logger();
    remove_filter('woocommerce_logging_class', $restore_logger, PHP_INT_MAX);
    if (false === $incidents['original_logging']) { delete_option('wooms_logger_enable'); }
    else { update_option('wooms_logger_enable', $incidents['original_logging']); }
});

$api_failure_response = static function (int $status, string $body): array {
    return array('headers' => array(), 'body' => $body, 'response' => array('code' => $status, 'message' => ''), 'cookies' => array(), 'filename' => null);
};

it('reports lost API access from both clients while preserving their return values', function (string $client) use (&$incidents, $api_failure_response): void {
    $body = array('errors' => array(array('code' => 1056, 'error' => 'private-account@example.test secret-password')));
    $response = $api_failure_response(401, wp_json_encode($body));
    $filter = static function () use ($response) { return $response; };
    add_filter('pre_http_request', $filter, 20);
    try {
        $actual = $client('entity/organization?key=secret-query&filter=private-name');
    } finally {
        remove_filter('pre_http_request', $filter, 20);
    }
    expect($actual)->toBe($body);
    expect(count($incidents['issues']))->toBe(1);
    $issue = $incidents['issues'][0];
    expect($issue['code'])->toBe('api_authentication_failed');
    expect($issue['context'])->toBe(array('http_status' => 401, 'api_resource' => 'entity/organization', 'provider_error_codes' => array(1056)));
    expect($issue['log_source'])->toBe('wooms-integration');
    expect($issue['log_url'])->toBe(admin_url('admin.php?page=wc-status&tab=logs&source=wooms-integration'));
    expect(wp_is_uuid($issue['event_id']))->toBeTrue();
    expect(count($incidents['logger']->records))->toBe(1);
    expect($incidents['logger']->records[0]['context']['event_id'])->toBe($issue['event_id']);
    $encoded = wp_json_encode(array($incidents['issues'], $incidents['logger']->records));
    foreach (array('secret-password', 'private-account', 'secret-query', 'private-name', 'Authorization') as $secret) {
        expect(strpos($encoded, $secret))->toBeFalse();
    }
})->with(array('WooMS\\request', 'wooms_request'));

it('classifies API HTTP errors and malformed responses', function (int $status, string $body, string $code) use (&$incidents, $api_failure_response): void {
    IntegrationDiagnostics::record_api_response('https://api.moysklad.ru/api/remap/1.2/entity/product', $api_failure_response($status, $body), json_decode($body, true));
    expect(array_column($incidents['issues'], 'code'))->toBe(array($code));
})->with(array(
    array(403, '{}', 'api_access_denied'), array(429, '{}', 'api_request_failed'), array(503, 'private upstream details', 'api_request_failed'),
    array(200, '{"errors":[{"code":1070}]}', 'api_request_failed'), array(200, '', 'api_invalid_response'), array(200, 'not JSON', 'api_invalid_response'),
));

it('reports transport errors without exposing their private messages', function (string $client) use (&$incidents): void {
    $filter = static function () { return new WP_Error('http_request_failed', 'https://private.example/?token=secret'); };
    add_filter('pre_http_request', $filter, 20);
    try { expect($client('entity/product'))->toBeFalse(); }
    finally { remove_filter('pre_http_request', $filter, 20); }
    expect(array_column($incidents['issues'], 'code'))->toBe(array('api_transport_failed'));
    expect(strpos(wp_json_encode($incidents['issues']), 'secret'))->toBeFalse();
})->with(array('WooMS\\request', 'wooms_request'));

it('does not emit incidents for successful reads or empty HTTP204 responses', function () use (&$incidents, $api_failure_response): void {
    IntegrationDiagnostics::record_api_response('https://api.moysklad.ru/api/remap/1.2/entity/product', $api_failure_response(200, '{"rows":[]}'), array('rows' => array()));
    IntegrationDiagnostics::record_api_response('https://api.moysklad.ru/api/remap/1.2/entity/product/123', $api_failure_response(204, ''));
    expect($incidents['issues'])->toBe(array());
    expect($incidents['logger']->records)->toBe(array());
});

it('preserves client results when a notification listener throws', function () use (&$incidents, $api_failure_response): void {
    $filter = static function () use ($api_failure_response) { return $api_failure_response(401, '{"errors":[{"code":1056}]}'); };
    $listener = static function () { throw new RuntimeException('private notification failure'); };
    $path = tempnam(sys_get_temp_dir(), 'wooms-issue-log-');
    $original = ini_get('error_log');
    ini_set('error_log', $path);
    add_filter('pre_http_request', $filter, 20);
    add_action('wooms_integration_issue', $listener, 20);
    try {
        expect(WooMS\request('entity/organization'))->toBe(array('errors' => array(array('code' => 1056))));
        expect(strpos(file_get_contents($path), 'integration_issue_listener_failed'))->not->toBeFalse();
        expect(strpos(file_get_contents($path), 'private notification failure'))->toBeFalse();
    } finally {
        remove_filter('pre_http_request', $filter, 20);
        remove_action('wooms_integration_issue', $listener, 20);
        ini_set('error_log', $original);
        unlink($path);
    }
});

it('reports queue decisions with safe correlation fields', function (array $decision, string $code) use (&$incidents): void {
    $context = array('phase' => 'processing', 'order_id' => 123, 'request_id' => 'request-id-123', 'attempt' => 0,
        'error' => 'private exception with password', 'body' => 'private customer', 'retry_error' => 'secret') + $decision;
    do_action('wooms_logger_error', OrderUpdateFromMoySklad::class, 'private title', $context);
    expect(array_column($incidents['issues'], 'code'))->toBe(array($code));
    expect($incidents['issues'][0]['context']['order_id'])->toBe(123);
    expect($incidents['issues'][0]['context']['request_id'])->toBe('request-id-123');
    expect(strpos(wp_json_encode(array($incidents['issues'], $incidents['logger']->records)), 'private'))->toBeFalse();
})->with(array(
    array(array('retry_action_id' => 42, 'next_attempt' => 1), 'webhook_retry_scheduled'),
    array(array('retry_error_code' => 'wooms_webhook_retries_exhausted'), 'webhook_retries_exhausted'),
    array(array('retry_error_code' => 'wooms_webhook_retry_queue_failed'), 'webhook_retry_queue_failed'),
));

it('reports initial enqueue failures but ignores unauthenticated probes and superseded generations', function () use (&$incidents): void {
    do_action('wooms_logger_error', OrderUpdateFromMoySklad::class, 'queue failure', array('phase' => 'enqueue', 'request_id' => 'request-id-123'));
    do_action('wooms_logger_error', OrderUpdateFromMoySklad::class, 'bot probe', array('phase' => 'http_response', 'http_status' => 401));
    do_action('wooms_logger_error', OrderUpdateFromMoySklad::class, 'old generation', array('superseded' => true, 'retry_error_code' => 'wooms_webhook_retries_exhausted'));
    expect(array_column($incidents['issues'], 'code'))->toBe(array('webhook_queue_failed'));
});

it('observes scheduler failures only for the WooMS webhook group and hooks', function (string $hook, string $group, bool $owned) use (&$incidents): void {
    $id = as_enqueue_async_action($hook, array('request_id' => 'request-id-123', 'order_id' => 123, 'secret' => 'never-forward-this'), $group);
    $incidents['actions'][] = $id;
    do_action('action_scheduler_failed_execution', $id, new RuntimeException('private failure'), 'test');
    expect(count($incidents['issues']))->toBe($owned ? 1 : 0);
    if ($owned) {
        expect($incidents['issues'][0]['context']['action_id'])->toBe($id);
        expect($incidents['issues'][0]['context']['order_id'])->toBe(123);
        expect(strpos(wp_json_encode($incidents['issues']), 'never-forward-this'))->toBeFalse();
    }
})->with(array(
    array('wooms_process_order_webhook', 'WooMS', true), array('wooms_order_update_from_moysklad', 'WooMS', true),
    array('some_other_hook', 'WooMS', false), array('wooms_process_order_webhook', 'other-plugin', false),
));

it('records timeout and fatal shutdown with nested final-update arguments', function () use (&$incidents): void {
    $id = as_enqueue_async_action('wooms_order_update_from_moysklad', array('data' => array('post_id' => 456, 'request_id' => 'request-id-123')), 'WooMS');
    $incidents['actions'][] = $id;
    do_action('action_scheduler_failed_action', $id, 300);
    do_action('action_scheduler_unexpected_shutdown', $id, array('type' => E_ERROR, 'message' => 'private fatal details', 'file' => __FILE__, 'line' => __LINE__));
    expect(array_column($incidents['issues'], 'code'))->toBe(array('webhook_action_timed_out', 'webhook_action_shutdown'));
    expect($incidents['issues'][0]['context']['timeout_seconds'])->toBe(300);
    expect($incidents['issues'][1]['context']['post_id'])->toBe(456);
});

it('does not duplicate the worker retry decision as an unhandled scheduler failure', function () use (&$incidents): void {
    $id = as_enqueue_async_action('wooms_process_order_webhook', array('order_id' => 123), 'WooMS');
    $incidents['actions'][] = $id;
    do_action('action_scheduler_before_execute', $id);
    do_action('wooms_logger_error', OrderUpdateFromMoySklad::class, 'retry', array('phase' => 'processing', 'retry_action_id' => 42));
    do_action('action_scheduler_failed_execution', $id, new RuntimeException('Already reported'), 'test');
    expect(array_column($incidents['issues'], 'code'))->toBe(array('webhook_retry_scheduled'));
    expect($incidents['issues'][0]['context']['action_id'])->toBe($id);
});

it('preserves native failed action status without running other scheduled jobs', function () use (&$incidents): void {
    $id = as_enqueue_async_action('wooms_process_order_webhook', array('order_id' => 123, 'order_uuid' => wp_generate_uuid4(), 'request_id' => 'request-id-123'), 'WooMS');
    $incidents['actions'][] = $id;
    $callback = static function () { throw new RuntimeException('private unexpected callback failure'); };
    add_action('wooms_process_order_webhook', $callback, 1);
    try { ActionScheduler_QueueRunner::instance()->process_action($id, 'WooMS diagnostics test'); }
    finally { remove_action('wooms_process_order_webhook', $callback, 1); }
    expect(ActionScheduler::store()->get_status($id))->toBe(ActionScheduler_Store::STATUS_FAILED);
    expect(array_column($incidents['issues'], 'code'))->toBe(array('webhook_action_failed'));
    expect($incidents['issues'][0]['context']['action_id'])->toBe($id);
});

it('prevents recursive incident delivery and preserves later incidents', function () use (&$incidents): void {
    $listener = static function () { IntegrationDiagnostics::record_api_response('https://api.moysklad.ru/api/remap/1.2/entity/product', new WP_Error('http_request_failed', 'private')); };
    add_action('wooms_integration_issue', $listener, 20);
    try { IntegrationDiagnostics::record_api_response('https://api.moysklad.ru/api/remap/1.2/entity/product', new WP_Error('http_request_failed')); }
    finally { remove_action('wooms_integration_issue', $listener, 20); }
    IntegrationDiagnostics::record_api_response('https://api.moysklad.ru/api/remap/1.2/entity/product', new WP_Error('http_request_failed'));
    expect(count($incidents['issues']))->toBe(2);
});

it('retains safe diagnostics and delivers the hook when the WooCommerce logger fails', function () use (&$incidents, $api_failure_response): void {
    $incidents['logger']->fail = true;
    $path = tempnam(sys_get_temp_dir(), 'wooms-logger-failure-');
    $original = ini_get('error_log');
    ini_set('error_log', $path);
    try {
        IntegrationDiagnostics::record_api_response('https://api.moysklad.ru/api/remap/1.2/entity/organization', $api_failure_response(401, '{}'), array());
        expect(count($incidents['issues']))->toBe(1);
        expect(strpos(file_get_contents($path), $incidents['issues'][0]['event_id']))->not->toBeFalse();
        expect(strpos(file_get_contents($path), 'private logger failure'))->toBeFalse();
    } finally {
        ini_set('error_log', $original);
        unlink($path);
    }
});
