<?php
/**
 * Project: Arvan Create Bot Maker Platform (Super Uploader Engine)
 * File: child_uploader/plugins/default_plugin/admin_management.php
 * Role: Admin Operations & Work Management Dispatcher (Conditional Button Rendering)
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
// بخش روتینگ کارهای فرعی مدیریت به فایل‌های تخصصی خودشان (Sub-routes)
// ------------------------------------------

// ارجاع به مدیریت فیلدهای اطلاعاتی، کتگوری و لیست‌ساز
if (strpos($callbackData, 'def_settings_') === 0 || $callbackData === 'def_manage_settings') {
    $settingsPath = __DIR__ . '/admin_settings.php';
    if (file_exists($settingsPath)) {
        require_once $settingsPath;
    } else {
        $tg->sendMessage($userId, "❌ خطا: فایل تنظیمات تخصصی تاپیک یافت نشد.");
    }
    exit;
}

// ارجاع به جادوگر ثبت کار جدید و لیست مانهواها/فیلم‌ها
if (strpos($callbackData, 'def_manage_work_') === 0 || strpos($callbackData, 'def_work_') === 0) {
    $wizardPath = __DIR__ . '/admin_work_wizard.php';
    if (file_exists($wizardPath)) {
        require_once $wizardPath;
    } else {
        $tg->sendMessage($userId, "❌ خطا: فایل مدیریت کارها یافت نشد.");
    }
    exit;
}

// ارجاع به مدیریت فایل‌ها/چپترهای هر اثر
if (strpos($callbackData, 'def_manage_chapters_') === 0 || strpos($callbackData, 'def_chapters_') === 0) {
    $chaptersPath = __DIR__ . '/admin_chapter_manager.php';
    if (file_exists($chaptersPath)) {
        require_once $chaptersPath;
    } else {
        $tg->sendMessage($userId, "❌ خطا: فایل مدیریت چپترها یافت نشد.");
    }
    exit;
}

// ------------------------------------------
// بخش اول: پردازش متون ارسالی ماشین وضعیت (FSM Text Interceptors)
// ------------------------------------------
if ($userStep !== 'idle' && empty($callbackData)) {
    // پردازش ارتقای کاربر به مقام ادمین از طریق آیدی عددی
    if ($userStep === 'def_waiting_new_admin_id') {
        $targetId = trim($text);

        if (!is_numeric($targetId)) {
            $tg->sendMessage($userId, "❌ آیدی عددی تلگرام فقط باید عدد باشد. مجدداً ارسال کنید:");
            exit;
        }

        // بررسی اینکه آیا کاربر قبلاً ربات را استارت کرده است یا خیر
        $stmtCheck = $db->prepare("SELECT full_name FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id LIMIT 1");
        $stmtCheck->execute(['bot_id' => $botId, 'tg_id' => $targetId]);
        $targetUser = $stmtCheck->fetch();

        if (!$targetUser) {
            $tg->sendMessage($userId, "❌ کاربری با این آیدی عددی هنوز ربات را استارت نکرده است. ابتدا از او بخواهید ربات را استارت کند.");
            exit;
        }

        // ارتقا به ادمین و تایید رسمی عضویت
        $stmtUp = $db->prepare("UPDATE users SET role = 'admin', status = 'approved' WHERE bot_id = :bot_id AND tg_id = :tg_id");
        $stmtUp->execute(['bot_id' => $botId, 'tg_id' => $targetId]);

        // فرستادن نوتیفیکیشن برای کاربر ارتقا یافته
        $tg->sendMessage($targetId, "🎉 <b>تبریک می‌گویم! شما توسط مدیریت ارشد به مقام «ادمین رسمی ربات» ارتقا یافتید.</b>\n\nدستور <code>/start</code> را ارسال کنید تا پنل دسترسی‌ها برای شما فعال شود.");

        FSM::clearStep($botId, $userId);
        $tg->sendMessage($userId, "✅ کاربر <b>«{$targetUser['full_name']}»</b> با موفقیت به عنوان ادمین جدید ربات ثبت شد.", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت به مدیریت', 'callback_data' => 'def_management_menu']]]
        ]);
        exit;
    }
}

// ------------------------------------------
// بخش دوم: پردازش رویداد دکمه‌های شیشه‌ای مدیریت (Callbacks)
// ------------------------------------------

// الف) منوی اصلی «📂 مدیریت» (شامل چیدمان دکمه‌های ۳ ستونه سفارشی شما)
if ($callbackData === 'def_management_menu') {
    $tg->answerCallbackQuery($callbackId);

    // ۱. بررسی وضعیت نصب بودن افزونه‌های تیکت پشتیبانی و پیشنهادات جهت رندر دکمه داینامیک [1]
    $stmtT = $db->prepare("
        SELECT plugin_slug 
        FROM bot_installed_plugins 
        WHERE bot_id = :bot_id 
          AND plugin_slug IN ('ticket_system', 'suggestions_system') 
          AND is_active = TRUE
    ");
    $stmtT->execute(['bot_id' => $botId]);
    $activeSupportPlugins = $stmtT->fetchAll(PDO::FETCH_COLUMN);

    $hasTicket = in_array('ticket_system', $activeSupportPlugins);
    $hasSugg   = in_array('suggestions_system', $activeSupportPlugins);

    $ticketBtnText = null;
    if ($hasTicket && $hasSugg) {
        $ticketBtnText = "✉️ تیکت‌ها و پیشنهادات";
    } elseif ($hasTicket) {
        $ticketBtnText = "✉️ بخش تیکت";
    } elseif ($hasSugg) {
        $ticketBtnText = "✉️ بخش پیشنهادات";
    }

    // ۲. چیدن دکمه‌های منوی مدیریت به صورت قرینه و تمیز
    $keyboardButtons = [
        [['text' => '⚙️ تنظیمات', 'callback_data' => 'def_manage_settings']],
        [
            ['text' => '📁 مدیریت کار', 'callback_data' => 'def_manage_work_list_1'],
            ['text' => '👥 مدیریت کاربران', 'callback_data' => 'def_manage_users']
        ],
        [['text' => '➕ افزودن ادمین', 'callback_data' => 'def_manage_add_admin']]
    ];

    // الحاق پویا دکمه تیکت (در صورت تایید نصب بودن صفحات از بازارچه) [1]
    if ($ticketBtnText !== null) {
        $keyboardButtons[] = [['text' => $ticketBtnText, 'callback_data' => 'def_manage_tickets_menu']];
    }

    $keyboardButtons[] = [['text' => '🔙 بازگشت به شخصی‌سازی', 'callback_data' => 'def_customization_menu']];

    $text = "📂 <b>بخش ابزارها و کارهای مدیریتی ربات:</b>\n\n"
          . "در این منو می‌توانید آرشیو کارها، کاربران، تنظیم فیلدهای پویا و تاپیک‌ها را مدیریت کنید.\n\n"
          . "یکی از گزینه‌های مدیریتی زیر را لمس کنید:";

    $tg->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $keyboardButtons]);
    exit;
}

// ب) کالبک کلیک روی «➕ افزودن ادمین» (FSM Setup)
elseif ($callbackData === 'def_manage_add_admin') {
    $tg->answerCallbackQuery($callbackId);
    FSM::setStep($botId, $userId, 'def_waiting_new_admin_id');

    $tg->sendMessage($userId, "🛡️ <b>بخش انتساب ادمین جدید:</b>\n\nلطفاً آیدی عددی تلگرام کاربر مورد نظر جهت ارتقا به ادمین را بفرستید:\n\n💡 <i>توجه: کاربر ابتدا باید ربات را استارت کرده باشد.</i>", [
        'inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'def_management_menu']]]
    ]);
    exit;
}

// ج) کالبک نمایش بخش تیکت‌ها و پیشنهادات (در صورت فعال بودن)
elseif ($callbackData === 'def_manage_tickets_menu') {
    $tg->answerCallbackQuery($callbackId);

    // واگذاری پردازش به افزونه تیکت در صورتی که فایل آن در بازارچه باشد
    $ticketAdminPath = __DIR__ . "/../ticket_system/admin_menu.php";
    if (file_exists($ticketAdminPath)) {
        require_once $ticketAdminPath;
    } else {
        // هندل پاپ‌آپ تایید
        $tg->apiRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text'              => "✉️ جهت پاسخ به تیکت‌ها، می‌توانید روی دکمه شیشه‌ای پاسخ در پیام تیکت‌های دریافتی کلیک کنید.",
            'show_alert'        => true
        ]);
    }
    exit;
}

// د) کالبک مدیریت کاربران ربات (User Management statistics)
elseif ($callbackData === 'def_manage_users') {
    $tg->answerCallbackQuery($callbackId);

    // واکشی آمارهای کلی کاربران ربات فرزند
    $stmtCount = $db->prepare("SELECT COUNT(*) as total_users FROM users WHERE bot_id = :bot_id");
    $stmtCount->execute(['bot_id' => $botId]);
    $totalUsers = $stmtCount->fetch()['total_users'];

    $stmtAdmins = $db->prepare("SELECT COUNT(*) as total_admins FROM users WHERE bot_id = :bot_id AND (role = 'admin' OR role = 'owner')");
    $stmtAdmins->execute(['bot_id' => $botId]);
    $totalAdmins = $stmtAdmins->fetch()['total_admins'];

    $text = "👥 <b>بخش مدیریت کاربران ربات سوپر آپلودر:</b>\n\n"
          . "📈 <b>آمار کلان کاربران:</b>\n"
          . "├ کل کاربران عضو ربات: <code>{$totalUsers}</code> نفر\n"
          . "└ تعداد کل مدیران و ادمین‌ها: <code>{$totalAdmins}</code> نفر\n\n"
          . "💡 <i>نکته: مدیریت و کنترل دسترسی‌های ۲۲گانه ادمین‌ها از طریق بخش [تنظیمات تیم -> لیست کامل اعضا] در ربات مانهوای اصلی قابل پیکربندی است.</i>";

    $keyboard = [
        'inline_keyboard' => [
            [['text' => '🔙 بازگشت به مدیریت', 'callback_data' => 'def_management_menu']]
        ]
    ];

    $tg->editMessageText($chatId, $messageId, $text, $keyboard);
    exit;
}
