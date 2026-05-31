<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: child_sandbox/router.php
 * Role: Webhook Router for Sandbox Bots with Production Fallback (Cascade Loading)
 */

// ۱. اطمینان از صحت دسترسی به کانتکست ربات فرزند
if (!isset($botContext) || $botContext['is_master']) {
    exit;
}

$update = $botContext['update'];
$botId  = $botContext['bot_id'];
$db     = DB::connect();

// ۲. نمونه‌سازی شیء تلگرام با توکن اختصاصی ربات فرزند جاری
$tg = new Telegram($botContext['bot_token']);

// ۳. استخراج متغیرهای پایه تلگرام از آپدیت ورودی
$message       = $update['message'] ?? null;
$callbackQuery = $update['callback_query'] ?? null;

// استخراج اطلاعات کاربر فرستنده
$userId    = $message['from']['id'] ?? $callbackQuery['from']['id'] ?? null;
$username  = $message['from']['username'] ?? $callbackQuery['from']['username'] ?? null;
$firstName = $message['from']['first_name'] ?? $callbackQuery['from']['first_name'] ?? '';
$lastName  = $message['from']['last_name'] ?? $callbackQuery['from']['last_name'] ?? '';
$fullName  = trim($firstName . ' ' . $lastName);

if (!$userId) {
    exit; // دریافت پیامی بدون هویت کاربر (مانند آپدیت‌های کانال یا سیستمی خاص)
}

// ۴. ثبت اولیه یا آپدیت اطلاعات کاربر در دیتابیس برای این ربات مانهوا
$user = FSM::initUser($botId, $userId, $username, $fullName);

// ۵. تضمین دسترسی مالک اصلی ربات:
// اگر کاربر جاری مالک ثبت‌شده این ربات در جدول bots باشد، نقش او را روی owner و وضعیت را روی approved قفل می‌کنیم.
if ($userId === $botContext['owner_id']) {
    if ($user['role'] !== 'owner' || $user['status'] !== 'approved') {
        FSM::setRole($botId, $userId, 'owner');
        FSM::setStatus($botId, $userId, 'approved');
        $user = FSM::getUserData($botId, $userId); // بارگزاری مجدد اطلاعات اصلاح شده کاربر
    }
}

// ۶. تشخیص نوع محیط چت تلگرام (گروه یا چت شخصی)
$chatType = $message['chat']['type'] ?? $callbackQuery['message']['chat']['type'] ?? 'private';
$isGroup  = ($chatType === 'group' || $chatType === 'supergroup');

// ۷. روتینگ آبشاری هوشمند با متد مستقیم (بدون محدودیت Scope)
if ($isGroup) {
    $file = 'group_panel.php';
    if (file_exists(__DIR__ . '/' . $file)) {
        require_once __DIR__ . '/' . $file;
    } else {
        require_once dirname(__DIR__) . '/child/' . $file;
    }
    exit;
} else {
    // پیام در پی‌وی (چت شخصی) ربات فرستاده شده است
    $isAdmin = ($user['role'] === 'owner' || $user['role'] === 'admin');

    if ($isAdmin) {
        $file = 'admin_panel.php';
        if (file_exists(__DIR__ . '/' . $file)) {
            require_once __DIR__ . '/' . $file;
        } else {
            require_once dirname(__DIR__) . '/child/' . $file;
        }
        exit;
    } else {
        $file = 'user_panel.php';
        if (file_exists(__DIR__ . '/' . $file)) {
            require_once __DIR__ . '/' . $file;
        } else {
            require_once dirname(__DIR__) . '/child/' . $file;
        }
        exit;
    }
}
