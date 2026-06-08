<?php
/**
 * Project: Arvan Create Bot Maker Platform (Super Uploader Engine)
 * File: child_uploader/plugins/default_plugin/admin_settings.php
 * Role: Admin Custom Fields Creator, Category Manager & Advanced Curated List Builder
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
// بخش کمکی: لود و ویرایش فیلدهای اطلاعاتی داینامیک از جدول تنظیمات
// ------------------------------------------
if (!function_exists('getCustomFieldsList')) {
    function getCustomFieldsList($db, $botId) {
        $stmt = $db->prepare("SELECT setting_value FROM bot_plugin_settings WHERE bot_id = :bot_id AND plugin_slug = 'default_plugin' AND setting_key = 'custom_fields_list' LIMIT 1");
        $stmt->execute(['bot_id' => $botId]);
        $val = $stmt->fetchColumn();
        return $val ? json_decode($val, true) : [];
    }
}

if (!function_exists('saveCustomFieldsList')) {
    function saveCustomFieldsList($db, $botId, $list) {
        $val = json_encode($list);
        $stmt = $db->prepare("
            INSERT INTO bot_plugin_settings (bot_id, plugin_slug, setting_key, setting_value) 
            VALUES (:bot_id, 'default_plugin', 'custom_fields_list', :val)
            ON CONFLICT (bot_id, plugin_slug, setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value
        ");
        $stmt->execute(['bot_id' => $botId, 'val' => $val]);
    }
}

// ------------------------------------------
// فاز ۱: پردازش متون ارسالی ماشین وضعیت (FSM Settings States)
// ------------------------------------------
if ($userStep !== 'idle' && empty($callbackData)) {

    // الف) دریافت تایتل فیلد اطلاعاتی جدید
    if ($userStep === 'def_wait_settings_meta_title') {
        $fieldTitle = trim($text);
        if (empty($fieldTitle) || mb_strlen($fieldTitle) > 100) {
            $tg->sendMessage($userId, "❌ عنوان فیلد نامعتبر است. مجدداً ارسال کنید:");
            exit;
        }

        FSM::setStep($botId, $userId, "idle"); // موقتاً گام را آزاد می‌کنیم تا دکمه‌های نوع داده رندر شوند
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📝 متن عادی (Text)', 'callback_data' => "def_settings_meta_settype_text_#_" . base64_encode($fieldTitle)],
                    ['text' => '🔢 عدد (Number)', 'callback_data' => "def_settings_meta_settype_number_#_" . base64_encode($fieldTitle)]
                ],
                [['text' => '❌ لغو و انصراف', 'callback_data' => 'def_settings_meta_fields_1']]
            ]
        ];

        $tg->sendMessage($userId, "🎨 فیلد جدید با عنوان <b>«{$fieldTitle}»</b> ثبت شد.\n\nنوع فیلد اطلاعاتی را مشخص کنید:", $keyboard);
        exit;
    }

    // ب) دریافت نام کتگوری/ژانر جدید
    elseif ($userStep === 'def_wait_settings_cat_title') {
        $catTitle = trim($text);
        if (empty($catTitle) || mb_strlen($catTitle) > 100) {
            $tg->sendMessage($userId, "❌ نام کتگوری نامعتبر است. مجدداً بفرستید:");
            exit;
        }

        try {
            $stmt = $db->prepare("INSERT INTO media_categories (bot_id, title) VALUES (:bot_id, :title) ON CONFLICT DO NOTHING");
            $stmt->execute(['bot_id' => $botId, 'title' => $catTitle]);

            FSM::clearStep($botId, $userId);
            $tg->sendMessage($userId, "✅ کتگوری/ژانر جدید با عنوان <b>«{$catTitle}»</b> با موفقیت ثبت شد.", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت به کتگوری‌ها', 'callback_data' => 'def_settings_categories_1']]]
            ]);
        } catch (PDOException $e) {
            $tg->sendMessage($userId, "❌ خطا در ذخیره‌سازی اطلاعات کتگوری در دیتابیس.");
        }
        exit;
    }

    // ج) دریافت عنوان لیست هوشمند جدید
    elseif ($userStep === 'def_wait_settings_list_title') {
        $listTitle = trim($text);
        if (empty($listTitle) || mb_strlen($listTitle) > 200) {
            $tg->sendMessage($userId, "❌ عنوان لیست نامعتبر است. مجدداً ارسال کنید:");
            exit;
        }

        FSM::setStep($botId, $userId, "idle");

        // واکشی کل کتگوری‌ها جهت منوی فیلترگذاری شیشه‌ای لیست‌ساز
        $stmtCats = $db->prepare("SELECT id, title FROM media_categories WHERE bot_id = :bot_id ORDER BY id ASC LIMIT 10");
        $stmtCats->execute(['bot_id' => $botId]);
        $categories = $stmtCats->fetchAll();

        $buttons = [];
        $buttons[] = [['text' => '⭐ اسکیپ / رد کردن (بدون فیلتر کتگوری)', 'callback_data' => "def_settings_list_setcat_none_#_" . base64_encode($listTitle)]];

        foreach ($categories as $cat) {
            $buttons[] = [['text' => "🎭 فیلتر ژانر: " . $cat['title'], 'callback_data' => "def_settings_list_setcat_{$cat['id']}_#_" . base64_encode($listTitle)]];
        }
        $buttons[] = [['text' => '❌ لغو عملیات', 'callback_data' => 'def_settings_lists_menu_1']];

        $keyboard = LayoutRenderer::makeGrid($buttons, 1);

        $tg->sendMessage($userId, "✍️ عنوان لیست هوشمند <b>«{$listTitle}»</b> ثبت شد.\n\nجهت فیلترگذاری مقتدرانه، کتگوری فیلتر این لیست را مشخص کنید یا اسکیپ را کلیک کنید:", ['inline_keyboard' => $keyboard]);
        exit;
    }
}

// ==========================================
// فاز ۲: پردازش رویداد دکمه‌های شیشه‌ای تنظیمات (Callbacks)
// ==========================================
if ($callbackQuery) {

    // ۱. منوی اصلی تنظیمات ربات
    if ($callbackData === 'def_manage_settings') {
        $tg->answerCallbackQuery($callbackId);

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📌 تنظیم تخصصی تاپیک و ژانرها', 'callback_data' => 'def_settings_topic_menu']],
                [['text' => '🏆 مدیریت پویای لیست‌ساز', 'callback_data' => 'def_settings_lists_menu_1']],
                [['text' => '🔙 بازگشت به مدیریت', 'callback_data' => 'def_management_menu']]
            ]
        ];

        $text = "⚙️ <b>بخش تنظیمات اختصاصی ربات سوپر آپلودر:</b>\n\n"
              . "در این منو می‌توانید زمینه‌های دلخواه، ژانرهای مانهوا/فیلم و چیدمان لیست‌ساز را مدیریت کنید.\n\n"
              . "یکی از گزینه‌های مدیریتی زیر را لمس کنید:";

        $tg->editMessageText($chatId, $messageId, $text, $keyboard);
        exit;
    }

    // منوی تنظیم تاپیک (فیلدهای اطلاعاتی و کتگوری)
    elseif ($callbackData === 'def_settings_topic_menu') {
        $tg->answerCallbackQuery($callbackId);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📅 تنظیم اطلاعات (فیلدها)', 'callback_data' => 'def_settings_meta_fields_1'],
                    ['text' => '🎭 تنظیم کتگوری (ژانرها)', 'callback_data' => 'def_settings_categories_1']
                ],
                [['text' => '🔙 بازگشت', 'callback_data' => 'def_manage_settings']]
            ]
        ];

        $text = "📌 <b>بخش تنظیم تخصصی تاپیک و ژانرها:</b>\n\n"
              . "<b>تنظیم اطلاعات:</b> فیلدهای ورودی پروژه (مثل سال انتشار، نویسنده) را مشخص می‌کند [1].\n"
              . "<b>تنظیم کتگوری:</b> ژانرهای متصل به موتور سرچ پیشرفته را کنترل می‌نماید.";

        $tg->editMessageText($chatId, $messageId, $text, $keyboard);
        exit;
    }

    // ۲. مدیریت فیلدهای اطلاعاتی داینامیک (Custom Fields List)
    elseif (strpos($callbackData, 'def_settings_meta_fields_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $page = (int)str_replace('def_settings_meta_fields_', '', $callbackData);

        $fields = getCustomFieldsList($db, $botId);
        $totalFields = count($fields);

        $text = "📅 <b>لیست فیلدهای اطلاعاتی پویا فعال ربات:</b>\n\n"
              . "این فیلدها در جادوگر ثبت کار جدید از شما دریافت می‌شوند و در شناسنامه کار نمایش داده می‌شوند [1].\n\n"
              . "برای افزودن فیلد اطلاعاتی جدید، روی دکمه افزودن فیلد در بالای لیست بزنید:";

        $buttons = [];
        $buttons[] = [['text' => '➕ افزودن فیلد اطلاعاتی جدید', 'callback_data' => 'def_settings_meta_add_init']];

        foreach ($fields as $field) {
            $typeText = $field['type'] === 'number' ? '🔢 عدد' : '📝 متن';
            $buttons[] = [
                ['text' => "📅 {$field['title']} ({$typeText})", 'callback_data' => 'dummy_settings'],
                ['text' => '🗑️ حذف فیلد', 'callback_data' => "def_settings_meta_delete_{$field['id']}"]
            ];
        }
        $buttons[] = [['text' => '🔙 بازگشت', 'callback_data' => 'def_settings_topic_menu']];

        $tg->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $buttons]);
        exit;
    }

    // شروع جادوگر افزودن فیلد اطلاعاتی جدید
    elseif ($callbackData === 'def_settings_meta_add_init') {
        $tg->answerCallbackQuery($callbackId);
        FSM::setStep($botId, $userId, 'def_wait_settings_meta_title');

        $tg->sendMessage($userId, "✍️ <b>لطفاً تایتل و عنوان فیلد اطلاعاتی جدید را ارسال کنید:</b>\n\nمثال: <code>سال انتشار</code> یا <code>نویسنده اثر</code>", [
            'inline_keyboard' => [[['text' => '❌ لغو عملیات', 'callback_data' => 'def_settings_meta_fields_1']]]
        ]);
        exit;
    }

    // ثبت نهایی نوع داده فیلد جدید در لیست JSON تنظیمات
    elseif (strpos($callbackData, 'def_settings_meta_settype_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $data = str_replace('def_settings_meta_settype_', '', $callbackData);
        $parts = explode('_#_', $data);
        $type = $parts[0];
        $fieldTitle = base64_decode($parts[1]);

        $fields = getCustomFieldsList($db, $botId);
        
        // محاسبه آیدی جدید یکتا
        $maxId = 0;
        foreach ($fields as $f) {
            if ($f['id'] > $maxId) {
                $maxId = $f['id'];
            }
        }
        $newId = $maxId + 1;

        $fields[] = [
            'id'    => $newId,
            'title' => $fieldTitle,
            'type'  => $type
        ];

        saveCustomFieldsList($db, $botId, $fields);

        $tg->sendMessage($userId, "✅ فیلد اطلاعاتی جدید با موفقیت به ساختار ربات اضافه گردید.");
        
        // رفرش منوی فیلدها
        $callbackData = 'def_settings_meta_fields_1';
        require __FILE__;
        exit;
    }

    // حذف فیلد اطلاعاتی داینامیک از لیست JSON تنظیمات
    elseif (strpos($callbackData, 'def_settings_meta_delete_') === 0) {
        $fieldId = (int)str_replace('def_settings_meta_delete_', '', $callbackData);

        $fields = getCustomFieldsList($db, $botId);
        $newFields = [];
        foreach ($fields as $f) {
            if ($f['id'] !== $fieldId) {
                $newFields[] = $f;
            }
        }

        saveCustomFieldsList($db, $botId, $newFields);
        $tg->answerCallbackQuery($callbackId, "❌ فیلد با موفقیت حذف گردید.", true);

        // رفرش منو
        $callbackData = 'def_settings_meta_fields_1';
        require __FILE__;
        exit;
    }

    // ۳. مدیریت کتگوری‌ها و ژانرهای ربات (Categories Manager)
    elseif (strpos($callbackData, 'def_settings_categories_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $page = (int)str_replace('def_settings_categories_', '', $callbackData);
        $limit = 10;
        $offset = ($page - 1) * $limit;

        // محاسبه کل کتگوری‌ها
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM media_categories WHERE bot_id = :bot_id");
        $stmtCount->execute(['bot_id' => $botId]);
        $totalCats = $stmtCount->fetchColumn() ?: 0;
        $totalPages = ceil($totalCats / $limit);

        // واکشی داده‌های صفحه جاری
        $stmtCats = $db->prepare("SELECT id, title FROM media_categories WHERE bot_id = :bot_id ORDER BY id DESC LIMIT :limit OFFSET :offset");
        $stmtCats->bindValue(':bot_id', $botId, PDO::PARAM_INT);
        $stmtCats->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmtCats->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmtCats->execute();
        $categories = $stmtCats->fetchAll();

        $text = "🎭 <b>بخش مدیریت ژانرها و کتگوری‌های ربات (صفحه {$page} از {$totalPages}):</b>\n\n"
              . "ژانرهایی که در این منو می‌سازید، در صفحه جستجوی پیشرفته و ثبت کار جدید استفاده می‌شوند:\n\n"
              . "برای افزودن کتگوری جدید، روی دکمه بالای لیست کلیک کنید:";

        $buttons = [];
        $buttons[] = [['text' => '➕ افزودن کتگوری/ژانر جدید', 'callback_data' => 'def_settings_cat_add_init']];

        foreach ($categories as $cat) {
            $buttons[] = [
                ['text' => "🎭 ژانر: " . $cat['title'], 'callback_data' => 'dummy_settings'],
                ['text' => '🗑️ حذف', 'callback_data' => "def_settings_cat_delete_{$cat['id']}"]
            ];
        }

        $keyboard = LayoutRenderer::makeGrid($buttons, 1);
        $navRow = LayoutRenderer::makePaginationRow($page, $totalPages, 'def_settings_categories');
        if (!empty($navRow)) {
            $keyboard[] = $navRow;
        }
        $keyboard[] = [['text' => '🔙 بازگشت', 'callback_data' => 'def_settings_topic_menu']];

        $tg->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $keyboard]);
        exit;
    }

    // شروع FSM جهت دریافت تایتل کتگوری جدید
    elseif ($callbackData === 'def_settings_cat_add_init') {
        $tg->answerCallbackQuery($callbackId);
        FSM::setStep($botId, $userId, 'def_wait_settings_cat_title');

        $tg->sendMessage($userId, "✍️ <b>لطفاً عنوان کتگوری/ژانر جدید را ارسال کنید:</b>\n\nمثال: <code>ایسکای</code> یا <code>وحشتناک</code>", [
            'inline_keyboard' => [[['text' => '❌ لغو عملیات', 'callback_data' => 'def_settings_categories_1']]]
        ]);
        exit;
    }

    // حذف کتگوری از پایگاه‌داده
    elseif (strpos($callbackData, 'def_settings_cat_delete_') === 0) {
        $catId = (int)str_replace('def_settings_cat_delete_', '', $callbackData);

        $stmtDel = $db->prepare("DELETE FROM media_categories WHERE bot_id = :bot_id AND id = :id");
        $stmtDel->execute(['bot_id' => $botId, 'id' => $catId]);
        $tg->answerCallbackQuery($callbackId, "❌ کتگوری با موفقیت حذف گردید.", true);

        // رفرش لیست
        $callbackData = 'def_settings_categories_1';
        require __FILE__;
        exit;
    }

    // ۴. مدیریت لیست‌های ساخته شده توسط ادمین در ابزار «لیست‌ساز»
    elseif (strpos($callbackData, 'def_settings_lists_menu_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $page = (int)str_replace('def_settings_lists_menu_', '', $callbackData);
        $limit = 10;
        $offset = ($page - 1) * $limit;

        // محاسبه کل لیست‌های ادمین
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM curated_lists WHERE bot_id = :bot_id");
        $stmtCount->execute(['bot_id' => $botId]);
        $totalLists = $stmtCount->fetchColumn() ?: 0;
        $totalPages = ceil($totalLists / $limit);

        // واکشی لیست‌های جاری
        $stmtLists = $db->prepare("SELECT id, title FROM curated_lists WHERE bot_id = :bot_id ORDER BY id DESC LIMIT :limit OFFSET :offset");
        $stmtLists->bindValue(':bot_id', $botId, PDO::PARAM_INT);
        $stmtLists->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmtLists->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmtLists->execute();
        $lists = $stmtLists->fetchAll();

        $text = "📋 <b>بخش مدیریت پویای لیست‌ساز ربات (صفحه {$page} از {$totalPages}):</b>\n\n"
              . "لیست‌هایی که در این بخش فیلترگذاری و ثبت می‌کنید، به صورت خودکار به دکمه‌های منوی اول دانلودر کاربران اضافه می‌شوند:\n\n"
              . "جهت ساخت لیست هوشمند جدید، روی دکمه افزودن کلیک کنید:";

        $buttons = [];
        $buttons[] = [['text' => '➕ افزودن لیست هوشمند جدید', 'callback_data' => 'def_settings_list_add_init']];

        foreach ($lists as $lst) {
            $buttons[] = [
                ['text' => "🔥 " . $lst['title'], 'callback_data' => 'dummy_settings'],
                ['text' => '🗑️ حذف لیست', 'callback_data' => "def_settings_list_delete_{$lst['id']}"]
            ];
        }

        $keyboard = LayoutRenderer::makeGrid($buttons, 1);
        $navRow = LayoutRenderer::makePaginationRow($page, $totalPages, 'def_settings_lists_menu');
        if (!empty($navRow)) {
            $keyboard[] = $navRow;
        }
        $keyboard[] = [['text' => '🔙 بازگشت به تنظیمات', 'callback_data' => 'def_manage_settings']];

        $tg->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $keyboard]);
        exit;
    }

    // شروع FSM جهت دریافت عنوان لیست هوشمند جدید
    elseif ($callbackData === 'def_settings_list_add_init') {
        $tg->answerCallbackQuery($callbackId);
        FSM::setStep($botId, $userId, 'def_wait_settings_list_title');

        $tg->sendMessage($userId, "✍️ <b>بخش ساخت ابزار لیست‌ساز اختصاصی:</b>\n\nلطفاً ابتدا عنوان شکیل لیست خود را وارد کنید (مثال: برترین‌های این هفته):", [
            'inline_keyboard' => [[['text' => '❌ لغو عملیات', 'callback_data' => 'def_settings_lists_menu_1']]]
        ]);
        exit;
    }

    // ذخیره موقت فیلتر کتگوری لیست و رفتن به مرحله بعد
    elseif (strpos($callbackData, 'def_settings_list_setcat_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $data = str_replace('def_settings_list_setcat_', '', $callbackData);
        $parts = explode('_#_', $data);
        $catIdRaw = $parts[0];
        $listTitle = base64_decode($parts[1]);

        $catId = $catIdRaw === 'none' ? null : (int)$catIdRaw;

        // منوی انتخاب فیلتر زمانی لیست‌ساز
        $text = "⏳ <b>انتخاب بازه زمانی فیلتر لیست «{$listTitle}»:</b>\n\n"
              . "فقط آثار منتشرشده در این بازه زمانی همراه با آمار بازدید در لیست نمایش داده می‌شوند:";

        $buttons = [
            [
                ['text' => 'امروز (۲۴ ساعت)', 'callback_data' => "def_settings_list_settime_today_#_{$catId}_#_" . base64_encode($listTitle)],
                ['text' => 'هفته (۷ روز گذشته)', 'callback_data' => "def_settings_list_settime_week_#_{$catId}_#_" . base64_encode($listTitle)]
            ],
            [
                ['text' => 'ماه (۳۰ روز گذشته)', 'callback_data' => "def_settings_list_settime_month_#_{$catId}_#_" . base64_encode($listTitle)],
                ['text' => 'سه ماه گذشته', 'callback_data' => "def_settings_list_settime_3month_#_{$catId}_#_" . base64_encode($listTitle)]
            ],
            [
                ['text' => 'شش ماه گذشته', 'callback_data' => "def_settings_list_settime_6month_#_{$catId}_#_" . base64_encode($listTitle)],
                ['text' => '۱۲ ماه گذشته', 'callback_data' => "def_settings_list_settime_12month_#_{$catId}_#_" . base64_encode($listTitle)]
            ],
            [['text' => 'بدون فیلتر زمانی (کل زمان‌ها)', 'callback_data' => "def_settings_list_settime_all_#_{$catId}_#_" . base64_encode($listTitle)]],
            [['text' => '❌ لغو عملیات', 'callback_data' => 'def_settings_lists_menu_1']]
        ];

        $tg->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $buttons]);
        exit;
    }

    // ثبت نهایی لیست هوشمند در جدول curated_lists بر اساس تنظیمات ادمین
    elseif (strpos($callbackData, 'def_settings_list_settime_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $data = str_replace('def_settings_list_settime_', '', $callbackData);
        $parts = explode('_#_', $data);
        $timeFilter = $parts[0];
        $catIdRaw   = $parts[1];
        $listTitle  = base64_decode($parts[2]);

        $catId = $catIdRaw === 'none' ? null : (int)$catIdRaw;

        try {
            $stmtIns = $db->prepare("
                INSERT INTO curated_lists (bot_id, title, category_id, time_filter) 
                VALUES (:bot_id, :title, :cat_id, :time)
            ");
            $stmtIns->execute([
                'bot_id' => $botId,
                'title'  => $listTitle,
                'cat_id' => $catId,
                'time'   => $timeFilter
            ]);

            $tg->sendMessage($userId, "✅ لیست هوشمند جدید با عنوان <b>«{$listTitle}»</b> با موفقیت ساخته شد و به کیبورد استارت کاربران الصاق گردید.");
        } catch (PDOException $e) {
            $tg->sendMessage($userId, "❌ خطای سیستم در زمان ساخت لیست هوشمند در پایگاه‌داده.");
            error_log("Failed to create curated list: " . $e->getMessage());
        }

        // رفرش منو
        $callbackData = 'def_settings_lists_menu_1';
        require __FILE__;
        exit;
    }

    // حذف لیست هوشمند از پایگاه‌داده
    elseif (strpos($callbackData, 'def_settings_list_delete_') === 0) {
        $listId = (int)str_replace('def_settings_list_delete_', '', $callbackData);

        $stmtDel = $db->prepare("DELETE FROM curated_lists WHERE bot_id = :bot_id AND id = :id");
        $stmtDel->execute(['bot_id' => $botId, 'id' => $listId]);
        $tg->answerCallbackQuery($callbackId, "❌ لیست هوشمند با موفقیت حذف گردید.", true);

        // رفرش لیست
        $callbackData = 'def_settings_lists_menu_1';
        require __FILE__;
        exit;
    }

    // هندل کارهای دکمه‌های نمایشی غیرفعال
    elseif ($callbackData === 'dummy_settings') {
        $tg->answerCallbackQuery($callbackId);
        exit;
    }
}
