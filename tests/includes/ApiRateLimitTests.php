<?php

declare(strict_types=1);

use WooMS\IntegrationDiagnostics;

$rate_limit = array();

beforeEach(function () use (&$rate_limit): void {
    $rate_limit = array('responses' => array(), 'calls' => array(), 'issues' => array());
    $rate_limit['original_logging'] = get_option('wooms_logger_enable');
    $rate_limit['original_logger'] = wc_get_logger();
    $rate_limit['logger'] = new class extends WC_Logger {
        public array $records = array();
        public bool $fail = false;

        public function log($level, $message, $context = array()) {
            if ($this->fail) { throw new RuntimeException('private-logger-error'); }
            $this->records[] = compact('level', 'message', 'context');
        }
    };
    $rate_limit['response'] = static function (int $status = 429, array $headers = array('x-lognex-retry-after' => '100'), ?array $body = null): array {
        return array(
            'headers' => $headers,
            'body' => wp_json_encode($body ?? array('errors' => array(array('code' => 1049, 'error' => 'private-provider-body')))),
            'response' => array('code' => $status, 'message' => ''),
            'cookies' => array(), 'filename' => null,
        );
    };
    $rate_limit['http_filter'] = static function ($preempt, $args, $url) use (&$rate_limit) {
        $rate_limit['calls'][] = array('url' => $url, 'args' => $args, 'at' => microtime(true));
        return array_shift($rate_limit['responses']) ?? new WP_Error('unexpected_fixture_request');
    };
    $rate_limit['logger_filter'] = static function () use (&$rate_limit) { return $rate_limit['logger']; };
    $rate_limit['listener'] = static function ($issue) use (&$rate_limit) { $rate_limit['issues'][] = $issue; };
    add_filter('pre_http_request', $rate_limit['http_filter'], 10, 3);
    add_filter('woocommerce_logging_class', $rate_limit['logger_filter']);
    add_action('wooms_integration_issue', $rate_limit['listener']);
    update_option('wooms_logger_enable', 0);
});

afterEach(function () use (&$rate_limit): void {
    remove_filter('pre_http_request', $rate_limit['http_filter'], 10);
    remove_filter('woocommerce_logging_class', $rate_limit['logger_filter']);
    remove_action('wooms_integration_issue', $rate_limit['listener']);
    // WooCommerce caches the logger object even after its filter has been removed.
    $restore_logger = static function () use (&$rate_limit) { return $rate_limit['original_logger']; };
    add_filter('woocommerce_logging_class', $restore_logger, PHP_INT_MAX);
    wc_get_logger();
    remove_filter('woocommerce_logging_class', $restore_logger, PHP_INT_MAX);
    if (false === $rate_limit['original_logging']) { delete_option('wooms_logger_enable'); }
    else { update_option('wooms_logger_enable', $rate_limit['original_logging']); }
});

it('retries a rate-limited read without changing its URL, credentials or result', function (string $client) use (&$rate_limit): void {
    $body = array('rows' => array(array('id' => 'fixture-product')));
    $rate_limit['responses'] = array($rate_limit['response'](), $rate_limit['response'](200, array(), $body));

    expect($client('entity/product?filter=code=private-query'))->toBe($body);
    expect($rate_limit['calls'])->toHaveCount(2);
    [$first, $second] = $rate_limit['calls'];
    $retry_args = $second['args'];
    expect($retry_args['timeout'])->toBeGreaterThan(0)->toBeLessThanOrEqual(10);
    $retry_args['timeout'] = $first['args']['timeout'];
    expect($retry_args)->toBe($first['args'])
        ->and($second['url'])->toBe($first['url'])
        ->and($second['at'] - $first['at'])->toBeGreaterThanOrEqual(0.1)
        ->and($rate_limit['issues'])->toBeEmpty();
    $records = $rate_limit['logger']->records;
    expect(array_column(array_column($records, 'context'), 'code'))
        ->toBe(array('api_rate_limit_retry', 'api_rate_limit_recovered'));
    expect(wp_json_encode($records))->not->toContain('private-query', 'private-provider-body', 'Authorization');
})->with(array('WooMS\\request', 'wooms_request'));

it('returns the final rejection and reports one incident after three attempts', function (string $client) use (&$rate_limit): void {
    $body = array('errors' => array(array('code' => 1049, 'error' => 'last-rejection')));
    $rate_limit['responses'] = array(
        $rate_limit['response'](), $rate_limit['response'](), $rate_limit['response'](429, array(), $body),
    );

    expect($client('entity/product'))->toBe($body)
        ->and($rate_limit['calls'])->toHaveCount(3)
        ->and(array_column($rate_limit['issues'], 'code'))->toBe(array('api_request_failed'));
    expect($rate_limit['issues'][0]['context']['provider_error_codes'])->toBe(array(1049));
})->with(array('WooMS\\request', 'wooms_request'));

it('stops retries when the next attempt has a transport failure', function (string $client) use (&$rate_limit): void {
    $rate_limit['responses'] = array($rate_limit['response'](), new WP_Error('http_request_failed', 'private-transport-error'));

    expect($client('entity/product'))->toBeFalse()
        ->and($rate_limit['calls'])->toHaveCount(2)
        ->and(array_column($rate_limit['issues'], 'code'))->toBe(array('api_transport_failed'));
})->with(array('WooMS\\request', 'wooms_request'));

it('does not retry unrelated HTTP or application errors', function (string $client, int $status) use (&$rate_limit): void {
    $body = array('errors' => array(array('code' => 1049)));
    $rate_limit['responses'] = array($rate_limit['response']($status, array(), $body));

    expect($client('entity/product'))->toBe($body)
        ->and($rate_limit['calls'])->toHaveCount(1)
        ->and($rate_limit['issues'])->toHaveCount(1);
})->with(array('WooMS\\request', 'wooms_request'))->with(array(200, 401, 403, 500, 503));

it('does not replay document writes even when a caller used GET with a body', function (string $client, string $method) use (&$rate_limit): void {
    $response = $rate_limit['response']();
    $rate_limit['responses'] = array($response);

    expect($client('entity/customerorder', array('description' => 'fixture-write'), $method))
        ->toBe(json_decode($response['body'], true))
        ->and($rate_limit['calls'])->toHaveCount(1);
    expect($rate_limit['calls'][0]['args']['method'])->toBe('GET' === $method ? 'POST' : $method);
})->with(array('WooMS\\request', 'wooms_request'))->with(array('POST', 'PUT', 'PATCH', 'DELETE', 'GET'));

it('never retries earlier than a server delay exceeding its budget', function (string $client, array $headers) use (&$rate_limit): void {
    $response = $rate_limit['response'](429, $headers);
    $rate_limit['responses'] = array($response);

    expect($client('entity/product'))->toBe(json_decode($response['body'], true))
        ->and($rate_limit['calls'])->toHaveCount(1)
        ->and(array_column($rate_limit['issues'], 'code'))->toBe(array('api_request_failed'));
})->with(array('WooMS\\request', 'wooms_request'))->with(array(
    'provider retry milliseconds' => array(array('x-lognex-retry-after' => '20000')),
    'provider reset milliseconds' => array(array('x-lognex-reset' => '20000')),
    'too little time for the transport' => array(array('x-lognex-retry-after' => '9500')),
    'standard seconds' => array(array('retry-after' => '20')),
    'largest provider hint' => array(array('x-lognex-retry-after' => '100', 'x-lognex-reset' => '20000')),
    'largest standard hint' => array(array('x-lognex-retry-after' => '100', 'retry-after' => '20')),
    'duplicate standard header' => array(array('retry-after' => array('0', '20'))),
    'very large integer' => array(array('x-lognex-retry-after' => str_repeat('9', 100))),
    'duplicate provider header' => array(array('x-lognex-retry-after' => array('100', '20000'))),
));

it('reads duplicate delays from the native WordPress response header adapter', function () use (&$rate_limit): void {
    $native = new WpOrg\Requests\Response();
    $native->headers = new WpOrg\Requests\Response\Headers();
    $native->headers['X-Lognex-Retry-After'] = '100';
    $native->headers['X-Lognex-Retry-After'] = '20000';
    $response = $rate_limit['response']();
    $response['headers'] = (new WP_HTTP_Requests_Response($native))->get_headers();
    $rate_limit['responses'] = array($response);

    expect(WooMS\request('entity/product'))->toBe(json_decode($response['body'], true))
        ->and($rate_limit['calls'])->toHaveCount(1);
});

it('understands an HTTP date and does not interpret it as a short delay', function () use (&$rate_limit): void {
    $response = $rate_limit['response'](429, array('retry-after' => gmdate('D, d M Y H:i:s', time() + 60) . ' GMT'));
    $rate_limit['responses'] = array($response);

    expect(WooMS\request('entity/product'))->toBe(json_decode($response['body'], true))
        ->and($rate_limit['calls'])->toHaveCount(1);
});

it('parses an HTTP date without deprecated PHP features', function () use (&$rate_limit): void {
    $response = $rate_limit['response'](429, array('retry-after' => gmdate('D, d M Y H:i:s', time() + 60) . ' GMT'));
    $rate_limit['responses'] = array($response);
    $previous_handler = null;
    $previous_handler = set_error_handler(static function ($severity, $message, $file, $line) use (&$previous_handler) {
        if (E_DEPRECATED === $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
        return is_callable($previous_handler) ? $previous_handler($severity, $message, $file, $line) : false;
    });
    try {
        expect(WooMS\request('entity/product'))->toBe(json_decode($response['body'], true))
            ->and($rate_limit['calls'])->toHaveCount(1);
    } finally {
        restore_error_handler();
    }
});

it('uses the longest delay hint and records milliseconds without emitting an alert', function () use (&$rate_limit): void {
    $rate_limit['responses'] = array(
        $rate_limit['response'](429, array('x-lognex-retry-after' => '100', 'x-lognex-reset' => '200')),
        $rate_limit['response'](200, array(), array('rows' => array())),
    );

    expect(WooMS\request('entity/product'))->toBe(array('rows' => array()));
    expect($rate_limit['calls'][1]['at'] - $rate_limit['calls'][0]['at'])->toBeGreaterThanOrEqual(0.2);
    expect($rate_limit['logger']->records[0]['context']['retry_delay_ms'])->toBe(200)
        ->and($rate_limit['logger']->records[0]['context']['attempt'])->toBe(1)
        ->and($rate_limit['issues'])->toBeEmpty();
});

it('falls back to a full three-second window for absent or malformed delay hints', function (array $headers) use (&$rate_limit): void {
    $rate_limit['responses'] = array(
        $rate_limit['response'](429, $headers), $rate_limit['response'](200, array(), array('rows' => array())),
    );

    expect(WooMS\request('entity/product'))->toBe(array('rows' => array()));
    expect($rate_limit['calls'][1]['at'] - $rate_limit['calls'][0]['at'])->toBeGreaterThanOrEqual(3.0)
        ->and($rate_limit['logger']->records[0]['context']['retry_delay_ms'])->toBe(3000);
})->with(array(
    'missing' => array(array()),
    'invalid' => array(array('x-lognex-retry-after' => '-1', 'x-lognex-reset' => 'invalid', 'retry-after' => '0.1')),
    'invalid date with null byte' => array(array('retry-after' => "Wed, 01 Jan\0 2031 00:00:00 GMT")),
));

it('interprets standard Retry-After as seconds rather than milliseconds', function () use (&$rate_limit): void {
    $rate_limit['responses'] = array(
        $rate_limit['response'](429, array('retry-after' => '1')),
        $rate_limit['response'](200, array(), array('rows' => array())),
    );

    expect(WooMS\request('entity/product'))->toBe(array('rows' => array()));
    expect($rate_limit['calls'][1]['at'] - $rate_limit['calls'][0]['at'])->toBeGreaterThanOrEqual(1.0)
        ->and($rate_limit['logger']->records[0]['context']['retry_delay_ms'])->toBe(1000);
});

it('preserves a recovered HEAD response and correlation with the current scheduler action', function () use (&$rate_limit): void {
    $response = $rate_limit['response'](204, array(), array());
    $rate_limit['responses'] = array($rate_limit['response'](), $response);
    IntegrationDiagnostics::track_action(987654);
    try {
        expect(WooMS\MoySkladApiRequest::request('https://api.moysklad.ru/api/remap/1.2/entity/product', array('method' => 'HEAD', 'timeout' => 45)))
            ->toBe($response)->and($rate_limit['calls'])->toHaveCount(2);
        foreach ($rate_limit['logger']->records as $record) {
            expect($record['context']['action_id'])->toBe(987654);
        }
    } finally {
        IntegrationDiagnostics::clear_action(987654);
    }
});

it('preserves the recovered result when the diagnostic logger throws', function () use (&$rate_limit): void {
    $rate_limit['responses'] = array($rate_limit['response'](), $rate_limit['response'](200, array(), array('rows' => array())));
    $rate_limit['logger']->fail = true;
    $path = tempnam(sys_get_temp_dir(), 'wooms-rate-limit-log-');
    $original_log = ini_get('error_log');
    ini_set('error_log', $path);
    try {
        expect(WooMS\request('entity/product?filter=private-query'))->toBe(array('rows' => array()))
            ->and($rate_limit['calls'])->toHaveCount(2)->and($rate_limit['issues'])->toBeEmpty();
        expect(file_get_contents($path))->not->toContain('private-query', 'private-logger-error', 'private-provider-body');
    } finally {
        ini_set('error_log', $original_log);
        unlink($path);
    }
});

it('does not restart its retry budget after another rate-limit response', function () use (&$rate_limit): void {
    $first = $rate_limit['response'](429, array('x-lognex-retry-after' => '6000'));
    $last = $rate_limit['response'](429, array('x-lognex-retry-after' => '6000'), array('errors' => array(array('code' => 1049, 'error' => 'budget-exhausted'))));
    $rate_limit['responses'] = array($first, $last);

    expect(WooMS\request('entity/product'))->toBe(json_decode($last['body'], true))
        ->and($rate_limit['calls'])->toHaveCount(2)
        ->and(array_column($rate_limit['issues'], 'code'))->toBe(array('api_request_failed'));
});
