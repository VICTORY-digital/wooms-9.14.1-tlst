<?php

namespace WooMS;

defined('ABSPATH') || exit;

/**
 * Bounded retry scheduling for background order webhooks.
 *
 * @internal
 */
final class OrderWebhookRetries
{
    const HOOK_NAME = 'wooms_process_order_webhook';
    const BATCH_HOOK_NAME = 'wooms_dispatch_order_webhook_batch';
    const ORDER_UPDATE_HOOK = 'wooms_order_update_from_moysklad';

    /** @var int[] Delays before the first, second, and third retry, in seconds. */
    private static $retry_delays = array(30, 120, 300);

    /**
     * Persist the next retry after a failed background attempt.
     *
     * The initial attempt is numbered zero. Scheduling never executes the job.
     * The caller must retain the failed source action and report any WP_Error.
     *
     * @param int $order_id Local order ID.
     * @param string $order_uuid Validated MoySklad order UUID.
     * @param string $request_id Provider notification ID.
     * @param int $attempt Number of the attempt that just failed.
     * @return int|\WP_Error Persisted action ID, or an explicit scheduling/limit error.
     */
    public static function schedule_retry($order_id, $order_uuid, $request_id, $attempt = 0)
    {
        return self::schedule_action_retry(self::HOOK_NAME, array(
            'order_id' => $order_id,
            'order_uuid' => $order_uuid,
            'request_id' => $request_id,
        ), $attempt);
    }

    /**
     * Retry only the part of a notification not yet accepted by the child queue.
     *
     * @param string $request_id Provider notification ID.
     * @param string[] $order_uuids Remaining validated MoySklad order UUIDs.
     * @param int $attempt Number of the dispatcher attempt that just failed.
     * @return int|\WP_Error
     */
    public static function schedule_batch_retry($request_id, array $order_uuids, $attempt = 0)
    {
        return self::schedule_action_retry(self::BATCH_HOOK_NAME, array(
            'request_id' => $request_id,
            'order_uuids' => array_values($order_uuids),
        ), $attempt);
    }

    /**
     * Retry the final legacy order update callback.
     *
     * @param array $context Final update context.
     * @param int $attempt Number of the final update attempt that just failed.
     * @return int|\WP_Error
     */
    public static function schedule_final_retry(array $context, $attempt = 0)
    {
        return self::schedule_action_retry(self::ORDER_UPDATE_HOOK, array(
            'post_id' => $context['post_id'] ?? null,
            'request_id' => $context['request_id'] ?? null,
            'order_uuid' => $context['order_uuid'] ?? null,
            'generation' => $context['generation'] ?? null,
        ), $attempt, true);
    }

    private static function schedule_action_retry($hook, array $args, $attempt, $wrap_data = false)
    {
        if (!is_int($attempt) || $attempt < 0) {
            return new \WP_Error('wooms_webhook_invalid_attempt', 'Invalid webhook retry attempt.');
        }
        if ($attempt >= count(self::$retry_delays)) {
            return new \WP_Error('wooms_webhook_retries_exhausted', 'Webhook retry limit reached.');
        }

        $next_attempt = $attempt + 1;
        $args['attempt'] = $next_attempt;
        $scheduled_args = $wrap_data ? array('data' => $args) : $args;
        $action_id = 0;
        $error_message = 'Could not persist webhook retry.';
        try {
            $action_id = as_schedule_single_action(
                time() + self::$retry_delays[$attempt],
                $hook,
                $scheduled_args,
                'WooMS'
            );
        } catch (\Throwable $exception) {
            $error_message .= ' ' . $exception->getMessage();
        }

        if (!is_int($action_id) || $action_id <= 0) {
            return new \WP_Error(
                'wooms_webhook_retry_queue_failed',
                $error_message,
                array('request_id' => $args['request_id'], 'attempt' => $next_attempt)
            );
        }

        return $action_id;
    }
}
