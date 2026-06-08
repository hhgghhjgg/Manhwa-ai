<?php
/**
 * Project: Arvan Create Bot Maker Platform (Super Uploader Engine)
 * File: child_uploader/plugins/default_plugin/user_profile.php
 * Role: Full User Profile Sheet Renderer with Gregorian-to-Jalali Converter & Read Logger
 */

// جلوگیری از لود مستقل بدون کانتکست و متغیرهای تعریف شده در هسته ربات
if (!isset($db, $tg, $botId, $userId)) {
    exit;
}

$callbackData = $callbackData ?? ($callbackQuery['data'] ?? '');
$messageId    = $messageId ?? ($callbackQuery['message']['message_id'] ?? null);
$chatId       = $chatId ?? ($callbackQuery['message']['chat']['id'] ?? $userId);

// تضمین وجود جدول لاگ دانلودها و تاریخچه مطالعه برای محاسبات دقیق پروفایل [1]
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS user_read_history (
            bot_id INT NOT NULL,
            user_id BIGINT NOT NULL,
            chapter_id INT NOT NULL,
            read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (bot_id, user_id, chapter_id)
        );
    ");
} catch (PDOException $e) {
    error_log("Failed to create user_read_history table: " . $e->getMessage());
}

// ------------------------------------------
// بخش کمکی: الگوریتم استاندارد و بومی تبدیل تاریخ میلادی به هجری شمسی
// ------------------------------------------
if (!function_exists('gregorianToJalaliProfile')) {
    /**
     * تبدیل فرمت تاریخی میلادی (سال، ماه، روز) به شمسی
     */
    function gregorianToJalaliProfile($gy, $gm, $gd) {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $jy = ($gy <= 1600) ? 0 : 979;
        $gy -= ($gy <= 1600) ? 621 : 1600;
        $leap = (($gy + 3) % 4 == 0 && ($gy + 3) % 100 != 0) || (($gy + 399) % 400 == 0);
        $g_day_no = 365 * $gy + floor(($gy + 3) / 4) - floor(($gy + 99) / 100) + floor(($gy + 399) / 400) + $gd + $g_d_m[$gm - 1] + (($gm > 2 && $leap) ? 1 : 0);
        $jy += 33 * floor($g_day_no / 12053);
        $g_day_no %= 12053;
        $jy += 4 * floor($g_day_no / 1461);
        $g_day_no %= 1461;
        $jy += floor($g_day_no / 365);
        if ($g_day_no > 365) {
            $g_day_no %= 365;
        }
        $j_day_no = $g_day_no + 79;
        if ($j_day_no >= 366) {
            $j_day_no -= 366;
            $jy++;
        }
        $j_day_no %= 366;
        $jm = 1;
        while ($jm <= 11 && $j_day_no >= [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29][$jm - 1]) {
            $j_day_no -= [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29][$jm - 1];
            $jm++;
        }
        $jd = $j_day_no + 1;
        return [$jy, $jm, $jd];
    }
}

// ------------------------------------------
// بخش اصلی: استعلام اطلاعات کاربری و رندر شناسنامه پروفایل
// ------------------------------------------

// ۱. واکشی اطلاعات ثبت‌نام کاربر از جدول اصلی users
$stmtUser = $db->prepare("
    SELECT joined_at, role 
    FROM users 
    WHERE bot_id = :bot_id AND tg_id = :u_id 
    LIMIT 1
");
$stmtUser->execute(['bot_id' => $botId, 'u_id' => $userId]);
$userData = $stmtUser->fetch();

$rawJoinedAt = $userData ? ($userData['joined_at'] ?? 'now') : 'now';
$userRole    = $userData ? ($userData['role'] ?? 'none') : 'none';

// ۲. تبدیل تاریخ میلادی ثبت‌نام کاربر به تاریخ شمسی با استفاده از تابع کمکی
$timestamp = strtotime($rawJoinedAt);
$gy = (int)date('Y', $timestamp);
$gm = (int)date('n', $timestamp);
$gd = (int)date('j', $timestamp);

list($jy, $jm, $jd) = gregorianToJalaliProfile($gy, $gm, $gd);
$shamsiDate = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);

// ۳. استعلام تعداد کارهای نشان‌شده کاربر (Favorites Count)
$stmtFav = $db->prepare("
    SELECT COUNT(*) 
    FROM user_favorites 
    WHERE bot_id = :bot_id AND user_id = :u_id
");
$stmtFav->execute(['bot_id' => $botId, 'u_id' => $userId]);
$favoritesCount = $stmtFav->fetchColumn() ?: 0;

// ۴. استعلام تعداد کل کارهای مطالعه/دانلودشده کاربر (Read History Count)
$stmtRead = $db->prepare("
    SELECT COUNT(*) 
    FROM user_read_history 
    WHERE bot_id = :bot_id AND user_id = :u_id
");
$stmtRead->execute(['bot_id' => $botId, 'u_id' => $userId]);
$readCount = $stmtRead->fetchColumn() ?: 0;

// ۵. بررسی وضعیت اشتراک ویژه جهت معادل‌سازی نقش کاربری (VIP Status Check)
$roleDisplay = "مهمان (خواننده عمومی)";
$isVipInstalled = PluginLoader::isPluginActive($db, $botId, 'vip_subscription');

if ($isVipInstalled) {
    $stmtVip = $db->prepare("
        SELECT setting_value 
        FROM bot_plugin_settings 
        WHERE bot_id = :bot_id 
          AND plugin_slug = 'vip_subscription' 
          AND setting_key = 'vip_users' 
        LIMIT 1
    ");
    $stmtVip->execute(['bot_id' => $botId]);
    $vipData = $stmtVip->fetchColumn();
    $vipArray = $vipData ? json_decode($vipData, true) : [];

    if (in_array($userId, $vipArray)) {
        $roleDisplay = "💎 عضو ویژه ربات (VIP)";
    }
}

// معادل‌سازی نقش‌های سیستمی ادمین و مالک
if ($userRole === 'owner') {
    $roleDisplay = "👑 مالک و مدیر ارشد ربات";
} elseif ($userRole === 'admin') {
    $roleDisplay = "🛡️ ادمین رسمی ربات";
}

// ۶. ساخت متن و کیبورد نهایی پروفایل کاربری
$textProfile = "👤 <b>پروفایل کاربری و اطلاعات سیستمی شما:</b>\n\n"
             . "👤 نام شما: <b>{$fullName}</b>\n"
             . "🆔 شناسه عددی تلگرام: <code>{$userId}</code>\n"
             . "⚔️ سطح کاربری: <b>{$roleDisplay}</b>\n"
             . "📅 تاریخ شروع استفاده: <code>{$shamsiDate}</code>\n"
             . "📥 تعداد کل چپترهای دانلود شده: <code>{$readCount}</code> عدد\n"
             . "⭐ آثار موجود در کتابخانه شما: <code>{$favoritesCount}</code> عدد\n\n"
             . "💡 <i>نکته: تعداد دانلودها با کلیک روی هر دکمه دریافت چپتر در لیست کارها به طور خودکار افزایش می‌یابد.</i>";

$keyboard = [
    'inline_keyboard' => [
        [['text' => '🔙 بازگشت به منو اصلی', 'callback_data' => 'def_home']]
    ]
];

// ارسال و ویرایش پیام ادمین یا کاربر عادی تلگرام
$tg->editMessageText($chatId, $messageId, $textProfile, $keyboard);
exit;
