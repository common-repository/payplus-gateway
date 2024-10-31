<?php

/*
  Plugin Name: PayPlus Gateway
  Plugin URI: https://www.payplusinc.com/
  Description: PayPlus gateway for woocommerce
  Version: 1.0.2
  Author: PayPlus
  Author URI: https://www.payplusinc.com/about-us/
  Plugin License: license.txt
 */
add_action('plugins_loaded', 'ppgeneric_init', 0);

function ppgeneric_init() {
  if (!class_exists('WC_Payment_Gateway'))
    return;

  class PayPlusGeneric extends WC_Payment_Gateway {

    public function __construct() {
      $this->id = 'ppgeneric';
      $this->has_fields = false;
      $this->method_title = 'PayPlus';

      $this->init_form_fields();
      $this->init_settings();

      $this->title = $this->settings['title'];
      $this->description = $this->settings['description'];
      $this->gateway_url = $this->settings['gateway_url'];
      $this->gateway_method = $this->settings['gateway_method'];
      $this->merchant_token = $this->settings['merchant_token'];
//            $this->merchant_secret = $this->settings['merchant_secret'];
      $this->currency = get_woocommerce_currency();
      $this->shop_url = $this->settings['shop_url'];
      $this->icon = $this->get_image_path() . 'icon-256x256.jpg';
      $this->pp_generic_logo = $this->settings["pp_generic_logo"];

      $this->order_id = "";

      $this->msg['message'] = "";
      $this->msg['class'] = "";

      $this->lang = get_bloginfo("language");

      if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
      } else {
        add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
      }
      add_action('woocommerce_receipt_ppgeneric', array(&$this, 'receipt_page'));

      /* callback/datafeed */
      add_action('woocommerce_api_wc_pp_generic', array($this, 'gateway_response_ppgeneric'));

      /* Success Redirect URL */
      add_action('woocommerce_api_wc_ppgeneric_success_page', array($this, 'ppgeneric_success_redirect_url'));
    }

    /**
     * Returns Logo Image Path
     *
     * @return string
     */
    public function get_image_path() {
      return WP_PLUGIN_URL . '/' . plugin_basename(dirname(__FILE__)) . '/assets/';
    }

    /**
     * Get gateway icon.
     *
     * @access public
     * @return string
     */
    public function get_icon() {
      $icon_html = null;
      if ($this->pp_generic_logo == "yes") {
        $icon_path = $this->get_image_path() . 'icon-256x256.jpg';
        $icon_width = '120';
        $icon_html = '<img src="' . $icon_path . '" alt="' . $this->title . '" style="max-width:' . $icon_width . 'px; max-height:30px;"/>';
      }
      return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
    }

    function sign($fields, $secret) {
      return hash('SHA512', http_build_query($fields) . $secret);
    }

    function init_form_fields() {

      $this->form_fields = array(
        'enabled' => array(
          'title' => __('Enable/Disable'),
          'type' => 'checkbox',
          'label' => __('Enable PayPlus Module.'),
          'default' => 'no'),
        'title' => array(
          'title' => __('Title:'),
          'type' => 'text',
          'required' => true,
          'description' => __('The title to be shown in checkout options.'),
          'default' => __('Generic')),
        'description' => array(
          'title' => __('Description:'),
          'type' => 'textarea',
          'required' => true,
          'description' => __('The description to be shown in checkout.'),
          'default' => __('Pay securely through PayPlus services.')),
        'gateway_url' => array(
          'title' => __('Gateway URL'),
          'type' => 'text',
          'required' => true,
          'description' => __('Gateway URL to send payment request.')),
        'shop_url' => array(
          'title' => __('Shop URL'),
          'type' => 'text',
          'required' => true,
          'description' => __('Your online shop link.')),
        'merchant_token' => array(
          'title' => __('Merchant token'),
          'type' => 'text',
          'required' => true,
          'description' => __('Your bearer token.')),
        'pp_generic_logo' => array(
          'title' => __('Gateway Logo', 'pp_generic-for-woocommerce'),
          'type' => 'checkbox',
          'label' => __('This controls the logo which the user sees during checkout.'),
          'default' => 'yes'
        ),
        'gateway_method' => array(
          'title' => __('Gateway Method', 'pp_generic-for-woocommerce'),
          'type' => 'select',
          'options' => array(
            "*" => "All",
            "alipay" => "Alipay",
            "wechatpay" => "WeChat Pay",
            "creditcard" => "VISA/MasterCard",
          ),
          'label' => __('This controls the logo which the user sees during checkout.'),
          'default' => '*'
        ),
      );
    }

    public function admin_options() {

      $allow_tag = array(
        "table" => array("class" => array()),
      );

      $str = '<h3>PayPlus Gateway</h3>
              <hr/>
              <table class="form-table">';
      echo wp_kses_post($str);

      // Generate setting form.
      $this->generate_settings_html();

      echo wp_kses('</table>', $allow_tag);
    }

    function payment_fields() {
      if ($this->description)
       echo wpautop(wptexturize($this->description));
        // echo esc_html(wpautop(wptexturize($this->description)));
    }

    /**
     * Receipt Page
     * */
    function receipt_page($order) {
//      echo '<p>' . __('Thank you for your order. You will be redirected to the Payment Gateway to proceed with the payment.') . '</p>';
      return $this->generate_payplus_form($order);
    }

    /**
     * Generate Payment link
     * */
    public function generate_payplus_form($order_id) {

      global $woocommerce;
      $logger = wc_get_logger();

      $order = new WC_Order($order_id);
      $this->order_id = $order_id;

      $success_url = $order->get_checkout_order_received_url();

      $return_url = $this->shop_url . "/index.php?wc-api=wc_ppgeneric_success_page&order_id={$order_id}";
      $notify_url = $this->shop_url . "/index.php?wc-api=wc_pp_generic";

      $fields = [
        "order" => [
          "reference" => (string) $order_id,
//          "currency" => $order->get_currency(),
          "currency" => "NZD",
          "amount" => $order->get_total(),
        ],
        "types" => [$this->gateway_method],
        "customer" => [
          "first_name" => $order->get_billing_first_name(),
          "last_name" => $order->get_billing_last_name(),
          "email" => $order->get_billing_email(),
          "mobile" => $order->get_billing_phone(),
          "ip" => $order->get_customer_ip_address(),
          "country" => $order->get_billing_country(),
        ],
        "return_url" => $return_url,
        "notify_url" => $notify_url
      ];

      $cdata = json_encode($fields);

      $endpoint = $this->gateway_url . "/1.0/gateway/payments";

      $response = $this->sendCurl($endpoint, $cdata);
      $payload = $response["payload"];

      if ($response["code"] == "200") {
        $uri = $payload["payment"]["page_url"];
        if (isset($payload["transaction"]["uri"]) && strlen($payload["transaction"]["uri"])) {
          $uri = $payload["transaction"]["uri"];
        }

        if (isset($uri) && strlen($uri)) {
          echo wp_kses_post('<p>' . __('Thank you for your order. You will be redirected to the Payment Gateway to proceed with the payment.') . '</p>');

//          header("location: {$uri}");

          wp_redirect($uri);
          exit;
        }
      }

      if ($response["code"] !== "200") {
        $logger->debug(json_encode($response));
        $errorMsg = isset($payload["error"]["message"]) ? $payload["error"]["message"] : json_encode($payload);
        
        if($errorMsg == "Unauthorized"){
          echo wp_kses_post('<p>' . __('Unauthorized.') . '</p>');
        }else{
          echo wp_kses_post('<p>' . __('Something wrong.') . '</p>');
        }
        
        exit;
      }
    }

    public function sendCurl($endpoint, $json, $method = "POST") {
      global $woocommerce;

      $args = [
        'method' => $method,
        'timeout' => '5',
        'redirection' => '10',
        'httpversion' => '1.1',
        'blocking' => true,
      ];

      if(strtoupper($method) == "POST"){
        $args['headers'] = [
          'Content-Type' => 'application/json',
          'Authorization' => 'Bearer ' . $this->merchant_token,
        ];

        $args['body'] = $json;
      }else{
        $args['headers'] = [
        'Authorization' => 'Bearer ' . $this->merchant_token,
      ];

      }

      $response = wp_remote_retrieve_body(wp_remote_get($endpoint, $args));

      return json_decode($response, 1);
    }

    /**
     * Process the payment and return the result
     * */
    function process_payment($order_id) {

      global $woocommerce;
      $order = new WC_Order($order_id);

      /* return array(
        'result' => 'success',
        'redirect' => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(get_option('woocommerce_pay_page_id'))))
        ); */
      $woocommerce->cart->empty_cart();
      return array(
        'result' => 'success',
        'redirect' => $order->get_checkout_payment_url(true)
      );
    }

    /**
     * Datafeed
     * */
    function gateway_response_ppgeneric() {
      global $woocommerce;
      $logger = wc_get_logger();

      $json_input = file_get_contents('php://input');

      $datafeed = json_decode($json_input, 1);

      if ($datafeed["status"] !== "Completed") {
        $logger->debug($json_input);
        echo esc_attr("datafeed fail");
        exit;
      }

      $order_id = $datafeed["order_reference"];

      $order = new WC_Order($order_id);

      // if ($datafeed["status"] !== "Completed") {
      //   $this->msg['message'] = 'Thank you for shopping with us. However, the transaction has been declined. Payment reference no: ' . $order_id;
      //   $this->msg['class'] = 'woocommerce_error';
      //   $order->update_status('failed');
      //   $order->add_order_note('Payment unsuccessful! Payment reference no: ' . $order_id);

      //   add_action('the_content', array(&$this, 'showMessage'));
      //   echo esc_attr("order fail");
      //   exit();
      // }

      $this->msg['message'] = 'Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be shipping your order to you soon. Payment reference no: ' . $order_id;
      $this->msg['class'] = 'woocommerce_message';

      $order->update_status('processing');
      $order->add_order_note('Payment successful! Payment reference no: ' . $order_id);
      $woocommerce->cart->empty_cart();

      add_action('the_content', array(&$this, 'showMessage'));
      echo esc_attr("OK");
      exit();
    }

    // redirect after payment to avoid lost cookies
    public function ppgeneric_success_redirect_url() {
      global $woocommerce;

      $logger = wc_get_logger();

      $order_id = filter_input(INPUT_GET, "order_id");

      if (!isset($order_id) || is_null($order_id)) {
        $logger->debug($order_id);
        echo esc_attr("order not found");
        exit;
      }

      // query transaction 
      $endpoint = $this->gateway_url . "/1.0/gateway/payments?order_reference=".$order_id;

      $post_data = null;

      $response = $this->sendCurl($endpoint, json_encode($post_data), "GET");
      $logger->debug(json_encode($response));

      $order = new WC_Order($order_id);

      $successURL = $order->get_checkout_order_received_url();
      if ((string) $response["code"] !== "200") {
        $logger->debug(json_encode($response));
        $successURL = $order->get_view_order_url();
      }
      if ($response["payload"]["status"] !== "Completed") {
        $logger->debug(json_encode($response));
        $successURL = $order->get_view_order_url();
      }

      wp_redirect($successURL);
      exit;

//      $html = "<!DOCTYPE html><html><head><meta charset='utf-8'></head><body><form id='form-success' name='form-success' method='post' action='{$successURL}'></form><script type='text/javascript'>document.getElementById('form-success').submit();</script></body></html>";
//
//      echo $html;
////      header("location: {$successURL}");
//      exit;
    }

    function showMessage($content) {
      return '<div class="box ' . $this->msg['class'] . '-box">' . $this->msg['message'] . '</div>' . $content;
    }

    // get all pages
    function get_pages($title = false, $indent = true) {
      $wp_pages = get_pages('sort_column=menu_order');
      $page_list = array();
      if ($title)
        $page_list[] = $title;
      foreach ($wp_pages as $page) {
        $prefix = '';
        // show indented child pages?
        if ($indent) {
          $has_parent = $page->post_parent;
          while ($has_parent) {
            $prefix .= ' - ';
            $next_page = get_page($has_parent);
            $has_parent = $next_page->post_parent;
          }
        }
        // add to page list array array
        $page_list[$page->ID] = $prefix . $page->post_title;
      }
      return $page_list;
    }

  }

  /**
   * Add the Gateway to WooCommerce
   * */
  function add_ppgeneric_gateway($methods) {
    $methods[] = 'PayPlusGeneric';
    return $methods;
  }

  add_filter('woocommerce_payment_gateways', 'add_ppgeneric_gateway');
  
}
