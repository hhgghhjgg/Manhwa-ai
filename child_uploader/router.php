<?php
/**
 * Project: Arvan Create Bot Maker Platform (Super Uploader Engine)
 * File: child_uploader/router.php
 * Role: Webhook Router & Secure Dynamic Event Dispatcher for Child Bots
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

$userId    = $message['from']['id'] ?? $callbackQuery['from']['id'] ?? null;
$username  = $message['from']['username'] ?? $callbackQuery['from']['username'] ?? null;
$firstName = $message['from']['first_name'] ?? $callbackQuery['from']['first_name'] ?? '';
$lastName  = $message['from']['last_name'] ?? $callbackQuery['from']['last_name'] ?? '';
$fullName  = trim($firstName . ' ' . $lastName);

if (!$userId) {
    exit; // خروج در صورت دریافت آپدیت‌های بدون هویت کاربر (مانند آپدیت کانال)
}

// ۴. ثبت یا به‌روزرسانی مشخصات کاربر در پایگاه‌داده
$user = FSM::initUser($botId, $userId, $username, $fullName);

// ۵. تضمین دسترسی مالک اصلی ربات (قفل کردن نقش روی owner در صورت تطابق آیدی)
if ($userId === $botContext['owner_id']) {
    if ($user['role'] !== 'owner' || $user['status'] !== 'approved') {
        FSM::setRole($botId, $userId, 'owner');
        FSM::setStatus($botId, $userId, 'approved');
        $user = FSM::getUserData($botId, $userId); // بارگذاری مجدد اطلاعات اصلاح‌شده
    }
}

// ۶. تشخیص نوع محیط چت تلگرام (گروه یا چت شخصی پی‌وی)
$chatType = $message['chat']['type'] ?? $callbackQuery['message']['chat']['type'] ?? 'private';
$isGroup  = ($chatType === 'group' || $chatType === 'supergroup');

if ($isGroup) {
    // هدایت به پردازشگر کارهای گروهی کاری سوپر آپلودر
    $groupPanelPath = __DIR__ . '/group_panel.php';
    if (file_exists($groupPanelPath)) {
        require_once $groupPanelPath;
    }
    exit;
}

// ==========================================
// فاز پردازش چت شخصی پی‌وی (Private Chat)
// ==========================================

// بارگذاری لودر افزونه‌ها جهت استفاده از کلاس کمکی PluginLoader
$pluginLoaderPath = __DIR__ . '/plugin_loader.php';
if (file_exists($pluginLoaderPath)) {
    require_once $pluginLoaderPath;
} else {
    $tg->sendMessage($userId, "❌ خطای بحرانی: فایل لودر افزونه‌ها روی سرور یافت نشد.");
    exit;
}

$isAdmin   = ($user['role'] === 'owner' || $user['role'] === 'admin');
$userStep  = $user['step'] ?? 'idle';
$text      = isset($message['text']) ? trim($message['text']) : '';

// ------------------------------------------
// سناریوی الف: هدایت پویای مراحل ماشین وضعیت فعال (FSM Dynamic Routing)
// ------------------------------------------
if ($userStep !== 'idle' && !empty($userStep)) {
    // تفکیک مرحله بر اساس کاراکتر زیرخط (_) جهت استخراج پیشوند
    $stepParts = explode('_', $userStep, 2);
    $stepPrefix = $stepParts[0] ?? '';
    $pluginSlug = PluginLoader::getSlugByPrefix($stepPrefix);

    // اعتبارسنجی امنیتی نام پوشه افزونه جهت جلوگیری از حملات Directory Traversal
    if ($pluginSlug && preg_match('/^[a-zA-Z0-9_]+$/', $pluginSlug)) {
        // بررسی فعال بودن افزونه برای ربات جاری جهت ممانعت از تداخل کدهای غیراونر
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
    
    // تفکیک داده کالبک جهت استخراج پیشوند دکمه شیشه‌ای
    $callbackParts = explode('_', $callbackData, 2);
    $callbackPrefix = $callbackParts[0] ?? '';
    $pluginSlug = PluginLoader::getSlugByPrefix($callbackPrefix);

    // اعتبارسنجی امنیتی شناسه افزونه هدف
    if ($pluginSlug && preg_match('/^[a-zA-Z0-9_]+$/', $pluginSlug)) {
        // بررسی فعال بودن افزونه برای ربات جاری
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
    // کاربر ادمین یا مالک اصلی ربات است؛ لود پنل ادمین اصلی سوپر آپلودر
    $adminPanelPath = __DIR__ . '/admin_panel.php';
    if (file_exists($adminPanelPath)) {
        require_once $adminPanelPath;
    } else {
        $tg->sendMessage($userId, "❌ خطای سیستم: فایل پنل ادمین سوپر آپلودر یافت نشد.");
    }
} else {
    // کاربر عادی یا دانلودکننده فایل است؛ لود پنل کاربری اصلی سوپر آپلودر
    $userPanelPath = __DIR__ . '/user_panel.php';
    if (file_exists($userPanelPath)) {
        require_once $userPanelPath;
    } else {
        $tg->sendMessage($userId, "❌ خطای سیستم: فایل بخش کاربری سوپر آپلودر یافت نشد.");
    }
}

exit;
