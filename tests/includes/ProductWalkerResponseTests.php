<?php

declare(strict_types=1);

$walker_response = array();

beforeEach(function () use (&$walker_response): void {
    global $wpdb, $wp_filter;

    $walker_response = array(
        'responses' => array(), 'calls' => array(), 'scheduled' => array(),
        'finished' => 0, 'recounted' => 0, 'iterations' => array(), 'batches' => 0,
        'options' => array(), 'hooks' => array(),
    );
    $missing = new stdClass();
    foreach (array('wooms_products_walker_state', 'wooms_product_hiding_disable', 'wooms_logger_enable') as $name) {
        $value = get_option($name, $missing);
        $walker_response['options'][$name] = array('exists' => $value !== $missing, 'value' => $value);
    }
    $walker_response['original_logger'] = wc_get_logger();
    $walker_response['logger'] = new class extends WC_Logger {
        public function log($level, $message, $context = array()) {}
    };
    // Finish also cancels pending pagination actions; rollback preserves the pre-test queue.
    $wpdb->query('START TRANSACTION');
    foreach (array(
        'wooms_main_walker_finish', 'wooms_recount_terms', 'wooms_logger', 'wooms_logger_error',
        'wooms_integration_issue', 'wooms_walker_start_iteration', 'wooms_products_batch_end',
        'wooms_product_data', 'wooms_skip_product_import', 'wooms_add_product',
        'wooms_get_product_id', 'wooms_url_get_products', 'wooms_url_get_products_filters',
    ) as $hook) {
        $walker_response['hooks'][$hook] = isset($wp_filter[$hook]) ? clone $wp_filter[$hook] : null;
        unset($wp_filter[$hook]);
    }
    $walker_response['args'] = array(
        'session_id' => 'walker-response-' . wp_generate_uuid4(),
        'query_arg' => array('offset' => 100, 'limit' => 3), 'rows_in_bunch' => 100,
    );
    $walker_response['state'] = $walker_response['args'] + array('finish' => 'unchanged', 'end_timestamp' => 123);
    update_option('wooms_products_walker_state', $walker_response['state']);
    update_option('wooms_product_hiding_disable', 0);
    update_option('wooms_logger_enable', 0);
    $walker_response['pending_id'] = as_schedule_single_action(
        time() + HOUR_IN_SECONDS, WooMS\Products\HOOK_NAME,
        array($walker_response['args']), 'WooMS'
    );
    $walker_response['response'] = static function (string $body, int $status = 200): array {
        return array(
            'headers' => array('x-lognex-retry-after' => '100'), 'body' => $body,
            'response' => array('code' => $status, 'message' => ''), 'cookies' => array(), 'filename' => null,
        );
    };
    $walker_response['http_filter'] = static function ($preempt, $args, $url) use (&$walker_response) {
        $walker_response['calls'][] = compact('args', 'url');
        return array_shift($walker_response['responses']) ?? new WP_Error('unexpected_walker_fixture_request');
    };
    $walker_response['schedule_filter'] = static function ($preempt, $timestamp, $hook, $args, $group) use (&$walker_response) {
        if (!in_array($hook, array(WooMS\Products\HOOK_NAME, WooMS\ProductsHider\HOOK_NAME), true)) {
            return $preempt;
        }
        $walker_response['scheduled'][] = compact('timestamp', 'hook', 'args', 'group');
        return 987654;
    };
    $walker_response['logger_filter'] = static function () use (&$walker_response) { return $walker_response['logger']; };
    add_filter('pre_http_request', $walker_response['http_filter'], PHP_INT_MAX, 3);
    add_filter('pre_as_schedule_single_action', $walker_response['schedule_filter'], PHP_INT_MAX, 5);
    add_filter('woocommerce_logging_class', $walker_response['logger_filter'], PHP_INT_MAX);
    add_action('wooms_main_walker_finish', static function () use (&$walker_response): void { $walker_response['finished']++; });
    add_action('wooms_main_walker_finish', 'WooMS\\ProductsHider\\add_task_for_hide');
    add_action('wooms_recount_terms', static function () use (&$walker_response): void { $walker_response['recounted']++; });
    add_action('wooms_walker_start_iteration', static function ($data) use (&$walker_response): void { $walker_response['iterations'][] = $data; });
    add_action('wooms_products_batch_end', static function () use (&$walker_response): void { $walker_response['batches']++; });
    expect($walker_response['pending_id'])->toBeGreaterThan(0);
});

afterEach(function () use (&$walker_response): void {
    global $wpdb, $wp_filter;

    remove_filter('pre_http_request', $walker_response['http_filter'], PHP_INT_MAX);
    remove_filter('pre_as_schedule_single_action', $walker_response['schedule_filter'], PHP_INT_MAX);
    remove_filter('woocommerce_logging_class', $walker_response['logger_filter'], PHP_INT_MAX);
    $restore_logger = static function () use (&$walker_response) { return $walker_response['original_logger']; };
    add_filter('woocommerce_logging_class', $restore_logger, PHP_INT_MAX);
    wc_get_logger();
    remove_filter('woocommerce_logging_class', $restore_logger, PHP_INT_MAX);
    foreach ($walker_response['hooks'] as $hook => $original) {
        if (null === $original) { unset($wp_filter[$hook]); }
        else { $wp_filter[$hook] = $original; }
    }
    foreach ($walker_response['options'] as $name => $original) {
        if ($original['exists']) { update_option($name, $original['value']); }
        else { delete_option($name); }
    }
    $wpdb->query('ROLLBACK');
});

it('rejects an unusable product page after a rate-limit retry without finishing or hiding', function (string $failure) use (&$walker_response): void {
    $last_response = 'transport' === $failure
        ? new WP_Error('http_request_failed', 'Synthetic transport failure')
        : $walker_response['response']($failure);
    $walker_response['responses'] = array(
        $walker_response['response']('{"errors":[{"code":1049}]}', 429), $last_response,
    );
    $exception = null;
    try {
        WooMS\Products\walker($walker_response['args']);
    } catch (Throwable $error) {
        $exception = $error;
    }

    expect($walker_response['calls'])->toHaveCount(2)
        ->and($walker_response['finished'])->toBe(0)
        ->and($walker_response['recounted'])->toBe(0)
        ->and($walker_response['scheduled'])->toBeEmpty()
        ->and($walker_response['iterations'])->toBeEmpty()
        ->and($walker_response['batches'])->toBe(0)
        ->and(WooMS\Products\get_state())->toBe($walker_response['state'])
        ->and(ActionScheduler::store()->get_status($walker_response['pending_id']))->toBe(ActionScheduler_Store::STATUS_PENDING)
        ->and($exception)->toBeInstanceOf(Exception::class);
})->with(array(
    'transport failure' => 'transport',
    'invalid JSON' => 'not-json',
    'null JSON' => 'null',
    'false JSON' => 'false',
    'integer JSON' => '42',
    'string JSON' => '"unexpected"',
    'missing rows' => '{}',
    'null rows' => '{"rows":null}',
    'empty string rows' => '{"rows":""}',
    'nonempty string rows' => '{"rows":"unexpected"}',
    'integer rows' => '{"rows":42}',
    'false rows' => '{"rows":false}',
));

it('preserves explicit API errors without completing the product walker', function () use (&$walker_response): void {
    $walker_response['responses'] = array(
        $walker_response['response']('{"errors":[{"code":1049}]}', 429),
        $walker_response['response']('{"errors":[{"code":1056}],"rows":[]}'),
    );
    $exception = null;
    try {
        WooMS\Products\walker($walker_response['args']);
    } catch (Throwable $error) {
        $exception = $error;
    }

    expect($exception)->toBeInstanceOf(Exception::class)
        ->and($exception->getMessage())->toContain('1056')
        ->and($walker_response['calls'])->toHaveCount(2)
        ->and($walker_response['finished'])->toBe(0)
        ->and($walker_response['recounted'])->toBe(0)
        ->and($walker_response['scheduled'])->toBeEmpty()
        ->and(WooMS\Products\get_state())->toBe($walker_response['state'])
        ->and(ActionScheduler::store()->get_status($walker_response['pending_id']))->toBe(ActionScheduler_Store::STATUS_PENDING);
});

it('finishes an explicitly empty page and schedules the native hider only when enabled', function (bool $hiding_enabled) use (&$walker_response): void {
    update_option('wooms_product_hiding_disable', $hiding_enabled ? 0 : 1);
    $walker_response['responses'] = array($walker_response['response']('{"rows":[]}'));

    expect(WooMS\Products\walker($walker_response['args']))->toBe(array('result' => 'finish'))
        ->and($walker_response['finished'])->toBe(1)
        ->and($walker_response['recounted'])->toBe(1)
        ->and($walker_response['iterations'])->toBeEmpty()
        ->and($walker_response['batches'])->toBe(0)
        ->and(WooMS\Products\get_state('end_timestamp'))->toBeGreaterThan(123)
        ->and(ActionScheduler::store()->get_status($walker_response['pending_id']))->toBe(ActionScheduler_Store::STATUS_CANCELED);
    expect(array_column($walker_response['scheduled'], 'hook'))
        ->toBe($hiding_enabled ? array(WooMS\ProductsHider\HOOK_NAME) : array());
    if ($hiding_enabled) {
        expect($walker_response['scheduled'][0]['args'])->toBe(array())
            ->and($walker_response['scheduled'][0]['group'])->toBe('WooMS');
    }
})->with(array('enabled' => true, 'disabled' => false));

it('processes a valid page while preserving skip and variant behavior and advancing pagination', function () use (&$walker_response): void {
    $prefix = wp_generate_uuid4();
    $rows = array(
        array('id' => $prefix . '-skip', 'meta' => array('type' => 'product')),
        array('id' => $prefix . '-variant', 'meta' => array('type' => 'variant')),
        array('id' => $prefix . '-product', 'meta' => array('type' => 'product')),
    );
    $seen = array();
    $processed = array();
    $add_attempts = array();
    add_filter('wooms_skip_product_import', static function ($skip, $row) use (&$seen, $rows) {
        $seen[] = $row['id'];
        return $row['id'] === $rows[0]['id'];
    }, 10, 2);
    add_filter('wooms_product_data', static function ($data, $row) use (&$processed) {
        $processed[] = $row['id'];
        return $data;
    }, 10, 2);
    // Stop at the existing creation filter, before any product writes or product-update hooks.
    add_filter('wooms_add_product', static function ($allow, $row) use (&$add_attempts) {
        $add_attempts[] = $row['id'];
        return false;
    }, 10, 2);
    $walker_response['responses'] = array($walker_response['response'](wp_json_encode(array('rows' => $rows))));
    $next = $walker_response['args'];
    $next['query_arg']['offset'] += 3;
    $next['rows_in_bunch'] += 3;

    expect(WooMS\Products\walker($walker_response['args']))->toBe(array('result' => 'restart', 'args_next_iteration' => $next))
        ->and($seen)->toBe(array_column($rows, 'id'))
        ->and($processed)->toBe(array($rows[2]['id']))
        ->and($add_attempts)->toBe(array($rows[2]['id']))
        ->and($walker_response['calls'])->toHaveCount(1)
        ->and($walker_response['finished'])->toBe(0)
        ->and($walker_response['recounted'])->toBe(0)
        ->and($walker_response['iterations'])->toBe(array(array('rows' => $rows)))
        ->and($walker_response['batches'])->toBe(1)
        ->and($walker_response['scheduled'])->toHaveCount(1)
        ->and($walker_response['scheduled'][0]['hook'])->toBe(WooMS\Products\HOOK_NAME)
        ->and($walker_response['scheduled'][0]['args'])->toBe(array($next))
        ->and($walker_response['scheduled'][0]['group'])->toBe('WooMS')
        ->and(WooMS\Products\get_state())->toBe($walker_response['state'])
        ->and(ActionScheduler::store()->get_status($walker_response['pending_id']))->toBe(ActionScheduler_Store::STATUS_PENDING);
});
