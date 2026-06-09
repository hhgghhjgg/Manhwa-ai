<?php
/**
 * Project: Arvan Create Bot Maker Platform (Super Uploader Engine)
 * File: child_uploader/plugins/default_plugin/handler.php
 * Role: Core Default Plugin User Dispatcher & Dynamic UI Handler (Fully Fixed)
 */

// جلوگیری از لود مستقل بدون کانتکست و متغیرهای تعریف شده در هسته ربات
if (!isset($db, $tg, $botId, $userId)) {
    exit;
}

$callbackData = $callbackData ?? ($callbackQuery['data'] ?? '');
$callbackId   = $callbackId ?? ($callbackQuery['id'] ?? null); // مهار لودینگ ساعت تلگرام جهت تایید رویدادها [1]
$messageId    = $messageId ?? ($callbackQuery['message']['message_id'] ?? null);
$chatId       = $chatId ?? ($callbackQuery['message']['chat']['id'] ?? $userId);

// تفکیک متدهای کمکی جهت واکشی اطلاعات فارسی نوع فعالیت
if (!function_exists('getContentTypeFarsiName')) {
    function getContentTypeFarsiName($type) {
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

// ==========================================
// فاز ۱: رندر کردن منوی اصلی و استارت ربات (Action: render_start_menu)
// ==========================================
if ($pluginAction === 'render_start_menu') {
    // ۱. استعلام نوع محتوای پیش‌فرض ربات از هسته
    $contentType = LayoutRenderer::getBotContentType($db, $botId);

    // ۲. مقادیر خوش‌آمدگویی و دکمه‌های پیش‌فرض بر اساس نوع محتوای انتخاب‌شده در سوال بنیادین (Smart Presets)
    $defaultWelcome = "👋 به ربات سوپر آپلودر ما خوش آمدید.\n\nلطفاً جهت دسترسی به محتوای مورد نظر خود از منوی زیر استفاده کنید:";
    $defaultSearch  = "🔍 جستجوی عمومی";
    $defaultFav     = "⭐ لیست نشان‌شده‌ها";
    $defaultProfile = "👤 پروفایل کاربری";

    if ($contentType === 'movie') {
        $defaultWelcome = "🎬 <b>به بزرگ‌ترین آرشیو فیلم و سریال تلگرام خوش آمدید.</b>\n\nبرای جستجو از دکمه‌های زیر استفاده کنید:";
        $defaultSearch  = "🔍 جستجو در فیلم‌ها";
        $defaultFav     = "⭐ سینمای شخصی من";
    } elseif ($contentType === 'music') {
        $defaultWelcome = "🎵 <b>به پخش‌کننده و آرشیو موزیک خوش آمدید.</b>\n\nنام خواننده، آهنگ یا آلبوم دلخواه خود را در چت بفرستید:";
        $defaultSearch  = "🔍 جستجوی موزیک";
        $defaultFav     = "⭐ پلی‌لیست‌های من";
    } elseif ($contentType === 'manhwa') {
        $defaultWelcome = "📚 <b>به کتابخانه رسمی مانهوا، مانگا و ناول خوش آمدید.</b>\n\nجدیدترین آثار دنیای کمیک را در منوی منظم زیر بخوانید:";
        $defaultSearch  = "🔍 جستجوی مانهوا";
        $defaultFav     = "📚 کتابخانه شخصی من";
    }

    // ۳. بازنویسی برچسب دکمه‌ها در صورت شخصی‌سازی توسط ادمین در پنل مدیریت دیفالت
    $welcomeText = LayoutRenderer::getCustomLabel($db, $botId, 'welcome_msg_text', $defaultWelcome);
    $btnSearch   = LayoutRenderer::getCustomLabel($db, $botId, 'btn_search_label', $defaultSearch);
    $btnFav      = LayoutRenderer::getCustomLabel($db, $botId, 'btn_fav_label', $defaultFav);
    $btnProfile  = LayoutRenderer::getCustomLabel($db, $botId, 'btn_profile_label', $defaultProfile);

    // ۴. چیدن منوی اصلی دانلودر (شامل جستجوی پیشرفته به عنوان دکمه مستقل در منو اصلی)
    $mainButtons = [
        ['text' => $btnSearch, 'callback_data' => 'def_search_init'],
        ['text' => '⚡️ جستجوی پیشرفته', 'callback_data' => 'def_adv_search_init'],
        ['text' => $btnFav, 'callback_data' => 'def_favorites_list_1'],
        ['text' => $btnProfile, 'callback_data' => 'def_profile_view']
    ];

    $keyboard = LayoutRenderer::makeGrid($mainButtons, 2);

    // ۵. استخراج لیست‌های شیشه‌ای فعال ساخته شده در ابزار «لیست‌ساز» [1]
    $extraButtons = [];
    try {
        $stmtLists = $db->prepare("SELECT id, title FROM curated_lists WHERE bot_id = :bot_id ORDER BY id ASC LIMIT 5");
        $stmtLists->execute(['bot_id' => $botId]);
        $customLists = $stmtLists->fetchAll();
        foreach ($customLists as $lst) {
            $extraButtons[] = ['text' => "🔥 " . $lst['title'], 'callback_data' => "def_list_view_{$lst['id']}_1"];
        }
    } catch (PDOException $e) {}

    // ادغام و اضافه کردن لیست‌های ادمین به صورت مستقیم و مستقل در منوی استارت (تک‌دکمه‌های مستقل) [1]
    if (!empty($extraButtons)) {
        $gridExtra = LayoutRenderer::makeGrid($extraButtons, 2);
        foreach ($gridExtra as $row) {
            $keyboard[] = $row;
        }
    }

    // اضافه کردن بقیه منوهای فعال بازارچه (مانند تیکت یا عضویت) در ردیف آخر [1]
    $otherButtons = [];
    if (PluginLoader::isPluginActive($db, $botId, 'ticket_system')) {
        $otherButtons[] = ['text' => '✉️ تیکت پشتیبانی', 'callback_data' => 'ticket_system_init'];
    }
    if (PluginLoader::isPluginActive($db, $botId, 'practice_exams')) {
        $otherButtons[] = ['text' => '🏆 آزمون‌های تمرینی', 'callback_data' => 'practice_exams_init'];
    }
    
    if (!empty($otherButtons)) {
        $keyboard[] = $otherButtons;
    }

    $tg->sendMessage($userId, $welcomeText, ['inline_keyboard' => $keyboard]);
    exit;
}

// ==========================================
// فاز ۲: موتور جستجوی ساده آرشیو کارهای ربات (Action: search_query)
// ==========================================
elseif ($pluginAction === 'search_query') {
    $searchPath = __DIR__ . '/user_search.php';
    if (file_exists($searchPath)) {
        require_once $searchPath;
    }
    exit;
}

// ==========================================
// فاز ۳: پردازش متمرکز کالبک‌کوئری‌های کاربر عادی (Action: callback_query)
// ==========================================
elseif ($pluginAction === 'callback_query') {

    // الف) کالبک شروع جستجوی معمولی
    if ($callbackData === 'def_search_init') {
        $searchPath = __DIR__ . '/user_search.php';
        if (file_exists($searchPath)) {
            require_once $searchPath;
        }
        exit;
    }

    // ب) کالبک‌های مربوط به جستجوی پیشرفته داینامیک
    elseif (strpos($callbackData, 'def_adv_') === 0) {
        $searchPath = __DIR__ . '/user_search.php';
        if (file_exists($searchPath)) {
            require_once $searchPath;
        }
        exit;
    }

    // ج) کالبک بازگشت به خانه و منو اول
    elseif ($callbackData === 'def_home') {
        if ($callbackId) {
            $tg->answerCallbackQuery($callbackId);
        }
        FSM::clearStep($botId, $userId);

        $pluginAction = 'render_start_menu';
        require __FILE__;
        exit;
    }

    // د) کالبک مشاهده شناسنامه اثر و جزئیات (ارجاع پویا به user_details.php) [1]
    elseif (strpos($callbackData, 'def_view_media_') === 0 || strpos($callbackData, 'def_like_') === 0 || strpos($callbackData, 'def_dislike_') === 0 || strpos($callbackData, 'def_fav_add_') === 0) {
        $detailsPath = __DIR__ . '/user_details.php';
        if (file_exists($detailsPath)) {
            require $detailsPath;
        }
        exit;
    }

    // ه) کالبک نمایش لیست چپترها و دریافت فایل (ارجاع به user_chapters.php) [1]
    elseif (strpos($callbackData, 'def_chapters_list_') === 0 || strpos($callbackData, 'def_get_file_') === 0) {
        $chaptersPath = __DIR__ . '/user_chapters.php';
        if (file_exists($chaptersPath)) {
            require $chaptersPath;
        }
        exit;
    }

    // و) کالبک مشاهده لیست علاقه‌مندی‌های کاربر (ارجاع به user_favorites.php) [1]
    elseif (strpos($callbackData, 'def_favorites_list_') === 0) {
        $favsPath = __DIR__ . '/user_favorites.php';
        if (file_exists($favsPath)) {
            require $favsPath;
        }
        exit;
    }

    // ز) کالبک مشاهده پروفایل کاربر (ارجاع به user_profile.php) [1]
    elseif ($callbackData === 'def_profile_view') {
        $profilePath = __DIR__ . '/user_profile.php';
        if (file_exists($profilePath)) {
            require $profilePath;
        }
        exit;
    }

    // ح) کالبک مشاهده لیست‌های هوشمند لیست‌ساز (تراکنش زمانی و کتگوری) [1]
    elseif (strpos($callbackData, 'def_list_view_') === 0) {
        // لیست‌ها کارهای فیلترشده دیتابیس را لود می‌کنند، پس از موتور علاقه‌مندی‌ها برای نمایش ورق‌زن استفاده می‌کنیم [1]
        $favsPath = __DIR__ . '/user_favorites.php';
        if (file_exists($favsPath)) {
            require $favsPath;
        }
        exit;
    }
}
