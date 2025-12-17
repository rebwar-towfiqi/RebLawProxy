<?php
/*
Plugin Name: RebLaw Legal AI
Description: Simple legal AI Q&A box for RebLaw website with subscription lock support.
Version: 1.1.0
Author: Rebwar Towfiqi
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * API آدرس گرفتن متن مواد قانونی از Railway
 */
if (!defined('REBLAW_LAW_API_URL')) {
    define('REBLAW_LAW_API_URL', 'https://reblaw-law-api-production.up.railway.app/api/article-by-name');
}

/**
 * API آدرس پراکسی هوش مصنوعی
 * ❗ حتماً این را با آدرس واقعی پراکسی خودت روی Railway تنظیم کن.
 * مثال: https://reblaw-ai-proxy-production.up.railway.app/api/ask
 */
if (!defined('REBLAW_AI_PROXY_URL')) {
    define('REBLAW_AI_PROXY_URL', 'https://YOUR-AI-PROXY-URL-HERE/api/ask');
}

/*--------------------------------------------------------------
  1) تنظیمات قفل اشتراک / Level مورد نیاز برای هر صفحه
--------------------------------------------------------------*/

/**
 * مشخص می‌کند هر صفحه کدام Level اشتراک (Paid Memberships Pro) را لازم دارد.
 */
function reblaw_get_required_level_for_page( $post_id ) {
    switch ( (int) $post_id ) {
        case 4374: // صفحه مشاوره فوری
            return 2; // ID اشتراک مشاوره فوری
        case 4376: // صفحه درخواست لایحه
            return 3; // ID اشتراک لایحه
        case 4375: // صفحه تحلیل پرونده و قرارداد
            return 4; // ID اشتراک تحلیل پرونده
        default:
            return null; // سایر صفحات قفل نشوند
    }
}

/**
 * چک می‌کند آیا کاربر برای این صفحه دسترسی دارد یا خیر
 *  - اگر Level خاصی تعریف نشده: دسترسی آزاد
 *  - اگر تعریف شده:
 *      - یا باید PMPro level معتبر داشته باشد
 *      - یا باید کد فعال‌سازی در پروفایلش ثبت شده باشد (برای پرداخت دستی و تأیید از ربات)
 */
/**
 * چک می‌کند آیا کاربر برای این صفحه دسترسی دارد یا خیر
 *  - اگر Level خاصی تعریف نشده: دسترسی آزاد
 *  - اگر تعریف شده:
 *      - یا باید PMPro level معتبر داشته باشد
 *      - یا باید کد فعال‌سازی در پروفایلش ثبت شده باشد (برای پرداخت دستی و تأیید از ربات)
 *
 *  $forced_level اگر مقدار داشته باشد، از همان استفاده می‌شود و دیگر کاری به ID صفحه نداریم.
 */
function reblaw_user_has_access_for_page( $user_id, $post_id, $forced_level = null ) {
    // 1) تعیین Level مورد نیاز
    if ( $forced_level !== null ) {
        $required_level = (int) $forced_level;
    } else {
        $required_level = reblaw_get_required_level_for_page( $post_id );
    }

    // اگر هیچ Levelی تعریف نشده => این بخش قفل نیست
    if ( empty( $required_level ) ) {
        return true;
    }

    // اگر کاربر لاگین نباشد => عدم دسترسی
    if ( ! $user_id ) {
        return false;
    }

    // 2) اگر Paid Memberships Pro نصب باشد، Level را چک می‌کنیم
    if ( function_exists( 'pmpro_hasMembershipLevel' ) ) {
        if ( pmpro_hasMembershipLevel( (int) $required_level, $user_id ) ) {
            return true;
        }
    }

    // 3) اگر کد فعال‌سازی (مثلاً بعد از پرداخت دستی و تأیید ربات) موجود باشد
    $activation_code = get_user_meta( $user_id, 'reblaw_activation_code', true );
    if ( ! empty( $activation_code ) ) {
        return true;
    }

    return false;
}


/*--------------------------------------------------------------
  2) فیلد پروفایل کاربر برای «کد فعال‌سازی اشتراک»
--------------------------------------------------------------*/

add_action( 'show_user_profile', 'reblaw_activation_code_field' );
add_action( 'edit_user_profile', 'reblaw_activation_code_field' );
function reblaw_activation_code_field( $user ) {
    ?>
    <h3>کد فعال‌سازی اشتراک RebLaw</h3>
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
                    این کد پس از تأیید رسید در ربات @RebLCBot به کاربر اختصاص داده می‌شود
                    و امکان استفاده از فرم/هوش مصنوعی را برای او فعال می‌کند.
                </p>
            </td>
        </tr>
    </table>
    <?php
}

add_action( 'personal_options_update', 'reblaw_save_activation_code' );
add_action( 'edit_user_profile_update', 'reblaw_save_activation_code' );
function reblaw_save_activation_code( $user_id ) {
    if ( isset( $_POST['reblaw_activation_code'] ) ) {
        update_user_meta(
            $user_id,
            'reblaw_activation_code',
            sanitize_text_field( $_POST['reblaw_activation_code'] )
        );
    }
}

/*--------------------------------------------------------------
  3) Shortcode: [reblaw_legal_ai]
      - قفل شدن بر اساس Level و کد فعال‌سازی
--------------------------------------------------------------*/

/**
 * Shortcode: [reblaw_legal_ai] یا [reblaw_legal_ai level="2"]
 * Renders the AI question box on the frontend (with subscription lock).
 */
function reblaw_legal_ai_shortcode( $atts = [] ) {
    $ajax_url = admin_url( 'admin-ajax.php' );
    $nonce    = wp_create_nonce( 'reblaw_ai_nonce' );

    // شورت‌کد می‌تواند level را مستقیم دریافت کند (مثلاً level="2")
    $atts = shortcode_atts(
        [
            'level' => null,
        ],
        $atts,
        'reblaw_legal_ai'
    );
    $forced_level = $atts['level'] ? (int) $atts['level'] : null;

    // تشخیص ID صفحه واقعی (حتی در Elementor)
    if ( function_exists( 'get_queried_object_id' ) ) {
        $post_id = (int) get_queried_object_id();
    } else {
        global $post;
        $post_id = isset( $post->ID ) ? (int) $post->ID : 0;
    }

    $user_id    = get_current_user_id();
    $has_access = reblaw_user_has_access_for_page( $user_id, $post_id, $forced_level );

    ob_start();

    // اگر دسترسی ندارد، فقط پیام قفل را نمایش می‌دهیم
    if ( ! $has_access ) {
        ?>
        <div style="max-width:700px;margin:40px auto;padding:24px;border-radius:12px;background:#1a1a2e;color:#fff;direction:rtl;text-align:right;">
            <h3 style="margin-top:0;margin-bottom:12px;font-size:20px;font-weight:700;color:#ff6b6b;">
                🔒 دسترسی محدود شده است
            </h3>
            <p style="margin:0 0 10px;font-size:14px;line-height:1.8;">
                برای استفاده از دستیار حقوقی هوشمند در این بخش، نیاز به اشتراک فعال یا تأیید پرداخت دارید.
            </p>
            <ul style="margin:0 0 10px 20px;font-size:14px;line-height:1.8;list-style:disc;">
                <li>اگر هنوز پرداخت نکرده‌اید، لطفاً ابتدا هزینه را از طریق درگاه یا کارت به کارت واریز کنید.</li>
                <li>سپس رسید پرداخت را از طریق ربات تلگرام ارسال کنید تا اشتراک شما فعال شود.</li>
            </ul>
            <p style="margin:10px 0 0;font-size:14px;">
                📩 ارسال رسید از طریق ربات:
                <a href="https://t.me/RebLCBot?start=receipt" target="_blank" style="color:#20e57a;text-decoration:none;">
                    @RebLCBot
                </a>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    // اگر دسترسی دارد، باکس هوش مصنوعی را نمایش می‌دهیم
    ?>
    <div id="reblaw-ai-box"
         data-post-id="<?php echo esc_attr( $post_id ); ?>"
         style="max-width:700px;margin:40px auto;padding:30px;border-radius:12px;background:#020824;color:#fff;box-shadow:0 0 25px rgba(0,0,0,0.6);direction:rtl;text-align:right;">
        <h3 style="margin-top:0;margin-bottom:20px;font-size:22px;font-weight:700;color:#20e57a;">
            دستیار حقوقی هوشمند – RebLaw AI
        </h3>

        <textarea id="reblaw-ai-question"
                  style="width:100%;min-height:140px;border-radius:8px;border:1px solid #1b2745;background:#050c1b;color:#fff;padding:10px 12px;font-size:14px;resize:vertical;"
                  placeholder="سؤال حقوقی خود را اینجا بنویسید..."></textarea>

        <button id="reblaw-ai-submit"
                style="margin-top:15px;padding:10px 26px;border:none;border-radius:6px;background:#17c964;color:#000;font-weight:600;cursor:pointer;">
            ارسال سؤال
        </button>

        <div id="reblaw-ai-status"
             style="margin-top:15px;font-size:13px;color:#ff6b6b;display:none;"></div>

        <div id="reblaw-ai-answer"
             style="margin-top:20px;padding:15px;border-radius:8px;background:#050c1b;border:1px solid #232b46;font-size:14px;line-height:1.8;display:none;"></div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const box       = document.getElementById('reblaw-ai-box');
        const questionEl = document.getElementById('reblaw-ai-question');
        const submitBtn  = document.getElementById('reblaw-ai-submit');
        const statusEl   = document.getElementById('reblaw-ai-status');
        const answerEl   = document.getElementById('reblaw-ai-answer');

        if (!box || !questionEl || !submitBtn) {
            return;
        }

        const ajaxUrl = '<?php echo esc_js( $ajax_url ); ?>';
        const nonce   = '<?php echo esc_js( $nonce ); ?>';
        const postId  = box.getAttribute('data-post-id') || '0';

        submitBtn.addEventListener('click', function (e) {
            e.preventDefault();

            const question = questionEl.value.trim();
            if (!question) {
                statusEl.style.display = 'block';
                statusEl.style.color = '#ff6b6b';
                statusEl.textContent = 'لطفاً ابتدا سؤال خود را وارد کنید.';
                return;
            }

            statusEl.style.display = 'block';
            statusEl.style.color = '#ffd166';
            statusEl.textContent = 'در حال ارسال سؤال به هوش مصنوعی...';
            answerEl.style.display = 'none';
            answerEl.textContent = '';

            const formData = new FormData();
            formData.append('action', 'reblaw_ai_handle_request');
            formData.append('nonce', nonce);
            formData.append('question', question);
            formData.append('post_id', postId);

            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data && data.data.answer) {
                        statusEl.style.display = 'none';
                        answerEl.style.display = 'block';
                        answerEl.textContent = data.data.answer;
                    } else {
                        statusEl.style.display = 'block';
                        statusEl.style.color = '#ff6b6b';
                        statusEl.textContent = data.data && data.data.message
                            ? data.data.message
                            : 'پاسخی از هوش مصنوعی دریافت نشد.';
                    }
                })
                .catch(error => {
                    console.error('RebLaw AI fetch error:', error);
                    statusEl.style.display = 'block';
                    statusEl.style.color = '#ff6b6b';
                    statusEl.textContent = 'خطا در ارتباط با سرور. لطفاً بعداً دوباره تلاش کنید.';
                });
        });
    });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode( 'reblaw_legal_ai', 'reblaw_legal_ai_shortcode' );


/*--------------------------------------------------------------
  4) تشخیص شماره ماده و نام قانون از متن سؤال
--------------------------------------------------------------*/

/**
 * تشخیص شماره ماده و نام قانون از متن سؤال کاربر
 * مثال: "ماده 10 قانون مدنی" یا "لطفاً ماده ۱۰ قانون مدنی را توضیح دهید"
 */
function reblaw_detect_law_article( $question ) {
    $question = trim( $question );

    // الگوی ساده: ماده 10 قانون مدنی / ماده ۱۰ قانون مدنی
    $pattern = '/ماده\s*(\d+)\s*قانون\s*([^\s،\.]+(?:\s*[^\s،\.]+)*)/u';

    if ( preg_match( $pattern, $question, $matches ) ) {
        $article_number = intval( $matches[1] );
        $law_name_raw   = trim( $matches[2] );

        // نرمال‌سازی نام قانون
        if ( $law_name_raw === 'مدنی' ) {
            $law_name = 'قانون مدنی';
        } else {
            $law_name = $law_name_raw;
        }

        return [
            'article_number' => $article_number,
            'law_name'       => $law_name,
        ];
    }

    return null;
}

/*--------------------------------------------------------------
  5) ارتباط با API قانون برای دریافت متن رسمی ماده
--------------------------------------------------------------*/

/**
 * ارسال درخواست به RebLaw Legal API روی Railway
 * ورودی: نام قانون + شماره ماده
 * خروجی: آرایه شامل متن رسمی ماده، کد قانون، شماره ماده و منبع
 */
function reblaw_fetch_article_from_api( $law_name, $article_number ) {
    $api_url = REBLAW_LAW_API_URL;

    $body = [
        'law_name'       => $law_name,
        'article_number' => intval( $article_number ),
    ];

    $response = wp_remote_post( $api_url, [
        'method'      => 'POST',
        'headers'     => [
            'Content-Type' => 'application/json; charset=utf-8',
        ],
        'body'        => wp_json_encode( $body ),
        'timeout'     => 10,
        'data_format' => 'body',
    ] );

    if ( is_wp_error( $response ) ) {
        return null;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );

    if ( $code !== 200 || empty( $body ) ) {
        return null;
    }

    $data = json_decode( $body, true );
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
  6) AJAX handler: ارتباط با پراکسی RebLaw روی Railway
--------------------------------------------------------------*/

add_action( 'wp_ajax_reblaw_ai_handle_request', 'reblaw_ai_handle_request' );
add_action( 'wp_ajax_nopriv_reblaw_ai_handle_request', 'reblaw_ai_handle_request' );

function reblaw_ai_handle_request() {
    // 0) بررسی nonce برای امنیت
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'reblaw_ai_nonce' ) ) {
        wp_send_json_error( [ 'message' => 'درخواست نامعتبر است. لطفاً صفحه را رفرش کنید.' ] );
        exit;
    }

    // 0.1) گرفتن post_id و چک دسترسی بر اساس اشتراک / کد فعال‌سازی
    $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
    $user_id = get_current_user_id();

    if ( ! reblaw_user_has_access_for_page( $user_id, $post_id ) ) {
        wp_send_json_error( [
            'message' => 'دسترسی شما برای استفاده از این بخش فعال نیست. لطفاً اشتراک خود را تمدید یا کد فعال‌سازی را از طریق ربات دریافت کنید.'
        ] );
        exit;
    }

    // 1) گرفتن سؤال کاربر
    $question = isset( $_POST['question'] ) ? sanitize_text_field( $_POST['question'] ) : '';
    if ( empty( $question ) ) {
        wp_send_json_error( [ 'message' => 'سؤال خالی است.' ] );
        exit;
    }

    // 2) تلاش برای تشخیص ماده قانونی
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

    // 3) پرامپت سیستم برای موتور هوش مصنوعی (در سمت پراکسی استفاده می‌شود)
    $system_prompt =
"شما دستیار حقوقی هوشمند وب‌سایت RebLaw هستید.
- حوزه اصلی: حقوق ایران (قانون مدنی، قانون مجازات اسلامی، آیین دادرسی‌ها و سایر قوانین مرتبط).
- اگر متن رسمی ماده قانونی در ورودی وجود دارد، تفسیر و تحلیل خود را فقط بر اساس همان متن و اصول حقوقی ارائه کنید.
- از حدس زدن شماره مواد یا متن مواد قانونی خودداری کنید؛ اگر متن ماده ارائه نشده یا مطمئن نیستید، صریحاً بگویید که به متن رسمی دسترسی ندارید.
- همیشه یادآوری کنید که این پاسخ جایگزین مشاوره حضوری و وکالت حرفه‌ای نیست.";

    // 4) ساخت پیام کاربر (شامل متن ماده + سؤال)
    $user_content = '';
    if ( ! empty( $article_block ) ) {
        $user_content .= $article_block;
    }
    $user_content .= "سؤال کاربر:\n" . $question;

    $messages = [
        [
            'role'    => 'system',
            'content' => $system_prompt,
        ],
        [
            'role'    => 'user',
            'content' => $user_content,
        ],
    ];

    // 5) ارسال به پراکسی RebLaw روی Railway
    $payload = [
        'messages' => $messages,
        // در صورت نیاز می‌توان اطلاعات بیشتر مثل user_id یا meta ارسال کرد
        'meta'     => [
            'source'   => 'reblaw-wordpress',
            'user_id'  => $user_id,
            'post_id'  => $post_id,
        ],
    ];

    $response = wp_remote_post( REBLAW_AI_PROXY_URL, [
        'method'      => 'POST',
        'headers'     => [
            'Content-Type' => 'application/json; charset=utf-8',
        ],
        'body'        => wp_json_encode( $payload ),
        'timeout'     => 20,
        'data_format' => 'body',
    ] );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( [
            'message' => 'خطا در اتصال به سرور هوش مصنوعی. لطفاً بعداً دوباره تلاش کنید.'
        ] );
        exit;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );

    if ( $code !== 200 || empty( $body ) ) {
        wp_send_json_error( [
            'message' => 'پاسخ معتبری از سرور هوش مصنوعی دریافت نشد.'
        ] );
        exit;
    }

    $data = json_decode( $body, true );
    // انتظار: { success: true, answer: "متن پاسخ ..." }
    if ( ! is_array( $data ) || empty( $data['success'] ) || empty( $data['answer'] ) ) {
        wp_send_json_error( [
            'message' => ! empty( $data['message'] )
                ? $data['message']
                : 'هوش مصنوعی نتوانست پاسخی تولید کند.'
        ] );
        exit;
    }

    wp_send_json_success( [
        'answer' => $data['answer'],
    ] );
    exit;
}

/**
 * Shortcode: نمایش پرونده‌های مشهور RebLawBot
 * [reblaw_cases limit="10"]
 */
function reblaw_display_cases_shortcode($atts) {
    $atts = shortcode_atts([
        'limit' => 10,
    ], $atts);

    // آدرس API ربات (جایی که پرونده‌ها را می‌گیریم)
    $api_url = "https://railway.reblaw.tech/api/cases?limit=" . intval($atts['limit']);

    $response = wp_remote_get($api_url);

    if (is_wp_error($response)) {
        return "<p>⚠ اتصال به سرور پرونده‌ها برقرار نشد.</p>";
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!$data || empty($data['cases'])) {
        return "<p>📂 هیچ پرونده‌ای یافت نشد.</p>";
    }

    $output = "<div class='reblaw-cases-wrapper'>";
    foreach ($data['cases'] as $case) {
        $output .= "
        <div class='reblaw-case-card'>
            <h3>{$case['title']}</h3>
            <p>{$case['summary']}</p>
            <a href='/case/{$case['id']}' class='reblaw-btn'>مشاهده پرونده</a>
        </div>";
    }
    $output .= "</div>";

    return $output;
}
add_shortcode('reblaw_cases', 'reblaw_display_cases_shortcode');

