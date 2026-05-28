<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: child/router.php
 * Role: Webhook Router for Child Bots (Group vs Private, Admin vs User)
 */

// اطمینان از صحت دسترسی به کانتکست ربات فرزند
if (!isset($botContext) || $botContext['is_master']) {
    exit;
}

$update = $botContext['update'];
$botId  = $botContext['bot_id'];
$db     = DB::connect();

// ۱. نمونه‌سازی شیء تلگرام با توکن اختصاصی ربات فرزند جاری
$tg = new Telegram($botContext['bot_token']);

// ۲. استخراج متغیرهای پایه تلگرام از آپدیت ورودی
$message       = $update['message'] ?? null;
$callbackQuery = $update['callback_query'] ?? null;

// استخراج اطلاعات کاربر فرستنده
$userId    = $message['from']['id'] ?? $callbackQuery['from']['id'] ?? null;
$username  = $message['from']['username'] ?? $callbackQuery['from']['username'] ?? null;
$firstName = $message['from']['first_name'] ?? $callbackQuery['from']['first_name'] ?? '';
$lastName  = $message['from']['last_name'] ?? $callbackQuery['from']['last_name'] ?? '';
$fullName  = trim($firstName . ' ' . $lastName);

if (!$userId) {
    exit; // دریافت پیامی بدون هویت کاربر (مثلاً آپدیت‌های کانال یا سیستمی خاص)
}

// ۳. ثبت اولیه یا آپدیت اطلاعات کاربر در دیتابیس برای این ربات مانهوا
$user = FSM::initUser($botId, $userId, $username, $fullName);

// ۴. تضمین دسترسی مالک اصلی ربات:
// اگر کاربر جاری مالک ثبت‌شده این ربات در جدول bots باشد، نقش او را روی owner و وضعیت را روی approved قفل می‌کنیم.
if ($userId === $botContext['owner_id']) {
    if ($user['role'] !== 'owner' || $user['status'] !== 'approved') {
        FSM::setRole($botId, $userId, 'owner');
        FSM::setStatus($botId, $userId, 'approved');
        $user = FSM::getUserData($botId, $userId); // بارگزاری مجدد اطلاعات اصلاح شده کاربر
    }
}

// ۵. تشخیص نوع محیط چت تلگرام (گروه یا چت شخصی)
$chatType = $message['chat']['type'] ?? $callbackQuery['message']['chat']['type'] ?? 'private';
$isGroup  = ($chatType === 'group' || $chatType === 'supergroup');

if ($isGroup) {
    // پیام در گروه یا سوپرگروه مانهوا فرستاده شده است
    require_once __DIR__ . '/group_panel.php';
    exit;
} else {
    // پیام در پی‌وی (چت شخصی) ربات فرستاده شده است
    $isAdmin = ($user['role'] === 'owner' || $user['role'] === 'admin');

    if ($isAdmin) {
        // ۱. ادمین‌ها و مالکین به پنل ادمین هدایت می‌شوند
        require_once __DIR__ . '/admin_panel.php';
        exit;
    } else {
        // ۲. اعضای عادی تیم (تایپیست، کلینر، مترجم) و داوطلبان جدید به پنل کاربر هدایت می‌شوند
        require_once __DIR__ . '/user_panel.php';
        exit;
    }
}
