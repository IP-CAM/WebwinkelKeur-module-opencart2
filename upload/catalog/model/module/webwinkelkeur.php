<?php
require_once DIR_SYSTEM . 'library/Peschar_URLRetriever.php';
class ModelModuleWebwinkelkeur extends Model {

    public function sendInvites() {
        $msg = @include DIR_SYSTEM . 'library/webwinkelkeur-messages.php';

        $settings = $this->getSettings();

        if(empty($settings['shop_id']) ||
           empty($settings['api_key']) ||
           empty($settings['invite'])
        )
            return;

        foreach($this->getOrdersToInvite($settings) as $order) {
            $this->db->query("
                UPDATE `" . DB_PREFIX . "order`
                SET
                    webwinkelkeur_invite_tries = webwinkelkeur_invite_tries + 1,
                    webwinkelkeur_invite_time = " . time() . "
                WHERE
                    order_id = " . $order['order_id'] . "
                    AND webwinkelkeur_invite_tries = " . $order['webwinkelkeur_invite_tries'] . "
                    AND webwinkelkeur_invite_time = " . $order['webwinkelkeur_invite_time'] . "
            ");
            if($this->db->countAffected()) {
                $parameters = array(
                    'id'        => $settings['shop_id'],
                    'code'  => $settings['api_key']
                );
                $post = array(
                    'email'     => $order['email'],
                    'order'     => $order['order_id'],
                    'delay'     => $settings['invite_delay'],
                    'language'      => str_replace('-', '_', $order['language_code']),
                    'customer_name' => "$order[payment_firstname] $order[payment_lastname]",
                    'phones' => [$order['telephone']],
                    'order_total' => $order['total'],
                    'client'    => 'opencart2',
                    'platform_version' => VERSION,
                    'plugin_version' => '1.1'
                );
                if($settings['invite'] == 2)
                    $parameters['max_invitations_per_email'] = '1';

                if (!$settings['limit_order_data']) {
                    $post['order_data'] = json_encode($this->getOrderData($order));
                }

                $url = 'http://' . $msg['API_DOMAIN'] . '/api/1.0/invitations.json?' . http_build_query($parameters);
                $retriever = new Peschar_URLRetriever();
                $response = $retriever->retrieve($url, $post);
                if($this->isInviteSent($response)) {
                    $this->db->query("UPDATE `" . DB_PREFIX . "order` SET webwinkelkeur_invite_sent = 1 WHERE order_id = " . $order['order_id']);
                } else {
                    $this->db->query("INSERT INTO `" . DB_PREFIX . "webwinkelkeur_invite_error` SET url = '" . $this->db->escape($url) . "', response = '" . $this->db->escape($response) . "', time = " . time());
                }
            }
        }
    }

    private function getOrdersToInvite($settings) {
        $max_time = time() - 1800;

        $where = array();

        $where[] = 'o.store_id = ' . (int) $this->config->get('config_store_id');

        if(empty($settings['order_statuses']))
            $where[] = '0';
        else
            $where[] = 'o.order_status_id IN (' . implode(',', array_map('intval', $settings['order_statuses'])) . ')';

        if(empty($where))
            $where = '0';
        else
            $where = implode(' AND ', $where);

        $query = $this->db->query($q="
            SELECT o.*, l.code as language_code
            FROM `" . DB_PREFIX . "order` o
            LEFT JOIN `" . DB_PREFIX . "language` l USING(language_id)
            WHERE
                o.webwinkelkeur_invite_sent = 0
                AND o.webwinkelkeur_invite_tries < 10
                AND o.webwinkelkeur_invite_time < $max_time
                AND $where
        ");

        return $query->rows;
    }

    private function isInviteSent($response) {
        $result = @json_decode($response);
        return is_object($result) && isset ($result->status)
               && ($result->status == 'success' || strpos($result->message, 'already sent') !== false);
    }

    private function getOrderData($order) {
        $customer_fields = array(
            'customer_id', 'customer_group_id',
            'firstname', 'lastname',
            'email', 'telephone', 'fax',
            'custom_field'
        );
        $customer = array();
        $invoice_address = array();
        $delivery_address = array();
        foreach ($order as $field => $value) {
            if (in_array($field, $customer_fields)) {
                $customer[$field] = $order[$field];
            } else if (strpos($field, 'payment_') === 0) {
                $new_field = str_replace('payment_', '', $field);
                $invoice_address[$new_field] = $order[$field];
            } else if (strpos($field, 'shipping_') === 0) {
                $new_field = str_replace('shipping_', '', $field);
                $delivery_address[$new_field] = $order[$field];
            } else {
                continue;
            }
            unset ($order[$field]);
        }

        $lines_query = "SELECT * FROM `" . DB_PREFIX . "order_product` WHERE `order_id` = {$order['order_id']}";
        $order['order_lines'] = $this->db->query($lines_query)->rows;

        $order_data = array(
            'order' => $order,
            'products' => $this->getOrderProducts($order),
            'customer' => $customer,
            'invoice_address' => $invoice_address,
            'delivery_address' => $delivery_address
        );

        return $order_data;
    }

    private function getOrderProducts($order) {
        $product_ids = array();
        foreach ($order['order_lines'] as $line) {
            if (!$line['product_id']) {
                continue;
            }
            $product_ids[] = $line['product_id'];
        }

        if (empty ($product_ids)) {
            return array();
        }

        $products_query = "
          SELECT * 
          FROM `" . DB_PREFIX . "product` AS `p`
          LEFT JOIN `" . DB_PREFIX . "product_description` AS `pd`
            ON `p`.`product_id` = `pd`.`product_id`
                AND `pd`.`language_id` = {$order['language_id']}
          WHERE `p`.`product_id` IN (" . join(',', $product_ids) . ')';
        $products = $this->db->query($products_query)->rows;

        $base_url = $this->request->server['HTTPS']
                    ? $this->config->get('config_ssl')
                    : $this->config->get('config_url');
        foreach ($products as &$product) {
            $images = array($base_url . 'image/' . $product['image']);
            $image_query = "SELECT * FROM `" . DB_PREFIX . "product_image` WHERE `product_id` = {$product['product_id']}";
            foreach ($this->db->query($image_query)->rows as $image) {
                $images[] = $base_url . 'image/' . $image['image'];
            }
            $product['image_urls'] = $images;
        }
        return $products;
    }

    public function getSettings() {
        $this->load->model('setting/setting');

        $store_id = $this->config->get('config_store_id');

        $this->load->model('extension/module');
        foreach($this->getModulesByCode('webwinkelkeur') as $module) {
            $data = $this->model_extension_module->getModule($module['module_id']);
            if($data['store_id'] == $store_id)
                return $data;
        }

        $wwk_settings = $this->model_setting_setting->getSetting('webwinkelkeur');

        $settings = array();
        foreach($wwk_settings as $key => $value) {
            preg_match('~^webwinkelkeur_(.*)$~', $key, $name);
            $settings[$name[1]] = $value;
        }

        return $settings;
    }

    public function getModulesByCode($code) {
        $query = $this->db->query("
            SELECT * FROM `" . DB_PREFIX . "module`" .
                " WHERE `code` = '" . $this->db->escape($code) . "'" .
                " ORDER BY `name`");

        return $query->rows;
    }
}
