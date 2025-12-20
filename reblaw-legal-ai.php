<?php
/*
Plugin Name: RebLaw Legal AI
Description: Legal AI Q&A + Smart Membership Lock (WooCommerce/YITH compatible) with anti-hallucination law-article guard.
Version: 2.2.0
Author: Rebwar Towfiqi
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*==============================================================
  0) Constants
==============================================================*/

/**
 * Law Article API (Railway)
 * Expected: POST JSON { law_name, article_number } -> { success, law_name, law_code, article_number, text, source }
 */
if ( ! defined( 'REBLAW_LAW_API_URL' ) ) {
    define( 'REBLAW_LAW_API_URL', 'https://reblaw-law-api-production.up.railway.app/api/article-by-name' );
}

/**
 * AI Proxy API (Railway)
 * Expected: POST JSON {messages:[...], question:"...", meta:{...}} -> { success:true, answer:"..." }
 */
if ( ! defined( 'REBLAW_AI_PROXY_URL' ) ) {
    define( 'REBLAW_AI_PROXY_URL', 'https://reblawproxy-production.up.railway.app/ask' );
}

/**
 * Bot Link for Support
 */
if ( ! defined( 'REBLAW_BOT_LINK' ) ) {
    define( 'REBLAW_BOT_LINK', 'https://t.me/RebLCBot?start=receipt' );
}

/**
 * Optional Cases API base
 */
if ( ! defined( 'REBLAW_CASES_API_BASE' ) ) {
    define( 'REBLAW_CASES_API_BASE', 'https://reblawproxy-production.up.railway.app' );
}

/**
 * Full subscription WooCommerce Product ID (اشتراک کامل)
 */
if ( ! defined( 'REBLAW_FULL_PRODUCT_ID' ) ) {
    define( 'REBLAW_FULL_PRODUCT_ID', 6223 );
}

/*==============================================================
  0.1) Safety / Debug
==============================================================*/

function reblaw_option_key( $k ) {
    return 'reblaw_legal_ai_' . $k;
}

function reblaw_is_debug() {
    return (bool) get_option( reblaw_option_key('debug'), false );
}

function reblaw_log( $msg ) {
    if ( reblaw_is_debug() ) {
        error_log('[RebLaw] ' . $msg);
    }
}

/*==============================================================
  1) Access Control Mapping (page -> required product)
==============================================================*/

function reblaw_get_required_product_for_page( $post_id ) {
    switch ( (int) $post_id ) {
        case 4374: // مشاوره فوری
            return 6077;
        case 4376: // درخواست دادخواست/لایحه
            return 4760;
        case 4375: // تحلیل پرونده
            return 4761;
        default:
            return 0;
    }
}

/*==============================================================
  1.1) Entitlements (expiry-based) for smart state
==============================================================*/

/**
 * Validity days per product
 * - single services: 1 day
 * - full subscription: 30 days
 */
function reblaw_product_validity_days( $product_id ) {
    $product_id = (int) $product_id;

    // سرویس‌های تکی
    if ( in_array( $product_id, [6077, 4760, 4761], true ) ) {
        return 1;
    }

    // اشتراک کامل
    if ( $product_id === (int) REBLAW_FULL_PRODUCT_ID ) {
        return 30;
    }

    return 0;
}

function reblaw_get_entitlement_meta_key( $product_id ) {
    return 'reblaw_expiry_' . (int) $product_id;
}

/**
 * Set entitlement expiry on paid order (processing / completed)
 * Fail-safe: if Woo not present, do nothing.
 */
add_action( 'woocommerce_order_status_processing', 'reblaw_set_entitlement_expiry_from_order' );
add_action( 'woocommerce_order_status_completed',  'reblaw_set_entitlement_expiry_from_order' );

function reblaw_set_entitlement_expiry_from_order( $order_id ) {
    if ( ! function_exists( 'wc_get_order' ) ) {
        return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    $user_id = (int) $order->get_user_id();
    if ( ! $user_id ) {
        return;
    }

    foreach ( $order->get_items() as $item ) {
        if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) continue;

        $product_id = (int) $item->get_product_id();
        $days       = (int) reblaw_product_validity_days( $product_id );

        if ( $days <= 0 ) continue;

        $meta_key = reblaw_get_entitlement_meta_key( $product_id );
        $now      = time();
        $current  = (int) get_user_meta( $user_id, $meta_key, true );

        // Extend from remaining time if still active
        $base = ( $current > $now ) ? $current : $now;
        $new_expiry = $base + ( $days * DAY_IN_SECONDS );

        update_user_meta( $user_id, $meta_key, $new_expiry );

        reblaw_log("Entitlement set: user={$user_id} product={$product_id} expiry={$new_expiry}");
    }
}

/**
 * Entitlement state:
 * not_logged_in | none | expired | active
 */
function reblaw_entitlement_state( $user_id, $product_id ) {
    $user_id    = (int) $user_id;
    $product_id = (int) $product_id;

    if ( ! $user_id ) return 'not_logged_in';

    // Manual activation override
    $activation_code = get_user_meta( $user_id, 'reblaw_activation_code', true );
    if ( ! empty( $activation_code ) ) return 'active';

    $days = (int) reblaw_product_validity_days( $product_id );
    if ( $days <= 0 ) return 'none';

    $meta_key = reblaw_get_entitlement_meta_key( $product_id );
    $expiry   = (int) get_user_meta( $user_id, $meta_key, true );

    if ( $expiry > 0 ) {
        return ( $expiry >= time() ) ? 'active' : 'expired';
    }

    // If no meta yet, infer from last orders
    if ( function_exists( 'wc_get_orders' ) ) {
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit'       => 25,
            'status'      => ['processing','completed'],
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        foreach ( $orders as $order ) {
            if ( ! $order || ! method_exists($order,'get_items') ) continue;

            foreach ( $order->get_items() as $item ) {
                if ( (int) $item->get_product_id() === $product_id ) {

                    $ts = 0;
                    if ( method_exists($order,'get_date_paid') && $order->get_date_paid() ) {
                        $ts = (int) $order->get_date_paid()->getTimestamp();
                    } elseif ( method_exists($order,'get_date_created') && $order->get_date_created() ) {
                        $ts = (int) $order->get_date_created()->getTimestamp();
                    } else {
                        $ts = time();
                    }

                    $inferred_expiry = $ts + ( $days * DAY_IN_SECONDS );
                    update_user_meta( $user_id, $meta_key, $inferred_expiry );

                    return ( $inferred_expiry >= time() ) ? 'active' : 'expired';
                }
            }
        }
    }

    return 'none';
}

/**
 * Active access = service active OR full active OR manual activation
 */
function reblaw_user_has_active_access( $user_id, $required_product_id ) {
    $user_id = (int) $user_id;
    $required_product_id = (int) $required_product_id;

    if ( ! $user_id ) return false;

    $activation_code = get_user_meta( $user_id, 'reblaw_activation_code', true );
    if ( ! empty( $activation_code ) ) return true;

    if ( $required_product_id > 0 && reblaw_entitlement_state( $user_id, $required_product_id ) === 'active' ) {
        return true;
    }

    if ( reblaw_entitlement_state( $user_id, (int) REBLAW_FULL_PRODUCT_ID ) === 'active' ) {
        return true;
    }

    return false;
}

/**
 * For AI shortcode: check access by page mapping or forced product
 */
function reblaw_user_has_access_for_page( $user_id, $post_id, $forced_product_id = 0 ) {
    $required_product_id = $forced_product_id ? (int) $forced_product_id : (int) reblaw_get_required_product_for_page( $post_id );
    if ( $required_product_id <= 0 ) return true; // public page
    return reblaw_user_has_active_access( $user_id, $required_product_id );
}

/*==============================================================
  2) User Profile field: Manual Activation Code
==============================================================*/

add_action( 'show_user_profile', 'reblaw_activation_code_field' );
add_action( 'edit_user_profile', 'reblaw_activation_code_field' );

function reblaw_activation_code_field( $user ) {
    ?>
    <h3>کد فعال‌سازی دستی RebLaw</h3>
    <table class="form-table">
        <tr>
            <th><label for="reblaw_activation_code">کد فعال‌سازی</label></th>
            <td>
                <input type="text"
                       name="reblaw_activation_code"
                       id="reblaw_activation_code"
                       value="<?php echo esc_attr( get_user_meta( $user->ID, 'reblaw_activation_code', true ) ); ?>"
                       class="regular-text" />
                <p class="description">
                    در صورت فعال‌سازی دستی (پرداخت دستی/رسید)، این مقدار را برای کاربر ثبت کنید تا دسترسی فعال شود.
                </p>
            </td>
        </tr>
    </table>
    <?php
}

add_action( 'personal_options_update', 'reblaw_save_activation_code' );
add_action( 'edit_user_profile_update', 'reblaw_save_activation_code' );

function reblaw_save_activation_code( $user_id ) {
    if ( ! current_user_can( 'edit_user', $user_id ) ) return;
    if ( isset( $_POST['reblaw_activation_code'] ) ) {
        update_user_meta(
            $user_id,
            'reblaw_activation_code',
            sanitize_text_field( wp_unslash( $_POST['reblaw_activation_code'] ) )
        );
    }
}

/*==============================================================
  3) Smart Lock Shortcode: [reblaw_smart_lock ...]
==============================================================*/

function reblaw_smart_lock_shortcode( $atts ) {

    $atts = shortcode_atts([
        'product_id' => 0,                         // service product
        'service'    => 'این سرویس',
        'full_pid'   => (int) REBLAW_FULL_PRODUCT_ID,
    ], $atts, 'reblaw_smart_lock' );

    $product_id = (int) $atts['product_id'];
    $service    = sanitize_text_field( $atts['service'] );
    $full_pid   = (int) $atts['full_pid'];

    $user_id = get_current_user_id();

    // If full subscription active: show nothing (no lock)
    if ( $user_id && reblaw_entitlement_state( $user_id, $full_pid ) === 'active' ) {
        return '';
    }

    $state_service = reblaw_entitlement_state( $user_id, $product_id );
    $state_full    = reblaw_entitlement_state( $user_id, $full_pid );

    $title = 'دسترسی این بخش محدود است';

    if ( $state_service === 'not_logged_in' ) {
        $msg = "برای استفاده از <b>{$service}</b> ابتدا وارد حساب کاربری شوید، سپس اشتراک را تهیه کنید.";
    } elseif ( $state_full === 'expired' ) {
        $msg = "اشتراک کامل شما <b style='color:#fde68a'>منقضی شده</b> است. برای ادامه استفاده، لطفاً آن را تمدید کنید.";
    } elseif ( $state_service === 'expired' ) {
        $msg = "اشتراک شما برای <b>{$service}</b> <b style='color:#fde68a'>منقضی شده</b> است. برای ادامه استفاده، لطفاً تمدید کنید.";
    } else {
        $msg = "برای استفاده از <b>{$service}</b> لازم است اشتراک فعال داشته باشید. به نظر می‌رسد هنوز این اشتراک را تهیه نکرده‌اید.";
    }

    ob_start(); ?>
    <div class="reblaw-locked" style="border-radius:18px;padding:18px 16px;background:linear-gradient(135deg,#0f172a,#111827);color:#e5e7eb;border:1px solid rgba(148,163,184,.25);box-shadow:0 18px 40px rgba(2,6,23,.25);margin:0 0 18px;direction:rtl;text-align:right;">
      <div class="reblaw-locked-title" style="font-weight:900;color:#fca5a5;margin:0 0 8px;font-size:16px;display:flex;align-items:center;gap:8px;">
        <span style="font-size:16px">🔒</span> <?php echo esc_html($title); ?>
      </div>

      <p style="margin:0 0 10px;font-size:13px;line-height:2"><?php echo $msg; ?></p>

      <ul style="margin:0;padding:0 18px 0 0;line-height:2;font-size:13px">
        <li>فعال‌سازی بلافاصله پس از پرداخت انجام می‌شود.</li>
        <li>می‌توانید اشتراک تکی یا اشتراک کامل را انتخاب کنید.</li>
      </ul>

      <div style="margin-top:14px;display:flex;flex-wrap:wrap;gap:10px;align-items:center">
        <?php
          if ( $product_id > 0 ) {
              echo do_shortcode('[add_to_cart id="'.$product_id.'" show_price="true"]');
          }
          echo do_shortcode('[add_to_cart id="'.$full_pid.'" show_price="true"]');
        ?>
      </div>

      <p style="margin:10px 0 0;font-size:12px;line-height:1.9;opacity:.9">
        نکته: اگر خرید انجام داده‌اید، مطمئن شوید با همان حساب وارد شده‌اید.
      </p>
    </div>
    <?php
    return ob_get_clean();
}

add_action( 'init', function() {
    if ( ! shortcode_exists( 'reblaw_smart_lock' ) ) {
        add_shortcode( 'reblaw_smart_lock', 'reblaw_smart_lock_shortcode' );
    }
}, 20 );

/*==============================================================
  4) Law detection + normalization (Anti-hallucination support)
==============================================================*/

function reblaw_normalize_law_name( $law_name ) {
    $law_name = trim( (string) $law_name );

    // Strip trailing punctuation (including :)
    $law_name = preg_replace('/[\s\:\؛\،\.\-–—]+$/u', '', $law_name);
    $law_name = preg_replace('/\s+/u', ' ', $law_name);

    $map = [
        'مدنی' => 'قانون مدنی',
        'مجازات اسلامی' => 'قانون مجازات اسلامی',
        'ق.م.ا' => 'قانون مجازات اسلامی',
        'ق.م' => 'قانون مدنی',
    ];

    if ( isset($map[$law_name]) ) return $map[$law_name];

    if ( $law_name === 'قانون مجازات اسلامی' ) return $law_name;
    if ( $law_name === 'قانون مدنی' ) return $law_name;

    return $law_name;
}

/**
 * Detect: "ماده ۲۷ قانون مجازات اسلامی:" and extract optional user-provided text after ":"
 */
function reblaw_detect_law_article( $question ) {

    $q = trim( (string) $question );
    if ( $q === '' ) return null;

    // Support ":" and "：" and "؛"
    $pattern = '/ماده\s*([0-9۰-۹]+)\s*(?:از\s*)?(?:قانون|ق\.)\s*([^\n\r]+?)(?:\s*[:：؛\n\r]|$)/u';

    if ( ! preg_match( $pattern, $q, $matches ) ) {
        return null;
    }

    $raw_number = $matches[1];
    $en_number = strtr( $raw_number, [
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'
    ] );

    $article_number = (int) $en_number;
    $law_name_raw   = trim( (string) $matches[2] );
    $law_name       = reblaw_normalize_law_name( $law_name_raw );

    if ( $article_number <= 0 || $law_name === '' ) {
        return null;
    }

    $user_provided_text = '';
    if ( preg_match('/[:：]\s*(.+)$/us', $q, $m2) ) {
        $user_provided_text = trim((string)$m2[1]);
    }

    return [
        'article_number'     => $article_number,
        'law_name'           => $law_name,
        'user_provided_text' => $user_provided_text,
    ];
}

/*==============================================================
  5) Fetch article text from Law API (Railway)
==============================================================*/

function reblaw_fetch_article_from_api( $law_name, $article_number ) {

    $api_url = (string) REBLAW_LAW_API_URL;

    $body = [
        'law_name'       => (string) $law_name,
        'article_number' => (int) $article_number,
    ];

    $response = wp_remote_post( $api_url, [
        'method'      => 'POST',
        'headers'     => [
            'Content-Type' => 'application/json; charset=utf-8',
        ],
        'body'        => wp_json_encode( $body ),
        'timeout'     => 15,
        'data_format' => 'body',
    ] );

    if ( is_wp_error( $response ) ) {
        reblaw_log('LawAPI error: ' . $response->get_error_message());
        return null;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $raw  = (string) wp_remote_retrieve_body( $response );

    if ( $code !== 200 || $raw === '' ) {
        reblaw_log("LawAPI bad response: code={$code} body=" . substr($raw,0,200));
        return null;
    }

    $data = json_decode( $raw, true );
    if ( ! is_array( $data ) || empty( $data['success'] ) ) {
        reblaw_log("LawAPI unexpected JSON: " . substr($raw,0,200));
        return null;
    }

    return [
        'law_name'       => $data['law_name']       ?? $law_name,
        'law_code'       => $data['law_code']       ?? '',
        'article_number' => $data['article_number'] ?? $article_number,
        'text'           => $data['text']           ?? '',
        'source'         => $data['source']         ?? '',
    ];
}

/*==============================================================
  6) Shortcode: [reblaw_legal_ai]  Optional: product="4761"
==============================================================*/

function reblaw_legal_ai_shortcode( $atts = [] ) {

    $ajax_url = admin_url( 'admin-ajax.php' );
    $nonce    = wp_create_nonce( 'reblaw_ai_nonce' );

    $atts = shortcode_atts(
        [
            'product' => 0,
        ],
        $atts,
        'reblaw_legal_ai'
    );

    $forced_product_id = (int) $atts['product'];

    $post_id = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
    if ( ! $post_id ) {
        global $post;
        $post_id = isset( $post->ID ) ? (int) $post->ID : 0;
    }

    $user_id    = get_current_user_id();
    $has_access = reblaw_user_has_access_for_page( $user_id, $post_id, $forced_product_id );

    ob_start();

    if ( ! $has_access ) : ?>
        <div style="max-width:720px;margin:28px auto;padding:22px;border-radius:14px;background:linear-gradient(135deg,#0b1220,#111827);color:#e5e7eb;border:1px solid rgba(148,163,184,.25);box-shadow:0 18px 40px rgba(2,6,23,.25);direction:rtl;text-align:right;">
            <div style="font-weight:900;color:#fca5a5;margin:0 0 10px;font-size:16px;display:flex;align-items:center;gap:8px;">
                <span style="font-size:16px">🔒</span> دسترسی محدود است
            </div>

            <p style="margin:0 0 10px;font-size:13px;line-height:2;">
                برای استفاده از دستیار حقوقی هوشمند در این صفحه، باید اشتراک مربوطه را فعال داشته باشید (یا دسترسی دستی برای شما فعال شده باشد).
            </p>

            <ul style="margin:0;padding:0 18px 0 0;line-height:2;font-size:13px;">
                <li>اگر خرید را انجام داده‌اید، مطمئن شوید با همان حسابی وارد شده‌اید که خرید با آن ثبت شده است.</li>
                <li>اگر اشتراک شما منقضی شده، لازم است تمدید کنید.</li>
            </ul>

            <p style="margin:10px 0 0;font-size:13px;">
                پشتیبانی:
                <a href="<?php echo esc_url( REBLAW_BOT_LINK ); ?>" target="_blank" rel="noopener" style="color:#22c55e;text-decoration:none;font-weight:900;">
                    @RebLCBot
                </a>
            </p>
        </div>
    <?php
        return ob_get_clean();
    endif;
    ?>

    <div id="reblaw-ai-box"
         data-post-id="<?php echo esc_attr( $post_id ); ?>"
         style="max-width:720px;margin:28px auto;padding:24px;border-radius:14px;background:#020824;color:#fff;box-shadow:0 0 25px rgba(0,0,0,0.45);direction:rtl;text-align:right;border:1px solid rgba(148,163,184,.18);">
        <h3 style="margin:0 0 14px;font-size:18px;font-weight:900;color:#22c55e;">
            دستیار حقوقی هوشمند – RebLaw AI
        </h3>

        <textarea id="reblaw-ai-question"
                  style="width:100%;min-height:140px;border-radius:10px;border:1px solid #1b2745;background:#050c1b;color:#fff;padding:12px 12px;font-size:14px;resize:vertical;outline:none;"
                  placeholder="سؤال حقوقی خود را اینجا بنویسید..."></textarea>

        <button id="reblaw-ai-submit"
                type="button"
                style="margin-top:12px;padding:10px 22px;border:none;border-radius:999px;background:linear-gradient(135deg,#f59e0b,#22c55e);color:#0b1020;font-weight:900;cursor:pointer;">
            ارسال سؤال
        </button>

        <div id="reblaw-ai-status" style="margin-top:12px;font-size:13px;display:none;"></div>

        <div id="reblaw-ai-answer"
             style="margin-top:14px;padding:14px;border-radius:10px;background:#050c1b;border:1px solid #232b46;font-size:14px;line-height:2;display:none;white-space:pre-wrap;"></div>
    </div>

    <script>
    (function () {
        function ready(fn){ if(document.readyState !== 'loading'){ fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }

        ready(function () {
            var box       = document.getElementById('reblaw-ai-box');
            var questionEl = document.getElementById('reblaw-ai-question');
            var submitBtn  = document.getElementById('reblaw-ai-submit');
            var statusEl   = document.getElementById('reblaw-ai-status');
            var answerEl   = document.getElementById('reblaw-ai-answer');

            if (!box || !questionEl || !submitBtn || !statusEl || !answerEl) return;

            var ajaxUrl = '<?php echo esc_js( $ajax_url ); ?>';
            var nonce   = '<?php echo esc_js( $nonce ); ?>';
            var postId  = box.getAttribute('data-post-id') || '0';

            function setStatus(text, color){
                statusEl.style.display = 'block';
                statusEl.style.color = color || '#ffd166';
                statusEl.textContent = text;
            }

            submitBtn.addEventListener('click', function (e) {
                e.preventDefault();

                var question = (questionEl.value || '').trim();
                if (!question) {
                    setStatus('لطفاً ابتدا سؤال خود را وارد کنید.', '#ff6b6b');
                    return;
                }

                setStatus('در حال ارسال سؤال...', '#ffd166');
                answerEl.style.display = 'none';
                answerEl.textContent = '';

                var formData = new FormData();
                formData.append('action', 'reblaw_ai_handle_request');
                formData.append('nonce', nonce);
                formData.append('question', question);
                formData.append('post_id', postId);

                fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data && data.success && data.data && data.data.answer) {
                        statusEl.style.display = 'none';
                        answerEl.style.display = 'block';
                        answerEl.textContent = data.data.answer;
                    } else {
                        var msg = (data && data.data && data.data.message) ? data.data.message : 'پاسخی دریافت نشد.';
                        setStatus(msg, '#ff6b6b');
                    }
                })
                .catch(function () {
                    setStatus('خطا در ارتباط با سرور. لطفاً بعداً دوباره تلاش کنید.', '#ff6b6b');
                });
            });
        });
    })();
    </script>

    <?php
    return ob_get_clean();
}
add_shortcode( 'reblaw_legal_ai', 'reblaw_legal_ai_shortcode' );

/*==============================================================
  7) AJAX handler: Send request to AI Proxy (with anti-hallucination guard)
==============================================================*/

add_action( 'wp_ajax_reblaw_ai_handle_request', 'reblaw_ai_handle_request' );
add_action( 'wp_ajax_nopriv_reblaw_ai_handle_request', 'reblaw_ai_handle_request' );

function reblaw_ai_handle_request() {

    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'reblaw_ai_nonce' ) ) {
        wp_send_json_error( [ 'message' => 'درخواست نامعتبر است. لطفاً صفحه را رفرش کنید.' ] );
    }

    $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
    $user_id = get_current_user_id();

    if ( ! reblaw_user_has_access_for_page( $user_id, $post_id, 0 ) ) {
        wp_send_json_error( [ 'message' => 'دسترسی شما فعال نیست. لطفاً با همان حساب وارد شوید یا اشتراک را فعال/تمدید کنید.' ] );
    }

    $question = isset( $_POST['question'] ) ? sanitize_textarea_field( wp_unslash( $_POST['question'] ) ) : '';
    $question = trim( (string) $question );

    if ( $question === '' ) {
        wp_send_json_error( [ 'message' => 'سؤال خالی است.' ] );
    }

    // Detect law article and fetch official text or use user-provided text after ":"
    $article_info  = reblaw_detect_law_article( $question );
    $article_block = '';

    if ( $article_info ) {

        $law_name = (string) $article_info['law_name'];
        $art_no   = (int) $article_info['article_number'];

        // If user provided text after ":" use it
        if ( ! empty( $article_info['user_provided_text'] ) ) {

            $article_block =
                "📌 متن ماده (ارائه‌شده توسط کاربر) — {$law_name} ماده {$art_no}:\n"
                . (string) $article_info['user_provided_text'] . "\n\n"
                . "⚠️ یادداشت سیستم: این متن توسط کاربر ارائه شده و ممکن است نیاز به تطبیق با نسخه رسمی داشته باشد.\n\n";

        } else {

            $article_data = reblaw_fetch_article_from_api( $law_name, $art_no );

            if ( $article_data && ! empty( $article_data['text'] ) ) {
                $article_block =
                    "📜 متن رسمی {$article_data['law_name']} – ماده {$article_data['article_number']}:\n"
                    . (string) $article_data['text'] . "\n\n";
            } else {
                // Anti-hallucination guard: explicit forbid quoting text
                $article_block =
                    "⚠️ هشدار سیستم: متن رسمی ماده از پایگاه داده پیدا نشد. شما حق ندارید متن ماده را نقل‌قول کنید یا از خودتان متن بسازید.\n"
                    . "فقط اعلام کنید: «متن رسمی این ماده در دسترس نیست/یافت نشد» و سپس تحلیل کلی ارائه دهید یا از کاربر بخواهید متن رسمی را ارسال کند.\n\n";
            }
        }
    }

    $system_prompt =
"شما دستیار حقوقی وب‌سایت RebLaw هستید.
- حوزه اصلی: حقوق ایران.
- اگر «متن رسمی ماده» یا «متن ماده ارائه‌شده توسط کاربر» در ورودی وجود دارد، تحلیل را دقیقاً مبتنی بر همان متن انجام بده.
- اگر متن رسمی ماده در ورودی نیست یا سیستم اعلام کرده «یافت نشد»، تحت هیچ شرایطی متن ماده را نقل‌قول نکن و متن جعلی تولید نکن.
- اگر متن رسمی یافت نشد، فقط بگو: «متن رسمی این ماده در دسترس/یافت نشد» و سپس تحلیل کلی بده یا از کاربر بخواه متن رسمی را ارسال کند.
- در پایان یادآوری کن: پاسخ جایگزین مشاوره حضوری و وکالت حرفه‌ای نیست.";

    $user_content = '';
    if ( $article_block !== '' ) {
        $user_content .= $article_block;
    }
    $user_content .= "سؤال کاربر:\n" . $question;

    $messages = [
        [ 'role' => 'system', 'content' => $system_prompt ],
        [ 'role' => 'user',   'content' => $user_content ],
    ];

    $payload = [
        'messages' => $messages,
        'question' => $question,
        'meta'     => [
            'source'  => 'reblaw-wordpress',
            'user_id' => (int) $user_id,
            'post_id' => (int) $post_id,
        ],
    ];

    $response = wp_remote_post( (string) REBLAW_AI_PROXY_URL, [
        'method'      => 'POST',
        'headers'     => [
            'Content-Type' => 'application/json; charset=utf-8',
        ],
        'body'        => wp_json_encode( $payload ),
        'timeout'     => 25,
        'data_format' => 'body',
    ] );

    if ( is_wp_error( $response ) ) {
        reblaw_log('AI Proxy error: ' . $response->get_error_message());
        wp_send_json_error( [ 'message' => 'خطا در اتصال به سرور هوش مصنوعی. لطفاً بعداً دوباره تلاش کنید.' ] );
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $raw  = (string) wp_remote_retrieve_body( $response );

    if ( $code !== 200 || $raw === '' ) {
        reblaw_log("AI Proxy bad response: code={$code} body=" . substr($raw,0,200));
        wp_send_json_error( [ 'message' => 'پاسخ معتبری از سرور هوش مصنوعی دریافت نشد.' ] );
    }

    $data = json_decode( $raw, true );

    if ( ! is_array( $data ) || empty( $data['success'] ) || empty( $data['answer'] ) ) {
        $msg = ( is_array( $data ) && ! empty( $data['message'] ) ) ? (string) $data['message'] : 'هوش مصنوعی نتوانست پاسخ تولید کند.';
        reblaw_log("AI Proxy unexpected JSON: " . substr($raw,0,200));
        wp_send_json_error( [ 'message' => $msg ] );
    }

    wp_send_json_success( [ 'answer' => (string) $data['answer'] ] );
}

/*==============================================================
  8) Shortcode: Famous cases list [reblaw_cases limit="10"]
==============================================================*/

function reblaw_display_cases_shortcode( $atts ) {

    $atts = shortcode_atts(
        [
            'limit' => 10,
        ],
        $atts,
        'reblaw_cases'
    );

    $api_url  = rtrim( (string) REBLAW_CASES_API_BASE, '/' ) . '/cases?limit=' . (int) $atts['limit'];
    $response = wp_remote_get( $api_url, [ 'timeout' => 12 ] );

    if ( is_wp_error( $response ) ) {
        return '<p>⚠ اتصال به سرور پرونده‌های RebLaw برقرار نشد. لطفاً بعداً دوباره تلاش کنید.</p>';
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $raw  = (string) wp_remote_retrieve_body( $response );

    if ( $code !== 200 || $raw === '' ) {
        return '<p>⚠ پاسخ نامعتبر از سرور پرونده‌ها (کد ' . esc_html( $code ) . ').</p>';
    }

    $data = json_decode( $raw, true );
    if ( ! is_array( $data ) || empty( $data['cases'] ) || ! is_array( $data['cases'] ) ) {
        return '<p>📂 هنوز پرونده‌ای برای نمایش ثبت نشده است.</p>';
    }

    $out  = '<div class="reblaw-cases-wrapper" style="max-width:1000px;margin:20px auto;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;">';

    foreach ( $data['cases'] as $case ) {
        $title   = esc_html( $case['title'] ?? '' );
        $summary = esc_html( $case['summary'] ?? '' );
        $id      = (int) ( $case['id'] ?? 0 );

        $out .= '<div class="reblaw-case-card" style="border:1px solid #e5e7eb;border-radius:14px;padding:14px;background:#fff;">';
        $out .= '<h3 style="margin:0 0 8px;font-size:15px;font-weight:900;color:#111827;">' . $title . '</h3>';
        $out .= '<p style="margin:0 0 10px;font-size:13px;line-height:1.9;color:#4b5563;">' . $summary . '</p>';

        if ( $id ) {
            $out .= '<a href="/case/' . $id . '" class="reblaw-btn" style="display:inline-block;padding:8px 12px;border-radius:999px;background:#111827;color:#fff;text-decoration:none;font-weight:900;font-size:12px;">مشاهده جزئیات</a>';
        }

        $out .= '</div>';
    }

    $out .= '</div>';
    $out .= '<style>@media(max-width:900px){.reblaw-cases-wrapper{grid-template-columns:1fr !important;}}</style>';

    return $out;
}
add_shortcode( 'reblaw_cases', 'reblaw_display_cases_shortcode' );

/*==============================================================
  9) Admin Settings (Debug toggle)
==============================================================*/

add_action('admin_menu', function() {
    add_options_page(
        'RebLaw Legal AI',
        'RebLaw Legal AI',
        'manage_options',
        'reblaw-legal-ai-settings',
        'reblaw_render_settings_page'
    );
});

function reblaw_render_settings_page() {
    if ( ! current_user_can('manage_options') ) return;

    if ( isset($_POST['reblaw_save_settings']) && check_admin_referer('reblaw_save_settings_nonce') ) {
        $debug = isset($_POST['reblaw_debug']) ? 1 : 0;
        update_option( reblaw_option_key('debug'), $debug );
        echo '<div class="updated"><p>تنظیمات ذخیره شد.</p></div>';
    }

    $debug = reblaw_is_debug();
    ?>
    <div class="wrap">
        <h1>RebLaw Legal AI — Settings</h1>

        <form method="post">
            <?php wp_nonce_field('reblaw_save_settings_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">Debug Log</th>
                    <td>
                        <label>
                            <input type="checkbox" name="reblaw_debug" value="1" <?php checked($debug, true); ?> />
                            فعال باشد (خطاها در error_log ثبت می‌شود)
                        </label>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button class="button button-primary" type="submit" name="reblaw_save_settings">ذخیره</button>
            </p>

            <hr>

            <h2>Quick Reference</h2>
            <p>
                <b>AI Shortcode:</b> <code>[reblaw_legal_ai]</code><br>
                <b>Smart Lock:</b> <code>[reblaw_smart_lock product_id="4761" service="تحلیل پرونده"]</code>
            </p>
        </form>
    </div>
    <?php
}
