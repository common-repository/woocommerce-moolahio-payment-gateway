<?php
if (!defined('ABSPATH')) {
  exit;
} // Exit if accessed directly

/**
 * Plugin Name: WooCommerce Moolah.io Gateway
 * Plugin URI: https://www.moolah.io/
 * Description:  Provides a Moolah.io Payment Gateway.
 * Author: Gaëtan Petit
 * Author URI:
 * Version: 1.0.1
 */
/**
 * Moolah.io Gateway
 * Based on the PayPal Standard Payment Gateway
 *
 * Provides a Moolah.io Payment Gateway.
 *
 * @class 		WC_Gateway_Moolah
 * @version		1.0.1
 * @package		WooCommerce/Classes/Payment
 * @author 		Moolah.io based on PayPal module by WooThemes & Coinpayments.net payment gateway
 */
add_action('plugins_loaded', 'moolah_gateway_load', 0);

function moolah_gateway_load() {
  // Check if WC exists.
  if (!class_exists('WC_Payment_Gateway')) {
    // oops!
    return;
  }

  /**
   * Add the gateway to WooCommerce.
   */
  add_filter('woocommerce_payment_gateways', 'wcmoolah_add_gateway');

  function wcmoolah_add_gateway($methods) {
    if (!in_array('WC_Gateway_Moolah', $methods)) {
      $methods[] = 'WC_Gateway_Moolah';
    }

    return $methods;
  }

  class WC_Gateway_Moolah extends WC_Payment_Gateway {

    private $ipn_url;

    /**
     * Gateway constructor
     * 
     * @access public
     */
    public function __construct() {
      global $woocommerce;

      // Basic conf.
      $this->id = 'moolah';
      $this->has_fields = false;
      $this->method_title = __('Moolah.io', 'woocommerce');
      $this->ipn_url = add_query_arg('wc-api', 'WC_Gateway_Moolah', home_url('/'));

      // Load the settings.
      $this->init_form_fields();
      $this->init_settings();
      
      // Logs
      $this->log = new WC_Logger();

      // Define user set variables.
      $this->title = $this->get_option('title');
      $this->description = $this->get_option('description');
      $this->api_key = $this->get_option('api_key');
      $this->ipn_secret = $this->get_option('ipn_secret');
      $this->send_shipping = $this->get_option('send_shipping');
      // Set the guids array.
      $this->guids = array();
      foreach ($this->get_currencies() as $name => $coin) {
        // Add value only if user setted a guid.
        $option_value = $this->get_option($coin . '_guid');
        if (isset($option_value) && !empty($option_value)) {
          $this->guids[$coin . '_guid'] = array('name' => $name, 'guid' => $option_value);
        }
      }

      $this->form_submission_method = $this->get_option('form_submission_method') == 'yes' ? true : false;

      // Actions.
      add_action('woocommerce_receipt_moolah', array($this, 'receipt_page'));
      add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

      // Payment listener/API hook
      add_action('woocommerce_api_wc_gateway_moolah', array($this, 'check_ipn_response'));
      
      if (!$this->is_valid_for_use())
        $this->enabled = false;
    }
    
    /**
     * Check if this gateway is enabled and available in the user's country
     *
     * @access public
     * @return bool
     */
    function is_valid_for_use() {
      // Well it's valid for use.
      return true;
    }

    /**
     * Admin Panel Options
     * - Options for bits like 'title' and availability on a country-by-country basis
     *
     * @since 1.0.0
     */
    public function admin_options() {
      ?>
      <h3><?php _e('Moolah.io', 'woocommerce'); ?></h3>
      <p><?php _e('Completes checkout via Moolah.io', 'woocommerce'); ?></p>

      <table class="form-table">
        <?php
        // Generate the HTML For the settings form.
        $this->generate_settings_html();
        ?>
      </table><!--/.form-table-->
      <?php
    }

    /**
     * Initialise Gateway Settings Form Fields
     *
     * @access public
     * @return void
     */
    public function init_form_fields() {

      foreach ($this->get_currencies() as $name => $coin) {
        $guids[$coin . '_guid'] = array(
            'title' => __($name . ' GUID', 'woocommerce'),
            'type' => 'text',
            'description' => __('Please enter your ' . $name . ' guid.', 'woocommerce'),
            'default' => '',
        );
      }

      $this->form_fields = array(
          'enabled' => array(
              'title' => __('Enable/Disable', 'woocommerce'),
              'type' => 'checkbox',
              'label' => __('Enable Moolah.io', 'woocommerce'),
              'default' => 'yes'
          ),
          'title' => array(
              'title' => __('Title', 'woocommerce'),
              'type' => 'text',
              'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
              'default' => __('Crypto currencies', 'woocommerce'),
              'desc_tip' => true,
          ),
          'description' => array(
              'title' => __('Description', 'woocommerce'),
              'type' => 'textarea',
              'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
              'default' => __('Pay with Bitcoin, Litecoin, Dogecoin or other altcoins via Moolah.io', 'woocommerce')
          ),
          'api_key' => array(
              'title' => __('API key', 'woocommerce'),
              'type' => 'text',
              'description' => __('Please enter your Moolah.io API key.', 'woocommerce'),
              'default' => '',
          ),
          'ipn_secret' => array(
              'title' => __('IPN Secret', 'woocommerce'),
              'type' => 'text',
              'description' => __('Please enter your Moolah.io IPN Secret.', 'woocommerce'),
              'default' => '',
          ),
          'invoice_prefix' => array(
              'title' => __('Invoice Prefix', 'woocommerce'),
              'type' => 'text',
              'description' => __('Please enter a prefix for your invoice numbers. If you use your Moolah.io account for multiple stores ensure this prefix is unique.', 'woocommerce'),
              'default' => 'WC-',
              'desc_tip' => true,
          ),
      );

      $this->form_fields = array_merge($this->form_fields, $guids);
    }

    public function payment_fields() {
      ?>

      <p><?php echo $this->description ?></p>

      <p><?php echo __('Select your currency:') ?></p>

      <form>
        <select name="moolah-guid">
          <?php foreach ($this->guids as $coin): ?>
            <option value="<?php echo $coin['guid'] ?>"><?php echo $coin['name'] ?></option>
      <?php endforeach ?>
        </select>
      </form>

      <p style="margin-top: 10px"><?php echo __('You will be redirected to the payment form.<br/>'
              . 'After sending the required amount of coins to the specified address <strong>please wait</strong> while the transaction is validated by the blockchain, for the page to refresh.')
      ?></p>

      <?php
    }
    
    /**
     * Validate payment fields.
     * 
     * @return bool
     */
    public function validate_fields() {
      global $woocommerce;

      if(isset($_POST['moolah-guid'])) {
        $this->crypto_guid = $_POST['moolah-guid'];
        return TRUE;
      } else {
        $error_message = __('Please select a currency.');
        $woocommerce->add_error(__('Payment error:', 'woothemes') . $error_message);
        return FALSE;
      }
    }
    
    /**
     * Get Moolah.io Args
     *
     * @access private
     * @param mixed $order
     * @return array
     */
    private function get_moolah_args($order) {
      global $woocommerce;
      
      $moolah_args = array(
        'currency' => get_woocommerce_currency(),
        'amount'   => number_format($order->get_total(), 8, '.', ''),
        'product'  => sprintf(__('Order %s', 'woocommerce'), $order->get_order_number()),
        'return'   => $this->get_return_url( $order ),
        'guid'     => $woocommerce->session->get('selected_guid'),
        'ipn'      => $this->ipn_url,
      );
      
      $moolah_args = apply_filters('woocommerce_moolah_args', $moolah_args);
      
      return $moolah_args;
    }

    /**
     * Generate the moolah button link
     *
     * @access public
     * @param mixed $order_id
     * @return string
     */
    public function generate_moolah_form($order_id) {
      global $woocommerce;

      $order = new WC_Order($order_id);
      $moolah_adr = "https://moolah.io/api/pay";

      // Get request param.
      $moolah_args = $this->get_moolah_args($order);
      // Build http query.
      $query = http_build_query($moolah_args);
      
      $query_secret = $query . '&secret=' . $this->ipn_secret;
      $hash = hash('sha256', $query_secret);
      $query .= '&hash=' . $hash;
      
      // Execute query.
      $result = file_get_contents($moolah_adr.'?'.$query);
      $response_data = json_decode($result);
      $payment_form_url = $response_data->url;
      // Store remote tx_id in post metadata.
      update_post_meta($order_id, 'moolah_tx', $response_data->tx);

      $woocommerce->add_inline_js('
        jQuery("body").block({
                        message: "' . esc_js(__('Thank you for your order. We are now redirecting you to Moolah.io to make payment.', 'woocommerce')) . '",
                        baseZ: 99999,
                        overlayCSS:
                        {
                          background: "#fff",
                          opacity: 0.6
                        },
                        css: {
                        padding:        "20px",
                        zindex:         "9999999",
                        textAlign:      "center",
                        color:          "#555",
                        border:         "3px solid #aaa",
                        backgroundColor:"#fff",
                        cursor:         "wait",
                        lineHeight:		"24px",
                    }
                });
        jQuery("#submit_moolah_payment_form").click();
      ');

      return '<form action="' . esc_url($payment_form_url) . '" method="GET" id="moolah_payment_form" target="_top">
                <input type="submit" class="button alt" id="submit_moolah_payment_form" value="' . __('Pay via Moolah.io', 'woocommerce') . '" /> <a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Cancel order &amp; restore cart', 'woocommerce') . '</a>
              </form>';
    }
    
    /**
     * Output for the order received page.
     * @param object $order
     * @return void
     */
    public function receipt_page($order) {
      echo '<p>' . __('Thank you for your order, please click the button below to pay with Moolah.io.', 'woocommerce') . '</p>';

      echo $this->generate_moolah_form($order);
    }
    
    /**
     * Process the payment and return the result
     *
     * @access public
     * @param int $order_id
     * @return array
     */
    function process_payment($order_id) {
      global $woocommerce;
      // Register user selected guid in session.
      $woocommerce->session->set('selected_guid', $_POST['moolah-guid']);
      
      $order = new WC_Order($order_id);
      
      return array(
          'result' => 'success',
          'redirect' => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
      );
    }
    
    /**
     * Check for Moolah IPN Response
     *
     * @access public
     * @return void
     */
    function check_ipn_response() {
      if (!empty($_GET) && $this->check_ipn_request_is_valid()) {
        header( 'HTTP/1.1 200 OK' );
      } else {
        wp_die("Moolah.io IPN Request Failure");
      }
    }

    /**
     * Check Moolah.net IPN validity
     **/
    function check_ipn_request_is_valid() {
      global $woocommerce;
      
      // Retrieve the IPN from $_GET if the caller did not supply an IPN array.
      // Note that Drupal has already run stripslashes() on the contents of the
      // $_GET array at this point, so we don't need to worry about them.
      $ipn = $_GET;

      // Exit now if the request is not a  $_GET.
      if (empty($ipn)) {
        wp_die('IPN URL accessed with no GET data submitted.');
      }

      // Exit if IPN parameters are no set.
      if (!isset($ipn['ipn_secret']) || !isset($ipn['status']) || !isset($ipn['tx'])) {
        $this->log->add('moolah', 'Something wrong with secret: '. $ipn['ipn_secret'] .' status: ' . $ipn['status'] . ' tx: '.$ipn['tx']);
        wp_die('Missing IPN parameter.');
      }

      $this->log->add('moolah', 'Processing with secret: '. $ipn['ipn_secret'] .' status: ' . $ipn['status'] . ' tx: '.$ipn['tx']);
      
      // Exit if the given IPN secret is incorrect.
      $ipn_secret = $this->ipn_secret;
      if ($ipn['ipn_secret'] != $ipn_secret) {
        $this->log->add('moolah', 'No mtach for, remote secret: '. $ipn['ipn_secret'] .'secret: ' . $this->ipn_secret);
        wp_die('Incorrect IPN secret.');
      }
      
      // Load transaction form remote tx_id
      $order = $this->get_order_by_tx($ipn['tx']);
      // No order found stop here.
      if(!$order) {
        $this->log->add('moolah', 'No order found for tx: '. $ipn['tx']);
        wp_die('No order found for given remote tx_id.');
      }
      
      // Update order according to IPN status.
      switch ($ipn['status']) {
        case 'cancelled':
          $order->update_status('cancelled', 'Moolah.io Payment cancelled/timed out.');
          break;

        case 'complete':
          $order->add_order_note('Moolah.io : payment complete.');
          $order->payment_complete();
          break;

        default:
          break;
      }
      
      return TRUE;
    }
    
    /**
     * Get all available currencies.
     * 
     * @return array
     */
    private function get_currencies() {
      $currencies = array(
          'Bitcoin' => 'bitcoin',
          'Litecoin' => 'litecoin',
          'Dogecoin' => 'dogecoin',
          'Vertcoin' => 'vertcoin',
          'Auroracoin' => 'auroracoin',
          'Mintcoin' => 'mintcoin',
          'Darkcoin' => 'darkcoin',
          'Maxcoin' => 'maxcoin',
      );

      return $currencies;
    }
    
    /**
     * Get order by remote tx_id.
     * 
     * @param string $tx_id
     * @return object Order
     */
    private function get_order_by_tx($tx_id) {
      $args = array(
          'post_type' => 'shop_order',
          'meta_query' => array(
              array(
                  'value' => $tx_id,
              )
          )
      );
      $my_query = new WP_Query($args);
      
      if(!is_null($my_query->get_posts())) {
        $posts = $my_query->get_posts();
        
        $order_id = $posts[0]->ID;
        return new WC_Order($order_id);
      }
      
      return FALSE;
    }
  }
}
