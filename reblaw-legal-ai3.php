<?php
/*
Plugin Name: RebLaw Legal AI
Description: Legal AI Q&A box for RebLaw website with WooCommerce/YITH purchase-based access control.
Version: 2.1.0
Author: Rebwar Towfiqi
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*--------------------------------------------------------------
  0) Constants
--------------------------------------------------------------*/

/**
 * API: دریافت متن ماده قانونی از Railway
 * Expected: POST JSON { law_name, article_number } -> { success, law_name, law_code, article_number, text, source }
 */
if ( ! defined( 'REBLAW_LAW_API_URL' ) ) {
    // TODO: اگر URL واقعی Law API متفاوت است، اینجا اصلاح کنید
    define( 'REBLAW_LAW_API_URL', 'https://reblaw-law-api-production.up.railway.app/api/article-by-name' );
}

/**
 * API: پراکسی هوش مصنوعی
 * Recommended: https://reblawproxy-production.up.railway.app/ask
 */
if ( ! defined( 'REBLAW_AI_PROXY_URL' ) ) {
    define( 'REBLAW_AI_PROXY_URL', 'https://reblawproxy-production.up.railway.app/ask' );
}

/**
 * Bot link (optional)
 */
if ( ! defined( 'REBLAW_BOT_LINK' ) ) {
    define( 'REBLAW_BOT_LINK', 'https://t.me/RebLCBot?start=receipt' );
}

/**
 * Cases API base (optional)
 * اگر سرویس پرونده‌ها ندارید، می‌توانید shortcode مربوط به cases را استفاده نکنید.
 */
if ( ! defined( 'REBLAW_CASES_API_BASE' ) ) {
    // اگر کیس‌ها روی همین پراکسی نیست، این مقدار را تغییر دهید
    define( 'REBLAW_CASES_API_BASE', 'https://reblawproxy-production.up.railway.app' );
}

/*--------------------------------------------------------------
  1) Access Control (WooCommerce Purchase + optional activation code)
--------------------------------------------------------------*/

function reblaw_get_required_product_for_page( $post_id ) {
    switch ( (int) $post_id ) {
        case 4374: // صفحه مشاوره فوری
            return 6077;
        case 4376: // صفحه درخواست لایحه/دادخواست
            return 4760;
        case 4375: // صفحه تحلیل پرونده
            return 4761;
        default:
            return null;
    }
}

/*--------------------------------------------------------------
  1.1) Entitlements (expiry-based) for smart state: none/expired/active
--------------------------------------------------------------*/

function reblaw_product_validity_days( $product_id ) {
    $product_id = (int) $product_id;

    // سرویس‌های تکی: 1 روز
    if ( in_array( $product_id, [6077, 4760, 4761], true ) ) {
        return 1;
    }

    // اشتراک کامل: 30 روز
    if ( $product_id === 6223 ) {
        return 30;
    }

    return 0;
}

function reblaw_get_entitlement_meta_key( $product_id ) {
    return 'reblaw_expiry_' . (int) $product_id;
}

add_action( 'woocommerce_order_status_processing', 'reblaw_set_entitlement_expiry_from_order' );
add_action( 'woocommerce_order_status_completed',  'reblaw_set_entitlement_expiry_from_order' );

function reblaw_set_entitlement_expiry_from_order( $order_id ) {
    if ( ! function_exists( 'wc_get_order' ) ) return;

    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    $user_id = (int) $order->get_user_id();
    if ( ! $user_id ) return;

    foreach ( $order->get_items() as $item ) {
        $product_id = (int) $item->get_product_id();
        $days       = (int) reblaw_product_validity_days( $product_id );

        if ( $days <= 0 ) continue;

        $meta_key = reblaw_get_entitlement_meta_key( $product_id );
        $now      = time();
        $current  = (int) get_user_meta( $user_id, $meta_key, true );

        // تمدید هوشمند: اگر هنوز فعال است، روی باقی‌مانده تمدید می‌شود
        $base = ( $current > $now ) ? $current : $now;
        $new_expiry = $base + ( $days * DAY_IN_SECONDS );

        update_user_meta( $user_id, $meta_key, $new_expiry );
    }
}

/**
 * وضعیت دسترسی برای یک محصول:
 * not_logged_in | none | expired | active
 */
function reblaw_entitlement_state( $user_id, $product_id ) {
    $user_id    = (int) $user_id;
    $product_id = (int) $product_id;

    if ( ! $user_id ) return 'not_logged_in';

    // فعال‌سازی دستی (ادمین) = همیشه فعال
    $activation_code = get_user_meta( $user_id, 'reblaw_activation_code', true );
    if ( ! empty( $activation_code ) ) return 'active';

    $days = (int) reblaw_product_validity_days( $product_id );
    if ( $days <= 0 ) return 'none';

    $meta_key = reblaw_get_entitlement_meta_key( $product_id );
    $expiry   = (int) get_user_meta( $user_id, $meta_key, true );

    if ( $expiry > 0 ) {
        return ( $expiry >= time() ) ? 'active' : 'expired';
    }

    // اگر برای خریدهای قدیمی meta نداریم، از آخرین خرید استنتاج می‌کنیم و ذخیره می‌کنیم
    if ( function_exists( 'wc_get_orders' ) ) {
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit'       => 20,
            'status'      => ['processing','completed'],
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                if ( (int) $item->get_product_id() === $product_id ) {

                    $ts = $order->get_date_paid()
                        ? $order->get_date_paid()->getTimestamp()
                        : $order->get_date_created()->getTimestamp();

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
 * آیا کاربر دسترسی فعال دارد؟ (سرویس تکی یا اشتراک کامل)
 */
function reblaw_user_has_active_access( $user_id, $required_product_id ) {
    $user_id = (int) $user_id;
    $required_product_id = (int) $required_product_id;

    if ( ! $user_id ) return false;

    // فعال‌سازی دستی
    $activation_code = get_user_meta( $user_id, 'reblaw_activation_code', true );
    if ( ! empty( $activation_code ) ) return true;

    // دسترسی سرویس تکی
    if ( reblaw_entitlement_state( $user_id, $required_product_id ) === 'active' ) {
        return true;
    }

    // دسترسی اشتراک کامل
    if ( reblaw_entitlement_state( $user_id, 6223 ) === 'active' ) {
        return true;
    }

    return false;
}

/*--------------------------------------------------------------
  2) User Profile field: Activation Code (optional)
--------------------------------------------------------------*/

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
                    در صورت فعال‌سازی دستی (مثلاً پرداخت کارت‌به‌کارت/رسید)، این کد را برای کاربر ثبت کنید تا دسترسی فعال شود.
                </p>
            </td>
        </tr>
    </table>
    <?php
}

add_action( 'personal_options_update', 'reblaw_save_activation_code' );
add_action( 'edit_user_profile_update', 'reblaw_save_activation_code' );

function reblaw_save_activation_code( $user_id ) {
    if ( ! current_user_can( 'edit_user', $user_id ) ) {
        return;
    }
    if ( isset( $_POST['reblaw_activation_code'] ) ) {
        update_user_meta(
            $user_id,
            'reblaw_activation_code',
            sanitize_text_field( wp_unslash( $_POST['reblaw_activation_code'] ) )
        );
    }
}

/*--------------------------------------------------------------
  3) Shortcode: [reblaw_legal_ai]  Optional: [reblaw_legal_ai product="4761"]
--------------------------------------------------------------*/

function reblaw_legal_ai_shortcode( $atts = [] ) {

    $ajax_url = admin_url( 'admin-ajax.php' );
    $nonce    = wp_create_nonce( 'reblaw_ai_nonce' );

    $atts = shortcode_atts(
        [
            'product' => null,
        ],
        $atts,
        'reblaw_legal_ai'
    );

    $forced_product_id = ! empty( $atts['product'] ) ? (int) $atts['product'] : null;

    $post_id = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
    if ( ! $post_id ) {
        global $post;
        $post_id = isset( $post->ID ) ? (int) $post->ID : 0;
    }

    $user_id    = get_current_user_id();
    $has_access = reblaw_user_has_access_for_page( $user_id, $post_id, $forced_product_id );

    ob_start();

    if ( ! $has_access ) {
        ?>
        <div style="max-width:720px;margin:28px auto;padding:22px;border-radius:14px;background:linear-gradient(135deg,#0b1220,#111827);color:#e5e7eb;border:1px solid rgba(148,163,184,.25);box-shadow:0 18px 40px rgba(2,6,23,.25);direction:rtl;text-align:right;">
            <div style="font-weight:900;color:#fca5a5;margin:0 0 10px;font-size:16px;display:flex;align-items:center;gap:8px;">
                <span style="font-size:16px">🔒</span> دسترسی محدود است
            </div>

            <p style="margin:0 0 10px;font-size:13px;line-height:2;">
                برای استفاده از دستیار حقوقی هوشمند در این صفحه، باید اشتراک مربوطه را خریداری کرده باشید (یا دسترسی دستی برای شما فعال شده باشد).
            </p>

            <ul style="margin:0;padding:0 18px 0 0;line-height:2;font-size:13px;">
                <li>اگر خرید را انجام داده‌اید، لطفاً مطمئن شوید با همان حسابی وارد شده‌اید که خرید با آن ثبت شده است.</li>
                <li>اگر پرداخت شما دستی است، پس از تأیید، دسترسی برای حساب شما فعال می‌شود.</li>
            </ul>

            <p style="margin:10px 0 0;font-size:13px;">
                اگر نیاز به پشتیبانی دارید:
                <a href="<?php echo esc_url( REBLAW_BOT_LINK ); ?>" target="_blank" rel="noopener" style="color:#22c55e;text-decoration:none;font-weight:900;">
                    @RebLCBot
                </a>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }
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

                setStatus('در حال ارسال سؤال به هوش مصنوعی...', '#ffd166');
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
                        var msg = (data && data.data && data.data.message) ? data.data.message : 'پاسخی از هوش مصنوعی دریافت نشد.';
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

/*--------------------------------------------------------------
  Smart Lock Shortcode (SAFE): [reblaw_smart_lock ...]
--------------------------------------------------------------*/

if ( ! function_exists( 'reblaw_smart_lock_shortcode' ) ) {

    function reblaw_smart_lock_shortcode( $atts ) {

        $atts = shortcode_atts([
            'product_id' => 0,
            'service'    => 'این سرویس',
            'full_url'   => 'https://rlawcoin.com/?page_id=6224&lang=fa',
            'full_pid'   => 6223,
        ], $atts);

        $product_id = (int) $atts['product_id'];
        $service    = sanitize_text_field( $atts['service'] );
        $full_url   = esc_url( $atts['full_url'] );
        $full_pid   = (int) $atts['full_pid'];

        $user_id = get_current_user_id();

        // اگر توابع entitlement هنوز لود نشده باشند، خطا نده
        if ( ! function_exists( 'reblaw_entitlement_state' ) ) {
            $state_service = $user_id ? 'none' : 'not_logged_in';
            $state_full    = $user_id ? 'none' : 'not_logged_in';
        } else {
            // اگر اشتراک کامل فعال است، قفل را اصلاً نمایش نده
            if ( $user_id && reblaw_entitlement_state( $user_id, $full_pid ) === 'active' ) {
                return '';
            }

            $state_service = reblaw_entitlement_state( $user_id, $product_id );
            $state_full    = reblaw_entitlement_state( $user_id, $full_pid );
        }

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
        <div class="reblaw-locked">
          <div class="reblaw-locked-title"><?php echo esc_html($title); ?></div>

          <p style="margin:0 0 10px;font-size:13px;line-height:2"><?php echo $msg; ?></p>

          <ul>
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

}

add_action( 'init', function() {
    if ( shortcode_exists( 'reblaw_smart_lock' ) ) {
        return; // اگر قبلاً ثبت شده، دوباره ثبت نکن
    }
    add_shortcode( 'reblaw_smart_lock', 'reblaw_smart_lock_shortcode' );
}, 20 );

/*--------------------------------------------------------------
  4) Law article detection: "ماده ۱۰ قانون مدنی"
--------------------------------------------------------------*/

function reblaw_detect_law_article( $question ) {
    $question = trim( (string) $question );
    $pattern = '/ماده\s*([0-9۰-۹]+)\s*قانون\s*([^\s،\.]+(?:\s*[^\s،\.]+)*)/u';

    if ( preg_match( $pattern, $question, $matches ) ) {

        $raw_number = $matches[1];
        $en_number = strtr( $raw_number, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'
        ] );

        $article_number = (int) $en_number;
        $law_name_raw   = trim( $matches[2] );

        $law_name = ( $law_name_raw === 'مدنی' ) ? 'قانون مدنی' : $law_name_raw;

        if ( $article_number <= 0 || empty( $law_name ) ) {
            return null;
        }

        return [
            'article_number' => $article_number,
            'law_name'       => $law_name,
        ];
    }

    return null;
}

/*--------------------------------------------------------------
  5) Fetch article text from Railway API
--------------------------------------------------------------*/

function reblaw_fetch_article_from_api( $law_name, $article_number ) {

    $api_url = REBLAW_LAW_API_URL;

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
        error_log('[RebLaw LawAPI] wp_remote_post error: ' . $response->get_error_message());
        return null;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $raw  = wp_remote_retrieve_body( $response );

    if ( $code !== 200 || empty( $raw ) ) {
        return null;
    }

    $data = json_decode( $raw, true );
    if ( ! is_array( $data ) || empty( $data['success'] ) ) {
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

/*--------------------------------------------------------------
  6) AJAX handler: Send request to AI Proxy (with access check)
--------------------------------------------------------------*/

add_action( 'wp_ajax_reblaw_ai_handle_request', 'reblaw_ai_handle_request' );
add_action( 'wp_ajax_nopriv_reblaw_ai_handle_request', 'reblaw_ai_handle_request' );

function reblaw_ai_handle_request() {

    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'reblaw_ai_nonce' ) ) {
        wp_send_json_error( [ 'message' => 'درخواست نامعتبر است. لطفاً صفحه را رفرش کنید.' ] );
    }

    $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
    $user_id = get_current_user_id();

    if ( ! reblaw_user_has_access_for_page( $user_id, $post_id ) ) {
        wp_send_json_error( [ 'message' => 'دسترسی شما برای این بخش فعال نیست. لطفاً با همان حسابی که خرید کرده‌اید وارد شوید یا دسترسی را فعال کنید.' ] );
    }

    // Use textarea sanitization to avoid losing punctuation/newlines
    $question = isset( $_POST['question'] ) ? sanitize_textarea_field( wp_unslash( $_POST['question'] ) ) : '';
    $question = trim( (string) $question );

    if ( $question === '' ) {
        wp_send_json_error( [ 'message' => 'سؤال خالی است.' ] );
    }

    // Detect law article and fetch official text
    $article_info  = reblaw_detect_law_article( $question );
    $article_block = '';
    $article_data  = null;

    if ( $article_info ) {
        $article_data = reblaw_fetch_article_from_api(
            $article_info['law_name'],
            $article_info['article_number']
        );

        if ( $article_data && ! empty( $article_data['text'] ) ) {
            $law_name_esc = (string) $article_data['law_name'];
            $art_no_esc   = (int) $article_data['article_number'];

            $article_block =
                "📜 متن رسمی {$law_name_esc} – ماده {$art_no_esc}:\n"
                . (string) $article_data['text'] . "\n\n";
        }
    }

    $system_prompt =
"شما دستیار حقوقی هوشمند وب‌سایت RebLaw هستید.
- حوزه اصلی: حقوق ایران (قانون مدنی، قانون مجازات اسلامی، آیین دادرسی‌ها و سایر قوانین مرتبط).
- اگر متن رسمی ماده قانونی در ورودی وجود دارد، تحلیل خود را مبتنی بر همان متن و اصول حقوقی ارائه کن.
- از حدس‌زدن متن مواد یا شماره مواد خودداری کن؛ اگر متن رسمی ارائه نشده یا مطمئن نیستی، شفاف بگو.
- در پایان یادآوری کن که پاسخ جایگزین مشاوره حضوری و وکالت حرفه‌ای نیست.";

    $user_content = '';
    if ( $article_block !== '' ) {
        $user_content .= $article_block;
    }
    $user_content .= "سؤال کاربر:\n" . $question;

    $messages = [
        [ 'role' => 'system', 'content' => $system_prompt ],
        [ 'role' => 'user',   'content' => $user_content ],
    ];

    // Payload compatible with BOTH proxy styles:
    // - New: {messages:[...], meta:{...}}
    // - Legacy: {question:"..."}
    $payload = [
        'messages' => $messages,
        'question' => $question, // fallback for proxies that only accept "question"
        'meta'     => [
            'source'  => 'reblaw-wordpress',
            'user_id' => (int) $user_id,
            'post_id' => (int) $post_id,
        ],
    ];

    $response = wp_remote_post( REBLAW_AI_PROXY_URL, [
        'method'      => 'POST',
        'headers'     => [
            'Content-Type' => 'application/json; charset=utf-8',
        ],
        'body'        => wp_json_encode( $payload ),
        'timeout'     => 25,
        'data_format' => 'body',
    ] );

    if ( is_wp_error( $response ) ) {
        error_log('[RebLaw AI] wp_remote_post error: ' . $response->get_error_message());
        wp_send_json_error( [ 'message' => 'خطا در اتصال به سرور هوش مصنوعی. لطفاً بعداً دوباره تلاش کنید.' ] );
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $raw  = (string) wp_remote_retrieve_body( $response );

    if ( $code !== 200 || $raw === '' ) {
        error_log('[RebLaw AI] Bad response code/body. code=' . $code . ' body=' . substr($raw, 0, 500));
        wp_send_json_error( [ 'message' => 'پاسخ معتبری از سرور هوش مصنوعی دریافت نشد.' ] );
    }

    $data = json_decode( $raw, true );

    // Expected: { success:true, answer:"..." }
    if ( ! is_array( $data ) || empty( $data['success'] ) || empty( $data['answer'] ) ) {
        $msg = ( is_array( $data ) && ! empty( $data['message'] ) ) ? (string) $data['message'] : 'هوش مصنوعی نتوانست پاسخی تولید کند.';
        error_log('[RebLaw AI] Unexpected proxy JSON: ' . substr($raw, 0, 500));
        wp_send_json_error( [ 'message' => $msg ] );
    }

    wp_send_json_success( [ 'answer' => (string) $data['answer'] ] );
}

/*--------------------------------------------------------------
  7) Shortcode: Famous cases list [reblaw_cases limit="10"]
--------------------------------------------------------------*/

function reblaw_display_cases_shortcode( $atts ) {

    $atts = shortcode_atts(
        [
            'limit' => 10,
        ],
        $atts,
        'reblaw_cases'
    );

    $api_url  = rtrim( REBLAW_CASES_API_BASE, '/' ) . '/cases?limit=' . (int) $atts['limit'];

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
