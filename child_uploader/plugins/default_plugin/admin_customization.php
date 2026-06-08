<?php
/**
 * Project: Arvan Create Bot Maker Platform (Super Uploader Engine)
 * File: child_uploader/plugins/default_plugin/admin_customization.php
 * Role: Page Builder & Button/Search Customizer Panel (Admin View & Logic)
 */

// جلوگیری از لود مستقل بدون کانتکست و متغیرهای تعریف شده در هسته ربات
if (!isset($db, $tg, $botId, $userId)) {
    exit;
}

$userStep     = $user['step'] ?? 'idle';
$callbackData = $callbackData ?? ($callbackQuery['data'] ?? '');
$messageId    = $messageId ?? ($callbackQuery['message']['message_id'] ?? null);
$chatId       = $chatId ?? ($callbackQuery['message']['chat']['id'] ?? $userId);

// ------------------------------------------
// بخش اول: پردازش متون ارسالی ماشین وضعیت (FSM Text Interceptors)
// ------------------------------------------
if ($userStep !== 'idle' && empty($callbackData)) {
    // ۱. هندل کردن تغییر اسم دکمه‌های صفحه جزئیات
    if (strpos($userStep, 'def_waiting_label_') === 0) {
        $btnType = str_replace('def_waiting_label_', '', $userStep);
        $newLabel = trim($text);

        if (empty($newLabel) || mb_strlen($newLabel) > 50) {
            $tg->sendMessage($userId, "❌ نام دکمه نامعتبر است. لطفاً متنی کوتاه‌تر از ۵۰ کاراکتر بفرستید:");
            exit;
        }

        // ذخیره نام دکمه در جدول تنظیمات داینامیک
        $stmtSave = $db->prepare("
            INSERT INTO bot_plugin_settings (bot_id, plugin_slug, setting_key, setting_value) 
            VALUES (:bot_id, 'default_plugin', :key, :val)
            ON CONFLICT (bot_id, plugin_slug, setting_key) 
            DO UPDATE SET setting_value = EXCLUDED.setting_value
        ");
        $stmtSave->execute([
            'bot_id' => $botId,
            'key'    => "btn_{$btnType}_label",
            'val'    => $newLabel
        ]);

        FSM::clearStep($botId, $userId);
        $tg->sendMessage($userId, "✅ نام دکمه با موفقیت به <b>«{$newLabel}»</b> تغییر یافت.", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت به تنظیمات دکمه‌ها', 'callback_data' => "def_custom_btn_edit_{$btnType}"]]]
        ]);
        exit;
    }

    // ۲. هندل کردن تغییر متن راهنمای صفحه سرچ
    elseif ($userStep === 'def_waiting_search_prompt') {
        $newPrompt = trim($text);

        if (empty($newPrompt) || mb_strlen($newPrompt) > 255) {
            $tg->sendMessage($userId, "❌ متن راهنما نامعتبر است. لطفاً متن کوتاه‌تری بفرستید:");
            exit;
        }

        $stmtSave = $db->prepare("
            INSERT INTO bot_plugin_settings (bot_id, plugin_slug, setting_key, setting_value) 
            VALUES (:bot_id, 'default_plugin', 'btn_search_prompt_text', :val)
            ON CONFLICT (bot_id, plugin_slug, setting_key) 
            DO UPDATE SET setting_value = EXCLUDED.setting_value
        ");
        $stmtSave->execute([
            'bot_id' => $botId,
            'val'    => $newPrompt
        ]);

        FSM::clearStep($botId, $userId);
        $tg->sendMessage($userId, "✅ متن راهنمای جستجو با موفقیت به‌روزرسانی شد.", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت به تنظیمات سرچ', 'callback_data' => 'def_custom_edit_search']]]
        ]);
        exit;
    }
}

// ------------------------------------------
// بخش دوم: پردازش رویداد دکمه‌های شیشه‌ای (Callbacks)
// ------------------------------------------

// الف) منوی ریشه «🛠 شخصی سازی» (سیاهه صفحات فعال ربات)
if ($callbackData === 'def_customization_menu') {
    $tg->answerCallbackQuery($callbackId);

    $text = "🛠 <b>بخش شخصی‌سازی صفحات فعال ربات:</b>\n\n"
          . "لیست صفحاتی که در حال حاضر در ساختار دانلودر ربات شما فعال هستند:\n"
          . "├ ۱. صفحه سرچ شیشه‌ای / اینلاین\n"
          . "├ ۲. صفحه سرچ پیشرفته چندلایه\n"
          . "├ ۳. صفحه شناسنامه و جزئیات اثر\n"
          . "├ ۴. صفحه علاقه‌مندی‌ها و نشان‌شده‌ها\n"
          . "└ ۵. صفحه پروفایل کاربری\n\n"
          . "برای اضافه کردن صفحات فرعی (تیکت، درخواست کار) روی دکمه افزودن کلیک کنید. جهت مدیریت دکمه‌های هر صفحه، روی گزینه مربوطه بزنید:";

    $keyboard = [
        'inline_keyboard' => [
            [['text' => '➕ افزودن صفحه فرعی یا لیست‌های هوشمند', 'callback_data' => 'def_custom_add_page_menu']],
            [
                ['text' => '🎨 صفحه جزئیات اثر', 'callback_data' => 'def_custom_edit_details'],
                ['text' => '🔍 صفحه سرچ', 'callback_data' => 'def_custom_edit_search']
            ],
            [['text' => '🔙 بازگشت به پنل افزونه', 'callback_data' => 'def_managements']] // مپ بک‌تو منو
        ]
    ];

    $tg->editMessageText($chatId, $messageId, $text, $keyboard);
    exit;
}

// ب) منوی افزودن صفحه فرعی و لیست‌های بازارچه
elseif ($callbackData === 'def_custom_add_page_menu') {
    $tg->answerCallbackQuery($callbackId);

    $text = "➕ <b>افزودن صفحه یا لیست جدید به ربات:</b>\n\n"
          . "یکی از محصولات فرعی زیر را برای الحاق آنی به لایه کاربری ربات انتخاب کنید:";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '✉️ صفحه تیکت پشتیبانی', 'callback_data' => 'def_custom_confirm_add_ticket'],
                ['text' => '🤝 صفحه درخواست کار', 'callback_data' => 'def_custom_confirm_add_job']
            ],
            [['text' => '📋 لیست‌های هوشمند (لیست‌ساز)', 'callback_data' => 'def_custom_confirm_add_lists']],
            [['text' => '🔙 بازگشت', 'callback_data' => 'def_customization_menu']]
        ]
    ];

    $tg->editMessageText($chatId, $messageId, $text, $keyboard);
    exit;
}

// تایید فعال‌سازی ابزار یا صفحات فرعی
elseif (strpos($callbackData, 'def_custom_confirm_add_') === 0) {
    $tg->answerCallbackQuery($callbackId);
    $type = str_replace('def_custom_confirm_add_', '', $callbackData);

    $labelMap = [
        'ticket' => 'تیکت پشتیبانی',
        'job'    => 'درخواست کار و استخدام',
        'lists'  => 'لیست‌ساز و چیدمان پویای کارهای هوشمند'
    ];
    $title = $labelMap[$type] ?? $type;

    $text = "❓ <b>درخواست تایید نصب صفحه فرعی:</b>\n\n"
          . "آیا مایل هستید ابزار <b>«{$title}»</b> را با موفقیت نصب کرده و دکمه ورود به آن را به منوی اصلی استارت کاربران اضافه کنید؟";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '✅ بله، نصب و فعال شود', 'callback_data' => "def_custom_do_add_{$type}"],
                ['text' => '❌ لغو عملیات', 'callback_data' => 'def_custom_add_page_menu']
            ]
        ]
    ];

    $tg->editMessageText($chatId, $messageId, $text, $keyboard);
    exit;
}

// عملیات نصب نهایی صفحات فرعی بازارچه
elseif (strpos($callbackData, 'def_custom_do_add_') === 0) {
    $type = str_replace('def_custom_do_add_', '', $callbackData);
    $slugMap = [
        'ticket' => 'ticket_system',
        'job'    => 'job_recruitment',
        'lists'  => 'curated_lists_plugin'
    ];
    $slug = $slugMap[$type] ?? $type;

    $stmtIns = $db->prepare("
        INSERT INTO bot_installed_plugins (bot_id, plugin_slug, is_active)
        VALUES (:bot_id, :slug, TRUE)
        ON CONFLICT (bot_id, plugin_slug) DO UPDATE SET is_active = TRUE
    ");
    $stmtIns->execute(['bot_id' => $botId, 'slug' => $slug]);

    $tg->apiRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text'              => "✅ ابزار جدید با موفقیت فعال و به منوی اصلی اضافه شد!",
        'show_alert'        => true
    ]);

    $callbackData = 'def_customization_menu';
    require __FILE__;
    exit;
}

// ج) مدیریت صفحه جزئیات اثر (تنظیمات دکمه‌ها)
elseif ($callbackData === 'def_custom_edit_details') {
    $tg->answerCallbackQuery($callbackId);

    $text = "🎨 <b>سفارشی‌سازی صفحه شناسنامه و جزئیات اثر:</b>\n\n"
          . "در این بخش می‌توانید چهار دکمه اصلی صفحه جزئیات کاربری را به طور کامل مدیریت، جابه‌جا، شخصی‌سازی یا غیرفعال کنید.\n\n"
          . "برای اعمال تغییرات روی هر دکمه کلیک کنید:";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📥 دکمه دریافت/چپترها', 'callback_data' => 'def_custom_btn_edit_download'],
                ['text' => '👍 دکمه لایک/پسندیدن', 'callback_data' => 'def_custom_btn_edit_like']
            ],
            [
                ['text' => '⭐ دکمه کتابخانه/نشان‌شده‌ها', 'callback_data' => 'def_custom_btn_edit_fav'],
                ['text' => '⚠️ دکمه گزارش خرابی', 'callback_data' => 'def_custom_btn_edit_report']
            ],
            [['text' => '🔙 بازگشت به شخصی سازی', 'callback_data' => 'def_customization_menu']]
        ]
    ];

    $tg->editMessageText($chatId, $messageId, $text, $keyboard);
    exit;
}

// د) ویرایشگر جزئیات و آپشن‌های هر دکمه به طور اختصاصی
elseif (strpos($callbackData, 'def_custom_btn_edit_') === 0) {
    $tg->answerCallbackQuery($callbackId);
    $btnType = str_replace('def_custom_btn_edit_', '', $callbackData);

    // واکشی نام فعلی دکمه و وضعیت فعال بودن آن از جدول تنظیمات
    $labelDefault = 'پیش‌فرض سیستم';
    if ($btnType === 'download') $labelDefault = 'نمایش لیست چپترها';
    if ($btnType === 'like') $labelDefault = 'لایک / پسندیدم';
    if ($btnType === 'fav') $labelDefault = 'افزودن به کتابخانه من';
    if ($btnType === 'report') $labelDefault = 'گزارش خرابی';

    $currentLabel = LayoutRenderer::getCustomLabel($db, $botId, "btn_{$btnType}_label", $labelDefault);

    // واکشی وضعیت فعال/غیرفعال بودن دکمه
    $stmtActive = $db->prepare("SELECT setting_value FROM bot_plugin_settings WHERE bot_id = :bot_id AND plugin_slug = 'default_plugin' AND setting_key = :key LIMIT 1");
    $stmtActive->execute(['bot_id' => $botId, 'key' => "btn_{$btnType}_active"]);
    $activeVal = $stmtActive->fetchColumn();
    $isActive = $activeVal !== '0'; // به صورت پیش‌فرض فعال است (مگر اینکه ادمین مقدار 0 گذاشته باشد)

    $statusText = $isActive ? "🟢 فعال (نمایان برای کاربران)" : "🔴 غیرفعال (مخفی)";

    $text = "⚙️ <b>مدیریت دکمه: «{$currentLabel}»</b>\n\n"
          . "📌 وضعیت فعلی دکمه: <b>{$statusText}</b>\n"
          . "🏷️ نام نمایشی جاری دکمه: <code>{$currentLabel}</code>\n\n"
          . "با استفاده از گزینه‌های زیر چیدمان، ظاهر یا برچسب این دکمه را شخصی‌سازی کنید:";

    $toggleCallback = $isActive ? "def_custom_btn_toggle_off_{$btnType}" : "def_custom_btn_toggle_on_{$btnType}";
    $toggleLabel    = $isActive ? "🔴 خاموش کردن دکمه" : "🟢 روشن کردن دکمه";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '✏️ تغییر اسم دکمه', 'callback_data' => "def_custom_btn_rename_{$btnType}"],
                ['text' => '🔄 جابه‌جایی چیدمان (جا)', 'callback_data' => "def_custom_btn_move_{$btnType}"]
            ],
            [
                ['text' => '🎨 تغییر رنگ / پوسته', 'callback_data' => "def_custom_btn_color_{$btnType}"],
                ['text' => $toggleLabel, 'callback_data' => $toggleCallback]
            ],
            [['text' => '🔙 بازگشت به دکمه‌ها', 'callback_data' => 'def_custom_edit_details']]
        ]
    ];

    $tg->editMessageText($chatId, $messageId, $text, $keyboard);
    exit;
}

// هندل تغییر گام ماشین وضعیت جهت ویرایش نام دکمه
elseif (strpos($callbackData, 'def_custom_btn_rename_') === 0) {
    $tg->answerCallbackQuery($callbackId);
    $btnType = str_replace('def_custom_btn_rename_', '', $callbackData);

    FSM::setStep($botId, $userId, "def_waiting_label_{$btnType}");

    $tg->sendMessage($userId, "✍️ <b>لطفاً نام یا برچسب جدید مورد نظر خود را برای این دکمه تایپ کرده و بفرستید:</b>\n\nمثال: <code>دریافت کیفیت‌ها</code> یا <code>کتابخانه من</code>", [
        'inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => "def_custom_btn_edit_{$btnType}"]]]
    ]);
    exit;
}

// هندل تغییر وضعیت خاموش/روشن کردن دکمه‌ها
elseif (strpos($callbackData, 'def_custom_btn_toggle_') === 0) {
    $data = str_replace('def_custom_btn_toggle_', '', $callbackData);
    $parts = explode('_', $data);
    $action = $parts[0]; // 'on' or 'off'
    $btnType = $parts[1];

    $value = $action === 'on' ? '1' : '0';

    $stmtSave = $db->prepare("
        INSERT INTO bot_plugin_settings (bot_id, plugin_slug, setting_key, setting_value) 
        VALUES (:bot_id, 'default_plugin', :key, :val)
        ON CONFLICT (bot_id, plugin_slug, setting_key) 
        DO UPDATE SET setting_value = EXCLUDED.setting_value
    ");
    $stmtSave->execute([
        'bot_id' => $botId,
        'key'    => "btn_{$btnType}_active",
        'val'    => $value
    ]);

    $tg->answerCallbackQuery($callbackId, "✅ وضعیت دکمه به‌روزرسانی شد.");
    
    // رفرش منوی ادیتور همان دکمه
    $callbackData = "def_custom_btn_edit_{$btnType}";
    require __FILE__;
    exit;
}

// ه) مدیریت صفحه سرچ (تنظیمات موتور جستجو)
elseif ($callbackData === 'def_custom_edit_search') {
    $tg->answerCallbackQuery($callbackId);

    // واکشی وضعیت حالت جستجوی فعلی ربات (شیشه‌ای یا اینلاین)
    $stmtMode = $db->prepare("SELECT setting_value FROM bot_plugin_settings WHERE bot_id = :bot_id AND plugin_slug = 'default_plugin' AND setting_key = 'search_mode' LIMIT 1");
    $stmtMode->execute(['bot_id' => $botId]);
    $modeVal = $stmtMode->fetchColumn() ?: 'keyboard';

    $modeText = $modeVal === 'inline' ? "🌐 اینلاین تلگرام ( switch_inline_query )" : "⌨️ شیشه‌ای کیبورد ( ارسال متن )";

    $text = "🔍 <b>تنظیمات تعاملی صفحه جستجو ربات:</b>\n\n"
          . "📌 حالت جاری جستجوی ربات شما: <b>{$modeText}</b>\n\n"
          . "با استفاده از گزینه‌های زیر نوع رفتار و متون راهنمای جستجو را پیکربندی کنید:";

    $toggleModeCallback = $modeVal === 'inline' ? 'def_custom_search_setmode_keyboard' : 'def_custom_search_setmode_inline';
    $toggleModeLabel    = $modeVal === 'inline' ? '⌨️ تغییر حالت به شیشه‌ای' : '🌐 تغییر حالت به اینلاین تلگرام';

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => $toggleModeLabel, 'callback_data' => $toggleModeCallback],
                ['text' => '✍️ تنظیم متن راهنما', 'callback_data' => 'def_custom_search_prompt_init']
            ],
            [
                ['text' => '🔧 تنظیم چیدمان دکمه‌ها', 'callback_data' => 'dummy_custom'],
                ['text' => '🔙 بازگشت به شخصی‌سازی', 'callback_data' => 'def_customization_menu']
            ]
        ]
    ];

    $tg->editMessageText($chatId, $messageId, $text, $keyboard);
    exit;
}

// تغییر حالت موتور جستجو بین کیبوردی و اینلاین تلگرام
elseif (strpos($callbackData, 'def_custom_search_setmode_') === 0) {
    $mode = str_replace('def_custom_search_setmode_', '', $callbackData);

    $stmtSave = $db->prepare("
        INSERT INTO bot_plugin_settings (bot_id, plugin_slug, setting_key, setting_value) 
        VALUES (:bot_id, 'default_plugin', 'search_mode', :val)
        ON CONFLICT (bot_id, plugin_slug, setting_key) 
        DO UPDATE SET setting_value = EXCLUDED.setting_value
    ");
    $stmtSave->execute([
        'bot_id' => $botId,
        'val'    => $mode
    ]);

    $tg->answerCallbackQuery($callbackId, "✅ حالت جستجوی ربات با موفقیت تغییر یافت.");
    
    // رفرش منوی ادیتور سرچ
    $callbackData = 'def_custom_edit_search';
    require __FILE__;
    exit;
}

// فعال‌سازی FSM جهت تغییر متن راهنمای جستجوی شیشه‌ای
elseif ($callbackData === 'def_custom_search_prompt_init') {
    // ابتدا چک می‌کنیم حالت جستجو اینلاین نباشد، زیرا این دکمه برای اینلاین غیرفعال است
    $stmtMode = $db->prepare("SELECT setting_value FROM bot_plugin_settings WHERE bot_id = :bot_id AND plugin_slug = 'default_plugin' AND setting_key = 'search_mode' LIMIT 1");
    $stmtMode->execute(['bot_id' => $botId]);
    $modeVal = $stmtMode->fetchColumn() ?: 'keyboard';

    if ($modeVal === 'inline') {
        $tg->answerCallbackQuery($callbackId, "⚠️ این تنظیم برای حالت اینلاین غیرفعال است؛ ادمین می‌تواند دکمه‌های شیشه‌ای هدایت‌کننده بسازد.", true);
        exit;
    }

    $tg->answerCallbackQuery($callbackId);
    FSM::setStep($botId, $userId, 'def_waiting_search_prompt');

    $tg->sendMessage($userId, "✍️ <b>لطفاً متن راهنمای جدید جستجو را که هنگام کلیک کاربر روی دکمه سرچ نمایش داده می‌شود بنویسید و بفرستید:</b>\n\nمثال: <code>لطفاً نام مانهوا یا انیمه مورد نظر را برای جستجو وارد کنید:</code>", [
        'inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'def_custom_edit_search']]]
    ]);
    exit;
}

// هندل کردن دکمه‌های فرعی موقتی
elseif ($callbackData === 'dummy_custom') {
    $tg->apiRequest('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text'              => "⚠️ این قابلیت در قالب بسته‌های گرافیکی جدید به بازارچه اضافه خواهد شد.",
        'show_alert'        => true
    ]);
    exit;
}
