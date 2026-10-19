<?php

namespace WooMS;

use Exception;

use function WooMS\request;


/**
 * Send statuses from moysklad.ru to WooCommerce
 */
class OrderUpdateFromMoySklad
{

    /**
     * этот хук является ключем для ActionSheduler и для метаполя, по которому определяются заказы для обновления.
     */
    public static $hook_order_update_from_moysklad = 'wooms_order_update_from_moysklad';
    public static $hook_process_webhook = 'wooms_process_order_webhook';
    public static $state_key = 'wooms_order_update_from_moysklad_state';
    private static $webhook_secret_option = 'wooms_webhook_secret';
    private static $webhook_replay_prefix = 'wooms_webhook_replay_';
    private static $webhook_rate_prefix = 'wooms_webhook_rate_';

    /**
     * The init
     */
    public static function init()
    {
        // add_action('init', function () {
        //     if (!isset($_GET['dd'])) {
        //         return;
        //     }

        //     self::update_order_from_moysklad(23690);

        //     die("ddd");
        // });

        add_action('wooms_order_update_from_moysklad', function ($args) {
            if (empty($args['post_id'])) {
                return;
            }
            self::update_order_from_moysklad($args['post_id']);
            delete_post_meta($args['post_id'], self::$hook_order_update_from_moysklad);
        });

        add_action('rest_api_init', array(__CLASS__, 'add_route'));
        add_action(self::$hook_process_webhook, array(__CLASS__, 'process_order_webhook'), 10, 4);

        add_action('init', array(__CLASS__, 'add_schedule_hook'));

        add_action('wooms_check_orders_for_sync_from_moysklad', array(__CLASS__, 'batch_handler'));


        add_action('admin_init', array(__CLASS__, 'add_settings'), 100);

        add_filter('wooms_update_order_from_moysklad', array(__CLASS__, 'update_order_status'), 11, 2);
        add_filter('wooms_update_order_from_moysklad', array(__CLASS__, 'update_order_items'), 15, 2);

        add_filter('wooms_skip_order_update', array(__CLASS__, 'skip_order_update_from_site'), 10, 2);

        add_action('save_post_shop_order', array(__CLASS__, 'lock_callback_update'));
    }


    public static function lock_callback_update($order_id)
    {

        //если стоит задача на апдейт из МойСклад, то пропускаем
        if (get_post_meta($order_id, self::$hook_order_update_from_moysklad, true)) {
            return;
        }

        update_post_meta($order_id, 'lock_callback_update', 1);
    }


    /**
     * update_order_status
     *
     * use hook wooms_update_order_from_moysklad
     *
     * @param \WC_Order $order
     *
     * @return \WC_Order - order object
     */
    public static function update_order_status($order, $data_api)
    {
        if (!self::is_enable()) {
            return $order;
        }

        $state_url  = $data_api["state"]["meta"]["href"];
        $state_data = request($state_url);
        if (empty($state_data['name'])) {
            return $order;
        }

        $state_name = $state_data['name'];

        $statuses_match_default = array(
            'wc-pending' => 'Новый',
            'wc-processing' => 'Подтвержден',
            'wc-on-hold' => 'Новый',
            'wc-completed' => 'Отгружен',
            'wc-cancelled' => 'Отменен',
            'wc-refunded' => 'Возврат',
            'wc-failed' => 'Не удался',
        );

        $statuses_match = get_option('wooms_order_statuses_from_moysklad', $statuses_match_default);

        foreach ($statuses_match as $status_key => $status_name) {

            if ($status_name == $state_name && $order->get_status() != $status_key) {
                $order->set_status($status_key, sprintf('Выбран статус через МойСклад: "%s"', $status_name));
            }
        }

        return $order;
    }

    /**
     * update_order_items
     *
     * @param \WC_Order $order
     *
     * @return \WC_Order - order object
     */
    public static function update_order_items($order, $data_api)
    {

        if (empty($data_api['positions']['meta']['href'])) {
            return $order;
        }

        $url_api = $data_api['positions']['meta']['href'];

        $data = request($url_api);


        $shipment_product_href = self::get_shipment_product_href();

        // remove shipment if exist from data api
        foreach ($data['rows'] as $key => $row) {
            if ($shipment_product_href == $row['assortment']['meta']['href']) {
                unset($data['rows'][$key]);
            }
        }

        $data_api_wooms_id_array = [];

        foreach ($data['rows'] as $row) {

            $product_href = $row['assortment']['meta']['href'];
            $product_uuid = wooms_get_wooms_id_from_href($product_href);
            $data_api_wooms_id_array[] = $product_uuid;
            $product_id = wooms_get_product_id_by_uuid($product_uuid);

            $product = wc_get_product($product_id);

            if ($item_id = self::get_order_item_id_by_wooms_id($product_uuid, $order)) {
                $line_item = new \WC_Order_Item_Product($item_id);
            } else {
                $line_item = new \WC_Order_Item_Product();
            }

            $line_item->set_product($product);
            $line_item->set_order_id($order->get_id());
            $line_item->set_quantity($row['quantity']);

            $total = floatval($row['quantity'] * $row['price'] / 100);

            $discount = $row['discount'];

            if (empty($discount)) {
                $line_item->set_total($total);
            } else {
                $total_with_discont = $total - ($total * $discount / 100);
                $line_item->set_total($total_with_discont);
            }

            $line_item->set_subtotal($total);

            $line_item->update_meta_data('wooms_id', $product_uuid);
            $line_item->set_total_tax(0);

            // if(!empty($row['vat'])){

            //     $tax = $row['vat'];

            //     if(empty($total_with_discont)){
            //         $tax_total = $tax * $total;
            //     } else {
            //         $tax_total = $tax * $total_with_discont;
            //     }

            //     $line_item->set_tax_class(strval($tax));
            //     $line_item->set_total_tax($tax_total);
            // }

            $item_id = $line_item->save();

            // $items = $order->get_items();
        }


        //delete old line items
        foreach ($order->get_items('line_item') as $line_item) {

            if ($wooms_id = self::get_wooms_id_from_line_item($line_item)) {

                if (!in_array($wooms_id, $data_api_wooms_id_array)) {
                    $order->remove_item($line_item->get_id());
                }
            }
        }


        // $order->calculate_totals();
        // $order->save();

        return $order;
    }

    /**
     * get item_id from order and wooms_id
     *
     * @param string $wooms_uuid
     * @param \WC_Order $order
     */
    public static function get_order_item_id_by_wooms_id($wooms_uuid, $order)
    {
        if (empty($wooms_uuid)) {
            return false;
        }

        $line_items = $order->get_items('line_item');

        foreach ($line_items as $line_item) {

            $wooms_id = self::get_wooms_id_from_line_item($line_item);

            if ($wooms_id == $wooms_uuid) {
                return $line_item->get_id();
            }
        }

        return false;
    }


    /**
     * Get wooms id from line item
     *
     * @param \WC_Order_Item_Product $line_item
     */
    public static function get_wooms_id_from_line_item($line_item)
    {
        $wooms_id = $line_item->get_meta('wooms_id', true);
        if (empty($wooms_id)) {

            $product_id = $line_item->get_variation_id();
            if (empty($product_id)) {
                $product_id = $line_item->get_product_id();
            }
            $wooms_id = get_post_meta($product_id, 'wooms_id', true);
        }

        if (empty($wooms_id)) {
            return false;
        }

        return $wooms_id;
    }


    /**
     * Skip order update from site, if exist task to update from MoySklad
     *
     * @param bool $skip
     * @param \WC_Order $order
     *
     * @return bool - skip or no
     */
    public static function skip_order_update_from_site($skip, $order)
    {
        if (!self::is_enable()) {
            return $skip;
        }

        $task_exist = $order->get_meta(self::$hook_order_update_from_moysklad, true);

        if ($task_exist) {
            return true;
        }

        return $skip;
    }


    public static function get_shipment_product_href()
    {
        if ($href = get_transient('wooms_shipment_href')) {
            return $href;
        }

        if (!$code = get_option('wooms_order_shipment_item_code')) {
            return false;
        }

        $url_api = sprintf('entity/service?filter=code=%s', $code);

        $data = request($url_api);

        if (empty($data['rows'][0]['meta']['href'])) {
            return false;
        }

        $href = $data['rows'][0]['meta']['href'];
        set_transient('wooms_shipment_href', $href, 60);

        return $href;
    }


    public static function batch_handler()
    {

        if (!self::is_enable()) {
            return;
        }

        $args = [
            'post_type'   => 'shop_order',
            'post_status' => 'any',
            'meta_query'             => array(
                array(
                    'key'     => self::$hook_order_update_from_moysklad,
                    'compare' => 'EXISTS',
                ),
            ),
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
            'cache_results'          => false,
        ];

        $posts = get_posts($args);


        if (empty($posts)) {
            self::set_state('is_wait', 1);
            return;
        }

        foreach ($posts as $post) {

            $args = [
                'data' => [
                    'post_id' => $post->ID,
                    'time' => time(),
                ]
            ];

            // as_enqueue_async_action( self::$hook_order_update_from_moysklad, $args, 'WooMS' );
            as_schedule_single_action(time(), self::$hook_order_update_from_moysklad, $args, 'WooMS');
        }

        return;
    }

    /**
     * update_order_from_moysklad
     */
    public static function update_order_from_moysklad($order_id = 0)
    {
        if (empty($order_id)) {
            return false;
        }

        $order_id = intval($order_id);
        if (!$order = wc_get_order($order_id)) {
            return false;
        }

        if (!$wooms_id = $order->get_meta('wooms_id', true)) {
            delete_post_meta($order_id, self::$hook_order_update_from_moysklad);
            return false;
        }

        $url_api = sprintf('entity/customerorder/%s', $wooms_id);

        $data = request($url_api);

        $order = apply_filters('wooms_update_order_from_moysklad', $order, $data);

        $order->save();
        $order = wc_get_order($order_id);


        $order->save();

        $order->calculate_totals();

        $sum = number_format($data['sum'] / 100, 2, '.', '');
        if ($sum != $order->get_total()) {
            do_action(
                'wooms_logger_error',
                __CLASS__,
                sprintf('Ошибка в сумме Заказа после обновления данных из МойСклад (сумма МС %s, сумма на сайте %s)', $sum, $order->get_total())
            );
        }

        do_action('wooms_update_order_from_moysklad_after_save', $order, $data);

        do_action(
            'wooms_logger',
            __CLASS__,
            sprintf('Заказ обновился данными из МойСклад (id %s, номер %s)', $order->get_id, $order->get_order_number())
        );

        return $order_id;
    }


    /**
     * Setup schedule
     *
     * @return mixed
     */
    public static function add_schedule_hook()
    {
        if (self::is_wait()) {
            as_unschedule_all_actions('wooms_check_orders_for_sync_from_moysklad');
            return;
        }

        if (!self::is_enable()) {
            as_unschedule_all_actions('wooms_check_orders_for_sync_from_moysklad');
            return;
        }

        if (false == as_next_scheduled_action('wooms_check_orders_for_sync_from_moysklad')) {
            as_schedule_recurring_action(time(), MINUTE_IN_SECONDS, 'wooms_check_orders_for_sync_from_moysklad', [], 'WooMS');
        }
    }


    /**
     * check wait state
     */
    public static function is_wait()
    {

        if (self::get_state('is_wait')) {
            return true;
        }

        return false;
    }


    /**
     * get state data
     */
    public static function get_state($key = '')
    {
        if (!$state = get_option(self::$state_key)) {
            $state = [];
            update_option(self::$state_key, $state);
        }

        if (empty($key)) {
            return $state;
        }

        if (empty($state[$key])) {
            return null;
        }

        return $state[$key];
    }

    /**
     * set state data
     */
    public static function set_state($key, $value)
    {

        if (!$state = get_option(self::$state_key)) {
            $state = [];
        }

        if (is_array($state)) {
            $state[$key] = $value;
        } else {
            $state = [];
            $state[$key] = $value;
        }

        update_option(self::$state_key, $state);
    }


    /**
     * Enable webhooks from MoySklad
     */
    public static function display_wooms_enable_webhooks()
    {
        $option = 'wooms_enable_webhooks';
        printf('<input type="checkbox" name="%s" value="1" %s />', $option, checked(1, get_option($option), false));

        printf(
            '<p><small>%s</small></p>',
            'Передатчик статусов из Мой Склад может работать только с 1 сайтом и только на платных тарифах сервиса Мой склад. Если вы используете платные тарифы, включите данную опцию.'
        );

        self::get_status_order_webhook();
    }

    /**
     * get_status_order_webhook
     */
    public static function get_status_order_webhook()
    {

        $check    = self::check_webhooks_and_try_fix();
        $url      = 'entity/webhook?limit=50';
        $data     = request($url);

        $webhooks = array();

        if (!empty($data['rows'])) {
            foreach ($data['rows'] as $row) {
                if (self::is_protected_webhook_url($row['url'])) {
                    $webhooks[$row['id']] = array(
                        'entityType' => $row['entityType'],
                        'url'        => $row['url'],
                        'method'     => $row['method'],
                        'enabled'    => $row['enabled'],
                        'action'     => $row['action'],
                    );
                }
            }
        }

        if (empty(get_option('wooms_enable_webhooks'))) {
            if (empty($webhooks)) {
                echo "Хук на стороне МойСклад отключен в соответствии с настройкой";
            } else {
                echo "Что то пошло не так. Хук на стороне МойСклад остался включен в нарушении настроек.";
            }
        } else {
            if (empty($webhooks)) {
                echo "Что то пошло не так. Хук на стороне МойСклад отключен в нарушении настройки. Попробуйте отключить и включить снова. Если не поможет - обратитесь в техподдержку.";
            } else {
                echo "Хук на стороне МойСклад добавлен в соответствии с настройки";
            }
        }
        echo '<p><small>Ссылка для приема данных из системы МойСклад защищена персональным ключом.</small></p>';

        if (!empty($data["errors"][0]["error"])) {
            $msg = $data["errors"][0]["error"];
            printf('<p><strong>%s</strong></p>', $msg);
        }
    }

    /**
     * Check isset hook and fix if not isset
     *
     * @return bool
     */
    public static function check_webhooks_and_try_fix()
    {

        $url  = 'entity/webhook';
        $data = request($url);

        $webhooks = array();

        if (!empty($data['rows'])) {

            foreach ($data['rows'] as $row) {
                if ('customerorder' !== $row['entityType'] || 'UPDATE' !== $row['action']) {
                    continue;
                }

                $webhooks[$row['id']] = array(
                    'entityType' => $row['entityType'],
                    'url'        => $row['url'],
                    'method'     => $row['method'],
                    'enabled'    => $row['enabled'],
                    'action'     => $row['action'],
                );
            }
        }


        //Проверка на включение опции и наличия хуков
        if (empty(get_option('wooms_enable_webhooks'))) {

            if (empty($webhooks)) {
                return true;
            } else {
                //пытаемся удалить лишний хук
                foreach ($webhooks as $id => $value) {
                    $url   = 'entity/webhook/' . $id;
                    $check = request($url, null, 'DELETE');
                }

                return false;
            }
        } else {

            $webhook_url = self::get_webhook_url();

            //Если нужного вебхука нет - создаем новый
            if (empty($webhooks)) {
                // создаем веб хук в МойСклад
                $data   = array(
                    'url'        => $webhook_url,
                    'action'     => "UPDATE",
                    "entityType" => "customerorder",
                );
                $result = request($url, $data);

                if (empty($result)) {
                    return false;
                } else {
                    return true;
                }
            } else {
                foreach ($webhooks as $id => $webhook) {
                    if (self::is_protected_webhook_url($webhook['url']) && !empty($webhook['enabled'])) {
                        return true;
                    }

                    $result = request(
                        'entity/webhook/' . $id,
                        array(
                            'url'        => $webhook_url,
                            'action'     => 'UPDATE',
                            'entityType' => 'customerorder',
                            'enabled'    => true,
                        ),
                        'PUT'
                    );

                    return !empty($result);
                }
            }
        }
    }


    /**
     * Match statuses from Site to MoySkald
     */
    public static function display_wooms_order_statuses_from_moysklad()
    {

        $option_key = 'wooms_order_statuses_from_moysklad';

        $statuses = wc_get_order_statuses();
        if (empty($statuses) or !is_array($statuses)) {
            printf(
                '<p>%s</p>',
                __('Что то пошло не так, сообщите о проблеме в тех поддержку', 'wooms')
            );
            return;
        }

        printf(
            '<p>%s</p>',
            __('Нужно написать какие статусы указывать в МойСклад, при смене статуса Заказов на Сайте, названия должны совпадать со статусами в МойСклад.', 'wooms')
        );

        $option_value = get_option($option_key);
        if (empty($option_value)) {
            $option_value = array();
        }

        foreach ($statuses as $status_key => $status_name) {

            if (empty($option_value[$status_key])) {
                switch ($status_key) {
                    case 'wc-pending':
                        $option_value[$status_key] = 'Новый';
                        break;

                    case 'wc-processing':
                        $option_value[$status_key] = 'Подтвержден';
                        break;

                    case 'wc-on-hold':
                        $option_value[$status_key] = 'Новый';
                        break;

                    case 'wc-completed':
                        $option_value[$status_key] = 'Отгружен';
                        break;

                    case 'wc-cancelled':
                        $option_value[$status_key] = 'Отменен';
                        break;

                    case 'wc-refunded':
                        $option_value[$status_key] = 'Возврат';
                        break;

                    case 'wc-failed':
                        $option_value[$status_key] = 'Не удался';
                        break;

                    default:
                        $option_value[$status_key] = 'Новый';
                        break;
                }
            }


            printf(
                '<p><input type="text" name="%s[%s]" value="%s" /> > %s (%s)</p>',
                $option_key,
                $status_key,
                $option_value[$status_key],
                $status_name,
                $status_key
            );
        }
    }


    /**
     * Add endpoint /wp-json/wooms/v1/order-update/
     */
    public static function add_route()
    {
        register_rest_route('wooms/v1', '/order-update/', array(
            'methods'  => \WP_REST_Server::EDITABLE,
            'callback' => array(__CLASS__, 'get_data_order_from_moysklad'),
            'permission_callback' => array(__CLASS__, 'authorize_webhook'),
        ));
    }

    /**
     * Return the authenticated webhook URL registered in MoySklad.
     *
     * @return string
     */
    public static function get_webhook_url()
    {
        return add_query_arg('key', self::get_webhook_secret(), rest_url('/wooms/v1/order-update/'));
    }

    /**
     * Authenticate, rate limit, and reserve a webhook event before handling it.
     *
     * @param \WP_REST_Request $request REST request.
     * @return true|\WP_Error
     */
    public static function authorize_webhook($request)
    {
        $params = $request->get_query_params();
        $key = is_string($params['key'] ?? null) ? $params['key'] : '';

        if ('' === $key || !hash_equals(self::get_webhook_secret(), $key)) {
            return new \WP_Error(
                'wooms_webhook_unauthorized',
                'Webhook authentication failed.',
                array('status' => 401)
            );
        }

        if (!self::is_enable()) {
            return new \WP_Error(
                'wooms_webhook_disabled',
                'Webhook processing is disabled.',
                array('status' => 503)
            );
        }

        if (65536 < strlen($request->get_body())) {
            return new \WP_Error(
                'wooms_webhook_too_large',
                'Webhook payload is too large.',
                array('status' => 413)
            );
        }

        $request_id = self::get_webhook_request_id($request);
        if (false === $request_id) {
            return new \WP_Error(
                'wooms_webhook_invalid_request_id',
                'A valid MoySklad requestId is required.',
                array('status' => 400)
            );
        }

        $replay_key = self::get_webhook_replay_key($request_id);
        $replay_state = get_transient($replay_key);
        if ('processed' === $replay_state) {
            // MoySklad requires HTTP 200/204 even for already processed notifications.
            return true;
        }
        if (false !== $replay_state) {
            return new \WP_Error(
                'wooms_webhook_replayed',
                'This webhook event has already been received.',
                array('status' => 409)
            );
        }

        $rate_key = self::$webhook_rate_prefix . gmdate('YmdHi');
        $request_count = (int) get_transient($rate_key);
        if (120 <= $request_count) {
            return new \WP_Error(
                'wooms_webhook_rate_limited',
                'Webhook rate limit exceeded.',
                array('status' => 429)
            );
        }

        set_transient($rate_key, $request_count + 1, 2 * MINUTE_IN_SECONDS);
        set_transient($replay_key, 'processing', 5 * MINUTE_IN_SECONDS);

        return true;
    }

    /**
     * Get or create the secret embedded in the provider-side webhook URL.
     *
     * @return string
     */
    private static function get_webhook_secret()
    {
        $secret = get_option(self::$webhook_secret_option, '');
        if (is_string($secret) && 32 <= strlen($secret)) {
            return $secret;
        }

        $secret = wp_generate_password(48, false, false);
        if (!add_option(self::$webhook_secret_option, $secret, '', 'no')) {
            $stored_secret = get_option(self::$webhook_secret_option, '');
            if (is_string($stored_secret) && 32 <= strlen($stored_secret)) {
                return $stored_secret;
            }

            update_option(self::$webhook_secret_option, $secret, false);
        }

        return $secret;
    }

    /**
     * Check whether a provider webhook uses the current authenticated URL.
     *
     * @param string $url Provider webhook URL.
     * @return bool
     */
    private static function is_protected_webhook_url($url)
    {
        return is_string($url) && hash_equals(self::get_webhook_url(), $url);
    }

    /**
     * Validate the provider-generated event identifier.
     *
     * @param \WP_REST_Request $request REST request.
     * @return string|false
     */
    private static function get_webhook_request_id($request)
    {
        $params = $request->get_query_params();
        $request_id = $params['requestId'] ?? '';

        if (!is_string($request_id) || !preg_match('/\A[A-Za-z0-9._-]{8,128}\z/', $request_id)) {
            return false;
        }

        return $request_id;
    }

    /**
     * Build a non-sensitive transient key for replay tracking.
     *
     * @param string $request_id Provider event identifier.
     * @return string
     */
    private static function get_webhook_replay_key($request_id)
    {
        return self::$webhook_replay_prefix . hash('sha256', $request_id);
    }

    /**
     * Keep a replay marker after successful processing.
     *
     * @param \WP_REST_Request $request REST request.
     * @return void
     */
    private static function mark_webhook_processed($request)
    {
        $request_id = self::get_webhook_request_id($request);
        if (false !== $request_id) {
            set_transient(self::get_webhook_replay_key($request_id), 'processed', DAY_IN_SECONDS);
        }
    }

    /**
     * Release an in-flight replay marker after a processing failure.
     *
     * @param \WP_REST_Request $request REST request.
     * @return void
     */
    private static function release_webhook_request($request)
    {
        $request_id = self::get_webhook_request_id($request);
        if (false !== $request_id) {
            $replay_key = self::get_webhook_replay_key($request_id);
            if ('processing' === get_transient($replay_key)) {
                delete_transient($replay_key);
            }
        }
    }


    /**
     * Get data from MoySkald and start update order
     *
     * @param $data_request
     *
     * @return void|WP_REST_Response
     */
    public static function get_data_order_from_moysklad($data_request)
    {
        try {
            $request_id = self::get_webhook_request_id($data_request);
            $body = $data_request->get_body();
            $data = json_decode($body, true);
            $already_processed = false !== $request_id
                && 'processed' === get_transient(self::get_webhook_replay_key($request_id));

            if (!$already_processed) {
                do_action(
                    'wooms_logger',
                    __CLASS__,
                    sprintf('Прилетел веб-хук %s', $request_id),
                    $data
                );
            }

            if (!self::is_enable()) {
                self::release_webhook_request($data_request);
                return new \WP_REST_Response(array('error' => 'Webhook processing is disabled'), 503);
            }

            if (empty($data["events"][0]["meta"]["href"])) {
                self::release_webhook_request($data_request);
                return new \WP_REST_Response(array('error' => 'Invalid webhook payload'), 400);
            }

            $order_path = MoySkladApiUrl::get_customer_order_path($data["events"][0]["meta"]["href"]);
            if (false === $order_path) {
                do_action(
                    'wooms_logger_error',
                    __CLASS__,
                    'Отклонен webhook с небезопасным адресом заказа МойСклад'
                );

                self::release_webhook_request($data_request);
                return new \WP_REST_Response(array('error' => 'Invalid webhook payload'), 400);
            }

            if ($already_processed) {
                return new \WP_REST_Response(null, 204);
            }

            $order_uuid = basename($order_path);

            $args   = array(
                'numberposts' => 1,
                'post_type'   => 'shop_order',
                'post_status' => 'any',
                'meta_key'    => 'wooms_id',
                'meta_value'  => $order_uuid,
            );
            $orders = get_posts($args);


            if (empty($orders[0]->ID)) {
                do_action(
                    'wooms_logger',
                    __CLASS__,
                    sprintf('Веб-хук %s пропущен: заказ МойСклад %s не связан с заказом сайта', $request_id, $order_uuid)
                );
                self::mark_webhook_processed($data_request);
                return new \WP_REST_Response(null, 204);
            }

            $order_id = $orders[0]->ID;

            //Если стоит блокировка обновления Заказа, то выходим (защита от состояния гонки)
            if (get_post_meta($order_id, 'lock_callback_update', true)) {
                delete_post_meta($order_id, 'lock_callback_update');
                self::mark_webhook_processed($data_request);
                return new \WP_REST_Response(array('success' => 'Order update is already locked'), 200);
            }

            $action_id = 0;
            $queue_error = 'Action Scheduler did not return a persisted action ID';
            try {
                $action_id = as_enqueue_async_action(
                    self::$hook_process_webhook,
                    array('order_id' => $order_id, 'order_uuid' => $order_uuid, 'request_id' => $request_id),
                    'WooMS'
                );
            } catch (\Throwable $exception) {
                $queue_error = $exception->getMessage();
            }

            if (!is_int($action_id) || $action_id <= 0) {
                self::release_webhook_request($data_request);
                do_action(
                    'wooms_logger_error',
                    __CLASS__,
                    sprintf('Веб-хук %s не поставлен в фоновую очередь', $request_id),
                    array('phase' => 'enqueue', 'request_id' => $request_id, 'order_id' => $order_id, 'order_uuid' => $order_uuid, 'error' => $queue_error)
                );
                return new \WP_REST_Response(array('error' => 'Webhook could not be queued'), 503);
            }

            self::mark_webhook_processed($data_request);
            do_action(
                'wooms_logger',
                __CLASS__,
                sprintf('Веб-хук %s принят в фоновую очередь: задача %d, заказ %d', $request_id, $action_id, $order_id)
            );
            return new \WP_REST_Response(null, 204);
        } catch (Exception $e) {
            self::release_webhook_request($data_request);
            $response = new \WP_REST_Response(array('fail', $e->getMessage()));
            $response->set_status(500);
            return $response;
        }
    }

    /**
     * Process a queued webhook and persist a bounded retry when the attempt fails.
     *
     * @internal
     * @param int $order_id Local order ID.
     * @param string $order_uuid Validated MoySklad order UUID.
     * @param string $request_id Provider notification ID.
     * @param int $attempt Zero for the initial attempt, otherwise the persisted retry number.
     * @return void
     */
    public static function process_order_webhook($order_id, $order_uuid, $request_id, $attempt = 0)
    {
        try {
            self::prepare_webhook_order_update($order_id, $order_uuid, $request_id);
        } catch (\Throwable $exception) {
            $retry = OrderWebhookRetries::schedule_retry($order_id, $order_uuid, $request_id, $attempt);
            $context = array(
                'phase' => 'processing',
                'request_id' => $request_id,
                'order_id' => $order_id,
                'order_uuid' => $order_uuid,
                'attempt' => $attempt,
                'error' => $exception->getMessage(),
            );
            if (is_wp_error($retry)) {
                $context['retry_error_code'] = $retry->get_error_code();
                $context['retry_error'] = $retry->get_error_message();
                $message = 'wooms_webhook_retries_exhausted' === $retry->get_error_code()
                    ? sprintf('Веб-хук %s: попытки обработки исчерпаны', $request_id)
                    : sprintf('Веб-хук %s: не удалось назначить повтор (%s)', $request_id, $retry->get_error_message());
            } else {
                $context['retry_action_id'] = $retry;
                $context['next_attempt'] = $attempt + 1;
                $message = sprintf('Веб-хук %s: ошибка попытки %d, назначен повтор %d (задача %d)', $request_id, $attempt + 1, $attempt + 1, $retry);
            }
            do_action('wooms_logger_error', __CLASS__, $message, $context);

            // Retain the failed source action in Action Scheduler, including the retry decision.
            throw new \RuntimeException($message . ': ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Fetch webhook order data and hand off to the existing order synchronization queue.
     */
    private static function prepare_webhook_order_update($order_id, $order_uuid, $request_id)
    {
        if (!self::is_enable()) {
            throw new \RuntimeException('Webhook processing is disabled');
        }

        if (!self::can_process_webhook_order($order_id, $order_uuid)) {
            return;
        }

        $data_order = request('entity/customerorder/' . $order_uuid);
        if (empty($data_order['id']) || strtolower((string) $data_order['id']) !== $order_uuid) {
            throw new \RuntimeException('MoySklad order request failed for webhook ' . $request_id);
        }

        // The order may have been deleted or relinked while the provider request was running.
        if (!self::can_process_webhook_order($order_id, $order_uuid)) {
            return;
        }

        update_post_meta($order_id, self::$hook_order_update_from_moysklad, 1);
        if (!get_post_meta($order_id, self::$hook_order_update_from_moysklad, true)) {
            throw new \RuntimeException('Order could not be queued for webhook ' . $request_id);
        }
        self::set_state('is_wait', 0);

        do_action(
            'wooms_logger',
            __CLASS__,
            sprintf('Заказ %s поставлен в очередь на обновление из МойСклад (веб-хук %s)', $order_id, $request_id)
        );
        do_action('wooms_order_update_from_moysklad_action', $order_id, $data_order, $order_uuid);
    }

    /**
     * Check that the queued order still belongs to this webhook and consume callback locks.
     */
    private static function can_process_webhook_order($order_id, $order_uuid)
    {
        if (strtolower((string) get_post_meta($order_id, 'wooms_id', true)) !== $order_uuid) {
            return false;
        }
        if (get_post_meta($order_id, 'lock_callback_update', true)) {
            delete_post_meta($order_id, 'lock_callback_update');
            return false;
        }
        return true;
    }

    /**
     * is_enable
     */
    public static function is_enable()
    {
        if (get_option('wooms_enable_webhooks')) {
            return true;
        }

        return false;
    }

    /**
     * Setting
     */
    public static function add_settings()
    {

        register_setting('mss-settings', 'wooms_enable_webhooks');
        add_settings_field(
            $id = 'wooms_enable_webhooks',
            $title = 'Передатчик Статуса и данных по Заказу:<br/> МойСклад > Сайт',
            $callback = array(__CLASS__, 'display_wooms_enable_webhooks'),
            $page = 'mss-settings',
            $section = 'wooms_section_orders'
        );

        if (get_option('wooms_enable_webhooks')) {
            register_setting('mss-settings', 'wooms_order_statuses_from_moysklad');
            add_settings_field(
                $id = 'wooms_order_statuses_from_moysklad',
                $title = 'Связь статусов:<br/> от МойСклад на Сайт',
                $callback = array(__CLASS__, 'display_wooms_order_statuses_from_moysklad',),
                $page = 'mss-settings',
                $section = 'wooms_section_orders'
            );
        }
    }
}

OrderUpdateFromMoySklad::init();
