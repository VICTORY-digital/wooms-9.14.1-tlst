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
        if (!is_int($attempt) || $attempt < 0) {
            return new \WP_Error('wooms_webhook_invalid_attempt', 'Invalid webhook retry attempt.');
        }
        if ($attempt >= count(self::$retry_delays)) {
            return new \WP_Error('wooms_webhook_retries_exhausted', 'Webhook retry limit reached.');
        }

        $next_attempt = $attempt + 1;
        $action_id = 0;
        $error_message = 'Could not persist webhook retry.';
        try {
            $action_id = as_schedule_single_action(
                time() + self::$retry_delays[$attempt],
                self::HOOK_NAME,
                array(
                    'order_id' => $order_id,
                    'order_uuid' => $order_uuid,
                    'request_id' => $request_id,
                    'attempt' => $next_attempt,
                ),
                'WooMS'
            );
        } catch (\Throwable $exception) {
            $error_message .= ' ' . $exception->getMessage();
        }

        if (!is_int($action_id) || $action_id <= 0) {
            return new \WP_Error(
                'wooms_webhook_retry_queue_failed',
                $error_message,
                array('request_id' => $request_id, 'attempt' => $next_attempt)
            );
        }

        return $action_id;
    }
}
