jQuery(function ($) {
    const $box = $("#reblaw-ai-box");
    const $question = $("#reblaw-ai-question");
    const $submit = $("#reblaw-ai-submit");
    const $loading = $("#reblaw-ai-loading");
    const $answerBox = $("#reblaw-ai-answer");
    const $errorBox = $("#reblaw-ai-error");

    // Optional config injected by theme/plugin
    const cfg = window.RebLawAI || {};
    const ajaxUrl = cfg.ajax_url || window.ajaxurl || "/wp-admin/admin-ajax.php";
    const actionName = cfg.action || "reblaw_ai_handle_request";
    const nonce = cfg.nonce || "";
    const locale = cfg.locale || "";

    function resetOutputs() {
        $answerBox.hide().empty();
        $errorBox.hide().empty();
    }

    function getPostId() {
        // Supports both data() and raw attribute
        return (
            ($box.length ? ($box.data("post-id") || $box.attr("data-post-id")) : null) ||
            0
        );
    }

    $submit.on("click", function () {
        const question = ($question.val() || "").trim();
        if (!question) {
            alert("لطفاً سؤال خود را بنویسید.");
            return;
        }

        resetOutputs();
        if ($loading.length) $loading.show();
        $submit.prop("disabled", true);

        $.post(
            ajaxUrl,
            {
                action: actionName,
                nonce: nonce,
                question: question,
                post_id: getPostId(),
                locale: locale,
            },
            function (response) {
                if ($loading.length) $loading.hide();
                $submit.prop("disabled", false);

                if (!response || !response.success) {
                    const msg =
                        (response && response.data && response.data.message) ||
                        "خطا در دریافت پاسخ.";
                    $errorBox.text(msg).show();
                    return;
                }

                $answerBox.html(response.data.answer).show();
            }
        ).fail(function () {
            if ($loading.length) $loading.hide();
            $submit.prop("disabled", false);
            $errorBox.text("خطا در ارتباط با سرور.").show();
        });
    });
});
