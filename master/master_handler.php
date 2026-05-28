<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: master/master_handler.php
 * Role: Master Bot Processor (Bot Creator Engine)
 */

// اطمینان از دسترسی به اطلاعات زمینه ربات مادر
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

$userId    = $message['from']['id'] ?? $callbackQuery['from']['id'] ?? null;
$username  = $message['from']['username'] ?? $callbackQuery['from']['username'] ?? null;
$fullName  = ($message['from']['first_name'] ?? $callbackQuery['from']['first_name'] ?? '') . ' ' . ($message['from']['last_name'] ?? $callbackQuery['from']['last_name'] ?? '');
$text      = $message['text'] ?? '';

if (!$userId) exit;

// ۱. ثبت یا به‌روزرسانی مشخصات کاربر در زمینه ربات مادر (bot_id = 0)
$user = FSM::initUser(0, $userId, $username, $fullName);
$step = $user['step'] ?? 'idle';

// ۲. پردازش دکمه‌های شیشه‌ای ربات‌ساز مادر (Callback Queries)
if ($callbackQuery) {
    $callbackData = $callbackQuery['data'];
    $callbackId   = $callbackQuery['id'];
    $messageId    = $callbackQuery['message']['message_id'];

    $tg->answerCallbackQuery($callbackId);

    // دکمه درخواست ساخت ربات جدید
    if ($callbackData === 'master_new_bot') {
        FSM::setStep(0, $userId, 'waiting_for_token');
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ لغو عملیات', 'callback_data' => 'master_cancel']]
            ]
        ];
        
        $tg->sendMessage($userId, "📥 <b>لطفاً توکن ربات مانهوای خود را ارسال کنید:</b>\n\nبرای این کار ابتدا به آیدی @BotFather در تلگرام رفته، ربات جدید بسازید و توکنی که به شما می‌دهد (که شبیه به متن زیر است) را کپی کرده و برای من بفرستید:\n\n<code>123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ</code>", $keyboard);
        exit;
    }

    // دکمه نمایش لیست ربات‌های ساخته شده توسط کاربر جاری
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

    // دکمه راهنمای ساخت ربات
    elseif ($callbackData === 'master_help') {
        $helpText = "❓ <b>راهنمای گام‌به‌گام ساخت ربات مدیریت مانهوا:</b>\n\n"
                  . "۱. وارد ربات @BotFather شوید.\n"
                  . "۲. دستور <code>/newbot</code> را بزنید.\n"
                  . "۳. نام ربات و سپس یک یوزرنیم که به bot ختم شود انتخاب کنید.\n"
                  . "۴. توکن ارسالی از طرف بات‌فادر را کپی کرده و در بخش [➕ ساخت ربات جدید] ارسال کنید.\n\n"
                  . "💡 پس از ثبت موفق، وارد ربات مانهوای خود شده و <code>/start</code> بزنید تا به عنوان مدیر کل تیم، کنترل پنل شیشه‌ای مانهوا را ببینید.";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔙 بازگشت به منو', 'callback_data' => 'master_cancel']]
            ]
        ];
        $tg->sendMessage($userId, $helpText, $keyboard);
        exit;
    }

    // دکمه لغو و بازگشت به منوی اصلی ربات‌ساز
    elseif ($callbackData === 'master_cancel') {
        FSM::clearStep(0, $userId);
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '➕ ساخت ربات جدید', 'callback_data' => 'master_new_bot']],
                [['text' => '📋 لیست ربات‌های من', 'callback_data' => 'master_my_bots']],
                [['text' => '❓ راهنما و قوانین', 'callback_data' => 'master_help']]
            ]
        ];
        
        $tg->sendMessage($userId, "🤖 به منوی اصلی ربات‌ساز خوش آمدید. گزینه مورد نظر خود را انتخاب کنید:", $keyboard);
        exit;
    }
}

// ۳. پردازش پیام‌های متنی ارسالی به ربات‌ساز مادر (Text Commands)
if (!empty($text)) {
    // دستور استارت ربات‌ساز اصلی
    if ($text === '/start') {
        FSM::clearStep(0, $userId);

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '➕ ساخت ربات جدید', 'callback_data' => 'master_new_bot']],
                [['text' => '📋 لیست ربات‌های من', 'callback_data' => 'master_my_bots']],
                [['text' => '❓ راهنما و قوانین', 'callback_data' => 'master_help']]
            ]
        ];

        $welcome = "سلام <b>{$fullName}</b> گرامی!\n"
                 . "به ربات‌ساز بزرگ <b>تیم مانهوا مانپین</b> خوش آمدید.\n\n"
                 . "با این سیستم می‌توانید ربات پیشرفته اختصاصی خود را جهت مدیریت مانهوا، ترجمه، تایپ، کلینرها، محاسبه حقوق و سازماندهی کارهای تیم خود بسازید.\n\n"
                 . "👇 برای شروع کار یکی از گزینه‌های زیر را انتخاب کنید:";

        // اگر کاربر استارت کننده، مالک کل سیستم باشد، پنل آمار کل را نیز نشان می‌دهیم
        if ($userId === OWNER_ID) {
            $stmtCount = $db->prepare("SELECT COUNT(*) as total_bots FROM bots");
            $stmtCount->execute();
            $totalBots = $stmtCount->fetch()['total_bots'];

            $stmtCountUsers = $db->prepare("SELECT COUNT(DISTINCT tg_id) as total_users FROM users WHERE bot_id > 0");
            $stmtCountUsers->execute();
            $totalUsers = $stmtCountUsers->fetch()['total_users'];

            $welcome .= "\n\n📊 <b>آمار کل سرور (مخصوص مالک اصلی ربات‌ساز):</b>\n"
                      . "└ تعداد کل ربات‌های ساخته شده: <code>{$totalBots}</code> ربات\n"
                      . "└ تعداد کل اعضای ثبت‌شده در ربات‌ها: <code>{$totalUsers}</code> نفر";
        }

        $tg->sendMessage($userId, $welcome, $keyboard);
        exit;
    }

    // اگر کاربر در حال ارسال توکن ربات مانهوا باشد
    if ($step === 'waiting_for_token') {
        // حذف فاصله‌های اضافی احتمالی از ابتدا و انتهای توکن
        $tokenInput = trim($text);

        // بررسی اینکه توکن فرمت اولیه مناسبی دارد یا خیر
        if (!preg_match('/^[0-9]+:[a-zA-Z0-9_-]+$/', $tokenInput)) {
            $tg->sendMessage($userId, "❌ فرمت توکن ارسال شده نامعتبر است. لطفاً توکن معتبر ارسال کنید یا دکمه لغو را فشار دهید.");
            exit;
        }

        // استعلام صحت توکن از API تلگرام با کلاس موقت تلگرام
        $tempTg = new Telegram($tokenInput);
        $meResult = $tempTg->getMe();

        if ($meResult && isset($meResult['ok']) && $meResult['ok'] === true) {
            $botUsername = $meResult['result']['username'];
            $botName     = $meResult['result']['first_name'];

            // تشخیص پویای دامنه فعال سرور روی رندر جهت ست کردن وب‌هوک
            $host = $_SERVER['HTTP_HOST'] ?? '';
            if (empty($host)) {
                $tg->sendMessage($userId, "❌ خطای سیستمی در تشخیص دامنه رندر رخ داده است. لطفاً مراتب را با ادمین در میان بگذارید.");
                exit;
            }

            // ساخت آدرس داینامیک وب‌هوک منطبق با معماری چندمستاجری ما
            $webhookUrl = "https://{$host}/index.php?bot_token=" . urlencode($tokenInput);

            // ست کردن وب‌هوک در سرور تلگرام
            $webhookResult = $tempTg->setWebhook($webhookUrl);

            if ($webhookResult && isset($webhookResult['ok']) && $webhookResult['ok'] === true) {
                // ثبت یا آپدیت ربات در دیتابیس نئون
                $stmt = $db->prepare("
                    INSERT INTO bots (token, owner_id, bot_name) 
                    VALUES (:token, :owner_id, :bot_name)
                    ON CONFLICT (token) DO UPDATE 
                    SET owner_id = EXCLUDED.owner_id, bot_name = EXCLUDED.bot_name
                    RETURNING id
                ");
                $stmt->execute([
                    'token'    => $tokenInput,
                    'owner_id' => $userId,
                    'bot_name' => '@' . $botUsername
                ]);
                $botRow = $stmt->fetch();
                $newBotId = (int)$botRow['id'];

                // مقداردهی اولیه تنظیمات و نرخ‌های حقوق برای ربات جدید
                $stmtSettings = $db->prepare("
                    INSERT INTO settings (bot_id, key, value) VALUES 
                    (:bot_id, 'rate_translator', '10000'),
                    (:bot_id, 'rate_cleaner', '8000'),
                    (:bot_id, 'rate_typesetter', '8000'),
                    (:bot_id, 'rules', 'تست‌ها باید با کیفیت و بدون واترمارک باشند.')
                    ON CONFLICT (bot_id, key) DO NOTHING
                ");
                $stmtSettings->execute(['bot_id' => $newBotId]);

                // ثبت سازنده به عنوان مالک (owner) در لیست کاربران ربات جدید
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

                // ریست وضعیت کاربر به حالت عادی
                FSM::clearStep(0, $userId);

                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '🚀 ورود به ربات مانهوا', 'url' => "https://t.me/{$botUsername}"]],
                        [['text' => '🔙 بازگشت به منوی ربات‌ساز', 'callback_data' => 'master_cancel']]
                    ]
                ];

                $tg->sendMessage($userId, "🎉 <b>تبریک می‌گویم! ربات اختصاصی شما ساخته شد.</b>\n\n🤖 آیدی ربات: @{$botUsername}\n⚙️ نام نمایشی: {$botName}\n\n👇 وارد ربات خود شوید و دکمه <code>/start</code> را بفرستید تا کنترل پنل کامل ادمین تیم مانهوا برایتان باز شود.", $keyboard);
            } else {
                $tg->sendMessage($userId, "❌ تلگرام درخواست ست کردن وب‌هوک را رد کرد. احتمالاً آی‌پی یا پروتکل دچار تداخل شده است. مجدداً تلاش کنید.");
            }
        } else {
            $tg->sendMessage($userId, "❌ <b>توکن نامعتبر است!</b>\n\nتوکن ارسالی توسط تلگرام تایید نشد. لطفاً مجدداً بررسی کنید که توکن را به طور صحیح کپی کرده باشید.");
        }
        exit;
    }
}
