<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: master/master_handler.php
 * Role: Master Bot Processor with Real-time DB, Error Catching, Granular Bot Selection, and Mandatory Channel Join Lock
 */

// بررسی کانتکست و جلوگیری از خطای دسترسی غیرمجاز
if (!isset($botContext) || !$botContext['is_master']) {
    exit;
}

$update = $botContext['update'];
$db = DB::connect();

// نمونه‌سازی هِلپر تلگرام با توکن ربات‌ساز اصلی
$tg = new Telegram(MASTER_BOT_TOKEN);

// استخراج متغیرهای پیام یا کالبک‌کوئری
$message       = $update['message'] ?? null;
$callbackQuery = $update['callback_query'] ?? null;
$callbackId    = $callbackQuery['id'] ?? null;

$userId    = $message['from']['id'] ?? $callbackQuery['from']['id'] ?? null;
$username  = $message['from']['username'] ?? $callbackQuery['from']['username'] ?? null;
$firstName = $message['from']['first_name'] ?? $callbackQuery['from']['first_name'] ?? '';
$lastName  = $message['from']['last_name'] ?? $callbackQuery['from']['last_name'] ?? '';
$fullName  = trim($firstName . ' ' . $lastName);
$text      = $message['text'] ?? '';

if (!$userId) {
    exit;
}

// ثبت یا به‌روزرسانی مشخصات کاربر در زمینه ربات مادر (bot_id = 0)
$user = FSM::initUser(0, $userId, $username, $fullName);
$step = $user['step'] ?? 'idle';

// بررسی اینکه آیا کاربر استارت‌کننده، مالک اصلی کل سیستم است یا خیر
$isSystemOwner = ($userId === OWNER_ID);

// =========================================================
// فاز ۰: سیستم قفل جوین اجباری شیشه‌ای کانال مراجع توسعه (@arvan_dev)
// =========================================================
if (!function_exists('checkMasterJoin')) {
    /**
     * بررسی زنده عضویت کاربر در کانال توسعه با استفاده از متد تلگرام
     */
    function checkMasterJoin($tg, $userId) {
        if ($userId === OWNER_ID) {
            return true; // مالک اصلی سیستم معاف از قفل کانال تلگرام است
        }
        
        $response = $tg->apiRequest('getChatMember', [
            'chat_id' => '@arvan_dev',
            'user_id' => $userId
        ]);
        
        if ($response && isset($response['ok']) && $response['ok'] === true) {
            $status = $response['result']['status'] ?? '';
            return in_array($status, ['creator', 'administrator', 'member']);
        }
        return false;
    }
}

$isJoined = checkMasterJoin($tg, $userId);

// اگر کاربر عضو نبود، تمام سناریوها قفل و او را ملزم به عضویت در کانال می‌کنیم
if (!$isJoined) {
    $checkData = $callbackQuery['data'] ?? '';
    
    if ($checkData === 'master_check_join') {
        $tg->answerCallbackQuery($callbackId, "در حال بررسی وضعیت عضویت شما...");
        
        $reCheck = checkMasterJoin($tg, $userId);
        if ($reCheck) {
            FSM::clearStep(0, $userId);
            
            $keyboard = [];
            $keyboard[] = [['text' => '➕ ساخت ربات جدید', 'callback_data' => 'master_new_bot']];
            $keyboard[] = [
                ['text' => '📋 لیست ربات‌های من', 'callback_data' => 'master_my_bots'],
                ['text' => '❓ راهنما و قوانین', 'callback_data' => 'master_help']
            ];

            if ($isSystemOwner) {
                $keyboard[] = [
                    ['text' => '📊 آمار کل سیستم', 'callback_data' => 'master_owner_stats'],
                    ['text' => '🌐 لیست کل ربات‌ها', 'callback_data' => 'master_owner_all_bots']
                ];
            }

            $tg->sendMessage($userId, "🎉 <b>عضویت شما با موفقیت تایید شد!</b>\n\nبه منوی اصلی ربات‌ساز آروان خوش آمدید. لطفاً گزینه مورد نظر خود را انتخاب کنید:", ['inline_keyboard' => $keyboard]);
            exit;
        } else {
            $tg->sendMessage($userId, "⚠️ <b>شما هنوز عضو کانال رسمی توسعه‌دهندگان نشده‌اید!</b>\n\nلطفاً ابتدا از دکمه زیر وارد کانال شده، عضو شوید و سپس دکمه تایید بررسی عضویت را مجدداً لمس کنید.");
            exit;
        }
    } else {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📢 عضویت در کانال توسعه‌دهندگان (Arvan Dev)', 'url' => 'https://t.me/arvan_dev']],
                [['text' => '🔄 تایید و بررسی عضویت', 'callback_data' => 'master_check_join']]
            ]
        ];
        
        $lockText = "👋 سلام <b>{$fullName}</b> گرامی!\n\n"
                  . "برای استفاده از ربات‌ساز مانهوا و دسترسی به تمام ابزارهای آن، لطفاً ابتدا در کانال رسمی مراجع توسعه‌دهندگان (@arvan_dev) عضو شوید.\n\n"
                  . "👇 پس از کلیک روی دکمه عضویت زیر، عضو کانال شده و سپس دکمه تایید بررسی عضویت را بفشارید تا ربات برای شما فعال شود:";
        
        $tg->sendMessage($userId, $lockText, $keyboard);
        exit;
    }
}

// ---------------------------------------------------------
// تابع کمکی جهت ثبت نهایی ربات مانهوا در دیتابیس و تنظیم وب‌هوک
// ---------------------------------------------------------
if (!function_exists('registerBot')) {
    function registerBot($db, $tg, $tokenInput, $userId, $username, $fullName, $isSandbox = false) {
        try {
            $tempTg = new Telegram($tokenInput);
            $meResult = $tempTg->getMe();

            if ($meResult && isset($meResult['ok']) && $meResult['ok'] === true) {
                $botUsername = $meResult['result']['username'];
                $botName     = $meResult['result']['first_name'];

                // تشخیص پویای دامنه رندر جهت ست کردن وب‌هوک
                $host = $_SERVER['HTTP_HOST'] ?? '';
                if (empty($host)) {
                    $tg->sendMessage($userId, "❌ خطای سیستمی: دامنه سرور (HTTP_HOST) شناسایی نشد.");
                    return false;
                }

                $webhookUrl = "https://{$host}/index.php?bot_token=" . urlencode($tokenInput);
                $webhookResult = $tempTg->setWebhook($webhookUrl);

                if ($webhookResult && isset($webhookResult['ok']) && $webhookResult['ok'] === true) {
                    // ثبت یا به روز رسانی ربات در دیتابیس
                    $stmt = $db->prepare("
                        INSERT INTO bots (token, owner_id, bot_name, is_sandbox) 
                        VALUES (:token, :owner_id, :bot_name, :is_sandbox)
                        ON CONFLICT (token) DO UPDATE 
                        SET owner_id = EXCLUDED.owner_id, bot_name = EXCLUDED.bot_name, is_sandbox = EXCLUDED.is_sandbox
                        RETURNING id
                    ");
                    $stmt->execute([
                        'token'      => $tokenInput,
                        'owner_id'   => $userId,
                        'bot_name'   => '@' . $botUsername,
                        'is_sandbox' => $isSandbox ? 1 : 0
                    ]);
                    $botRow = $stmt->fetch();
                    
                    if (!$botRow) {
                        throw new Exception("خطا در بازگردانی شناسه ربات تازه ثبت شده از دیتابیس.");
                    }
                    
                    $newBotId = (int)$botRow['id'];

                    // مقداردهی اولیه تنظیمات برای ربات جدید
                    $stmtSettings = $db->prepare("
                        INSERT INTO settings (bot_id, key, value) VALUES 
                        (:bot_id, 'rate_translator', '10000'),
                        (:bot_id, 'rate_cleaner', '8000'),
                        (:bot_id, 'rate_typesetter', '8000'),
                        (:bot_id, 'rules', 'تست‌ها باید با کیفیت و بدون واترمارک باشند.')
                        ON CONFLICT (bot_id, key) DO NOTHING
                    ");
                    $stmtSettings->execute(['bot_id' => $newBotId]);

                    // ثبت سازنده به عنوان مالک (owner) در ربات جدید
                    $stmtOwner = $db->prepare("
                        INSERT INTO users (bot_id, tg_id, username, full_name, role, status)
                        VALUES (:bot_id, :tg_id, :username, :full_name, 'owner', 'approved')
                        ON CONFLICT (bot_id, tg_id) DO UPDATE 
                        SET role = 'owner', status = 'approved'
                    ");
                    $stmtOwner->execute([
                        'bot_id'    => $newBotId,
                        'tg_id'     => $userId,
                        'username'  => $username,
                        'full_name' => $fullName
                    ]);

                    FSM::clearStep(0, $userId);

                    $keyboard = [
                        'inline_keyboard' => [
                            [['text' => '🚀 ورود به ربات مانهوا', 'url' => "https://t.me/{$botUsername}"]],
                            [['text' => '🔙 بازگشت به منوی ربات‌ساز', 'callback_data' => 'master_cancel']]
                        ]
                    ];

                    $typeLabel = $isSandbox ? " (نسخه سندباکس)" : "";
                    $tg->sendMessage($userId, "🎉 <b>تبریک می‌گویم! ربات اختصاصی شما{$typeLabel} ساخته شد.</b>\n\n🤖 آیدی ربات: @{$botUsername}\n⚙️ نام نمایشی: {$botName}\n\n👇 وارد ربات خود شوید و دکمه <code>/start</code> را بفرستید تا کنترل پنل کامل ادمین تیم مانهوا برایتان باز شود.", $keyboard);
                    return true;
                } else {
                    $reason = $webhookResult['description'] ?? 'دلیل نامشخص از تلگرام';
                    $tg->sendMessage($userId, "❌ تلگرام درخواست ست کردن وب‌هوک را رد کرد.\nدلیل: <code>{$reason}</code>");
                }
            } else {
                $reason = $meResult['description'] ?? 'پاسخ نامشخص تلگرام';
                $tg->sendMessage($userId, "❌ <b>توکن نامعتبر است!</b>\n\nتوکن ارسالی توسط تلگرام تایید نشد.\nدلیل: <code>{$reason}</code>");
            }
        } catch (Exception $e) {
            $tg->sendMessage($userId, "❌ <b>خطای سیستمی رخ داد:</b>\n\n<code>" . htmlspecialchars($e->getMessage()) . "</code>");
            error_log("Master Bot Registration Exception: " . $e->getMessage());
        }
        return false;
    }
}

// ==========================================
// فاز ۳: پردازش دکمه‌های شیشه‌ای ربات‌ساز مادر (Callback Queries)
// ==========================================
if ($callbackQuery) {
    $callbackData = $callbackQuery['data'];
    $callbackId   = $callbackQuery['id'];
    $messageId    = $callbackQuery['message']['message_id'];
    $adminChatId  = $callbackQuery['message']['chat']['id'];

    $tg->answerCallbackQuery($callbackId);

    // مدیریت کلیک دکمه‌های گزینش نوع ربات (ویژه مالک سیستم)
    if (($callbackData === 'master_create_type_normal' || $callbackData === 'master_create_type_sandbox') && strpos($step, 'waiting_bot_type:') === 0) {
        $tokenInput = str_replace('waiting_bot_type:', '', $step);
        $isSandbox = ($callbackData === 'master_create_type_sandbox');
        
        registerBot($db, $tg, $tokenInput, $userId, $username, $fullName, $isSandbox);
        exit;
    }

    // دکمه لغو و بازگشت به منوی اصلی ربات‌ساز
    if ($callbackData === 'master_cancel') {
        FSM::clearStep(0, $userId);
        
        $keyboard = [];
        $keyboard[] = [['text' => '➕ ساخت ربات جدید', 'callback_data' => 'master_new_bot']];
        $keyboard[] = [
            ['text' => '📋 لیست ربات‌های من', 'callback_data' => 'master_my_bots'],
            ['text' => '❓ راهنما و قوانین', 'callback_data' => 'master_help']
        ];

        // نمایش دکمه‌های اختصاصی مالک کل سیستم
        if ($isSystemOwner) {
            $keyboard[] = [
                ['text' => '📊 آمار کل سیستم', 'callback_data' => 'master_owner_stats'],
                ['text' => '🌐 لیست کل ربات‌ها', 'callback_data' => 'master_owner_all_bots']
            ];
        }
        
        $tg->sendMessage($userId, "🤖 به منوی اصلی ربات‌ساز خوش آمدید. گزینه مورد نظر خود را انتخاب کنید:", ['inline_keyboard' => $keyboard]);
        exit;
    }

    // کالبک ساخت ربات جدید - نمایش لیست ربات‌هایی که می‌شود ساخت
    elseif ($callbackData === 'master_new_bot') {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📚 مدیریت تیم مانهوا', 'callback_data' => 'master_select_type_team']],
                [['text' => '🔙 بازگشت به منو', 'callback_data' => 'master_cancel']]
            ]
        ];
        
        $textMenu = "➕ <b>انتخاب نوع ربات جهت ساخت:</b>\n\n"
                  . "لطفاً نوع ربات مورد نظر خود را جهت ساخت انتخاب کنید تا وارد مراحل پیکربندی شویم:\n\n"
                  . "⚠️ <i>در حال حاضر فقط سیستم مدیریت تیم کاری فعال است.</i>";
                  
        $tg->editMessageText($adminChatId, $messageId, $textMenu, $keyboard);
        exit;
    }

    // کالبک انتخاب نوع ربات مدیریت تیم - نمایش توضیحات و نحوه دریافت توکن
    elseif ($callbackData === 'master_select_type_team') {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📥 شروع ساخت و ارسال توکن', 'callback_data' => 'master_start_team_token']],
                [['text' => '🔙 بازگشت به لیست', 'callback_data' => 'master_new_bot']]
            ]
        ];

        $infoText = "📚 <b>ربات مدیریت تیم کاری مانهوا ):</b>\n\n"
                  . "این ربات یک ابزار همه‌جانبه برای اسکن‌ها و تیم‌های ترجمه مانهوا، مانگا و ناول است که امکانات زیر را ارائه می‌دهد:\n"
                  . "🔹 سیستم پیشرفته استخدام با تست‌های ورودی داینامیک\n"
                  . "🔹 لیست اعضای تیم همراه با گزارش تراکنش‌ها\n"
                  . "🔹 پرداخت و تسویه حساب خودکار حقوق چپترها بر اساس نقش پرسنل\n"
                  . "🔹 سیستم تیکتینگ یکپارچه و آزمون‌های تمرینی عمومی\n"
                  . "🔹 پایش هوشمند پروژه‌های راکد و ارسال هشدارهای انضباطی به گروه‌ها\n\n"
                  . "💡 <b>راهنمای دریافت توکن:</b>\n"
                  . "۱. ابتدا وارد آیدی @BotFather در تلگرام شوید.\n"
                  . "۲. دستور <code>/newbot</code> را بفرستید و یک نام و یوزرنیم برای ربات خود انتخاب کنید.\n"
                  . "۳. توکن عددی ارسالی (HTTP API Token) را کپی کرده و آماده داشته باشید.\n\n"
                  . "👇 جهت شروع فرآیند ساخت و ارسال توکن، دکمه زیر را لمس کنید:";

        $tg->editMessageText($adminChatId, $messageId, $infoText, $keyboard);
        exit;
    }

    // کالبک شروع ارسال توکن - فعال‌سازی FSM مرحله waiting_for_token
    elseif ($callbackData === 'master_start_team_token') {
        FSM::setStep(0, $userId, 'waiting_for_token');
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ لغو عملیات', 'callback_data' => 'master_cancel']]
            ]
        ];

        $tg->sendMessage($userId, "📥 <b>بستر دریافت فعال شد.</b>\n\nلطفاً توکن دریافتی خود از @BotFather را ارسال کنید تا فرآیند ساخت آغاز شود:", $keyboard);
        exit;
    }

    // کالبک لیست ربات‌های کاربر جاری
    elseif ($callbackData === 'master_my_bots') {
        $stmt = $db->prepare("SELECT bot_name, token FROM bots WHERE owner_id = :owner_id ORDER BY id DESC");
        $stmt->execute(['owner_id' => $userId]);
        $myBots = $stmt->fetchAll();

        if (empty($myBots)) {
            $tg->sendMessage($userId, "⚠️ شما هنوز هیچ رباتی در سیستم نساخته‌اید.");
        } else {
            $textBots = "📋 <b>لیست ربات‌های فعال شما:</b>\n\n";
            $buttons = [];
            foreach ($myBots as $bot) {
                $textBots .= "🔹 {$bot['bot_name']}\n";
                $buttons[] = [['text' => "🚀 ورود به {$bot['bot_name']}", 'url' => "https://t.me/" . str_replace('@', '', $bot['bot_name'])]];
            }
            $buttons[] = [['text' => '🔙 بازگشت', 'callback_data' => 'master_cancel']];
            
            $tg->sendMessage($userId, $textBots, ['inline_keyboard' => $buttons]);
        }
        exit;
    }

    // کالبک راهنمای ساخت ربات
    elseif ($callbackData === 'master_help') {
        $helpText = "❓ <b>راهنمای گام‌به‌گام ساخت ربات مدیریت مانهوا:</b>\n\n"
                  . "💡 پس از ثبت موفق، وارد ربات مانهوای خود شده و <code>/start</code> بزنید تا به عنوان ادمین کل تیم، کنترل پنل شیشه‌ای مانهوا را ببینید.";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔙 بازگشت به منو', 'callback_data' => 'master_cancel']]
            ]
        ];
        $tg->sendMessage($userId, $helpText, $keyboard);
        exit;
    }

    // کالبک اختصاصی مالک: لیست کل ربات‌های ثبت شده در سیستم با لینک مستقیم ورود
    elseif ($callbackData === 'master_owner_all_bots' && $isSystemOwner) {
        $stmt = $db->prepare("SELECT bot_name, token, owner_id FROM bots WHERE id > 0 ORDER BY id DESC");
        $stmt->execute();
        $allBots = $stmt->fetchAll();

        if (empty($allBots)) {
            $tg->sendMessage($userId, "⚠️ هنوز هیچ رباتی در سیستم ثبت نشده است.");
        } else {
            $textBots = "🌐 <b>لیست کل ربات‌های ساخته شده در سرور مانهوا-آی‌آی:</b>\n\n";
            $buttons = [];
            foreach ($allBots as $bot) {
                $cleanName = str_replace('@', '', $bot['bot_name']);
                $textBots .= "🤖 <b>{$bot['bot_name']}</b>\n└ مالک مانهوا: <code>{$bot['owner_id']}</code>\n\n";
                $buttons[] = [['text' => "🚀 ورود به {$bot['bot_name']}", 'url' => "https://t.me/{$cleanName}"]];
            }
            $buttons[] = [['text' => '🔙 بازگشت به منوی ادمین', 'callback_data' => 'master_cancel']];
            
            $tg->sendMessage($userId, $textBots, ['inline_keyboard' => $buttons]);
        }
        exit;
    }

    // کالبک اختصاصی مالک: مانیتورینگ زنده و نمایش ۲۲ شاخص آماری کل سرور به صورت تفکیک‌شده (بدون خطا)
    elseif ($callbackData === 'master_owner_stats' && $isSystemOwner) {
        try {
            $stmtUserStats = $db->prepare("
                SELECT 
                    COUNT(DISTINCT tg_id) as total_users,
                    COUNT(DISTINCT CASE WHEN role = 'owner' THEN tg_id END) as total_owners,
                    COUNT(DISTINCT CASE WHEN role = 'admin' THEN tg_id END) as total_admins,
                    COUNT(DISTINCT CASE WHEN role = 'translator' AND status = 'approved' THEN tg_id END) as total_translators,
                    COUNT(DISTINCT CASE WHEN role = 'cleaner' AND status = 'approved' THEN tg_id END) as total_cleaners,
                    COUNT(DISTINCT CASE WHEN role = 'typesetter' AND status = 'approved' THEN tg_id END) as total_typesetters,
                    COUNT(CASE WHEN status = 'pending_test' THEN 1 END) as pending_recruits,
                    COALESCE(SUM(total_earned), 0) as total_earned_sum
                FROM users 
                WHERE bot_id > 0;
            ");
            $stmtUserStats->execute();
            $uStats = $stmtUserStats->fetch();

            $stmtManhwaStats = $db->prepare("
                SELECT 
                    COUNT(*) as total_manhwas,
                    COUNT(CASE WHEN group_id IS NOT NULL THEN 1 END) as connected_manhwas
                FROM manhwas;
            ");
            $stmtManhwaStats->execute();
            $mStats = $stmtManhwaStats->fetch();

            $stmtChapterStats = $db->prepare("
                SELECT 
                    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_chapters,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_chapters,
                    COALESCE(SUM(CASE WHEN status = 'approved' THEN translator_pay ELSE 0 END), 0) as pay_translators,
                    COALESCE(SUM(CASE WHEN status = 'approved' THEN cleaner_pay ELSE 0 END), 0) as pay_cleaners,
                    COALESCE(SUM(CASE WHEN status = 'approved' THEN typesetter_pay ELSE 0 END), 0) as pay_typesetters
                FROM chapters;
            ");
            $stmtChapterStats->execute();
            $cStats = $stmtChapterStats->fetch();

            $stmtTestStats = $db->prepare("
                SELECT 
                    COUNT(*) as total_tests,
                    COUNT(CASE WHEN status = 'accepted' THEN 1 END) as accepted_tests,
                    COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_tests
                FROM submitted_tests;
            ");
            $stmtTestStats->execute();
            $tStats = $stmtTestStats->fetch();

            $stmtMiscStats = $db->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM tickets) as total_tickets,
                    (SELECT COUNT(*) FROM tickets WHERE status = 'open') as open_tickets,
                    (SELECT COUNT(*) FROM practice_exams) as total_exams,
                    (SELECT COUNT(*) FROM bots WHERE id > 0) as total_bots;
            ");
            $stmtMiscStats->execute();
            $miscStats = $stmtMiscStats->fetch();

            $totalPaidSum = (float)$cStats['pay_translators'] + (float)$cStats['pay_cleaners'] + (float)$cStats['pay_typesetters'];

            $statsText = "📊 <b>گزارش آماری ۲۲ شاخص متمایز کل سرور (مخصوص مالک کل):</b>\n\n"
                       . "🤖 <b>بخش ربات‌ها:</b>\n"
                       . "└ ۱. کل ربات‌های فعال ثبت شده: <code>{$miscStats['total_bots']}</code> ربات\n\n"
                       . "👥 <b>بخش کاربران و پرسنل تیمی:</b>\n"
                       . "├ ۲. کل کاربران ثبت شده در ربات‌ها: <code>{$uStats['total_users']}</code> نفر\n"
                       . "├ ۳. تعداد مالکین ربات‌های مانهوا: <code>{$uStats['total_owners']}</code> نفر\n"
                       . "├ ۴. تعداد کل ادمین‌های منتسب شده: <code>{$uStats['total_admins']}</code> نفر\n"
                       . "├ ۵. مترجمین فعال تایید شده: <code>{$uStats['total_translators']}</code> نفر\n"
                       . "├ ۶. کلینرهای فعال تایید شده: <code>{$uStats['total_cleaners']}</code> نفر\n"
                       . "├ ۷. تایپیست‌های فعال تایید شده: <code>{$uStats['total_typesetters']}</code> نفر\n"
                       . "└ ۸. کاندیداهای در انتظار بررسی استخدام: <code>{$uStats['pending_recruits']}</code> نفر\n\n"
                       . "📚 <b>بخش مانهواها و پروژه‌ها:</b>\n"
                       . "├ ۹. کل پروژه‌های مانهوای ثبت شده: <code>{$mStats['total_manhwas']}</code> عدد\n"
                       . "└ ۱۰. مانهواهای فعال متصل به گروه کار: <code>{$mStats['connected_manhwas']}</code> عدد\n\n"
                       . "🔢 <b>بخش چپترها و ارسال کارها:</b>\n"
                       . "├ ۱۱. کل چپترهای تایید و ثبت شده: <code>{$cStats['approved_chapters']}</code> چپتر\n"
                       . "└ ۱۲. چپترهای در انتظار تایید مدیریت: <code>{$cStats['pending_chapters']}</code> چپتر\n\n"
                       . "💸 <b>بخش محاسبات مالی و حقوق‌ها:</b>\n"
                       . "├ ۱۳. کل حقوق توزیع شده پرسنل: <code>" . number_format($totalPaidSum) . "</code> تومان\n"
                       . "├ ۱۴. مجموع کیف پول فعلی اعضا: <code>" . number_format($uStats['total_earned_sum']) . "</code> تومان\n"
                       . "├ ۱۵. سهم پرداختی به مترجمین: <code>" . number_format($cStats['pay_translators']) . "</code> تومان\n"
                       . "├ ۱۶. سهم پرداختی به کلینرها: <code>" . number_format($cStats['pay_cleaners']) . "</code> تومان\n"
                       . "└ ۱۷. سهم پرداختی به تایپیست‌ها: <code>" . number_format($cStats['pay_typesetters']) . "</code> تومان\n\n"
                       . "📂 <b>بخش سنجش، آزمون‌ها و تیکت‌ها:</b>\n"
                       . "├ ۱۸. مجموع تست‌های استخدامی ثبت شده: <code>{$tStats['total_tests']}</code> تست\n"
                       . "├ ۱۹. تست‌های استخدامی پذیرفته‌شده: <code>{$tStats['accepted_tests']}</code> مورد\n"
                       . "├ ۲۰. تست‌های استخدامی رد شده: <code>{$tStats['rejected_tests']}</code> مورد\n"
                       . "├ ۲۱. کل تیکت‌های پشتیبانی باز شده: <code>{$miscStats['total_tickets']}</code> تیکت\n"
                       . "├ ۲۲. تیکت‌های باز در انتظار پاسخ: <code>{$miscStats['open_tickets']}</code> تیکت\n"
                       . "└ ۲۳. کل آزمون‌های تمرینی ثبت شده: <code>{$miscStats['total_exams']}</code> آزمون\n\n"
                       . "🕒 <i>این گزارش بر اساس آخرین تراکنش‌های دیتابیس نئون به صورت زنده تولید شده است.</i>";

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'master_cancel']]
                ]
            ];
            $tg->sendMessage($userId, $statsText, $keyboard);
        } catch (Exception $e) {
            $tg->sendMessage($userId, "❌ خطا در محاسبات زنده آماری سرور.");
        }
        exit;
    }
}

// ==========================================
// ۴. پردازش پیام‌های متنی ارسالی به ربات‌ساز مادر (Text Commands)
// ==========================================
if (!empty($text)) {
    // دستور استارت ربات‌ساز اصلی
    if ($text === '/start') {
        FSM::clearStep(0, $userId);

        $keyboard = [];
        $keyboard[] = [['text' => '➕ ساخت ربات جدید', 'callback_data' => 'master_new_bot']];
        $keyboard[] = [
            ['text' => '📋 لیست ربات‌های من', 'callback_data' => 'master_my_bots'],
            ['text' => '❓ راهنما و قوانین', 'callback_data' => 'master_help']
        ];

        $welcome = "سلام <b>{$fullName}</b> گرامی!\n"
                 . "به ربات‌ساز بزرگ <b>تیم مانهوا</b> خوش آمدید.\n\n"
                 . "با این سیستم می‌توانید ربات پیشرفته اختصاصی خود را جهت مدیریت مانهوا، ترجمه، تایپ، کلینرها، محاسبه حقوق و سازماندهی کارهای تیم خود بسازید.\n\n"
                 . "👇 برای شروع کار یکی از گزینه‌های زیر را انتخاب کنید:";

        // نمایش گزینه‌های مانیتورینگ پیشرفته برای مالک کل سیستم
        if ($isSystemOwner) {
            $keyboard[] = [
                ['text' => '📊 آمار کل سیستم', 'callback_data' => 'master_owner_stats'],
                ['text' => '🌐 لیست کل ربات‌ها', 'callback_data' => 'master_owner_all_bots']
            ];
            $welcome .= "\n\n🛡️ <b>شما به عنوان مالک اصلی سیستم شناسایی شدید. پنل مانیتورینگ زنده کل سرور برای شما فعال است.</b>";
        }

        $tg->sendMessage($userId, $welcome, ['inline_keyboard' => $keyboard]);
        exit;
    }

    // اگر کاربر در حال ارسال توکن ربات مانهوا باشد
    if ($step === 'waiting_for_token') {
        $tokenInput = trim($text);

        if (!preg_match('/^[0-9]+:[a-zA-Z0-9_-]+$/', $tokenInput)) {
            $tg->sendMessage($userId, "❌ فرمت توکن ارسال شده نامعتبر است. لطفاً توکن معتبر ارسال کنید یا دکمه لغو را فشار دهید.");
            exit;
        }

        // --- بررسی عدم امکان تصاحب غیرمجاز ربات دیگران ---
        $stmtCheck = $db->prepare("SELECT owner_id FROM bots WHERE token = :token LIMIT 1");
        $stmtCheck->execute(['token' => $tokenInput]);
        $existingBot = $stmtCheck->fetch();

        if ($existingBot) {
            if ((int)$existingBot['owner_id'] !== $userId) {
                $tg->sendMessage($userId, "❌ <b>خطای امنیتی!</b>\n\nاین ربات قبلاً توسط کاربر دیگری ثبت شده است و شما مالکیت آن را بر عهده ندارید.");
                exit;
            }
        }

        // اعتبارسنجی توکن از طریق تلگرام
        $tempTg = new Telegram($tokenInput);
        $meResult = $tempTg->getMe();

        if ($meResult && isset($meResult['ok']) && $meResult['ok'] === true) {
            if ($isSystemOwner) {
                // اگر فرستنده توکن مالک اصلی سیستم باشد، او را به پنل گزینش نوع ساختار هدایت می‌کنیم
                FSM::setStep(0, $userId, 'waiting_bot_type:' . $tokenInput);
                
                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '🤖 ربات عادی', 'callback_data' => 'master_create_type_normal'],
                            ['text' => '🧪 ربات سندباکس', 'callback_data' => 'master_create_type_sandbox']
                        ],
                        [['text' => '❌ لغو عملیات', 'callback_data' => 'master_cancel']]
                    ]
                ];
                $tg->sendMessage($userId, "👤 <b>مالک محترم کل سیستم؛</b>\n\nلطفاً نوع ساختار اجرایی این ربات را مشخص کنید تا وب‌هوک به شکل مناسب هدایت شود:", $keyboard);
            } else {
                // برای کاربران عادی، ربات به صورت مستقیم و در حالت عادی ثبت می‌گردد
                registerBot($db, $tg, $tokenInput, $userId, $username, $fullName, false);
            }
        } else {
            $tg->sendMessage($userId, "❌ <b>توکن نامعتبر است!</b>\n\nتوکن ارسالی توسط تلگرام تایید نشد.");
        }
        exit;
    }
}
