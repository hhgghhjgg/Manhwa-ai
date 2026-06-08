<?php
/**
 * Project: Arvan Create Bot Maker Platform (Super Uploader Engine)
 * File: child_uploader/plugins/default_plugin/admin_menu.php
 * Role: Main Admin Dispatcher, Onboarding Seeder & Root Default Panel Handler
 */

// جلوگیری از لود مستقل بدون کانتکست و متغیرهای تعریف شده در هسته ربات
if (!isset($db, $tg, $botId, $userId)) {
    exit;
}

$callbackData = $callbackData ?? ($callbackQuery['data'] ?? '');
$messageId    = $messageId ?? ($callbackQuery['message']['message_id'] ?? null);
$chatId       = $chatId ?? ($callbackQuery['message']['chat']['id'] ?? $userId);

// ------------------------------------------
// بخش کمکی: سیدرهای داینامیک مقداردهی اولیه برای ۱۳ حوزه فعالیت ربات
// ------------------------------------------
if (!function_exists('seedBotPresets')) {
    /**
     * اجرای سیدرهای پویا و پر کردن اطلاعات اولیه متناسب با نوع انتخابی ربات
     */
    function seedBotPresets($db, $botId, $type) {
        $db->beginTransaction();
        try {
            // ۱. تعریف کتگوری‌ها (ژانرها) بر اساس نوع محتوا
            $categories = [];
            // ۲. تعریف فیلدهای اطلاعاتی پویا بر اساس نوع محتوا
            $fields = [];
            // ۳. تعریف لیست‌های هوشمند پیش‌فرض
            $lists = [];

            if ($type === 'manhwa') {
                $fields = [
                    ['id' => 1, 'title' => 'نویسنده/طراح', 'type' => 'text'],
                    ['id' => 2, 'title' => 'وضعیت انتشار', 'type' => 'text'],
                    ['id' => 3, 'title' => 'خلاصه داستان', 'type' => 'text']
                ];
                $categories = ['اکشن', 'عاشقانه', 'درام', 'فانتزی', 'تناسخ'];
                $lists = [
                    ['title' => 'جدیدترین چپترها', 'time' => 'week'],
                    ['title' => 'پرطرفدارترین مانهواهای ماه', 'time' => 'month']
                ];
            } elseif ($type === 'movie' || $type === 'anime') {
                $fields = [
                    ['id' => 1, 'title' => 'سال انتشار', 'type' => 'number'],
                    ['id' => 2, 'title' => 'امتیاز اثر', 'type' => 'number'],
                    ['id' => 3, 'title' => 'کارگردان / استودیو', 'type' => 'text']
                ];
                $categories = ['اکشن', 'درام', 'کمدی', 'ترسناک', 'علمی تخیلی', 'فانتزی'];
                $lists = [
                    ['title' => 'فیلم‌های برتر این هفته', 'time' => 'week'],
                    ['title' => 'انیمه‌های محبوب ماه', 'time' => 'month']
                ];
            } else {
                // سیدر عمومی برای بقیه حوزه‌ها (آموزشی، موزیک، برنامه، پورن و غیره)
                $fields = [
                    ['id' => 1, 'title' => 'سال ساخت/انتشار', 'type' => 'number'],
                    ['id' => 2, 'title' => 'توضیحات فایل', 'type' => 'text']
                ];
                $categories = ['عمومی', 'ویژه', 'پیشنهادی'];
                $lists = [
                    ['title' => 'برترین کارهای هفته', 'time' => 'week']
                ];
            }

            // الف) ثبت خودکار فیلدهای داینامیک در جدول تنظیمات
            $stmtFields = $db->prepare("
                INSERT INTO bot_plugin_settings (bot_id, plugin_slug, setting_key, setting_value) 
                VALUES (:bot_id, 'default_plugin', 'custom_fields_list', :val)
                ON CONFLICT (bot_id, plugin_slug, setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value
            ");
            $stmtFields->execute([
                'bot_id' => $botId,
                'val'    => json_encode($fields)
            ]);

            // ب) ثبت خودکار کتگوری‌ها در جدول media_categories
            $db->exec("CREATE TABLE IF NOT EXISTS media_categories (id SERIAL PRIMARY KEY, bot_id INT NOT NULL, title VARCHAR(100) NOT NULL, CONSTRAINT unique_bot_category UNIQUE (bot_id, title));");
            $stmtCat = $db->prepare("INSERT INTO media_categories (bot_id, title) VALUES (:bot_id, :title) ON CONFLICT DO NOTHING");
            foreach ($categories as $cat) {
                $stmtCat->execute(['bot_id' => $botId, 'title' => $cat]);
            }

            // ج) ثبت خودکار لیست‌های هوشمند در جدول curated_lists
            $db->exec("CREATE TABLE IF NOT EXISTS curated_lists (id SERIAL PRIMARY KEY, bot_id INT NOT NULL, title VARCHAR(255) NOT NULL, category_id INT DEFAULT NULL, time_filter VARCHAR(50) DEFAULT 'all');");
            $stmtList = $db->prepare("INSERT INTO curated_lists (bot_id, title, time_filter) VALUES (:bot_id, :title, :time)");
            foreach ($lists as $lst) {
                $stmtList->execute([
                    'bot_id' => $botId,
                    'title'  => $lst['title'],
                    'time'   => $lst['time']
                ]);
            }

            $db->commit();
            return true;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Database seeding failed: " . $e->getMessage());
            return false;
        }
    }
}

// ------------------------------------------
// فاز ۱: پردازش کالبک سوال بنیادین و اجرای سیدرها (Onboarding Process)
// ------------------------------------------
if (strpos($callbackData, 'def_settype_') === 0) {
    $selectedType = str_replace('def_settype_', '', $callbackData);

    // ۱. ثبت نوع فعالیت ربات در جدول اصلی bots دیتابیس
    $stmtUpdate = $db->prepare("UPDATE bots SET bot_content_type = :type WHERE id = :id");
    $stmtUpdate->execute(['type' => $selectedType, 'id' => $botId]);

    // ۲. اجرای سیدر پویا متناسب با نوع انتخابی جهت پر کردن اتوماتیک دیتابیس [1]
    seedBotPresets($db, $botId, $selectedType);

    // ۳. ارسال پاپ‌آپ تایید موقت تلگرام
    $tg->answerCallbackQuery($callbackId, "✅ ربات شما با موفقیت پیکربندی شد!");

    // ۴. رفرش و هدایت خودکار به منوی اصلی ادمین دیفالت
    $callbackQuery['data'] = 'admin_managements';
    require __FILE__;
    exit;
}

// ------------------------------------------
// فاز ۲: ارجاع کالبک‌های مدیریت و شخصی‌سازی به فایل‌های مجزا (Menu Dispatching)
// ------------------------------------------

// ارجاع کل رویدادهای مربوط به «🛠 شخصی سازی» به فایل admin_customization.php
if ($callbackData === 'def_customization_menu' || strpos($callbackData, 'def_custom_') === 0) {
    $customizationPath = __DIR__ . '/admin_customization.php';
    if (file_exists($customizationPath)) {
        require_once $customizationPath;
    } else {
        $tg->sendMessage($userId, "❌ خطا: فایل شخصی‌سازی پنل مدیریت یافت نشد.");
    }
    exit;
}

// ارجاع کل رویدادهای مربوط به «📂 مدیریت» به فایل admin_management.php
if ($callbackData === 'def_management_menu' || strpos($callbackData, 'def_manage_') === 0 || strpos($callbackData, 'def_settings_') === 0) {
    $managementPath = __DIR__ . '/admin_management.php';
    if (file_exists($managementPath)) {
        require_once $managementPath;
    } else {
        $tg->sendMessage($userId, "❌ خطا: فایل ابزار مدیریت پنل یافت نشد.");
    }
    exit;
}

// ------------------------------------------
// فاز ۳: استعلام نوع ربات و لود منوی ریشه دیفالت (Default Main Menu)
// ------------------------------------------
$stmtType = $db->prepare("SELECT bot_content_type FROM bots WHERE id = :bot_id LIMIT 1");
$stmtType->execute(['bot_id' => $botId]);
$contentType = $stmtType->fetchColumn();

// الف) سناریوی لود سوال بنیادین (اگر ادمین برای نخستین بار کلیک کرده است)
if (!$contentType || $contentType === 'team') {
    $text = "❓ <b>ربات شما چیست؟</b>\n\n"
          . "حوزه فعالیت اصلی خود را مشخص کنید تا ربات اطلاعات اولیه، کتگوری‌ها و لیست‌ها را به صورت خودکار بسازد [1]:";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📚 مانهوا، مانگا و یائویی', 'callback_data' => 'def_settype_manhwa'],
                ['text' => '🎬 فیلم و سریال', 'callback_data' => 'def_settype_movie']
            ],
            [
                ['text' => ' انیمه', 'callback_data' => 'def_settype_anime'],
                ['text' => '📖 کتاب', 'callback_data' => 'def_settype_book']
            ],
            [
                ['text' => '🎵 موزیک', 'callback_data' => 'def_settype_music'],
                ['text' => '🎓 آموزشی', 'callback_data' => 'def_settype_educational']
            ],
            [
                ['text' => '📱 برنامه', 'callback_data' => 'def_settype_app'],
                ['text' => '💻 سورس کد', 'callback_data' => 'def_settype_source_code']
            ],
            [
                ['text' => '🎨 فایل گرافیک', 'callback_data' => 'def_settype_graphic'],
                ['text' => '🎮 بازی', 'callback_data' => 'def_settype_game']
            ],
            [
                ['text' => '🖼 والپیپر', 'callback_data' => 'def_settype_wallpaper'],
                ['text' => '🔞 پورن', 'callback_data' => 'def_settype_porn']
            ],
            [
                ['text' => ' هنتای', 'callback_data' => 'def_settype_hentai']
            ],
            [['text' => '🔙 لغو و بازگشت', 'callback_data' => 'admin_back_to_menu']]
        ]
    ];

    $tg->editMessageText($chatId, $messageId, $text, $keyboard);
    exit;
}

// ب) سناریوی لود منوی ریشه ادمین دیفالت (شخصی سازی / مدیریت)
if (!function_exists('getContentTypeFarsiLabel')) {
    function getContentTypeFarsiLabel($type) {
        $map = [
            'manhwa'      => '📚 مانهوا، مانگا و یائویی',
            'movie'       => '🎬 فیلم و سریال',
            'anime'       => ' انیمه',
            'book'        => '📖 کتاب',
            'music'       => '🎵 موزیک',
            'educational' => '🎓 آموزشی',
            'app'         => '📱 برنامه',
            'source_code' => '💻 سورس کد',
            'graphic'     => '🎨 فایل گرافیک',
            'game'        => '🎮 بازی',
            'wallpaper'   => '🖼 والپیپر',
            'porn'        => '🔞 پورن',
            'hentai'      => ' هنتای'
        ];
        return $map[$type] ?? $type;
    }
}

$textMenu = "⚙️ <b>پنل تنظیمات افزونه دیفالت:</b>\n\n"
          . "حوزه فعالیت ربات شما: <b>" . getContentTypeFarsiLabel($contentType) . "</b>\n\n"
          . "جهت تغییر دکمه‌های کاربری یا بخش کارها، یکی از گزینه‌های زیر را انتخاب کنید:";

$keyboardMenu = [
    'inline_keyboard' => [
        [['text' => '🛠 شخصی سازی', 'callback_data' => 'def_customization_menu']],
        [['text' => '📂 مدیریت', 'callback_data' => 'def_management_menu']],
        [['text' => '🔙 بازگشت به منوی ادمین', 'callback_data' => 'admin_back_to_menu']]
    ]
];

$tg->editMessageText($chatId, $messageId, $textMenu, $keyboardMenu);
exit;
