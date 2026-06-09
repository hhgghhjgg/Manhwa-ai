<?php
/**
 * Project: Arvan Create Bot Maker Platform (Super Uploader Engine)
 * File: child_uploader/user_panel.php
 * Role: Dynamic Dispatcher for Normal Users (Zero Hardcoded Menus/Templates)
 */

// ۱. اطمینان از صحت دسترسی به کانتکست و متغیرهای تعریف شده در روتر
if (!isset($botContext, $tg, $user, $db)) {
    exit;
}

$userId    = $user['tg_id'];
$fullName  = $user['full_name'];
$username  = $user['username'] ?? '';
$userStep  = $user['step'] ?? 'idle';
$botId     = $botContext['bot_id'];

$message       = $botContext['update']['message'] ?? null;
$callbackQuery = $botContext['update']['callback_query'] ?? null;

// ==========================================
// فاز ۱: پردازش پیام‌های متنی و ارسالی کاربران عادی
// ==========================================
if ($message && isset($message['text'])) {
    $text = trim($message['text']);

    // ۱. هندل کردن دستور عمومی استارت (/start)
    if ($text === '/start') {
        // پاک‌سازی گام کاربر عادی جهت قرارگیری در وضعیت پیش‌فرض
        FSM::clearStep($botId, $userId);

        // واگذاری ساختار و رندر منوی اصلی به لایه کاربری افزونه دیفالت همه‌کاره
        $defaultHandlerPath = __DIR__ . "/plugins/default_plugin/handler.php";
        if (file_exists($defaultHandlerPath)) {
            $pluginAction = 'render_start_menu'; // تعریف رویداد رندر منوی اول دانلودر برای هندلر
            require_once $defaultHandlerPath;
        } else {
            // هندل سقوط امن (Fallback) در صورتی که پوشه افزونه دیفالت لود نشده باشد
            $tg->sendMessage($userId, "👋 سلام <b>{$fullName}</b> گرامی، خوش آمدید.\n\nبه ربات بزرگ سوپر آپلودر خوش آمدید.");
        }
        exit;
    }

    // ۲. پردازش متون معمولی ارسالی (به عنوان کوئری جستجوی فایل / سرچ عادی)
    // اگر کاربر در گام خاصی نبود و متن فرستاد، یعنی قصد سرچ کردن در آرشیو فایل‌ها را دارد
    if ($userStep === 'idle' || empty($userStep)) {
        $defaultHandlerPath = __DIR__ . "/plugins/default_plugin/handler.php";
        if (file_exists($defaultHandlerPath)) {
            $pluginAction = 'search_query'; // تعریف رویداد سرچ در افزونه دیفالت
            require_once $defaultHandlerPath;
        } else {
            $tg->sendMessage($userId, "⚠️ ابزار جستجو در حال حاضر بر روی این ربات غیرفعال است.");
        }
        exit;
    }
}

// ==========================================
// فاز ۲: پردازش رویداد دکمه‌های شیشه‌ای کاربران عادی (Callbacks)
// ==========================================
if ($callbackQuery) {
    $callbackData = $callbackQuery['data'] ?? '';
    $callbackId   = $callbackQuery['id'];
    $messageId    = $callbackQuery['message']['message_id'];

    // ۱. تلاش برای واگذاری کالبک به افزونه‌های متفرقه نصب‌شده فعال (VIP, Ticket, etc.)
    // این متد کالبک را دریافت کرده و در صورت وجود افزونه مرتبط و فعال، آن را پردازش کرده و true برمی‌گرداند [1]
    $handledByPlugin = PluginLoader::dispatchCallback($db, $tg, $botId, $userId, $callbackQuery, $botContext, $user);
    
    if ($handledByPlugin) {
        exit;
    }

    // ۲. کالبک سقوط امن (Fallback)
    // اگر کالبک به هیچ افزونه متفرقه فعالی مپ نشد (مانند کالبک‌های عمومی یا مربوط به خود سوپر آپلودر)،
    // آن را جهت پردازش نهایی به هندلر افزونه دیفالت هدایت می‌کنیم [1].
    $defaultHandlerPath = __DIR__ . "/plugins/default_plugin/handler.php";
    if (file_exists($defaultHandlerPath)) {
        $pluginAction = 'callback_query'; // مشخص کردن نوع عملیات برای کدهای هندلر
        require_once $defaultHandlerPath;
        exit;
    } else {
        $tg->answerCallbackQuery($callbackId, "⚠️ خطا: هندلر پیش‌فرض یافت نشد.", true);
    }
    exit;
}
