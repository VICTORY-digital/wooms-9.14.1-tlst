<?php

namespace WooMS;

defined('ABSPATH') || exit;

/** Safe operational diagnostics, independent of optional verbose WooMS logging. */
final class IntegrationDiagnostics
{
    const LOG_SOURCE = 'wooms-integration';

    private static $reporting = false;
    private static $current_action_id = 0;
    private static $reported_actions = array();
    private static $webhook_hooks = array(
        'wooms_process_order_webhook', 'wooms_dispatch_order_webhook_batch', 'wooms_order_update_from_moysklad',
    );

    public static function init()
    {
        add_action('wooms_logger_error', array(__CLASS__, 'record_webhook_error'), 5, 3);
        add_action('action_scheduler_before_execute', array(__CLASS__, 'track_action'), 0);
        add_action('action_scheduler_after_execute', array(__CLASS__, 'clear_action'), PHP_INT_MAX);
        add_action('action_scheduler_execution_ignored', array(__CLASS__, 'clear_action'), PHP_INT_MAX);
        add_action('action_scheduler_failed_validation', array(__CLASS__, 'clear_action'), PHP_INT_MAX);
        add_action('action_scheduler_canceled_corrupted_action', array(__CLASS__, 'clear_action'), PHP_INT_MAX);
        add_action('action_scheduler_failed_execution', array(__CLASS__, 'record_action_failure'), 5);
        add_action('action_scheduler_failed_action', array(__CLASS__, 'record_action_timeout'), 5, 2);
        add_action('action_scheduler_unexpected_shutdown', array(__CLASS__, 'record_action_shutdown'), 5);
    }

    /** Observe a completed request without changing the caller's response or making another request. */
    public static function record_api_response($url, $response, $data = null)
    {
        $context = self::api_context($url);
        if (is_wp_error($response)) {
            self::report('api_transport_failed', 'МойСклад: не удалось выполнить запрос к API.', $context);
            return;
        }

        $context['http_status'] = (int) wp_remote_retrieve_response_code($response);
        $codes = array();
        foreach (is_array($data['errors'] ?? null) ? $data['errors'] : array() as $error) {
            if (is_numeric($error['code'] ?? null)) { $codes[] = (int) $error['code']; }
            if (count($codes) >= 10) { break; }
        }
        $context['provider_error_codes'] = $codes;
        if (401 === $context['http_status'] || in_array(1056, $codes, true)) {
            self::report('api_authentication_failed', 'МойСклад: API отклонил авторизацию. Проверьте доступы интеграции.', $context);
        } elseif (403 === $context['http_status']) {
            self::report('api_access_denied', 'МойСклад: API отказал в доступе к ресурсу.', $context);
        } elseif ($context['http_status'] >= 400 || !empty($data['errors'])) {
            self::report('api_request_failed', 'МойСклад: запрос к API завершился ошибкой.', $context);
        } elseif (204 !== $context['http_status'] && !is_array($data)) {
            self::report('api_invalid_response', 'МойСклад: API вернул пустой или некорректный JSON-ответ.', $context);
        }
    }

    /** A recoverable rejection belongs in the journal, without sending a premature failure alert. */
    public static function record_api_retry($url, $attempt, $delay_ms)
    {
        self::report('api_rate_limit_retry', 'МойСклад: запрос ограничен по частоте, выполняется повтор после паузы.',
            self::api_context($url) + array('http_status' => 429, 'attempt' => $attempt, 'retry_delay_ms' => $delay_ms),
            'warning', false);
    }

    public static function record_api_recovered($url, $attempt, $status)
    {
        self::report('api_rate_limit_recovered', 'МойСклад: повторный запрос после ограничения частоты выполнен.',
            self::api_context($url) + array('http_status' => $status, 'attempt' => $attempt), 'info', false);
    }

    private static function api_context($url)
    {
        $context = array();
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if (preg_match('#^/api/remap/1\.2/([a-z]+/[a-z]+)(?:/|$)#', $path, $matches)) {
            $context['api_resource'] = $matches[1];
        }
        return $context;
    }

    /** Reuse structured failure decisions already produced by the webhook workers. */
    public static function record_webhook_error($source, $title = '', $context = array())
    {
        if (OrderUpdateFromMoySklad::class !== $source || !is_array($context) || !empty($context['superseded'])) {
            return;
        }
        $phase = $context['phase'] ?? '';
        if (isset($context['retry_error_code'])) {
            $exhausted = 'wooms_webhook_retries_exhausted' === $context['retry_error_code'];
            self::report($exhausted ? 'webhook_retries_exhausted' : 'webhook_retry_queue_failed',
                $exhausted ? 'МойСклад: попытки обработки вебхука исчерпаны.' : 'МойСклад: не удалось поставить повтор вебхука в очередь.',
                $context);
            self::mark_action_reported();
        } elseif (isset($context['retry_action_id'])) {
            self::report('webhook_retry_scheduled', 'МойСклад: обработка вебхука завершилась ошибкой, назначен повтор.', $context, 'warning');
            self::mark_action_reported();
        } elseif (in_array($phase, array('enqueue', 'final_enqueue'), true)) {
            self::report('webhook_queue_failed', 'МойСклад: не удалось сохранить задачу обработки вебхука.', $context);
        } elseif ('release' === $phase) {
            self::report('webhook_lock_failed', 'МойСклад: не удалось освободить блокировку вебхука.', $context);
        } elseif ('http_response' === $phase && ($context['http_status'] ?? 0) >= 500) {
            self::report('webhook_response_failed', 'МойСклад: обработчик вебхука вернул ошибку сервера.', $context);
        } elseif ('http_response' === $phase && !empty($context['slow'])) {
            self::report('webhook_response_slow', 'МойСклад: превышено допустимое PHP-время ответа на вебхук.', $context, 'warning');
        }
    }

    public static function track_action($action_id)
    {
        self::$current_action_id = (int) $action_id;
        unset(self::$reported_actions[(int) $action_id]);
    }

    public static function clear_action($action_id)
    {
        unset(self::$reported_actions[(int) $action_id]);
        if ((int) $action_id === self::$current_action_id) { self::$current_action_id = 0; }
    }

    public static function record_action_failure($action_id)
    {
        try {
            // A worker already logged its precise retry decision for this execution.
            if (!isset(self::$reported_actions[(int) $action_id])) {
                self::record_action_issue($action_id, 'webhook_action_failed', 'МойСклад: фоновая задача завершилась необработанной ошибкой.');
            }
        } finally {
            self::clear_action($action_id);
        }
    }

    public static function record_action_timeout($action_id, $timeout = 0)
    {
        self::record_action_issue($action_id, 'webhook_action_timed_out', 'МойСклад: превышено время выполнения фоновой задачи.', array('timeout_seconds' => $timeout));
    }

    public static function record_action_shutdown($action_id)
    {
        self::record_action_issue($action_id, 'webhook_action_shutdown', 'МойСклад: выполнение фоновой задачи прервано фатальной ошибкой.');
    }

    private static function mark_action_reported()
    {
        if (self::$current_action_id) { self::$reported_actions[self::$current_action_id] = true; }
    }

    private static function record_action_issue($action_id, $code, $message, array $context = array())
    {
        try {
            $action = \ActionScheduler::store()->fetch_action($action_id);
            if ('WooMS' !== $action->get_group() || !in_array($action->get_hook(), self::$webhook_hooks, true)) {
                return;
            }
            $args = $action->get_args();
            $args = isset($args['data']) && is_array($args['data']) ? $args['data'] : $args;
            $context += array('action_id' => (int) $action_id, 'hook' => $action->get_hook());
            self::report($code, $message, $context + $args);
        } catch (\Throwable $exception) {
            // Fetching an unrelated/corrupt action must not disrupt the scheduler's failure handling.
            self::fallback_log('scheduler_diagnostics_failed');
        }
    }

    /** Only approved fields cross the logging/notification boundary; never copy exception text or API bodies. */
    private static function safe_context(array $context)
    {
        $safe = array();
        foreach (array('order_id', 'post_id', 'action_id', 'attempt', 'next_attempt', 'retry_action_id', 'http_status', 'timeout_seconds', 'retry_delay_ms') as $key) {
            if (isset($context[$key]) && is_numeric($context[$key]) && $context[$key] >= 0) { $safe[$key] = (int) $context[$key]; }
        }
        foreach (array('request_id', 'order_uuid', 'generation') as $key) {
            if (isset($context[$key]) && is_string($context[$key]) && preg_match('/^[a-zA-Z0-9._-]{8,128}$/D', $context[$key])) {
                $safe[$key] = $context[$key];
            }
        }
        if (in_array($context['hook'] ?? '', self::$webhook_hooks, true)) { $safe['hook'] = $context['hook']; }
        if (in_array($context['phase'] ?? '', array('processing', 'dispatch', 'final_update', 'enqueue', 'final_enqueue', 'release', 'http_response'), true)) {
            $safe['phase'] = $context['phase'];
        }
        if (isset($context['api_resource']) && is_string($context['api_resource']) && preg_match('#^[a-z]+/[a-z]+$#D', $context['api_resource'])) {
            $safe['api_resource'] = $context['api_resource'];
        }
        if (isset($context['provider_error_codes']) && is_array($context['provider_error_codes'])) {
            $safe['provider_error_codes'] = array_slice(array_values(array_filter($context['provider_error_codes'], 'is_int')), 0, 10);
        }
        if (isset($context['php_duration_ms']) && is_numeric($context['php_duration_ms'])) {
            $safe['php_duration_ms'] = max(0, (float) $context['php_duration_ms']);
        }
        if (in_array($context['retry_error_code'] ?? '', array('wooms_webhook_retries_exhausted', 'wooms_webhook_retry_queue_failed', 'wooms_webhook_invalid_attempt'), true)) {
            $safe['retry_error_code'] = $context['retry_error_code'];
        }
        return $safe;
    }

    private static function report($code, $message, array $context, $level = 'error', $notify = true)
    {
        if (self::$reporting) { return; }
        self::$reporting = true;
        try {
            $issue = array('event_id' => wp_generate_uuid4(), 'code' => $code, 'message' => $message, 'level' => $level,
                'occurred_at' => gmdate('c'), 'context' => self::safe_context($context), 'log_source' => self::LOG_SOURCE,
                'log_url' => add_query_arg(array('page' => 'wc-status', 'tab' => 'logs', 'source' => self::LOG_SOURCE), admin_url('admin.php')));
            if (self::$current_action_id && !isset($issue['context']['action_id'])) { $issue['context']['action_id'] = self::$current_action_id; }
            try {
                wc_get_logger()->log($level, $message, array('source' => self::LOG_SOURCE, 'event_id' => $issue['event_id'], 'code' => $code) + $issue['context']);
            } catch (\Throwable $exception) {
                self::fallback_log(wp_json_encode($issue));
            }
            if (!$notify) { return; }
            /**
             * Safe incident for notification adapters. Enqueue delivery here; do not block the provider's request.
             *
             * @param array $issue Event ID, stable code, safe message/context and WooCommerce admin log URL.
             *
             * @since 9.15
             */
            try {
                do_action('wooms_integration_issue', $issue);
            } catch (\Throwable $exception) {
                self::fallback_log('integration_issue_listener_failed ' . $issue['event_id']);
            }
        } catch (\Throwable $exception) {
            self::fallback_log('integration_diagnostics_failed ' . $code);
        } finally {
            self::$reporting = false;
        }
    }

    private static function fallback_log($message)
    {
        try {
            error_log('WooMS integration diagnostics: ' . $message);
        } catch (\Throwable $exception) {
            // Both diagnostic sinks failed; preserve the original business operation.
        }
    }
}

IntegrationDiagnostics::init();
