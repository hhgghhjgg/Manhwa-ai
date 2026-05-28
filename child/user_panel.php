<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: child/user_panel.php
 * Role: Full Member & Guest Dashboard Processor (Recruitment, Support Tickets, Practice Exams, Cancel System, Bot Logger)
 */

// ۱. اطمینان از صحت کانتکست و متغیرهای تعریف شده در index.php و child/router.php
if (!isset($botContext) || !isset($tg) || !isset($user) || !isset($db)) {
    exit;
}

$userId    = $user['tg_id'];
$fullName  = $user['full_name'];
$username  = $user['username'] ?? '';
$status    = $user['status'];
$role      = $user['role'];
$step      = $user['step'];
$botId     = $botContext['bot_id'];

// استخراج متن پیام کابر
$message = $botContext['update']['message'] ?? null;
$text    = $message['text'] ?? '';

// ==========================================
// فاز ۰: سیستم لاگ نویسی اختصاصی ربات (Bot Logging Utility)
// این تابع تمام رفتارها، موفقیت‌ها و خطاهای تلگرام/دیتابیس را ثبت می‌کند.
// ==========================================
if (!function_exists('botLog')) {
    /**
     * ثبت لاگ‌های رندر با ساختار مرتب و قابل فیلتر
     */
    function botLog($botId, $userId, $level, $logMessage, $context = []) {
        $formattedMessage = sprintf(
            "[BOT_USER_PANEL] [Bot:%d] [User:%s] [%s] %s %s",
            $botId,
            $userId,
            strtoupper($level),
            $logMessage,
            !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : ""
        );
        error_log($formattedMessage);
    }
}

// تابع کمکی جهت معادل‌سازی فارسی نقش‌های تیم مانهوا
if (!function_exists('getRoleFarsi')) {
    function getRoleFarsi($roleName) {
        $roles = [
            'translator' => 'مترجم',
            'cleaner'    => 'کلینر',
            'typesetter' => 'تایپیست',
            'admin'      => 'ادمین',
            'owner'      => 'مالک و ادمین کل',
            'none'       => 'مهمان (داوطلب عضویت)'
        ];
        return $roles[$roleName] ?? 'نامشخص';
    }
}

// ==========================================
// فاز ۱: سیستم لغو عمومی هوشمند (Cancel Process)
// ==========================================
if ($text === '/cancel' || (isset($callbackQuery) && $callbackQuery['data'] === 'user_cancel')) {
    botLog($botId, $userId, 'info', 'User triggered cancel action.', ['previous_step' => $step]);
    
    FSM::clearStep($botId, $userId);
    
    if (isset($callbackQuery)) {
        $tg->answerCallbackQuery($callbackId, "عملیات لغو شد.");
    }

    if ($status === 'approved') {
        // بازگرداندن اعضای رسمی به پنل اصلی
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💰 میزان حقوق من', 'callback_data' => 'member_salary'],
                    ['text' => '📚 کارها و پروژه‌ها', 'callback_data' => 'member_tasks']
                ],
                [
                    ['text' => '🏆 آزمون‌های تمرینی', 'callback_data' => 'user_practice_exams'],
                    ['text' => '✉️ تیکت پشتیبانی ادمین', 'callback_data' => 'user_open_ticket']
                ]
            ]
        ];
        
        $roleFarsi = getRoleFarsi($role);
        $tg->sendMessage($userId, "❌ <b>عملیات لغو شد.</b>\n\n👋 منوی اصلی اعضا (نقش شما: {$roleFarsi}):\nلطفاً یکی از گزینه‌های پنل شیشه‌ای زیر را انتخاب کنید:", $keyboard);
    } else {
        // بازگرداندن کاربران مهمان به صفحه ورود استخدام
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🤝 عضویت در تیم مانهوا مانپین', 'callback_data' => 'join_team']]
            ]
        ];
        
        $tg->sendMessage($userId, "❌ <b>عملیات لغو شد.</b>\n\n👋 به منوی اصلی خوش آمدید. برای شروع عضویت دکمه زیر را فشار دهید:", $keyboard);
    }
    exit;
}

// ==========================================
// فاز ۲: پردازش وضعیت‌های ورودی متنی FSM کاربر (دریافت فایل یا تیکت)
// ==========================================

// الف) کاربر در حال آپلود فایل حل شده تست استخدام است
if (strpos($step, 'waiting_test_') === 0) {
    $testRole = str_replace('waiting_test_', '', $step);

    $fileId = null;
    if (isset($message['document'])) {
        $fileId = $message['document']['file_id'];
    } elseif (isset($message['photo'])) {
        $fileId = end($message['photo'])['file_id'];
    }

    if (!$fileId) {
        botLog($botId, $userId, 'warning', 'User uploaded invalid file type for test submission.', ['step' => $step]);
        $tg->sendMessage($userId, "❌ <b>فایل نامعتبر است!</b>\n\nلطفاً فایل حل شده تست خود را فقط به صورت سند (Document) یا تصویر بفرستید:\n\n💡 جهت لغو، دستور <code>/cancel</code> را بفرستید.", [
            'inline_keyboard' => [[['text' => '❌ لغو و بازگشت', 'callback_data' => 'user_cancel']]]
        ]);
        exit;
    }

    botLog($botId, $userId, 'info', 'User uploaded solved recruitment test.', ['role' => $testRole, 'file_id' => $fileId]);

    try {
        // ثبت اطلاعات تست ارسالی در جدول تست‌های حل شده نئون دیتابیس
        $stmt = $db->prepare("
            INSERT INTO submitted_tests (bot_id, user_id, role, file_id, status)
            VALUES (:bot_id, :user_id, :role, :file_id, 'pending')
        ");
        $stmt->execute([
            'bot_id'  => $botId,
            'user_id' => $userId,
            'role'    => $testRole,
            'file_id' => $fileId
        ]);

        FSM::setStatus($botId, $userId, 'pending_test');
        FSM::clearStep($botId, $userId);

        $tg->sendMessage($userId, "✅ <b>تست شما با موفقیت به بخش عضو گیری تیم فرستاده شد.</b>\n\nپس از بررسی و تایید آن توسط ادمین‌های محترم تیم، لینک دعوت یک‌بار مصرف ورود به گروه اختصاصی کار برای شما ارسال خواهد شد. لطفاً منتظر بمانید.");
        botLog($botId, $userId, 'info', 'Solved recruitment test successfully recorded in database.');
    } catch (Exception $e) {
        botLog($botId, $userId, 'error', 'Database insertion failed during recruitment test submission.', ['error' => $e->getMessage()]);
        $tg->sendMessage($userId, "❌ خطای دیتابیس در ثبت تست حل شده. لطفاً مجدداً تلاش کنید.");
        exit;
    }

    // اطلاع‌رسانی خودکار به مالکین و ادمین‌های این ربات
    $stmtAdmins = $db->prepare("SELECT tg_id FROM users WHERE bot_id = :bot_id AND (role = 'admin' || role = 'owner')");
    $stmtAdmins->execute(['bot_id' => $botId]);
    $adminsList = $stmtAdmins->fetchAll();

    $roleFarsiName = getRoleFarsi($testRole);
    $adminNotifyText = "📥 <b>یک پاسخ تست حل شده جدید ثبت شد!</b>\n\n👤 کاربر: {$fullName} (@{$username})\n⚔️ نقش داوطلبی: {$roleFarsiName}\n\n👉 جهت مشاهده و بررسی فایل تست به پنل خود بخش [مدیریت عضوگیری -> آخرین تست‌ها] مراجعه کنید.";

    foreach ($adminsList as $admin) {
        $tg->sendMessage($admin['tg_id'], $adminNotifyText);
    }
    exit;
}

// ب) کاربر در حال نوشتن و ارسال متن تیکت پشتیبانی است
elseif (strpos($step, 'user_typing_ticket_') === 0) {
    $targetAdminPart = str_replace('user_typing_ticket_', '', $step);
    $assignedAdminId = ($targetAdminPart === 'null') ? null : (int)$targetAdminPart;

    if (empty($text)) {
        botLog($botId, $userId, 'warning', 'User attempted to submit an empty support ticket.', ['step' => $step]);
        $tg->sendMessage($userId, "❌ تیکت شما نمی‌تواند خالی باشد. لطفاً موضوع یا متن مشکل خود را تایپ کرده و بفرستید:\n\n💡 جهت لغو، دستور <code>/cancel</code> را بفرستید.", [
            'inline_keyboard' => [[['text' => '❌ لغو و بازگشت', 'callback_data' => 'user_cancel']]]
        ]);
        exit;
    }

    botLog($botId, $userId, 'info', 'User submitting support ticket message.', ['assigned_admin' => $assignedAdminId, 'text_snippet' => substr($text, 0, 30)]);

    try {
        // ثبت تیکت پشتیبانی در دیتابیس نئون
        $stmtTicket = $db->prepare("
            INSERT INTO tickets (bot_id, user_id, assigned_admin_id, subject)
            VALUES (:bot_id, :user_id, :assigned_admin_id, :subject)
            RETURNING id
        ");
        $stmtTicket->execute([
            'bot_id'            => $botId,
            'user_id'           => $userId,
            'assigned_admin_id' => $assignedAdminId,
            'subject'           => $text
        ]);
        $newTicketId = $stmtTicket->fetch()['id'];

        FSM::clearStep($botId, $userId);
        $tg->sendMessage($userId, "✅ <b>تیکت پشتیبانی شما با موفقیت ثبت شد و به بخش مدیریت ارسال گردید.</b>\n\n📌 شماره تیکت شما: <code>#{$newTicketId}</code>\n\nبه محض بررسی تیکت توسط ادمین مربوطه، پاسخ آن در پی‌وی شما فرستاده خواهد شد.");
        botLog($botId, $userId, 'info', 'Support ticket recorded in database successfully.', ['ticket_id' => $newTicketId]);
    } catch (Exception $e) {
        botLog($botId, $userId, 'error', 'Database insertion failed during support ticket submission.', ['error' => $e->getMessage()]);
        $tg->sendMessage($userId, "❌ خطای سیستمی در ثبت تیکت رخ داد. لطفاً بعداً تلاش فرمایید.");
        exit;
    }

    // اطلاع‌رسانی به ادمین هدف یا کلیه ادمین‌ها
    $notifyAdmins = [];
    if ($assignedAdminId) {
        $notifyAdmins[] = $assignedAdminId;
    } else {
        $stmtAll = $db->prepare("SELECT tg_id FROM users WHERE bot_id = :bot_id AND (role = 'admin' || role = 'owner')");
        $stmtAll->execute(['bot_id' => $botId]);
        $allAdmins = $stmtAll->fetchAll();
        foreach ($allAdmins as $ad) {
            $notifyAdmins[] = $ad['tg_id'];
        }
    }

    $adminAlert = "✉️ <b>تیکت پشتیبانی جدید دریافت شد! (#{$newTicketId})</b>\n\n"
                . "👤 فرستنده: {$fullName} (@{$username})\n\n"
                . "📝 متن تیکت:\n<i>{$text}</i>\n\n"
                . "👉 ادمین گرامی، جهت پاسخ‌دهی به تیکت می‌توانید روی دکمه شیشه‌ای پاسخ زیر کلیک کنید:";

    $keyboard = [
        'inline_keyboard' => [
            [['text' => '💬 پاسخ به تیکت', 'callback_data' => "admin_reply_ticket_{$newTicketId}"]]
        ]
    ];

    foreach ($notifyAdmins as $admId) {
        $tg->sendMessage($admId, $adminAlert, $keyboard);
    }
    exit;
}

// ج) کاربر در حال آپلود پاسخ یک آزمون تمرینی/دلخواه است
elseif (strpos($step, 'user_waiting_exam_solve_') === 0) {
    $examId = (int)str_replace('user_waiting_exam_solve_', '', $step);

    $fileId = null;
    if (isset($message['document'])) {
        $fileId = $message['document']['file_id'];
    } elseif (isset($message['photo'])) {
        $fileId = end($message['photo'])['file_id'];
    }

    if (!$fileId) {
        botLog($botId, $userId, 'warning', 'User uploaded invalid file type for practice exam solve.', ['exam_id' => $examId]);
        $tg->sendMessage($userId, "❌ <b>فایل نامعتبر است!</b>\n\nلطفاً پاسخ آزمون تمرینی خود را فقط به صورت سند (Document) یا تصویر بفرستید:\n\n💡 جهت لغو، دستور <code>/cancel</code> را بفرستید.", [
            'inline_keyboard' => [[['text' => '❌ لغو و بازگشت', 'callback_data' => 'user_cancel']]]
        ]);
        exit;
    }

    botLog($botId, $userId, 'info', 'User uploaded practice exam solution.', ['exam_id' => $examId, 'file_id' => $fileId]);

    // واکشی اطلاعات آزمون تمرینی برای تگ کردن اسم آزمون
    $stmtE = $db->prepare("SELECT title, role FROM practice_exams WHERE bot_id = :bot_id AND id = :id LIMIT 1");
    $stmtE->execute(['bot_id' => $botId, 'id' => $examId]);
    $exam = $stmtE->fetch();
    $examTitle = $exam ? $exam['title'] : 'آزمون تمرینی';

    FSM::clearStep($botId, $userId);
    $tg->sendMessage($userId, "✅ <b>پاسخ آزمون حل شده تمرینی شما با موفقیت برای ادمین‌های ارشد ارسال شد. خسته نباشید!</b>");

    // هدایت خودکار فایل آزمون حل شده به ادمین‌های ربات جهت بررسی و منتورینگ
    $stmtAdmins = $db->prepare("SELECT tg_id FROM users WHERE bot_id = :bot_id AND (role = 'admin' || role = 'owner')");
    $stmtAdmins->execute(['bot_id' => $botId]);
    $adminsList = $stmtAdmins->fetchAll();

    $roleName = getRoleFarsi($exam['role'] ?? 'none');
    $adminAlert = "🏆 <b>یک پاسخ آزمون تمرینی جدید دریافت شد!</b>\n\n"
                . "👤 داوطلب تمرین: {$fullName} (@{$username})\n"
                . "📚 نام آزمون تمرینی: <b>«{$examTitle}»</b>\n"
                . "⚔️ نقش مرتبط: <b>{$roleName}</b>\n\n"
                . "👇 فایل پاسخ حل شده در زیر ضمیمه شده است:";

    foreach ($adminsList as $admin) {
        $tg->sendDocument($admin['tg_id'], $fileId, $adminAlert);
    }
    exit;
}

// ==========================================
// فاز ۳: پردازش دستورات متنی (دریافت پیام متنی معمولی)
// ==========================================
if ($message) {
    if ($text === '/start') {
        botLog($botId, $userId, 'info', 'User triggered /start command.', ['membership_status' => $status]);
        FSM::clearStep($botId, $userId);

        if ($status === 'approved') {
            // نمایش کنترل پنل کامل شیشه‌ای برای اعضای تایید شده
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '💰 میزان حقوق من', 'callback_data' => 'member_salary'],
                        ['text' => '📚 کارها و پروژه‌ها', 'callback_data' => 'member_tasks']
                    ],
                    [
                        ['text' => '🏆 آزمون‌های تمرینی', 'callback_data' => 'user_practice_exams'],
                        ['text' => '✉️ تیکت پشتیبانی ادمین', 'callback_data' => 'user_open_ticket']
                    ]
                ]
            ];
            
            $roleFarsi = getRoleFarsi($role);
            $tg->sendMessage($userId, "👋 سلام <b>{$fullName}</b> عزیز، خوش آمدید.\n\nنقش شما در تیم: <b>{$roleFarsi}</b>\n\nلطفاً یکی از گزینه‌های پنل شیشه‌ای زیر را انتخاب کنید:", $keyboard);
        } else {
            // نمایش صفحه ورود و تست برای داوطلبان جدید استخدام
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🤝 عضویت در تیم مانهوا مانپین', 'callback_data' => 'join_team']]
                ]
            ];
            
            $tg->sendMessage($userId, "👋 سلام <b>{$fullName}</b> گرامی!\n\nبه ربات رسمی مدیریت تیم مانهوا خوش آمدید.\n\nآیا مایل هستید جهت ترجمه، تایپ یا کلین مانهواها به تیم ما بپیوندید؟ لطفاً دکمه زیر را لمس کنید:", $keyboard);
        }
        exit;
    }
}

// ==========================================
// فاز ۴: پردازش کلیک روی دکمه‌های شیشه‌ای (Callback Queries)
// ==========================================
if ($callbackQuery) {
    $callbackData = $callbackQuery['data'];
    $callbackId   = $callbackQuery['id'];
    $messageId    = $callbackQuery['message']['message_id'];

    $tg->answerCallbackQuery($callbackId);
    botLog($botId, $userId, 'info', 'User clicked inline button.', ['callback_data' => $callbackData]);

    // ۱. درخواست شروع استخدام داوطلب جدید
    if ($callbackData === 'join_team') {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🖌 کلینر (Cleaner)', 'callback_data' => 'user_role_cleaner'],
                    ['text' => '📝 مترجم (Translator)', 'callback_data' => 'user_role_translator']
                ],
                [
                    ['text' => '⌨️ تایپیست (Typesetter)', 'callback_data' => 'user_role_typesetter']
                ]
            ]
        ];
        
        $tg->sendMessage($userId, "لطفاً حوزه فعالیت مورد نظر خود را جهت عضویت و ارسال تست انتخاب کنید:", $keyboard);
        exit;
    }

    // ۲. انتخاب حوزه فعالیت توسط داوطلب
    elseif (strpos($callbackData, 'user_role_') === 0) {
        $selectedRole = str_replace('user_role_', '', $callbackData);
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📥 دریافت فایل تست', 'callback_data' => "get_test_{$selectedRole}"]]
            ]
        ];
        
        $roleFarsi = getRoleFarsi($selectedRole);
        $tg->sendMessage($userId, "شما حوزه <b>{$roleFarsi}</b> را انتخاب کردید.\n\nبرای شروع فرآیند استخدام و دانلود فایل تست، لطفاً دکمه زیر را فشار دهید:", $keyboard);
        exit;
    }

    // ۳. ارسال فایل تست خام استخدامی (به صورت کاملاً ایمن و مجهز به تفکیک‌کننده پویا)
    elseif (strpos($callbackData, 'get_test_') === 0) {
        $testRole = str_replace('get_test_', '', $callbackData);

        // واکشی فایل‌آیدی سالم و دستورالعمل از دیتابیس نئون
        $stmt = $db->prepare("SELECT file_id, instructions FROM test_templates WHERE bot_id = :bot_id AND role = :role LIMIT 1");
        $stmt->execute([
            'bot_id' => $botId,
            'role'   => $testRole
        ]);
        $template = $stmt->fetch();

        if (!$template || empty($template['file_id'])) {
            botLog($botId, $userId, 'warning', 'No recruitment test file found in database for role.', ['role' => $testRole]);
            $tg->sendMessage($userId, "⚠️ متاسفانه در حال حاضر تستی برای این نقش توسط ادمین‌های ربات آپلود نشده است. لطفاً بعداً تلاش کرده یا با ادمین در ارتباط باشید.");
            exit;
        }

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📤 فرستادن تست حل شده', 'callback_data' => "prepare_submit_{$testRole}"]]
            ]
        ];

        $captionText = "📖 فایل تست شما آماده دانلود است.\n\n";
        if (!empty($template['instructions'])) {
            $captionText .= "⚠️ <b>دستورالعمل تست:</b>\n<i>{$template['instructions']}</i>\n\n";
        }
        $captionText .= "لطفاً فایل را دانلود و حل کنید. پس از پایان کار، دکمه زیر را فشار داده و نسخه نهایی را بفرستید:";

        // تفکیک‌پذیری پویا بر اساس پیشوند ذخیره شده
        $rawFileId = $template['file_id'];
        $fileType  = 'doc'; // پیش‌فرض
        $cleanFileId = $rawFileId;

        if (strpos($rawFileId, ':') !== false) {
            $parts = explode(':', $rawFileId, 2);
            $fileType    = $parts[0];
            $cleanFileId = $parts[1];
        }

        botLog($botId, $userId, 'info', 'Sending recruitment test file with dynamic dispatcher.', ['file_id' => $cleanFileId, 'type' => $fileType]);

        // ارسال فایل با متد منطبق با نوع آن به تلگرام
        if ($fileType === 'photo') {
            $sent = $tg->sendPhoto($userId, $cleanFileId, $captionText, $keyboard);
        } else {
            $sent = $tg->sendDocument($userId, $cleanFileId, $captionText, $keyboard);
        }

        if (!$sent || !isset($sent['ok']) || !$sent['ok']) {
            botLog($botId, $userId, 'error', 'Telegram API failed to dispatch recruitment test file.', ['telegram_response' => $sent]);
        }
        exit;
    }

    // ۴. تغییر مرحله FSM جهت دریافت تست حل شده داوطلب
    elseif (strpos($callbackData, 'prepare_submit_') === 0) {
        $testRole = str_replace('prepare_submit_', '', $callbackData);

        FSM::setStep($botId, $userId, "waiting_test_{$testRole}");
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ انصراف و لغو', 'callback_data' => 'user_cancel']]
            ]
        ];

        $tg->sendMessage($userId, "📥 <b>بستر دریافت فایل فعال شد.</b>\n\nلطفاً پاسخ تست حل شده خود را به صورت سند (Document) یا تصویر بفرستید:\n\n💡 جهت لغو عملیات دکمه زیر را بزنید یا دستور <code>/cancel</code> را ارسال کنید:", $keyboard);
        exit;
    }

    // ۵. دکمه میزان حقوق (مخصوص اعضای تایید شده)
    elseif ($callbackData === 'member_salary') {
        $earned  = number_format($user['total_earned'] ?? 0);
        $totalCh = (int)($user['total_chapters'] ?? 0);
        $monthCh = (int)($user['monthly_chapters'] ?? 0);

        $textSalary = "💰 <b>گزارش کیف پول و حقوق شما:</b>\n\n"
                    . "💸 کل حقوق ثبت شده شما: <code>{$earned}</code> تومان\n"
                    . "🔢 مجموع چپترهای انجام شده: <code>{$totalCh}</code> چپتر\n"
                    . "📅 چپترهای ثبت شده این ماه: <code>{$monthCh}</code> چپتر\n\n"
                    . "ℹ️ حقوق شما با تایید هر چپتر ارسالی در گروه‌های مانهوا توسط ادمین، بلافاصله آپدیت می‌شود.";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔙 بازگشت به منو', 'callback_data' => 'member_back_to_menu']]
            ]
        ];
        
        $tg->sendMessage($userId, $textSalary, $keyboard);
        exit;
    }

    // ۶. دکمه کارها و مانهواهای منتسب شده به کاربر (مخصوص اعضا)
    elseif ($callbackData === 'member_tasks') {
        $stmt = $db->prepare("
            SELECT m.id, m.title 
            FROM manhwas m
            JOIN team_assignments ta ON m.id = ta.manhwa_id
            WHERE ta.bot_id = :bot_id AND ta.user_id = :user_id
        ");
        $stmt->execute([
            'bot_id'  => $botId,
            'user_id' => $userId
        ]);
        $tasks = $stmt->fetchAll();

        if (empty($tasks)) {
            $tg->sendMessage($userId, "⚠️ شما در حال حاضر روی هیچ پروژه فعالی از مانهواها ست نشده‌اید.", [
                'inline_keyboard' => [
                    [['text' => '🔙 بازگشت', 'callback_data' => 'member_back_to_menu']]
                ]
            ]);
        } else {
            $textTasks = "📚 <b>لیست پروژه‌های در دست اقدام شما:</b>\n\nجهت مشاهده جزئیات، شناسنامه مانهوا و آخرین وضعیت، روی مانهوا کلیک کنید:";
            $buttons = [];
            foreach ($tasks as $task) {
                $buttons[] = [['text' => "📚 " . $task['title'], 'callback_data' => "view_task_" . $task['id']]];
            }
            $buttons[] = [['text' => '🔙 بازگشت', 'callback_data' => 'member_back_to_menu']];

            $tg->sendMessage($userId, $textTasks, ['inline_keyboard' => $buttons]);
        }
        exit;
    }

    // ۷. مشاهده شناسنامه و کاور مانهواهای انتسابی
    elseif (strpos($callbackData, 'view_task_') === 0) {
        $manhwaId = (int)str_replace('view_task_', '', $callbackData);

        $stmt = $db->prepare("SELECT * FROM manhwas WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute([
            'bot_id' => $botId,
            'id'     => $manhwaId
        ]);
        $manhwa = $stmt->fetch();

        if ($manhwa) {
            $caption = "📚 <b>شناسنامه پروژه: {$manhwa['title']}</b>\n\n"
                     . "🎭 ژانرها: {$manhwa['genres']}\n"
                     . "🔢 آخرین چپتر تایید شده: <code>{$manhwa['last_chapter']}</code>\n\n"
                     . "📝 خلاصه داستان:\n<i>{$manhwa['summary']}</i>";

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🔙 بازگشت به پروژه‌ها', 'callback_data' => 'member_tasks']]
                ]
            ];

            if (!empty($manhwa['cover_file_id'])) {
                $tg->sendPhoto($userId, $manhwa['cover_file_id'], $caption, $keyboard);
            } else {
                $tg->sendMessage($userId, $caption, $keyboard);
            }
        } else {
            $tg->sendMessage($userId, "❌ مانهوای انتخابی یافت نشد.");
        }
        exit;
    }

    // ۸. منوی دکمه شیشه‌ای شروع ارسال تیکت پشتیبانی
    elseif ($callbackData === 'user_open_ticket') {
        $stmtAdmins = $db->prepare("SELECT tg_id, full_name FROM users WHERE bot_id = :bot_id AND (role = 'admin' || role = 'owner') AND status = 'approved'");
        $stmtAdmins->execute(['bot_id' => $botId]);
        $admins = $stmtAdmins->fetchAll();

        $buttons = [];
        $buttons[] = [['text' => '👥 تیکت عمومی (به کل مدیریت تیم)', 'callback_data' => 'user_send_ticket_general']];
        
        foreach ($admins as $ad) {
            $buttons[] = [['text' => "👤 ادمین اختصاصی: {$ad['full_name']}", 'callback_data' => "user_send_ticket_to_{$ad['tg_id']}"]];
        }
        $buttons[] = [['text' => '🔙 بازگشت', 'callback_data' => 'member_back_to_menu']];

        $tg->sendMessage($userId, "✉️ <b>بخش تیکتینگ مانهوا:</b>\n\nمشخص کنید تمایل دارید تیکت شما به صورت عمومی ارسال شود یا به پی‌وی یک ادمین مشخص برود:", ['inline_keyboard' => $buttons]);
        exit;
    }

    // تغییر وضعیت FSM جهت دریافت تیکت پشتیبانی
    elseif (strpos($callbackData, 'user_send_ticket_') === 0) {
        $targetAdmin = str_replace('user_send_ticket_', '', $callbackData);
        $adminId = ($targetAdmin === 'general') ? 'null' : (int)str_replace('to_', '', $targetAdmin);

        FSM::setStep($botId, $userId, "user_typing_ticket_{$adminId}");

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ انصراف و لغو تیکت', 'callback_data' => 'user_cancel']]
            ]
        ];

        $tg->sendMessage($userId, "✍️ <b>لطفاً موضوع یا متن تیکت پشتیبانی خود را بنویسید و ارسال کنید:</b>\n\n💡 جهت لغو عملیات دکمه زیر را بزنید یا دستور <code>/cancel</code> را ارسال کنید:", $keyboard);
        exit;
    }

    // منوی آزمون‌های تمرینی/دلخواه
    elseif ($callbackData === 'user_practice_exams') {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📝 آزمون‌های مترجم', 'callback_data' => 'user_view_exams_translator'],
                    ['text' => '🖌 آزمون‌های کلینر', 'callback_data' => 'user_view_exams_cleaner']
                ],
                [
                    ['text' => '⌨️ آزمون‌های تایپیست', 'callback_data' => 'user_view_exams_typesetter']
                ],
                [['text' => '🔙 بازگشت به منو', 'callback_data' => 'member_back_to_menu']]
            ]
        ];
        $tg->sendMessage($userId, "🏆 <b>بخش آزمون‌های تمرینی و دلخواه مانهوا:</b>\n\nبرای دیدن آرشیو سوالات و سوال‌های تمرینی آپلود شده توسط منتورها، نقش خود را انتخاب کنید:", $keyboard);
        exit;
    }

    // نمایش آرشیو آزمون‌های تمرینی بر اساس نقش انتخابی
    elseif (strpos($callbackData, 'user_view_exams_') === 0) {
        $targetRole = str_replace('user_view_exams_', '', $callbackData);

        $stmt = $db->prepare("SELECT id, title FROM practice_exams WHERE bot_id = :bot_id AND role = :role ORDER BY id DESC");
        $stmt->execute(['bot_id' => $botId, 'role' => $targetRole]);
        $exams = $stmt->fetchAll();

        if (empty($exams)) {
            $tg->sendMessage($userId, "⚠️ در حال حاضر هیچ آزمون یا پروژه تمرینی برای نقش " . getRoleFarsi($targetRole) . " ثبت نشده است.", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'user_practice_exams']]]
            ]);
        } else {
            $textList = "🏆 <b>آزمون‌های تمرینی فعال برای " . getRoleFarsi($targetRole) . " :</b>\n\nجهت دریافت فایل آزمون و شروع کلیک کنید:";
            $buttons = [];
            foreach ($exams as $ex) {
                $buttons[] = [['text' => "📝 " . $ex['title'], 'callback_data' => "user_download_exam_{$ex['id']}"]];
            }
            $buttons[] = [['text' => '🔙 بازگشت', 'callback_data' => 'user_practice_exams']];

            $tg->sendMessage($userId, $textList, ['inline_keyboard' => $buttons]);
        }
        exit;
    }

    // دانلود آزمون تمرینی انتخابی با تفکیک‌پذیری پویا
    elseif (strpos($callbackData, 'user_download_exam_') === 0) {
        $examId = (int)str_replace('user_download_exam_', '', $callbackData);

        $stmt = $db->prepare("SELECT * FROM practice_exams WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute([
            'bot_id' => $botId,
            'id'     => $examId
        ]);
        $exam = $stmt->fetch();

        if ($exam) {
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '📤 ارسال آزمون حل شده', 'callback_data' => "user_submit_exam_{$exam['id']}"]],
                    [['text' => '🔙 بازگشت به لیست', 'callback_data' => 'user_practice_exams']]
                ]
            ];

            $caption = "🏆 <b>نام آزمون: «{$exam['title']}»</b>\n\n"
                     . "ℹ️ این آزمون به صورت دلخواه و جهت ارتقای کارایی شما در تیم مانهوا قرار گرفته است.\n\n"
                     . "پس از حل کردن سوال، دکمه زیر را فشار داده و نسخه نهایی را جهت ارزیابی منتورها بفرستید:";

            // تفکیک‌پذیری پویا بر اساس پیشوند برای آزمون‌های تمرینی
            $rawFileId   = $exam['file_id'];
            $fileType    = 'doc'; // پیش‌فرض
            $cleanFileId = $rawFileId;

            if (strpos($rawFileId, ':') !== false) {
                $parts = explode(':', $rawFileId, 2);
                $fileType    = $parts[0];
                $cleanFileId = $parts[1];
            }

            botLog($botId, $userId, 'info', 'Sending practice exam file with dynamic dispatcher.', ['file_id' => $cleanFileId, 'type' => $fileType]);

            // هدایت متد بر اساس پیشوند
            if ($fileType === 'photo') {
                $sent = $tg->sendPhoto($userId, $cleanFileId, $caption, $keyboard);
            } else {
                $sent = $tg->sendDocument($userId, $cleanFileId, $caption, $keyboard);
            }

            if (!$sent || !isset($sent['ok']) || !$sent['ok']) {
                botLog($botId, $userId, 'error', 'Telegram API failed to dispatch practice exam file.', ['telegram_response' => $sent]);
            }
        } else {
            $tg->sendMessage($userId, "❌ آزمون یافت نشد.");
        }
        exit;
    }

    // ورود به مرحله FSM دریافت فایل آزمون تمرینی حل شده
    elseif (strpos($callbackData, 'user_submit_exam_') === 0) {
        $examId = (int)str_replace('user_submit_exam_', '', $callbackData);

        FSM::setStep($botId, $userId, "user_waiting_exam_solve_{$examId}");

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ انصراف و لغو', 'callback_data' => 'user_cancel']]
            ]
        ];

        $tg->sendMessage($userId, "📥 <b>بستر دریافت آزمون تمرینی حل شده فعال شد.</b>\n\nلطفاً پاسخ آزمون را به صورت سند (Document) یا تصویر معمولی ارسال فرمایید:\n\n💡 جهت لغو عملیات دکمه زیر را بزنید یا دستور <code>/cancel</code> را ارسال کنید:", $keyboard);
        exit;
    }

    // بازگشت به منوی اصلی اعضا
    elseif ($callbackData === 'member_back_to_menu') {
        FSM::clearStep($botId, $userId);
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💰 میزان حقوق من', 'callback_data' => 'member_salary'],
                    ['text' => '📚 کارها و پروژه‌ها', 'callback_data' => 'member_tasks']
                ],
                [
                    ['text' => '🏆 آزمون‌های تمرینی', 'callback_data' => 'user_practice_exams'],
                    ['text' => '✉️ تیکت پشتیبانی ادمین', 'callback_data' => 'user_open_ticket']
                ]
            ]
        ];
        
        $tg->sendMessage($userId, "👋 منوی اعضا\n\nلطفاً گزینه مورد نظر خود را انتخاب کنید:", $keyboard);
        exit;
    }
}
