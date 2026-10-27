<?php

namespace WooMS;

defined('ABSPATH') || exit;

/** Shared transport policy; callers retain their existing decoding and failure handling. */
final class MoySkladApiRequest
{
    const MAX_ATTEMPTS = 3;
    const RETRY_BUDGET_SECONDS = 10;
    const DEFAULT_DELAY_MS = 3000;
    const MIN_DELAY_MS = 100;
    const MIN_RETRY_TIMEOUT_SECONDS = 1;

    /**
     * Retry explicit rate-limit rejections of reads only. Never replay document writes or transport failures.
     * The retry budget starts after the first response; the original request keeps its original timeout.
     *
     * @param string $url Canonical URL already validated by MoySkladApiUrl.
     * @param array $args WordPress HTTP arguments, including the caller's secure transport options.
     * @return array|\WP_Error The final unmodified HTTP response.
     */
    public static function request($url, array $args)
    {
        $response = wp_remote_request($url, $args);
        if (!in_array(strtoupper($args['method'] ?? 'GET'), array('GET', 'HEAD'), true)) {
            return $response;
        }

        $deadline = microtime(true) + self::RETRY_BUDGET_SECONDS;
        for ($attempt = 1; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            if (is_wp_error($response) || 429 !== (int) wp_remote_retrieve_response_code($response)) {
                return $response;
            }

            $delay_ms = self::retry_delay_ms($response);
            // Requests' cURL transport rounds shorter timeouts up to one second.
            if ($delay_ms / 1000 + self::MIN_RETRY_TIMEOUT_SECONDS >= $deadline - microtime(true)) {
                return $response;
            }

            IntegrationDiagnostics::record_api_retry($url, $attempt, (int) ceil($delay_ms));
            if ($delay_ms / 1000 + self::MIN_RETRY_TIMEOUT_SECONDS >= $deadline - microtime(true)) {
                return $response;
            }
            usleep((int) ceil($delay_ms * 1000));
            $remaining = $deadline - microtime(true);
            if ($remaining < self::MIN_RETRY_TIMEOUT_SECONDS) {
                return $response;
            }

            $retry_args = $args;
            $retry_args['timeout'] = min((float) ($args['timeout'] ?? 5), $remaining);
            $response = wp_remote_request($url, $retry_args);
            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status >= 200 && $status < 300) {
                IntegrationDiagnostics::record_api_recovered($url, $attempt, $status);
            }
        }

        return $response;
    }

    /** Lognex durations are relative milliseconds; standard Retry-After uses seconds or an HTTP date. */
    private static function retry_delay_ms($response)
    {
        $delay_ms = 0.0;
        foreach (array('x-lognex-retry-after', 'x-lognex-reset', 'retry-after') as $header) {
            foreach ((array) wp_remote_retrieve_header($response, $header) as $value) {
                if (!is_string($value)) {
                    continue;
                }
                $value = trim($value);
                if ('retry-after' === $header) {
                    $delay = ctype_digit($value) ? (float) $value * 1000 : self::http_date_delay_ms($value);
                } else {
                    $delay = preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $value) ? (float) $value : 0;
                }
                // Respect the longest valid hint, including duplicate headers. Oversized values stop retries.
                $delay_ms = max($delay_ms, $delay);
            }
        }

        return $delay_ms > 0 ? max(self::MIN_DELAY_MS, $delay_ms) : self::DEFAULT_DELAY_MS;
    }

    private static function http_date_delay_ms($value)
    {
        if (!preg_match('/^[\x20-\x7e]{20,40}$/D', $value)) {
            return 0;
        }
        foreach (array('D, d M Y H:i:s \G\M\T', 'l, d-M-y H:i:s \G\M\T', 'D M j H:i:s Y', 'D M  j H:i:s Y') as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, new \DateTimeZone('GMT'));
            if (false !== $date && $date->format($format) === $value) {
                return max(0, $date->getTimestamp() - microtime(true)) * 1000;
            }
        }
        return 0;
    }
}
