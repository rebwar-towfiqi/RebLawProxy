<?php
/*
Plugin Name: RebLaw Legal AI
Description: Legal AI Q&A box for RebLaw website with WooCommerce/YITH purchase-based access control.
Version: 2.0.0
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
    define('REBLAW_AI_PROXY_URL', 'https://reblawproxy-production.up.railway.app/ask');
}

/**
 * API: پراکسی هوش مصنوعی (حتماً آدرس واقعی خودتان را تنظیم کنید)
 * Expected: POST JSON { messages:[...], meta:{...} } -> { success:true, answer:"..." }
 */
if ( ! defined( 'REBLAW_AI_PROXY_URL' ) ) {
    define('REBLAW_AI_PROXY_URL', 'https://reblawproxy-production.up.railway.app/ask');
}

/**
 * Bot link (optional)
 */
if ( ! defined( 'REBLAW_BOT_LINK' ) ) {
    define( 'REBLAW_BOT_LINK', 'https://t.me/RebLCBot?start=receipt' );
}

/*--------------------------------------------------------------
  1) Access Control (WooCommerce Purchase + optional activation code)
--------------------------------------------------------------*/

/**
 * Map page ID -> Required WooCommerce Product ID
 * Replace these IDs with your real page/product IDs if needed.
 */
function reblaw_get_required_product_for_page( $post_id ) {
    switch ( (int) $post_id ) {
        case 4374: // صفحه مشاوره فوری
            return 4757; // Product: اشتراک مشاوره فوری
        case 4376: // صفحه درخواست لایحه
            return 4760; // Product: اشتراک درخواست لایحه
        case 4375: // صفحه تحلیل پرونده
            return 4761; // Product: اشتراک تحلیل پرونده
        default:
            return null; // no lock
    }
}

/**
 * Whether user has access based on:
 * - If page has no required product => access
 * - Must be logged in
 * - Either:
 *   a) has activation code meta, OR
 *   b) has purchased required product (any paid status)
 *
 * You can also force product with shortcode attribute: product="4761"
 */
function reblaw_user_has_access_for_page( $user_id, $post_id, $forced_product_id = null ) {

    $required_product_id = $forced_product_id ? (int) $forced_product_id : reblaw_get_required_product_for_page( $post_id );

    // Not locked
    if ( empty( $required_product_id ) ) {
        return true;
    }

    // Not logged in => no access
    if ( ! $user_id ) {
        return false;
    }

    // Manual activation code (for offline/manual approval)
    $activation_code = get_user_meta( $user_id, 'reblaw_activation_code', true );
    if ( ! empty( $activation_code ) ) {
        return true;
    }

    // WooCommerce purchase check
    if ( function_exists( 'wc_customer_bought_product' ) ) {
        $user = get_user_by( 'id', $user_id );
        if ( $user && ! empty( $user->user_email ) ) {
            if ( wc_customer_bought_product( $user->user_email, $user_id, $required_product_id ) ) {
                return true;
            }
        }
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
  3) Shortcode: [reblaw_legal_ai]
     Optional: [reblaw_legal_ai product="4761"]
--------------------------------------------------------------*/

function reblaw_legal_ai_shortcode( $atts = [] ) {

    $ajax_url = admin_url( 'admin-ajax.php' );
    $nonce    = wp_create_nonce( 'reblaw_ai_nonce' );

    $atts = shortcode_atts(
        [
            'product' => null, // Force required product id
        ],
        $atts,
        'reblaw_legal_ai'
    );

    $forced_product_id = ! empty( $atts['product'] ) ? (int) $atts['product'] : null;

    // Detect current page ID reliably
    if ( function_exists( 'get_queried_object_id' ) ) {
        $post_id = (int) get_queried_object_id();
    } else {
        global $post;
        $post_id = isset( $post->ID ) ? (int) $post->ID : 0;
    }

    $user_id    = get_current_user_id();
    $has_access = reblaw_user_has_access_for_page( $user_id, $post_id, $forced_product_id );

    ob_start();

    // If no access: show lock box
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

    // Access OK: show AI box
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

        <div id="reblaw-ai-status"
             style="margin-top:12px;font-size:13px;display:none;"></div>

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
  4) Law article detection: "ماده ۱۰ قانون مدنی"
--------------------------------------------------------------*/

function reblaw_detect_law_article( $question ) {
    $question = trim( (string) $question );

    // Example: ماده 10 قانون مدنی / ماده ۱۰ قانون مدنی
    $pattern = '/ماده\s*([0-9۰-۹]+)\s*قانون\s*([^\s،\.]+(?:\s*[^\s،\.]+)*)/u';

    if ( preg_match( $pattern, $question, $matches ) ) {

        $raw_number = $matches[1];
        // convert Persian digits to English
        $en_number = strtr( $raw_number, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'
        ] );

        $article_number = (int) $en_number;
        $law_name_raw   = trim( $matches[2] );

        // Normalize common naming
        if ( $law_name_raw === 'مدنی' ) {
            $law_name = 'قانون مدنی';
        } else {
            $law_name = $law_name_raw;
        }

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

    // 1) Nonce security
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'reblaw_ai_nonce' ) ) {
        wp_send_json_error( [ 'message' => 'درخواست نامعتبر است. لطفاً صفحه را رفرش کنید.' ] );
    }

    $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
    $user_id = get_current_user_id();

    // 2) Access check
    if ( ! reblaw_user_has_access_for_page( $user_id, $post_id ) ) {
        wp_send_json_error( [ 'message' => 'دسترسی شما برای این بخش فعال نیست. لطفاً با همان حسابی که خرید کرده‌اید وارد شوید یا دسترسی را فعال کنید.' ] );
    }

    // 3) Question
    $question = isset( $_POST['question'] ) ? sanitize_text_field( wp_unslash( $_POST['question'] ) ) : '';
    if ( empty( $question ) ) {
        wp_send_json_error( [ 'message' => 'سؤال خالی است.' ] );
    }

    // 4) Try detect law article and fetch official text
    $article_info  = reblaw_detect_law_article( $question );
    $article_block = '';
    $article_data  = null;

    if ( $article_info ) {
        $article_data = reblaw_fetch_article_from_api(
            $article_info['law_name'],
            $article_info['article_number']
        );

        if ( $article_data && ! empty( $article_data['text'] ) ) {
            $article_block =
                "📜 متن رسمی {$article_data['law_name']} – ماده {$article_data['article_number']}:\n"
                . $article_data['text'] . "\n\n";
        }
    }

    // 5) System prompt
    $system_prompt =
"شما دستیار حقوقی هوشمند وب‌سایت RebLaw هستید.
- حوزه اصلی: حقوق ایران (قانون مدنی، قانون مجازات اسلامی، آیین دادرسی‌ها و سایر قوانین مرتبط).
- اگر متن رسمی ماده قانونی در ورودی وجود دارد، تحلیل خود را مبتنی بر همان متن و اصول حقوقی ارائه کن.
- از حدس‌زدن متن مواد یا شماره مواد خودداری کن؛ اگر متن رسمی ارائه نشده یا مطمئن نیستی، شفاف بگو.
- در پایان یادآوری کن که پاسخ جایگزین مشاوره حضوری و وکالت حرفه‌ای نیست.";

    // 6) Build user content
    $user_content = '';
    if ( ! empty( $article_block ) ) {
        $user_content .= $article_block;
    }
    $user_content .= "سؤال کاربر:\n" . $question;

    $messages = [
        [ 'role' => 'system', 'content' => $system_prompt ],
        [ 'role' => 'user',   'content' => $user_content ],
    ];

    $payload = [
        'messages' => $messages,
        'meta'     => [
            'source'  => 'reblaw-wordpress',
            'user_id' => (int) $user_id,
            'post_id' => (int) $post_id,
        ],
    ];

    // 7) Send to AI proxy
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
        wp_send_json_error( [ 'message' => 'خطا در اتصال به سرور هوش مصنوعی. لطفاً بعداً دوباره تلاش کنید.' ] );
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $raw  = wp_remote_retrieve_body( $response );

    if ( $code !== 200 || empty( $raw ) ) {
        wp_send_json_error( [ 'message' => 'پاسخ معتبری از سرور هوش مصنوعی دریافت نشد.' ] );
    }

    $data = json_decode( $raw, true );

    // Expected: { success:true, answer:"..." }
    if ( ! is_array( $data ) || empty( $data['success'] ) || empty( $data['answer'] ) ) {
        $msg = ( is_array( $data ) && ! empty( $data['message'] ) ) ? $data['message'] : 'هوش مصنوعی نتوانست پاسخی تولید کند.';
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

    // TODO: اگر endpoint پرونده‌ها را تغییر دادید، این آدرس را هم اصلاح کنید
    $api_base = 'https://reblaw-ai-proxy-production.up.railway.app';
    $api_url  = $api_base . '/cases?limit=' . (int) $atts['limit'];

    $response = wp_remote_get( $api_url, [ 'timeout' => 12 ] );

    if ( is_wp_error( $response ) ) {
        return '<p>⚠ اتصال به سرور پرونده‌های RebLaw برقرار نشد. لطفاً بعداً دوباره تلاش کنید.</p>';
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $raw  = wp_remote_retrieve_body( $response );

    if ( $code !== 200 || empty( $raw ) ) {
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
