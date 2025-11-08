<?php
if (!defined('ABSPATH')) exit;

/**
 * NPC GBB – Generic Bridge (data-driven)
 * - Frontend calls:
 *     /?npc_gbb=1&product_id=1179&profile=iphone
 *       &model=14&tier=better&quality=aftermarket&priority=0
 *       &addons=batt,clean
 * - Bridge loads /wp-content/uploads/npc-gbb/{profile}.json
 * - Recomputes total server-side from that pricebook
 * - Overrides product price (qty=1), builds label from template, redirects to checkout
 *
 * Pricebook schema (flexible):
 * {
 *   "label_template": "Screen Repair — {model} ({tier_label}, {quality_label})",
 *   "labels": {
 *     "tiers": {"good":"Discount — No Warranty","better":"Standard — 33-Day Warranty","premium":"Premium — 183-Day Warranty"},
 *     "qualities": {"aftermarket":"Premium Aftermarket","oled":"OEM-Match OLED"}
 *   },
 *   "base": { "14":149, "14 Pro":169, ... },                 // base price by model OR "base_number": 0
 *   "tiers": { "good": -19, "better": 0, "premium": 20 },    // optional
 *   "quality": {
 *     "aftermarket": 0,
 *     "oled": { "14":119, "14 Pro":249, ... }                // can be number or per-model map
 *   },
 *   "priority": { "standard": 0, "priority": 39, "priority_plus": 59, "priority_pro": 99 }, // optional
 *   "addons": { "batt":79, "clean":50, "port":89, "prot":10 }                                 // optional
 * }
 */

add_action('template_redirect', function () {
  if (empty($_GET['npc_gbb']) || !class_exists('WooCommerce')) return;

  // ---- Inputs ----
  $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 1179;
  $profile    = isset($_GET['profile'])    ? sanitize_key($_GET['profile']) : 'iphone';

  // Common selections (your frontends should send these names; add more as you like)
  $model      = isset($_GET['model'])      ? sanitize_text_field($_GET['model'])      : '';
  $tier       = isset($_GET['tier'])       ? sanitize_text_field($_GET['tier'])       : '';
  $quality    = isset($_GET['quality'])    ? sanitize_text_field($_GET['quality'])    : '';
  $priority   = isset($_GET['priority'])   ? sanitize_text_field($_GET['priority'])   : '';
  $addons_str = isset($_GET['addons'])     ? sanitize_text_field($_GET['addons'])     : '';
  $addons     = array_filter(array_map('trim', explode(',', $addons_str)));

  // You can accept arbitrary extra selections (cpu, gpu, ram, etc.) and sum them as addon ids
  // Example: if your PC page sends &cpu=ryzen7&gpu=rtx4070, just merge them into $addons by convention.
  foreach (['cpu','gpu','ram','ssd','psu','case','cooler','os'] as $k) {
    if (!empty($_GET[$k])) $addons[] = sanitize_text_field($_GET[$k]);
  }

  // ---- Load pricebook JSON for profile ----
  $pricebook = npc_gbb_load_pricebook($profile);
  if (!$pricebook) {
    wp_die('Pricebook not found for profile: ' . esc_html($profile));
  }

  // ---- Compute total from pricebook + selections ----
  $calc = npc_gbb_compute_total($pricebook, [
    'model'   => $model,
    'tier'    => $tier,
    'quality' => $quality,
    'priority'=> $priority,
    'addons'  => $addons,
  ]);

  // ---- Ensure session/cart ----
  if (is_null(WC()->session)) { wc()->initialize_session(); }
  if (is_null(WC()->cart))    { wc_load_cart(); }

  // ---- Build label from template ----
  $label = npc_gbb_label_from_template($pricebook, [
    'model'         => $model,
    'tier'          => $tier,
    'tier_label'    => npc_gbb_map_label($pricebook, 'tiers', $tier),
    'quality'       => $quality,
    'quality_label' => npc_gbb_map_label($pricebook, 'qualities', $quality),
    'total'         => wc_price($calc['total']),
  ]);

  // ---- Persist summary for order meta ----
  WC()->session->set('npc_gbb_total', $calc['total']);
  WC()->session->set('npc_gbb_label', $label);
  WC()->session->set('npc_gbb_meta', [
    'npc_gbb_profile'   => $profile,
    'npc_gbb_model'     => $model,
    'npc_gbb_tier'      => $tier,
    'npc_gbb_quality'   => $quality,
    'npc_gbb_priority'  => $priority,
    'npc_gbb_addons'    => implode(',', $addons),
    'npc_gbb_breakdown' => wp_json_encode($calc, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
  ]);

  // ---- Reset cart → add product (override price below), force qty=1 ----
  WC()->cart->empty_cart(true);
  $key = WC()->cart->add_to_cart($product_id, 1);
  if ($key) { WC()->cart->set_quantity($key, 1, false); }

  WC()->cart->calculate_totals();
  WC()->cart->set_session();
  WC()->cart->maybe_set_cart_cookies();

  // ---- Redirect to checkout ----
  nocache_headers();
  wp_safe_redirect(wc_get_checkout_url());
  exit;
});

/** Load pricebook JSON from /wp-content/uploads/npc-gbb/{profile}.json */
function npc_gbb_load_pricebook($profile) {
  $uploads = wp_get_upload_dir();
  $dir = trailingslashit($uploads['basedir']) . 'npc-gbb/';
  $file = $dir . sanitize_file_name($profile) . '.json';
  if (!file_exists($file)) return null;
  $json = file_get_contents($file);
  if (!$json) return null;
  $data = json_decode($json, true);
  return is_array($data) ? $data : null;
}

/** Compute total from pricebook + selections (generic) */
function npc_gbb_compute_total($pb, $sel) {
  $model   = (string)($sel['model'] ?? '');
  $tier    = (string)($sel['tier'] ?? '');
  $quality = (string)($sel['quality'] ?? '');
  $prioKey = (string)($sel['priority'] ?? '');
  $addons  = is_array($sel['addons'] ?? null) ? $sel['addons'] : [];

  $sum = 0;
  $break = [];

  // base
  if (isset($pb['base']) && is_array($pb['base'])) {
    $b = isset($pb['base'][$model]) ? floatval($pb['base'][$model]) : 0;
    $sum += $b; $break['base'] = $b;
  } elseif (isset($pb['base_number'])) {
    $b = floatval($pb['base_number']);
    $sum += $b; $break['base'] = $b;
  } else {
    $break['base'] = 0;
  }

  // tier delta
  if (!empty($tier) && !empty($pb['tiers']) && is_array($pb['tiers'])) {
    $t = isset($pb['tiers'][$tier]) ? floatval($pb['tiers'][$tier]) : 0;
    $sum += $t; $break['tier'] = $t;
  } else { $break['tier'] = 0; }

  // quality surcharge (number or per-model map)
  $qprice = 0;
  if (!empty($quality) && !empty($pb['quality']) && is_array($pb['quality'])) {
    if (isset($pb['quality'][$quality])) {
      $qVal = $pb['quality'][$quality];
      if (is_array($qVal)) {
        $qprice = isset($qVal[$model]) ? floatval($qVal[$model]) : 0;
      } else {
        $qprice = floatval($qVal);
      }
    }
  }
  $sum += $qprice; $break['quality'] = $qprice;

  // priority (map by key OR numeric passed)
  $pprice = 0;
  if ($prioKey !== '') {
    if (!empty($pb['priority']) && is_array($pb['priority']) && isset($pb['priority'][$prioKey])) {
      $pprice = floatval($pb['priority'][$prioKey]);
    } elseif (is_numeric($prioKey)) {
      $pprice = floatval($prioKey); // allow raw numeric priority if page passed a number
    }
  }
  $sum += $pprice; $break['priority'] = $pprice;

  // addons sum
  $aprice = 0;
  if (!empty($addons) && !empty($pb['addons']) && is_array($pb['addons'])) {
    foreach ($addons as $a) {
      if (isset($pb['addons'][$a])) $aprice += floatval($pb['addons'][$a]);
    }
  }
  $sum += $aprice; $break['addons'] = $aprice;

  return ['total' => round($sum, 2), 'breakdown' => $break];
}

/** Label from template with fallback */
function npc_gbb_label_from_template($pb, $ctx) {
  $tpl = !empty($pb['label_template']) ? $pb['label_template'] : 'Configured Item — {model} ({tier_label}, {quality_label})';
  $map = [
    '{model}'         => (string)($ctx['model'] ?? ''),
    '{tier}'          => (string)($ctx['tier'] ?? ''),
    '{tier_label}'    => (string)($ctx['tier_label'] ?? ''),
    '{quality}'       => (string)($ctx['quality'] ?? ''),
    '{quality_label}' => (string)($ctx['quality_label'] ?? ''),
    '{total}'         => (string)($ctx['total'] ?? ''),
  ];
  return strtr($tpl, $map);
}

/** Map a label from pricebook.labels */
function npc_gbb_map_label($pb, $group, $key) {
  if (empty($key) || empty($pb['labels'][$group]) || !is_array($pb['labels'][$group])) return $key;
  return isset($pb['labels'][$group][$key]) ? $pb['labels'][$group][$key] : $key;
}

/** Override product line price + force qty=1 */
add_action('woocommerce_before_calculate_totals', function($cart){
  if (is_admin() && !defined('DOING_AJAX')) return;
  if (empty(WC()->session)) return;

  $override = WC()->session->get('npc_gbb_total');
  $label    = WC()->session->get('npc_gbb_label');
  if (!$override) return;

  foreach ($cart->get_cart() as $cart_key => $item) {
    if (!isset($item['data'])) continue;
    // Always enforce our computed price and name on the single line item
    $item['data']->set_price( (float)$override );
    if ($label) $item['data']->set_name($label);
    if ($item['quantity'] !== 1) WC()->cart->set_quantity($cart_key, 1, false);
  }
}, 20);

/** Copy session meta to order line items */
add_action('woocommerce_checkout_create_order_line_item', function($item, $cart_item_key, $values, $order){
  $meta = WC()->session ? WC()->session->get('npc_gbb_meta') : null;
  if (!$meta || !is_array($meta)) return;
  foreach ($meta as $k => $v) {
    $item->add_meta_data(
      ucfirst(str_replace(['_','npc_gbb '], [' ',''], str_replace('npc_gbb_', 'npc gbb ', $k))),
      $v,
      true
    );
  }
}, 10, 4);
