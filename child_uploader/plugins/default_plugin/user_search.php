<?php
/**
 * Project: Arvan Create Bot Maker Platform (Super Uploader Engine)
 * File: child_uploader/plugins/default_plugin/user_search.php
 * Role: Full User-facing Simple & Advanced Multi-Filter Search Engine (JSONB Based)
 */

// جلوگیری از لود مستقل بدون کانتکست و متغیرهای تعریف شده در هسته ربات
if (!isset($db, $tg, $botId, $userId)) {
    exit;
}

$callbackData = $callbackData ?? ($callbackQuery['data'] ?? '');
$messageId    = $messageId ?? ($callbackQuery['message']['message_id'] ?? null);
$chatId       = $chatId ?? ($callbackQuery['message']['chat']['id'] ?? $userId);

// ==========================================
// توابع کمکی امنیتی جهت مدیریت نشست‌های جستجوی موقت هر کاربر (Session Manager)
// ==========================================
if (!function_exists('getUserSearchSession')) {
    /**
     * دریافت فیلترهای فعال کاربر برای جستجوی پیشرفته به صورت بدون وضعیت
     */
    function getUserSearchSession($db, $botId, $userId) {
        try {
            $stmt = $db->prepare("
                SELECT setting_value 
                FROM bot_plugin_settings 
                WHERE bot_id = :bot_id 
                  AND plugin_slug = 'default_plugin' 
                  AND setting_key = :key 
                LIMIT 1
            ");
            $stmt->execute(['bot_id' => $botId, 'key' => "search_session_{$userId}"]);
            $val = $stmt->fetchColumn();
            return $val ? json_decode($val, true) : ['category_id' => null, 'year' => null, 'status' => null];
        } catch (PDOException $e) {
            return ['category_id' => null, 'year' => null, 'status' => null];
        }
    }
}

if (!function_exists('saveUserSearchSession')) {
    /**
     * ذخیره‌سازی فیلترهای انتخاب‌شده کاربر در دیتابیس نئون
     */
    function saveUserSearchSession($db, $botId, $userId, $session) {
        try {
            $val = json_encode($session);
            $stmt = $db->prepare("
                INSERT INTO bot_plugin_settings (bot_id, plugin_slug, setting_key, setting_value) 
                VALUES (:bot_id, 'default_plugin', :key, :val)
                ON CONFLICT (bot_id, plugin_slug, setting_key) 
                DO UPDATE SET setting_value = EXCLUDED.setting_value
            ");
            $stmt->execute([
                'bot_id' => $botId, 
                'key'    => "search_session_{$userId}", 
                'val'    => $val
            ]);
        } catch (PDOException $e) {
            error_log("Error saving search session: " . $e->getMessage());
        }
    }
}

// ایجاد خودکار جدول کتگوری‌ها در دیتابیس در صورت عدم وجود (تضمین کارکرد پایدار سیستم)
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS media_categories (
            id SERIAL PRIMARY KEY,
            bot_id INT NOT NULL,
            title VARCHAR(100) NOT NULL,
            CONSTRAINT unique_bot_category UNIQUE (bot_id, title)
        );
    ");
} catch (PDOException $e) {
    error_log("Failed to create media_categories table: " . $e->getMessage());
}

// ==========================================
// پردازش رویدادهای جستجوی پیشرفته (Advanced Search Actions)
// ==========================================

// الف) کالبک شروع و نمایش منوی اصلی فیلترهای جستجوی پیشرفته
if ($callbackData === 'def_adv_search_init') {
    $tg->answerCallbackQuery($callbackId);
    $session = getUserSearchSession($db, $botId, $userId);

    // واکشی عنوان کتگوری انتخاب‌شده (اگر ست شده باشد)
    $catTitle = "همه ژانرها";
    if (!empty($session['category_id'])) {
        $stmtC = $db->prepare("SELECT title FROM media_categories WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmtC->execute(['bot_id' => $botId, 'id' => $session['category_id']]);
        $catTitle = $stmtC->fetchColumn() ?: "همه ژانرها";
    }

    $yearTitle   = !empty($session['year']) ? "سال " . $session['year'] : "همه سال‌ها";
    $statusTitle = "همه وضعیت‌ها";
    if ($session['status'] === 'ongoing') $statusTitle = "⏳ در حال پخش";
    if ($session['status'] === 'completed') $statusTitle = "✅ پایان یافته";

    $text = "⚡️ <b>بخش جستجوی پیشرفته آرشیو اثرها:</b>\n\n"
          . "شما می‌توانید با استفاده از گزینه‌های زیر روی دیتابیس فیلترگذاری کنید و سپس دکمه شروع جستجو را بفشارید:";

    $keyboard = [
        'inline_keyboard' => [
            [['text' => "🎭 ژانر/کتگوری: [ {$catTitle} ]", 'callback_data' => 'def_adv_set_category_1']],
            [
                ['text' => "📅 سال پخش: [ {$yearTitle} ]", 'callback_data' => 'def_adv_set_year'],
                ['text' => "🔢 وضعیت: [ {$statusTitle} ]", 'callback_data' => 'def_adv_set_status']
            ],
            [['text' => '🚀 شروع جستجوی پیشرفته', 'callback_data' => 'def_adv_search_run_1']],
            [
                ['text' => '❌ ریست کردن فیلترها', 'callback_data' => 'def_adv_search_reset'],
                ['text' => '🔙 بازگشت به خانه', 'callback_data' => 'def_home']
            ]
        ]
    ];

    $tg->editMessageText($chatId, $messageId, $text, $keyboard);
    exit;
}

// ب) کالبک نمایش لیست کتگوری‌های فعال ربات جهت انتخاب کاربر
elseif (strpos($callbackData, 'def_adv_set_category_') === 0) {
    $tg->answerCallbackQuery($callbackId);
    $page = (int)str_replace('def_adv_set_category_', '', $callbackData);
    $limit = 10;
    $offset = ($page - 1) * $limit;

    // شمارش کل کتگوری‌ها
    $stmtCount = $db->prepare("SELECT COUNT(*) FROM media_categories WHERE bot_id = :bot_id");
    $stmtCount->execute(['bot_id' => $botId]);
    $totalCats = $stmtCount->fetchColumn();
    $totalPages = ceil($totalCats / $limit);

    // واکشی داده‌ها
    $stmtCats = $db->prepare("SELECT id, title FROM media_categories WHERE bot_id = :bot_id ORDER BY id ASC LIMIT :limit OFFSET :offset");
    $stmtCats->bindValue(':bot_id', $botId, PDO::PARAM_INT);
    $stmtCats->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmtCats->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtCats->execute();
    $categories = $stmtCats->fetchAll();

    $text = "🎭 <b>یکی از ژانرها/کتگوری‌های زیر را جهت فیلتر انتخاب کنید:</b>";
    $buttons = [];
    $buttons[] = [['text' => '⭐ همه ژانرها (بدون فیلتر)', 'callback_data' => 'def_adv_select_cat_all']];

    foreach ($categories as $cat) {
        $buttons[] = [['text' => "🎭 " . $cat['title'], 'callback_data' => "def_adv_select_cat_{$cat['id']}"]];
    }

    $keyboard = LayoutRenderer::makeGrid($buttons, 2);
    $navRow = LayoutRenderer::makePaginationRow($page, $totalPages, 'def_adv_set_category');
    if (!empty($navRow)) {
        $keyboard[] = $navRow;
    }
    $keyboard[] = [['text' => '🔙 بازگشت به فیلترها', 'callback_data' => 'def_adv_search_init']];

    $tg->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $keyboard]);
    exit;
}

// ثبت کتگوری انتخاب‌شده در جلسه کاربر
elseif (strpos($callbackData, 'def_adv_select_cat_') === 0) {
    $catIdRaw = str_replace('def_adv_select_cat_', '', $callbackData);
    $catId = $catIdRaw === 'all' ? null : (int)$catIdRaw;

    $session = getUserSearchSession($db, $botId, $userId);
    $session['category_id'] = $catId;
    saveUserSearchSession($db, $botId, $userId, $session);

    // ارجاع مجدد به صفحه فیلترها
    $callbackQuery['data'] = 'def_adv_search_init';
    require __FILE__;
    exit;
}

// ج) کالبک نمایش سریع سال‌های پخش جهت فیلترگذاری
elseif ($callbackData === 'def_adv_set_year') {
    $tg->answerCallbackQuery($callbackId);
    
    $text = "📅 <b>سال انتشار اثر مورد نظر خود را انتخاب کنید:</b>";
    $buttons = [
        [['text' => 'همه سال‌ها', 'callback_data' => 'def_adv_select_yr_all']],
        [
            ['text' => '2026', 'callback_data' => 'def_adv_select_yr_2026'],
            ['text' => '2025', 'callback_data' => 'def_adv_select_yr_2025']
        ],
        [
            ['text' => '2024', 'callback_data' => 'def_adv_select_yr_2024'],
            ['text' => '2023', 'callback_data' => 'def_adv_select_yr_2023']
        ],
        [['text' => '🔙 بازگشت', 'callback_data' => 'def_adv_search_init']]
    ];

    $tg->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $buttons]);
    exit;
}

// ثبت سال انتخاب‌شده در جلسه کاربر
elseif (strpos($callbackData, 'def_adv_select_yr_') === 0) {
    $yearRaw = str_replace('def_adv_select_yr_', '', $callbackData);
    $year = $yearRaw === 'all' ? null : $yearRaw;

    $session = getUserSearchSession($db, $botId, $userId);
    $session['year'] = $year;
    saveUserSearchSession($db, $botId, $userId, $session);

    $callbackQuery['data'] = 'def_adv_search_init';
    require __FILE__;
    exit;
}

// د) کالبک نمایش فیلتر وضعیت پخش اثر
elseif ($callbackData === 'def_adv_set_status') {
    $tg->answerCallbackQuery($callbackId);

    $text = "🔢 <b>وضعیت پخش اثر را مشخص کنید:</b>";
    $buttons = [
        [['text' => 'همه وضعیت‌ها', 'callback_data' => 'def_adv_select_st_all']],
        [
            ['text' => '⏳ در حال پخش', 'callback_data' => 'def_adv_select_st_ongoing'],
            ['text' => '✅ پایان یافته', 'callback_data' => 'def_adv_select_st_completed']
        ],
        [['text' => '🔙 بازگشت', 'callback_data' => 'def_adv_search_init']]
    ];

    $tg->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $buttons]);
    exit;
}

// ثبت وضعیت در جلسه کاربر
elseif (strpos($callbackData, 'def_adv_select_st_') === 0) {
    $statusRaw = str_replace('def_adv_select_st_', '', $callbackData);
    $status = $statusRaw === 'all' ? null : $statusRaw;

    $session = getUserSearchSession($db, $botId, $userId);
    $session['status'] = $status;
    saveUserSearchSession($db, $botId, $userId, $session);

    $callbackQuery['data'] = 'def_adv_search_init';
    require __FILE__;
    exit;
}

// ه) کالبک اجرای جستجوی پیشرفته چندلایه در دیتابیس نئون با کوئری جی‌سان
elseif (strpos($callbackData, 'def_adv_search_run_') === 0) {
    $tg->answerCallbackQuery($callbackId);
    $page = (int)str_replace('def_adv_search_run_', '', $callbackData);
    $limit = 10;
    $offset = ($page - 1) * $limit;

    $session = getUserSearchSession($db, $botId, $userId);

    // پایه کوئری جستجوی پویا
    $sql = "SELECT id, title FROM manhwas WHERE bot_id = :bot_id ";
    $params = ['bot_id' => $botId];

    // پیاده‌سازی فیلترها بر روی فیلد اطلاعاتی custom_metadata از نوع JSONB نئون
    if (!empty($session['category_id'])) {
        $sql .= " AND custom_metadata->>'category_id' = :cat_id ";
        $params['cat_id'] = (string)$session['category_id'];
    }

    if (!empty($session['year'])) {
        $sql .= " AND custom_metadata->>'year' = :year ";
        $params['year'] = (string)$session['year'];
    }

    if (!empty($session['status'])) {
        $sql .= " AND custom_metadata->>'status' = :status ";
        $params['status'] = (string)$session['status'];
    }

    // محاسبه تعداد کل کارهای منطبق جهت ورق‌زن
    $sqlCount = str_replace("SELECT id, title", "SELECT COUNT(*)", $sql);
    $stmtC = $db->prepare($sqlCount);
    $stmtC->execute($params);
    $totalCount = $stmtC->fetchColumn();
    $totalPages = ceil($totalCount / $limit);

    if ($totalCount == 0) {
        $tg->sendMessage($userId, "🔍 اثری با فیلترهای انتخاب‌شده شما در آرشیو یافت نشد. لطفاً فیلترها را تغییر داده یا دکمه ریست را بفشارید.", [
            'inline_keyboard' => [[['text' => '🔙 ویرایش فیلترها', 'callback_data' => 'def_adv_search_init']]]
        ]);
        exit;
    }

    // اجرای کوئری نهایی برای واکشی داده‌های صفحه جاری
    $sql .= " ORDER BY id DESC LIMIT :limit OFFSET :offset ";
    $stmtData = $db->prepare($sql);
    
    // بایند کردن دستی متغیرهای فیلتر جی‌سان
    foreach ($params as $key => $val) {
        $stmtData->bindValue($key, $val);
    }
    $stmtData->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmtData->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmtData->execute();
    $results = $stmtData->fetchAll();

    $text = "⚡️ <b>نتایج حاصل از فیلترگذاری پیشرفته (صفحه {$page} از {$totalPages}):</b>\n\nبرای دیدن شناسنامه و دانلود اثر کلیک کنید:";
    $buttons = [];
    foreach ($results as $res) {
        $buttons[] = ['text' => "📚 " . $res['title'], 'callback_data' => "def_view_media_{$res['id']}"];
    }

    $keyboard = LayoutRenderer::makeGrid($buttons, 1);
    $navRow = LayoutRenderer::makePaginationRow($page, $totalPages, 'def_adv_search_run');
    if (!empty($navRow)) {
        $keyboard[] = $navRow;
    }
    $keyboard[] = [['text' => '🔙 ویرایش فیلترها', 'callback_data' => 'def_adv_search_init']];

    $tg->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $keyboard]);
    exit;
}

// و) کالبک ریست کردن کل فیلترهای جستجوی پیشرفته
elseif ($callbackData === 'def_adv_search_reset') {
    $tg->answerCallbackQuery($callbackId, "🔄 فیلترها بازنشانی شدند.");
    $session = ['category_id' => null, 'year' => null, 'status' => null];
    saveUserSearchSession($db, $botId, $userId, $session);

    $callbackQuery['data'] = 'def_adv_search_init';
    require __FILE__;
    exit;
}
