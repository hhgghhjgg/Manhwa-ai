<?php
/**
 * Project: Arvan Create Bot Maker Platform (Super Uploader Engine)
 * File: child_uploader/router.php
 * Role: Webhook Router & Secure Dynamic Event Dispatcher for Child Bots (Fully Fixed)
 */

// ۱. اطمینان از صحت دسترسی به کانتکست ربات فرزند و لود زیرساخت‌های پایه
if (!isset($botContext) || $botContext['is_master']) {
    exit;
}

$update = $botContext['update'];
$botId  = $botContext['bot_id'];
$db     = DB::connect();

// ۲. نمونه‌سازی موتور تلگرام با توکن اختصاصی این ربات فرزند
$tg = new Telegram($botContext['bot_token']);

// ۳. استخراج اطلاعات رویداد تلگرام (پیام متنی یا کالبک‌کوئری)
$message       = $update['message'] ?? null;
$callbackQuery = $update['callback_query'] ?? null;
$callbackId    = $callbackQuery['id'] ?? null; // تعریف سراسری جهت ارث‌بری خودکار در پلاگین‌ها و رفع لودینگ تلگرام

$userId    = $message['from']['id'] ?? $callbackQuery['from']['id'] ?? null;
$username  = $message['from']['username'] ?? $callbackQuery['from']['username'] ?? null;
$firstName = $message['from']['first_name'] ?? $callbackQuery['from']['first_name'] ?? '';
$lastName  = $message['from']['last_name'] ?? $callbackQuery['from']['last_name'] ?? '';
$fullName  = trim($firstName . ' ' . $lastName);

if (!$userId) {
    exit;
}

// ۴. ثبت یا به‌روزرسانی مشخصات کاربر در پایگاه‌داده
$user = FSM::initUser($botId, $userId, $username, $fullName);

// ۵. تضمین دسترسی مالک اصلی ربات (owner)
if ($userId === $botContext['owner_id']) {
    if ($user['role'] !== 'owner' || $user['status'] !== 'approved') {
        FSM::setRole($botId, $userId, 'owner');
        FSM::setStatus($botId, $userId, 'approved');
        $user = FSM::getUserData($botId, $userId);
    }
}

// ۶. تشخیص نوع محیط چت تلگرام (گروه یا چت شخصی پی‌وی)
$chatType = $message['chat']['type'] ?? $callbackQuery['message']['chat']['type'] ?? 'private';
$isGroup  = ($chatType === 'group' || $chatType === 'supergroup');

if ($isGroup) {
    $groupPanelPath = __DIR__ . '/group_panel.php';
    if (file_exists($groupPanelPath)) {
        require_once $groupPanelPath;
    }
    exit;
}

// =========================================================
// بارگذاری زیرساخت‌های گرافیکی و لودرها به صورت سراسری در روتر پی‌وی (حل مشکل کلاس یافت نشد)
// =========================================================

// لود کردن لودر افزونه‌ها جهت استفاده از کلاس کمکی PluginLoader
$pluginLoaderPath = __DIR__ . '/plugin_loader.php';
if (file_exists($pluginLoaderPath)) {
    require_once $pluginLoaderPath;
} else {
    $tg->sendMessage($userId, "❌ خطای بحرانی: فایل لودر افزونه‌ها روی سرور یافت نشد.");
    exit;
}

// لود کردن رندرر گرافیکی به صورت سراسری (حل نهایی خطای Class 'LayoutRenderer' not found در کل پلاگین‌ها) [1]
$layoutRendererPath = __DIR__ . '/layout_renderer.php';
if (file_exists($layoutRendererPath)) {
    require_once $layoutRendererPath;
} else {
    $tg->sendMessage($userId, "❌ خطای بحرانی: فایل رندرر گرافیکی روی سرور یافت نشد.");
    exit;
}

$isAdmin   = ($user['role'] === 'owner' || $user['role'] === 'admin');
$userStep  = $user['step'] ?? 'idle';
$text      = isset($message['text']) ? trim($message['text']) : '';

// ------------------------------------------
// سناریوی الف: هدایت پویای مراحل ماشین وضعیت فعال (FSM Dynamic Routing)
// ------------------------------------------
if ($userStep !== 'idle' && !empty($userStep)) {
    $stepParts = explode('_', $userStep, 2);
    $stepPrefix = $stepParts[0] ?? '';
    $pluginSlug = PluginLoader::getSlugByPrefix($stepPrefix);

    if ($pluginSlug && preg_match('/^[a-zA-Z0-9_]+$/', $pluginSlug)) {
        if (PluginLoader::isPluginActive($db, $botId, $pluginSlug)) {
            if ($isAdmin) {
                $pluginAdminPath = __DIR__ . "/plugins/{$pluginSlug}/admin_menu.php";
                if (file_exists($pluginAdminPath)) {
                    require_once $pluginAdminPath;
                    exit;
                }
            } else {
                $pluginUserPath = __DIR__ . "/plugins/{$pluginSlug}/handler.php";
                if (file_exists($pluginUserPath)) {
                    require_once $pluginUserPath;
                    exit;
                }
            }
        }
    }
}

// ------------------------------------------
// سناریوی ب: هدایت کالبک‌کوئری‌های افزونه‌محور بدون دخالت پنل اصلی (Callback Routing)
// ------------------------------------------
if ($callbackQuery) {
    $callbackData = $callbackQuery['data'] ?? '';
    
    $callbackParts = explode('_', $callbackData, 2);
    $callbackPrefix = $callbackParts[0] ?? '';
    $pluginSlug = PluginLoader::getSlugByPrefix($callbackPrefix);

    if ($pluginSlug && preg_match('/^[a-zA-Z0-9_]+$/', $pluginSlug)) {
        if (PluginLoader::isPluginActive($db, $botId, $pluginSlug)) {
            if ($isAdmin) {
                $pluginAdminPath = __DIR__ . "/plugins/{$pluginSlug}/admin_menu.php";
                if (file_exists($pluginAdminPath)) {
                    require_once $pluginAdminPath;
                    exit;
                }
            } else {
                $pluginUserPath = __DIR__ . "/plugins/{$pluginSlug}/handler.php";
                if (file_exists($pluginUserPath)) {
                    require_once $pluginUserPath;
                    exit;
                }
            }
        }
    }
}

// ------------------------------------------
// سناریوی ج: عدم وجود افکت پویای افزونه (انتقال به پنل‌های پیش‌فرض سیستم)
// ------------------------------------------
if ($isAdmin) {
    $adminPanelPath = __DIR__ . '/admin_panel.php';
    if (file_exists($adminPanelPath)) {
        require_once $adminPanelPath;
    } else {
        $tg->sendMessage($userId, "❌ خطای سیستم: فایل پنل ادمین سوپر آپلودر یافت نشد.");
    }
} else {
    $userPanelPath = __DIR__ . '/user_panel.php';
    if (file_exists($userPanelPath)) {
        require_once $userPanelPath;
    } else {
        $tg->sendMessage($userId, "❌ خطای سیستم: فایل بخش کاربری سوپر آپلودر یافت نشد.");
    }
}

exit;
