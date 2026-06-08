<?php
/**
 * Project: Arvan Create Bot Maker Platform
 * File: master/master_handler.php
 * Role: Master Bot Processor with Dynamic Webhook Set, Dual-Bot Creation & 22-Way Master Stats
 */

// ۱. بررسی کانتکست و جلوگیری از خطای دسترسی غیرمجاز به فایل ربات‌ساز اصلی
if (!isset($botContext) || !$botContext['is_master']) {
    exit;
}

$update = $botContext['update'];
$db = DB::connect();

// نمونه‌سازی هِلپر تلگرام با توکن ربات‌ساز اصلی (آروان کریت)
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

// بررسی دسترسی مالک کل سیستم آروان کریت
$isSystemOwner = ($userId === OWNER_ID);

// =========================================================
// فاز ۱: سیستم قفل جوین اجباری شیشه‌ای کانال مراجع توسعه (@arvan_dev)
// =========================================================
if (!function_exists('checkMasterJoin')) {
    /**
     * استعلام زنده وضعیت عضویت کاربر در کانال تلگرام توسعه‌دهندگان
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

// کالبک بررسی عضویت زنده کاربر
if ($callbackQuery && $callbackQuery['data'] === 'master_check_join') {
    $isJoined = checkMasterJoin($tg, $userId);
    
    if ($isJoined) {
        $tg->answerCallbackQuery($callbackId, "✅ عضویت شما تایید شد!", false);
        FSM::clearStep(0, $userId);
        
        // ساختار ترتیبی آرایه دکمه‌ها بدون وجود خطای نوع آرایه در تلگرام
        $keyboard = [
            [
                ['text' => '➕ ساخت ربات جدید', 'callback_data' => 'master_new_bot']
            ],
            [
                ['text' => '📋 لیست ربات‌های من', 'callback_data' => 'master_my_bots'],
                ['text' => '❓ راهنما و قوانین', 'callback_data' => 'master_help']
            ]
        ];

        if ($isSystemOwner) {
            $keyboard[] = [
                ['text' => '📊 آمار کل سیستم', 'callback_data' => 'master_owner_stats'],
                ['text' => '🌐 لیست کل ربات‌ها', 'callback_data' => 'master_owner_all_bots']
            ];
        }

        $welcomeText = "سلام <b>{$fullName}</b> گرامی!\n"
                     . "به پلتفرم ربات‌ساز بزرگ <b>آروان کریت (Arvan Create)</b> خوش آمدید.\n\n"
                     . "با این سیستم می‌توانید ربات‌های پیشرفته مانهوا تیمی و سوپر آپلودرهای همه‌کاره بسازید.\n\n"
                     . "👇 برای شروع کار یکی از گزینه‌های زیر را انتخاب کنید:";

        $adminChatId = $callbackQuery['message']['chat']['id'] ?? $userId;
        $messageId   = $callbackQuery['message']['message_id'] ?? null;

        // ویرایش مستقیم پیام قفل قبلی به منوی اصلی بدون ایجاد پیام اضافه
        if ($messageId) {
            $tg->editMessageText($adminChatId, $messageId, $welcomeText, ['inline_keyboard' => $keyboard]);
        } else {
            $tg->sendMessage($userId, $welcomeText, ['inline_keyboard' => $keyboard]);
        }
    } else {
        $tg->apiRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text'              => "⚠️ شما هنوز عضو کانال رسمی توسعه‌دهندگان نشده‌اید! لطفاً ابتدا عضو کانال @arvan_dev شوید.",
            'show_alert'        => true
        ]);
    }
    exit;
}

// بررسی عمومی عضویت برای ورود به ربات‌ساز
$isJoinedGlobal = checkMasterJoin($tg, $userId);
if (!$isJoinedGlobal) {
    $keyboard = [
        'inline_keyboard' => [
            [['text' => '📢 عضویت در کانال توسعه‌دهندگان (Arvan Dev)', 'url' => 'https://t.me/arvan_dev']],
            [['text' => '🔄 تایید و بررسی عضویت', 'callback_data' => 'master_check_join']]
        ]
    ];
    
    $lockText = "👋 سلام <b>{$fullName}</b> گرامی!\n"
              . "برای استفاده از ربات‌ساز آروان و دسترسی به تمام ابزارهای آن، لطفاً ابتدا در کانال رسمی مراجع توسعه‌دهندگان (@arvan_dev) عضو شوید.\n\n"
              . "👇 پس از عضویت، روی دکمه بررسی عضویت کلیک کنید تا ربات برای شما فعال شود:";
    
    $tg->sendMessage($userId, $lockText, $keyboard);
    exit;
}

// ---------------------------------------------------------
// بخش کمکی: ثبت، همگام‌سازی و ست کردن وب‌هوک ربات جدید در دیتابیس
// ---------------------------------------------------------
if (!function_exists('registerNewBotMaster')) {
    function registerNewBotMaster($db, $tg, $tokenInput, $userId, $username, $fullName, $botType) {
        try {
            $tempTg = new Telegram($tokenInput);
            $meResult = $tempTg->getMe();

            if ($meResult && isset($meResult['ok']) && $meResult['ok'] === true) {
                $botUsername = $meResult['result']['username'];
                $botName     = $meResult['result']['first_name'];

                // شناسایی خودکار دامنه رندرر سرور جهت ساخت لینک وب‌هوک زنده
                $host = $_SERVER['HTTP_HOST'] ?? '';
                if (empty($host)) {
                    $tg->sendMessage($userId, "❌ خطای سیستمی: دامنه سرور (HTTP_HOST) شناسایی نشد.");
                    return false;
                }

                $webhookUrl = "https://{$host}/index.php?bot_token=" . urlencode($tokenInput);
                $webhookResult = $tempTg->setWebhook($webhookUrl);

                if ($webhookResult && isset($webhookResult['ok']) && $webhookResult['ok'] === true) {
                    
                    // ثبت یا به روز رسانی ربات جدید در جدول bots
                    $stmt = $db->prepare("
                        INSERT INTO bots (token, owner_id, bot_name, bot_type, bot_content_type) 
                        VALUES (:token, :owner_id, :bot_name, :bot_type, :content_type)
                        ON CONFLICT (token) DO UPDATE 
                        SET owner_id = EXCLUDED.owner_id, bot_name = EXCLUDED.bot_name, bot_type = EXCLUDED.bot_type
                        RETURNING id
                    ");
                    
                    // به صورت پیش‌فرض نوع محتوای آپلودر تنظیم‌نشده (NULL) است تا سوال بنیادین لود شود
                    $stmt->execute([
                        'token'        => $tokenInput,
                        'owner_id'     => $userId,
                        'bot_name'     => '@' . $botUsername,
                        'bot_type'     => $botType,
                        'content_type' => $botType === 'uploader' ? null : 'manhwa'
                    ]);
                    $botRow = $stmt->fetch();
                    $newBotId = (int)$botRow['id'];

                    // مقداردهی فرعی دیتابیس بر اساس نوع ربات ساخته شده
                    if ($botType === 'uploader') {
                        // فعال‌سازی خودکار و بدون پسماند افزونه دیفالت برای ربات‌های آپلودر جدید
                        $stmtInsPlugin = $db->prepare("
                            INSERT INTO bot_installed_plugins (bot_id, plugin_slug, is_active) 
                            VALUES (:bot_id, 'default_plugin', TRUE) 
                            ON CONFLICT DO NOTHING
                        ");
                        $stmtInsPlugin->execute(['bot_id' => $newBotId]);
                    } else {
                        // مقداردهی تنظیمات برای ربات‌های مانهوا کاری معمولی
                        $stmtSettings = $db->prepare("
                            INSERT INTO settings (bot_id, key, value) VALUES 
                            (:bot_id, 'rate_translator', '10000'),
                            (:bot_id, 'rate_cleaner', '8000'),
                            (:bot_id, 'rate_typesetter', '8000'),
                            (:bot_id, 'rules', 'تست‌ها باید با کیفیت و بدون واترمارک باشند.')
                            ON CONFLICT (bot_id, key) DO NOTHING
                        ");
                        $stmtSettings->execute(['bot_id' => $newBotId]);
                    }

                    // ثبت سازنده به عنوان ادمین کل (owner) در دیتابیس ربات فرزند
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
                            [['text' => '🚀 ورود به ربات جدید', 'url' => "https://t.me/{$botUsername}"]],
                            [['text' => '🔙 بازگشت به ربات‌ساز', 'callback_data' => 'master_cancel']]
                        ]
                    ];

                    $typeLabel = $botType === 'uploader' ? "سوپر آپلودر همه‌کاره ماژولار" : "مدیریت تیم مانهوا کاری";
                    $tg->sendMessage($userId, "🎉 <b>تبریک می‌گویم! ربات جدید شما با موفقیت ساخته شد.</b>\n\n🤖 آیدی ربات: @{$botUsername}\n⚙️ نام ربات: {$botName}\n🏷️ نوع کاربری ربات: <b>{$typeLabel}</b>\n\nوارد ربات خود شوید و دستور <code>/start</code> را ارسال کنید تا پنل مدیریت متناظر برای شما لود شود.", $keyboard);
                    return true;
                } else {
                    $tg->sendMessage($userId, "❌ تلگرام تایید وب‌هوک را رد کرد. دلیل: <code>" . ($webhookResult['description'] ?? 'نامشخص') . "</code>");
                }
            } else {
                $tg->sendMessage($userId, "❌ <b>توکن نامعتبر است!</b> توکن ارسالی توسط سرور تلگرام تایید نشد.");
            }
        } catch (Exception $e) {
            $tg->sendMessage($userId, "❌ خطای سیستمی رخ داد: <code>" . $e->getMessage() . "</code>");
        }
        return false;
    }
}

// ==========================================
// فاز ۴: پردازش کالبک‌کوئری‌های ربات‌ساز اصلی (Callbacks)
// ==========================================
if ($callbackQuery) {
    $callbackData = $callbackQuery['data'];
    $callbackId   = $callbackQuery['id'];
    $messageId    = $callbackQuery['message']['message_id'];
    $adminChatId  = $callbackQuery['message']['chat']['id'];

    $tg->answerCallbackQuery($callbackId);

    // دکمه لغو و بازگشت به منوی ریشه ربات‌ساز
    if ($callbackData === 'master_cancel') {
        FSM::clearStep(0, $userId);
        
        $keyboard = [
            [
                ['text' => '➕ ساخت ربات جدید', 'callback_data' => 'master_new_bot']
            ],
            [
                ['text' => '📋 لیست ربات‌های من', 'callback_data' => 'master_my_bots'],
                ['text' => '❓ راهنما و قوانین', 'callback_data' => 'master_help']
            ]
        ];

        // نمایش دکمه‌های اختصاصی مالک کل سیستم به صورت منظم
        if ($isSystemOwner) {
            $keyboard[] = [
                ['text' => '📊 آمار کل سیستم', 'callback_data' => 'master_owner_stats'],
                ['text' => '🌐 لیست کل ربات‌ها', 'callback_data' => 'master_owner_all_bots']
            ];
        }
        
        $welcomeText = "سلام <b>{$fullName}</b> گرامی!\n"
                     . "به پلتفرم ربات‌ساز بزرگ <b>آروان کریت (Arvan Create)</b> خوش آمدید.\n\n"
                     . "با این سیستم می‌توانید ربات‌های تلگرامی پیشرفته با کاربردهای مختلف بسازید. در حال حاضر سیستم مجهز به قالب مدیریت تیم کاری، استخدام پرسنل و آپلود مانهوا و سوپر آپلودر است.\n\n"
                     . "👇 برای شروع کار یکی از گزینه‌های زیر را انتخاب کنید:";

        $tg->editMessageText($adminChatId, $messageId, $welcomeText, ['inline_keyboard' => $keyboard]);
        exit;
    }

    // منوی ساخت ربات جدید با تفکیک نوع مانهوا یا آپلودر
    elseif ($callbackData === 'master_new_bot') {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📚 ربات مدیریت تیم مانهوا کاری', 'callback_data' => 'master_select_type_team']],
                [['text' => '🚀 ربات سوپر آپلودر همه‌کاره ماژولار', 'callback_data' => 'master_select_type_uploader']],
                [['text' => '🔙 بازگشت به منو', 'callback_data' => 'master_cancel']]
            ]
        ];
        
        $textMenu = "➕ <b>انتخاب نوع ربات جهت ساخت:</b>\n\n"
                  . "لطفاً نوع ربات مورد نظر خود را جهت ساخت انتخاب کنید تا وارد مراحل پیکربندی شویم:";
                  
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

        $infoText = "📚 <b>ربات مدیریت تیم کاری مانهوا:</b>\n\n"
              . "این ربات مجهز به سیستم استخدام، محاسبه دستمزدها، گزارش ماهانه و پایش مانهواهای راکد است.\n\n"
              . "💡 <b>راهنمای دریافت توکن:</b>\n"
              . "۱. ابتدا وارد آیدی @BotFather در تلگرام شوید.\n"
              . "۲. دستور /newbot را بفرستید و یک نام و یوزرنیم برای ربات خود انتخاب کنید.\n"
              . "۳. توکن عددی ارسالی را کپی کرده و آماده داشته باشید.\n\n"
              . "👇 جهت شروع فرآیند ساخت و ارسال توکن، دکمه زیر را لمس کنید:";

        $tg->editMessageText($adminChatId, $messageId, $infoText, $keyboard);
        exit;
    }

    // کالبک انتخاب نوع ربات سوپر آپلودر
    elseif ($callbackData === 'master_select_type_uploader') {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📥 شروع ساخت و ارسال توکن', 'callback_data' => 'master_start_uploader_token']],
                [['text' => '🔙 بازگشت به لیست', 'callback_data' => 'master_new_bot']]
            ]
        ];

        $infoText = "🚀 <b>ربات سوپر آپلودر همه‌کاره ماژولار:</b>\n\n"
              . "این ربات مجهز به صفحه جستجوی پیشرفته، لیست‌های هوشمند، سیستم علاقه‌مندی‌ها و پروفایل دانلودر به صورت کاملاً پویا است.\n\n"
              . "👇 جهت شروع فرآیند ساخت و ارسال توکن، دکمه زیر را لمس کنید:";

        $tg->editMessageText($adminChatId, $messageId, $infoText, $keyboard);
        exit;
    }

    // کالبک شروع ارسال توکن - مانهوا کاری
    elseif ($callbackData === 'master_start_team_token') {
        FSM::setStep(0, $userId, 'waiting_for_token_team');
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ لغو عملیات', 'callback_data' => 'master_cancel']]
            ]
        ];

        $tg->sendMessage($userId, "📥 <b>بستر دریافت فعال شد.</b>\n\nلطفاً توکن مانهوا کاری خود را از @BotFather ارسال کنید تا فرآیند ساخت آغاز شود:", $keyboard);
        exit;
    }

    // کالبک شروع ارسال توکن - سوپر آپلودر
    elseif ($callbackData === 'master_start_uploader_token') {
        FSM::setStep(0, $userId, 'waiting_for_token_uploader');
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ لغو عملیات', 'callback_data' => 'master_cancel']]
            ]
        ];

        $tg->sendMessage($userId, "📥 <b>بستر دریافت فعال شد.</b>\n\nلطفاً توکن سوپر آپلودر خود را از @BotFather ارسال کنید تا فرآیند ساخت آغاز شود:", $keyboard);
        exit;
    }

    // کالبک لیست ربات‌های کاربر جاری
    elseif ($callbackData === 'master_my_bots') {
        $stmt = $db->prepare("SELECT bot_name, token, bot_type FROM bots WHERE owner_id = :owner_id ORDER BY id DESC");
        $stmt->execute(['owner_id' => $userId]);
        $myBots = $stmt->fetchAll();

        if (empty($myBots)) {
            $tg->sendMessage($userId, "⚠️ شما هنوز هیچ رباتی در سیستم آروان کریت نساخته‌اید.");
        } else {
            $textBots = "📋 <b>لیست ربات‌های فعال شما در پلتفرم آروان کریت:</b>\n\n";
            $buttons = [];
            foreach ($myBots as $bot) {
                $typeLabel = $bot['bot_type'] === 'uploader' ? "سوپر آپلودر" : "مدیریت تیم مانهوا";
                $textBots .= "🔹 <b>{$bot['bot_name']}</b> (نوع: {$typeLabel})\n";
                $buttons[] = [['text' => "🚀 ورود به {$bot['bot_name']}", 'url' => "https://t.me/" . str_replace('@', '', $bot['bot_name'])]];
            }
            $buttons[] = [['text' => '🔙 بازگشت به منو اصلی', 'callback_data' => 'master_cancel']];
            
            $tg->sendMessage($userId, $textBots, ['inline_keyboard' => $buttons]);
        }
        exit;
    }

    // کالبک راهنمای ساخت ربات
    elseif ($callbackData === 'master_help') {
        $helpText = "❓ <b>راهنمای پلتفرم ربات‌ساز آروان کریت:</b>\n\n"
                  . "این سیستم به شما اجازه می‌دهد ربات‌های تلگرامی پیشرفته با کاربردهای مختلف بسازید.\n\n"
                  . "۱. وارد آیدی @BotFather در تلگرام شوید.\n"
                  . "۲. دستور /newbot را ارسال کنید و نام و یوزرنیم ربات خود را بسازید.\n"
                  . "۳. توکن عددی ارسالی را کپی کرده و در بخش ساخت ربات جدید در پلتفرم ما آپلود کنید.\n"
                  . "۴. با زدن /start در ربات خود، کنترل پنل مربوطه را تحویل بگیرید.";

        $tg->sendMessage($userId, $helpText, ['inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'master_cancel']]]]);
        exit;
    }

    // کالبک اختصاصی مالک سیستم: نمایش لیست کل ربات‌های ثبت شده روی سرور نئون
    elseif ($callbackData === 'master_owner_all_bots' && $isSystemOwner) {
        $stmt = $db->prepare("SELECT bot_name, token, owner_id, bot_type FROM bots WHERE id > 0 ORDER BY id DESC");
        $stmt->execute();
        $allBots = $stmt->fetchAll();

        $textBots = "🌐 <b>لیست کل ربات‌های فعال ثبت شده در سرور مانهوا-آی‌آی:</b>\n\n";
        $buttons = [];
        foreach ($allBots as $bot) {
            $typeLabel = $bot['bot_type'] === 'uploader' ? "آپلودر" : "مانهوا";
            $textBots .= "🤖 <b>{$bot['bot_name']}</b> | نوع: {$typeLabel}\n└ مالک: <code>{$bot['owner_id']}</code>\n\n";
            $buttons[] = [['text' => "🚀 ورود به {$bot['bot_name']}", 'url' => "https://t.me/" . str_replace('@', '', $bot['bot_name'])]];
        }
        $buttons[] = [['text' => '🔙 بازگشت به منوی ادمین', 'callback_data' => 'master_cancel']];

        $tg->sendMessage($userId, $textBots, ['inline_keyboard' => $buttons]);
        exit;
    }

    // کالبک اختصاصی مالک سیستم: مانیتورینگ زنده و نمایش شاخص‌های آماری کل سرور به صورت تفکیک‌شده
    elseif ($callbackData === 'master_owner_stats' && $isSystemOwner) {
        try {
            // استخراج آمار زنده کلان کاربران
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

            // آمار مانهواها
            $stmtManhwaStats = $db->prepare("SELECT COUNT(*) as total_manhwas, COUNT(CASE WHEN group_id IS NOT NULL THEN 1 END) as connected_manhwas FROM manhwas;");
            $stmtManhwaStats->execute();
            $mStats = $stmtManhwaStats->fetch();

            // آمار چپترها و پرداخت‌های مالی
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

            // آمار تست‌ها، تیکت‌ها و ربات‌ها
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
                       . "└ کل ربات‌های ثبت شده روی دیتابیس نئون: <code>{$miscStats['total_bots']}</code> ربات\n\n"
                       . "👥 <b>بخش کاربران و اعضای رسمی ربات‌ها:</b>\n"
                       . "├ کل کاربران ثبت شده در ربات‌ها: <code>{$uStats['total_users']}</code> نفر\n"
                       . "├ تعداد مالکین ربات‌های مانهوا/آپلودر: <code>{$uStats['total_owners']}</code> نفر\n"
                       . "├ تعداد کل ادمین‌های منتسب شده: <code>{$uStats['total_admins']}</code> نفر\n"
                       . "├ مترجمین فعال تایید شده: <code>{$uStats['total_translators']}</code> نفر\n"
                       . "├ کلینرهای فعال تایید شده: <code>{$uStats['total_cleaners']}</code> نفر\n"
                       . "└ تایپیست‌های فعال تایید شده: <code>{$uStats['total_typesetters']}</code> نفر\n\n"
                       . "📚 <b>بخش مانهواها و پروژه‌ها:</b>\n"
                       . "├ کل پروژه‌های مانهوای ثبت شده: <code>{$mStats['total_manhwas']}</code> عدد\n"
                       . "└ مانهواهای فعال متصل به گروه کار: <code>{$mStats['connected_manhwas']}</code> عدد\n\n"
                       . "🔢 <b>بخش چپترها و ارسال کارها:</b>\n"
                       . "├ کل چپترهای تایید و ثبت شده: <code>{$cStats['approved_chapters']}</code> چپتر\n"
                       . "└ چپترهای در انتظار تایید مدیریت: <code>{$cStats['pending_chapters']}</code> چپتر\n\n"
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

            $tg->sendMessage($userId, $statsText, ['inline_keyboard' => [[['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'master_cancel']]]]);
        } catch (Exception $e) {
            $tg->sendMessage($userId, "❌ خطا در محاسبات زنده آماری سرور.");
        }
        exit;
    }
}

// ==========================================
// فاز ۵: پردازش پیام‌های متنی ارسالی به ربات‌ساز مادر (Text Commands)
// ==========================================
if (!empty($text)) {
    // دستور استارت ربات‌ساز اصلی
    if ($text === '/start') {
        FSM::clearStep(0, $userId);

        $keyboard = [
            [
                ['text' => '➕ ساخت ربات جدید', 'callback_data' => 'master_new_bot']
            ],
            [
                ['text' => '📋 لیست ربات‌های من', 'callback_data' => 'master_my_bots'],
                ['text' => '❓ راهنما و قوانین', 'callback_data' => 'master_help']
            ]
        ];

        $welcome = "سلام <b>{$fullName}</b> گرامی!\n"
                 . "به پلتفرم ربات‌ساز بزرگ <b>آروان کریت (Arvan Create)</b> خوش آمدید.\n\n"
                 . "با این سیستم می‌توانید ربات‌های تلگرامی پیشرفته با کاربردهای مختلف بسازید. در حال حاضر سیستم مجهز به قالب مدیریت تیم کاری، استخدام پرسنل و آپلود مانهوا و سوپر آپلودر است.\n\n"
                 . "👇 برای شروع کار یکی از گزینه‌های زیر را انتخاب کنید:";

        // نمایش گزینه‌های مانیتورینگ پیشرفته برای مالک کل سیستم به صورت کاملاً ترتیبی و پویا
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

    // الف) اگر کاربر در حال ارسال توکن ربات مانهوا کاری باشد
    if ($step === 'waiting_for_token_team') {
        $tokenInput = trim($text);

        if (!preg_match('/^[0-9]+:[a-zA-Z0-9_-]+$/', $tokenInput)) {
            $tg->sendMessage($userId, "❌ فرمت توکن ارسال شده نامعتبر است. لطفاً توکن معتبر ارسال کنید یا دکمه لغو را فشار دهید.");
            exit;
        }

        // بررسی عدم امکان تصاحب غیرمجاز ربات دیگران
        $stmtCheck = $db->prepare("SELECT owner_id FROM bots WHERE token = :token LIMIT 1");
        $stmtCheck->execute(['token' => $tokenInput]);
        $existingBot = $stmtCheck->fetch();

        if ($existingBot) {
            if ((int)$existingBot['owner_id'] !== $userId) {
                $tg->sendMessage($userId, "❌ <b>خطای امنیتی!</b> این ربات قبلاً توسط کاربر دیگری ثبت شده است و شما مالکیت آن را بر عهده ندارید.");
                exit;
            }
        }

        // ثبت ربات مانهوا کاری
        registerNewBotMaster($db, $tg, $tokenInput, $userId, $username, $fullName, 'team');
        exit;
    }

    // ب) اگر کاربر در حال ارسال توکن ربات سوپر آپلودر ماژولار باشد
    if ($step === 'waiting_for_token_uploader') {
        $tokenInput = trim($text);

        if (!preg_match('/^[0-9]+:[a-zA-Z0-9_-]+$/', $tokenInput)) {
            $tg->sendMessage($userId, "❌ فرمت توکن ارسال شده نامعتبر است. لطفاً توکن معتبر ارسال کنید یا دکمه لغو را فشار دهید.");
            exit;
        }

        // بررسی عدم امکان تصاحب غیرمجاز ربات دیگران
        $stmtCheck = $db->prepare("SELECT owner_id FROM bots WHERE token = :token LIMIT 1");
        $stmtCheck->execute(['token' => $tokenInput]);
        $existingBot = $stmtCheck->fetch();

        if ($existingBot) {
            if ((int)$existingBot['owner_id'] !== $userId) {
                $tg->sendMessage($userId, "❌ <b>خطای امنیتی!</b> این ربات قبلاً توسط کاربر دیگری ثبت شده است و شما مالکیت آن را بر عهده ندارید.");
                exit;
            }
        }

        // ثبت ربات سوپر آپلودر ماژولار
        registerNewBotMaster($db, $tg, $tokenInput, $userId, $username, $fullName, 'uploader');
        exit;
    }
}
