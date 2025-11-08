<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * NPC GBB Bridge – price-as-product (no fee line) + PayPal handoff
 * Flow:
 *   /?npc_gbb=1&model=14&tier=better&quality=aftermarket&priority=0&addons=&mode=paypal
 *   -> compute totals, push to cart, and if mode=paypal create order and redirect to PayPal
 */
add_action('template_redirect', function () {
  if ( empty($_GET['npc_gbb']) || ! class_exists('WooCommerce') ) return;

  $PRODUCT_ID = 1179;
  $mode = isset($_GET['mode']) ? sanitize_text_field($_GET['mode']) : 'checkout';

  // Inputs
  $model    = isset($_GET['model'])   ? sanitize_text_field($_GET['model'])   : '';
  $tier     = isset($_GET['tier'])    ? sanitize_text_field($_GET['tier'])    : 'better';
  $quality  = isset($_GET['quality']) ? sanitize_text_field($_GET['quality']) : 'aftermarket';
  $priority = isset($_GET['priority'])? intval($_GET['priority'])             : 0;
  $addons_s = isset($_GET['addons'])  ? sanitize_text_field($_GET['addons'])  : '';
  $addons_a = array_filter(array_map('trim', explode(',', $addons_s)));

  // Pricing tables
  $BASE_BETTER = array(
    "6"=>99,"6 Plus"=>99,"6s"=>99,"6s Plus"=>99,"7"=>99,"7 Plus"=>99,"8"=>99,"8 Plus"=>99,"X"=>99,"XR"=>99,
    "11"=>139,"12"=>139,"12 Pro"=>169,"12 Pro Max"=>209,
    "13 Mini"=>149,"13"=>149,"13 Pro"=>169,"13 Pro Max"=>209,
    "14"=>149,"14 Plus"=>149,"14 Pro"=>169,"14 Pro Max"=>209,
    "15"=>149,"15 Plus"=>149,"15 Pro"=>169,"15 Pro Max"=>209,
    "16"=>149,"16 Plus"=>149,"16 Pro"=>169,"16 Pro Max"=>209
  );
  $OLED = array(
    "6"=>null,"6 Plus"=>null,"6s"=>null,"6s Plus"=>null,"7"=>null,"7 Plus"=>null,"8"=>null,"8 Plus"=>null,"X"=>null,"XR"=>null,"11"=>null,
    "12"=>99,"12 Pro"=>99,"12 Pro Max"=>129,
    "13 Mini"=>139,"13"=>109,"13 Pro"=>189,"13 Pro Max"=>219,
    "14"=>119,"14 Plus"=>179,"14 Pro"=>249,"14 Pro Max"=>349,
    "15"=>249,"15 Plus"=>209,"15 Pro"=>359,"15 Pro Max"=>439,
    "16"=>299,"16 Plus"=>409,"16 Pro"=>449,"16 Pro Max"=>529
  );
  $ADDONS = array('batt'=>79,'clean'=>50,'port'=>89,'prot'=>10);

  // Validate + compute
  if (!isset($BASE_BETTER[$model])) $model = '14';
  if (!in_array($tier, array('good','better','premium'), true)) $tier = 'better';
  if ($quality !== 'oled' || $OLED[$model] === null) $quality = 'aftermarket';

  $base = (int)$BASE_BETTER[$model];
  if ($tier === 'good')    $base -= 19;
  if ($tier === 'premium') $base += 20;

  $qprice = ($quality === 'oled') ? (int)$OLED[$model] : 0;
  $prio   = max(0, min(99, (int)$priority));

  $addon_total = 0; $addon_names = array();
  foreach ($addons_a as $a) { if (isset($ADDONS[$a])) { $addon_total += $ADDONS[$a]; $addon_names[] = $a; } }

  $total = $base + $qprice + $prio + $addon_total;

  // Ensure session/cart
  if ( is_null(WC()->session) ) { wc()->initialize_session(); }
  if ( is_null(WC()->cart) ) { wc_load_cart(); }

  $label = sprintf('Screen Repair — %s (%s, %s)', $model, ucfirst($tier), ($quality==='oled'?'OLED':'Premium Aftermarket'));
  WC()->session->set('npc_gbb_total', $total);
  WC()->session->set('npc_gbb_label', $label);
  WC()->session->set('npc_gbb_meta', array(
    'npc_gbb_model'=>$model,'npc_gbb_tier'=>$tier,'npc_gbb_quality'=>$quality,'npc_gbb_priority'=>$prio,
    'npc_gbb_addons'=>implode(',', $addon_names),'npc_gbb_base'=>$base,'npc_gbb_qprice'=>$qprice,'npc_gbb_addons_total'=>$addon_total
  ));

  // Reset cart -> add the booking product with overridden price
  WC()->cart->empty_cart(true);
  $key = WC()->cart->add_to_cart($PRODUCT_ID, 1);
  if ($key) { WC()->cart->set_quantity($key, 1, false); }
  WC()->cart->calculate_totals();
  WC()->cart->set_session();
  WC()->cart->maybe_set_cart_cookies();

  // If PayPal one-click was requested, create order and handoff to the active PayPal gateway
  if ( $mode === 'paypal' ) {
    // Create order from current cart
    $order = wc_create_order();
    foreach ( WC()->cart->get_cart() as $cart_item ) {
      $order->add_product( $cart_item['data'], $cart_item['quantity'] );
    }
    $order->calculate_totals();

    // Try common PayPal gateway IDs in order
    $preferred = array(
      'ppcp-gateway',          // WooCommerce PayPal Payments
      'paypal',                // Legacy PayPal Standard
      'paypal_express',        // Some express plugins
      'wc_gateway_paypal'      // Edge cases
    );
    $gateways = WC()->payment_gateways()->payment_gateways();
    $paypal_gateway = null;
    foreach ($preferred as $id) {
      if ( isset($gateways[$id]) && $gateways[$id]->is_available() ) {
        $paypal_gateway = $gateways[$id];
        break;
      }
    }

    if ( $paypal_gateway ) {
      $order->set_payment_method( $paypal_gateway );
      $order->save();

      // Ask gateway for redirect URL
      $result = $paypal_gateway->process_payment( $order->get_id() );
      if ( is_array($result) && !empty($result['redirect']) ) {
        nocache_headers();
        wp_safe_redirect( $result['redirect'] );
        exit;
      }
    }
    // If no PayPal gateway found or no redirect, fall through to normal checkout:
  }

  // Default: send them to Woo checkout
  nocache_headers();
  wp_safe_redirect( wc_get_checkout_url() );
  exit;
});

/** Override product price + force qty=1 for product 1179 */
add_action('woocommerce_before_calculate_totals', function($cart){
  if ( is_admin() && ! defined('DOING_AJAX') ) return;
  if ( empty(WC()->session) ) return;

  $override = WC()->session->get('npc_gbb_total');
  $label    = WC()->session->get('npc_gbb_label');
  if ( ! $override ) return;

  foreach ($cart->get_cart() as $cart_key => $item) {
    if ((int)$item['product_id'] === 1179 && isset($item['data'])) {
      $item['data']->set_price( (float)$override );
      if ($label) $item['data']->set_name($label);
      if ($item['quantity'] !== 1) WC()->cart->set_quantity($cart_key, 1, false);
    }
  }
}, 20);
