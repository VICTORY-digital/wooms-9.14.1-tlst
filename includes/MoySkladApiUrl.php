<?php
/**
 * Validation and normalization of MoySklad API URLs.
 *
 * @package WooMS
 */

namespace WooMS;

defined('ABSPATH') || exit;

/**
 * Keeps authenticated requests on the trusted MoySklad API endpoint.
 */
final class MoySkladApiUrl
{
    const API_ORIGIN = 'https://api.moysklad.ru';
    const API_PATH   = '/api/remap/1.2/';

    /**
     * Hosts returned by supported MoySklad API versions.
     *
     * The legacy host is always rewritten to API_ORIGIN, so credentials are
     * never sent to it and no redirect needs to be followed.
     *
     * @var string[]
     */
    private static $allowed_hosts = array(
        'api.moysklad.ru',
        'online.moysklad.ru',
    );

    /**
     * Normalize a relative API path or a trusted absolute MoySklad API URL.
     *
     * @param mixed $target Relative API path or absolute API URL.
     *
     * @return string|false Canonical absolute URL, or false when unsafe.
     */
    public static function normalize($target)
    {
        if (! is_string($target) || '' === $target) {
            return false;
        }

        if ($target !== trim($target) || self::contains_unsafe_characters($target)) {
            return false;
        }

        $parts = wp_parse_url($target);
        if (false === $parts) {
            return false;
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            return self::normalize_absolute_url($parts);
        }

        if (0 === strpos($target, '//') || 0 === strpos($target, '/')) {
            return false;
        }

        $path = isset($parts['path']) ? $parts['path'] : '';
        if ('' === $path || self::contains_path_traversal($path)) {
            return false;
        }

        return self::API_ORIGIN.self::API_PATH.$target;
    }

    /**
     * Build a trusted relative path for a customer order webhook event.
     *
     * @param mixed $href Webhook event meta.href value.
     *
     * @return string|false Relative API path, or false when invalid.
     */
    public static function get_customer_order_path($href)
    {
        if (! is_string($href)) {
            return false;
        }

        $original_parts = wp_parse_url($href);
        if (
            false === $original_parts
            || empty($original_parts['scheme'])
            || empty($original_parts['host'])
            || isset($original_parts['query'])
            || isset($original_parts['fragment'])
        ) {
            return false;
        }

        $url = self::normalize($href);
        if (false === $url) {
            return false;
        }

        $parts = wp_parse_url($url);
        $path  = isset($parts['path']) ? $parts['path'] : '';
        $regex = '#^'.preg_quote(self::API_PATH, '#').'entity/customerorder/'
            .'([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/?$#i';

        if (! preg_match($regex, $path, $matches)) {
            return false;
        }

        return 'entity/customerorder/'.strtolower($matches[1]);
    }

    /**
     * Normalize a parsed absolute API URL.
     *
     * @param array $parts Parsed URL components.
     *
     * @return string|false
     */
    private static function normalize_absolute_url($parts)
    {
        if (
            empty($parts['scheme'])
            || 'https' !== strtolower($parts['scheme'])
            || empty($parts['host'])
            || ! in_array(strtolower($parts['host']), self::$allowed_hosts, true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && 443 !== (int) $parts['port'])
            || isset($parts['fragment'])
        ) {
            return false;
        }

        $path = isset($parts['path']) ? $parts['path'] : '';
        if ('/api/remap/1.2' === $path) {
            $path = self::API_PATH;
        }

        if (0 !== strpos($path, self::API_PATH) || self::contains_path_traversal($path)) {
            return false;
        }

        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return self::API_ORIGIN.$path.$query;
    }

    /**
     * Check for characters with ambiguous URL parser behavior.
     *
     * @param string $target URL or path.
     *
     * @return bool
     */
    private static function contains_unsafe_characters($target)
    {
        return false !== strpos($target, '\\')
            || 1 === preg_match('/[\x00-\x20\x7f]/', $target)
            || 1 === preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $target);
    }

    /**
     * Reject literal and encoded dot segments in the API path.
     *
     * @param string $path URL path.
     *
     * @return bool
     */
    private static function contains_path_traversal($path)
    {
        for ($iteration = 0; $iteration < 3; $iteration++) {
            if (1 === preg_match('#(?:^|/)(?:\.|\.\.)(?:/|$)#', $path)) {
                return true;
            }

            $decoded = rawurldecode($path);
            if ($decoded === $path) {
                break;
            }

            $path = $decoded;
        }

        return 1 === preg_match('#(?:^|/)(?:\.|\.\.)(?:/|$)#', $path);
    }
}
