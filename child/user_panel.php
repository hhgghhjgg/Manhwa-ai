<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: child/user_panel.php
 * Role: Full Member & Guest Dashboard Processor with Working Hours Ticketing, FAQ, and Multi-Test Engine
 */

// ۱. اطمینان از صحت کانتکست و متغیرهای تعریف شده
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

// استخراج مشخصات پیام کاربر
$message       = $botContext['update']['message'] ?? null;
$callbackQuery = $botContext['update']['callback_query'] ?? null;
$callbackId    = $callbackQuery['id'] ?? null;
$text          = $message['text'] ?? '';

// ==========================================
// بخش ۰: سیستم لاگ نویسی اختصاصی ربات
// ==========================================
if (!function_exists('botLog')) {
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
        
        $parts = explode(',', $roleName);
        $translated = [];
        foreach ($parts as $p) {
            $p = trim($p);
            $translated[] = $roles[$p] ?? $p;
        }
        return implode(' + ', $translated);
    }
}

// تابع کمکی بررسی روز و ساعت کاری تیکتینگ
if (!function_exists('checkTicketWorkingTime')) {
    function checkTicketWorkingTime($db, $botId) {
        // ۱. بررسی وضعیت فعال یا بسته بودن کامل تیکتینگ
        $stmtStatus = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = 'ticket_active_status' LIMIT 1");
        $stmtStatus->execute(['bot_id' => $botId]);
        $rowStatus = $stmtStatus->fetch();
        $ticketStatus = $rowStatus ? $rowStatus['value'] : 'open';

        if ($ticketStatus === 'closed') {
            return [
                'allowed' => false,
                'reason'  => "❌ <b>بخش تیکت پشتیبانی موقتاً توسط مدیریت بسته شده است.</b>"
            ];
        }

        // ۲. بررسی روزهای کاری تعریف شده
        $stmtDays = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = 'ticket_working_days' LIMIT 1");
        $stmtDays->execute(['bot_id' => $botId]);
        $rowDays = $stmtDays->fetch();
        $workingDays = $rowDays ? $rowDays['value'] : null;

        if ($workingDays) {
            $daysOfWeekFarsi = [
                'Sunday'    => 'یکشنبه',
                'Monday'    => 'دوشنبه',
                'Tuesday'   => 'سه شنبه',
                'Wednesday' => 'چهارشنبه',
                'Thursday'  => 'پنجشنبه',
                'Friday'    => 'جمعه',
                'Saturday'  => 'شنبه'
            ];
            $currentDayFarsi = $daysOfWeekFarsi[date('l')];
            $daysArray = explode(',', $workingDays);
            
            $isWorkingDay = false;
            foreach ($daysArray as $day) {
                if (trim($day) === $currentDayFarsi) {
                    $isWorkingDay = true;
                    break;
                }
            }

            if (!$isWorkingDay) {
                return [
                    'allowed' => false,
                    'reason'  => "📅 <b>امروز روز کاری بخش پشتیبانی نیست!</b>\n\n🗓️ روزهای کاری فعال تیکتینگ: <code>{$workingDays}</code>"
                ];
            }
        }

        // ۳. بررسی ساعات کاری تعریف شده
        $stmtHours = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = 'ticket_working_hours' LIMIT 1");
        $stmtHours->execute(['bot_id' => $botId]);
        $rowHours = $stmtHours->fetch();
        $workingHours = $rowHours ? $rowHours['value'] : null;

        if ($workingHours && strpos($workingHours, '-') !== false) {
            $currentTime = date('H:i');
            list($startHour, $endHour) = explode('-', $workingHours);
            $startHour = trim($startHour);
            $endHour   = trim($endHour);

            if ($currentTime < $startHour || $currentTime > $endHour) {
                return [
                    'allowed' => false,
                    'reason'  => "⏳ <b>خارج از ساعات کاری پشتیبانی!</b>\n\n⏰ ساعت کاری فعلی: <code>{$workingHours}</code>\n💬 ساعت فعلی سرور: <code>{$currentTime}</code>"
                ];
            }
        }

        return ['allowed' => true];
    }
}

// ==========================================
// بخش ۱: سیستم لغو عمومی هوشمند (Cancel Process)
// ==========================================
if ($text === '/cancel' || $text === 'لغو' || (isset($callbackQuery) && $callbackQuery['data'] === 'user_cancel')) {
    botLog($botId, $userId, 'info', 'User triggered cancel action.', ['previous_step' => $step]);
    
    FSM::clearStep($botId, $userId);
    
    if (isset($callbackQuery) && $callbackId !== null) {
        $tg->answerCallbackQuery($callbackId, "عملیات لغو شد.");
    }

    if ($status === 'approved') {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💰 میزان حقوق من', 'callback_data' => 'member_salary'],
                    ['text' => '📚 کارها و پروژه‌ها', 'callback_data' => 'member_tasks']
                ],
                [
                    ['text' => '🏆 آزمون‌های تمرینی', 'callback_data' => 'user_practice_exams'],
                    ['text' => '✉️ تیکت پشتیبانی ادمین', 'callback_data' => 'usr_tickets_p_1']
                ],
                [
                    ['text' => '❓ سوالات متداول (FAQ)', 'callback_data' => 'user_sys_faq_list_1']
                ]
            ]
        ];

        // در صورت ادمین بودن و چندشغله بودن، دکمه سوئیچ قرار می‌گیرد
        $isAdmin = ($user['role'] === 'owner' || strpos($user['role'], 'admin') !== false);
        if ($isAdmin) {
            $keyboard[] = [['text' => '🛡️ پنل ادمین', 'callback_data' => 'admin_sys_mode_admin']];
        }
        
        $roleFarsi = getRoleFarsi($role);
        $tg->sendMessage($userId, "❌ <b>عملیات لغو شد.</b>\n\n👋 منوی اصلی اعضا (نقش شما: {$roleFarsi}):", $keyboard);
    } else {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🤝 عضویت در تیم مانهوا', 'callback_data' => 'join_team']]
            ]
        ];
        
        $tg->sendMessage($userId, "❌ <b>عملیات لغو شد.</b>\n\n👋 به منوی اصلی خوش آمدید. برای شروع عضویت دکمه زیر را فشار دهید:", $keyboard);
    }
    exit;
}

// ==========================================
// بخش ۲: پردازش وضعیت‌های ورودی متنی FSM کاربر (دریافت فایل یا تیکت)
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
        $tg->sendMessage($userId, "❌ <b>فایل نامعتبر است!</b>\n\nلطفاً فایل حل شده تست خود را فقط به صورت سند (Document) یا تصویر بفرستید:", [
            'inline_keyboard' => [[['text' => '❌ لغو و بازگشت', 'callback_data' => 'user_cancel']]]
        ]);
        exit;
    }

    botLog($botId, $userId, 'info', 'User uploaded solved recruitment test.', ['role' => $testRole, 'file_id' => $fileId]);

    try {
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

        $tg->sendMessage($userId, "✅ <b>تست شما با موفقیت به بخش عضوگیری تیم فرستاده شد.</b>\n\nپس از بررسی و تایید آن توسط مدیریت، لینک دعوت برای شما ارسال خواهد شد.");
    } catch (Exception $e) {
        botLog($botId, $userId, 'error', 'Database insertion failed.', ['error' => $e->getMessage()]);
        $tg->sendMessage($userId, "❌ خطای دیتابیس در ثبت تست حل شده. لطفاً مجدداً تلاش کنید.");
    }
    exit;
}

// ب) کاربر رسمی در حال آپلود فایل تست مجدد برای نقش جدید است
elseif (strpos($step, 'user_waiting_retest_') === 0) {
    $testRole = str_replace('user_waiting_retest_', '', $step);

    $fileId = null;
    if (isset($message['document'])) {
        $fileId = $message['document']['file_id'];
    } elseif (isset($message['photo'])) {
        $fileId = end($message['photo'])['file_id'];
    }

    if (!$fileId) {
        $tg->sendMessage($userId, "❌ <b>فایل نامعتبر است!</b>\n\nلطفاً فایل تست را به صورت سند یا تصویر ارسال کنید:");
        exit;
    }

    try {
        // ثبت در تست‌ها با وضعیت pending_retest
        $stmt = $db->prepare("
            INSERT INTO submitted_tests (bot_id, user_id, role, file_id, status)
            VALUES (:bot_id, :user_id, :role, :file_id, 'pending_retest')
        ");
        $stmt->execute([
            'bot_id'  => $botId,
            'user_id' => $userId,
            'role'    => $testRole,
            'file_id' => $fileId
        ]);

        FSM::clearStep($botId, $userId);
        $tg->sendMessage($userId, "✅ <b>پاسخ تست نقش دوم شما با موفقیت برای مدیریت فرستاده شد.</b>\n\nپس از ارزیابی، نقش جدید به پروفایل شما اضافه خواهد شد.");
    } catch (Exception $e) {
        $tg->sendMessage($userId, "❌ خطا در ثبت اطلاعات تست مجدد.");
    }
    exit;
}

// ج) کاربر در حال نوشتن متن تیکت پشتیبانی است
elseif (strpos($step, 'user_typing_ticket_') === 0) {
    $targetAdminPart = str_replace('user_typing_ticket_', '', $step);
    $assignedAdminId = ($targetAdminPart === 'null') ? null : (int)$targetAdminPart;

    if (empty($text)) {
        $tg->sendMessage($userId, "❌ تیکت شما نمی‌تواند خالی باشد. لطفاً متن تیکت را بنویسید:");
        exit;
    }

    // بررسی نهایی مجدد زمان‌بندی کاری قبل از ثبت نهایی پیام
    $timeCheck = checkTicketWorkingTime($db, $botId);
    if (!$timeCheck['allowed']) {
        FSM::clearStep($botId, $userId);
        $tg->sendMessage($userId, $timeCheck['reason']);
        exit;
    }

    try {
        $stmtTicket = $db->prepare("
            INSERT INTO tickets (bot_id, user_id, assigned_admin_id, subject, status)
            VALUES (:bot_id, :user_id, :assigned_admin_id, :subject, 'open')
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
        $tg->sendMessage($userId, "✅ <b>تیکت شما با موفقیت ثبت شد.</b>\n\n📌 شماره تیکت: <code>#{$newTicketId}</code>\nپاسخ آن به زودی در پی‌وی شما ارسال می‌شود.");
    } catch (Exception $e) {
        $tg->sendMessage($userId, "❌ خطا در ثبت تیکت رخ داد.");
    }
    exit;
}

// د) کاربر در حال آپلود پاسخ یک آزمون تمرینی است
elseif (strpos($step, 'user_waiting_exam_solve_') === 0) {
    $examId = (int)str_replace('user_waiting_exam_solve_', '', $step);

    $fileId = null;
    if (isset($message['document'])) {
        $fileId = $message['document']['file_id'];
    } elseif (isset($message['photo'])) {
        $fileId = end($message['photo'])['file_id'];
    }

    if (!$fileId) {
        $tg->sendMessage($userId, "❌ <b>فایل نامعتبر است!</b>\n\nلطفاً پاسخ آزمون را به صورت سند یا تصویر بفرستید:");
        exit;
    }

    $stmtE = $db->prepare("SELECT title FROM practice_exams WHERE bot_id = :bot_id AND id = :id LIMIT 1");
    $stmtE->execute(['bot_id' => $botId, 'id' => $examId]);
    $examTitle = $stmtE->fetch()['title'] ?? 'آزمون تمرینی';

    FSM::clearStep($botId, $userId);
    $tg->sendMessage($userId, "✅ <b>پاسخ آزمون حل شده تمرینی شما با موفقیت برای منتورها ارسال شد.</b>");

    // هدایت به ادمین‌های تیکتینگ ۲۲گانه (perm_exams_manage)
    $stmtAdmins = $db->prepare("
        SELECT u.tg_id 
        FROM users u
        LEFT JOIN admin_permissions ap ON u.bot_id = ap.bot_id AND u.tg_id = ap.user_id
        WHERE u.bot_id = :bot_id 
          AND u.status = 'approved'
          AND (u.role = 'owner' OR (u.role = 'admin' AND ap.perm_exams_manage = TRUE))
    ");
    $stmtAdmins->execute(['bot_id' => $botId]);
    $adminsList = $stmtAdmins->fetchAll();

    $adminAlert = "🏆 <b>پاسخ آزمون تمرینی جدید دریافت شد:</b>\n\n"
                . "👤 کاربر: {$fullName} (@{$username})\n"
                . "📚 آزمون: <b>«{$examTitle}»</b>\n\n"
                . "👇 فایل پاسخ پیوست شده است:";

    foreach ($adminsList as $admin) {
        $tg->sendDocument($admin['tg_id'], $fileId, $adminAlert);
    }
    exit;
}

// ==========================================
// بخش ۳: پردازش دستورات متنی کاربر
// ==========================================
if ($message && $text === '/start') {
    botLog($botId, $userId, 'info', 'User triggered /start command.', ['membership_status' => $status]);
    FSM::clearStep($botId, $userId);

    // بررسی اینکه آیا کاربر ورودی یک پارامتر دعوت هوشمند (invite_code) است
    $startParam = null;
    if (preg_match('/^\/start\s+invite_(.+)/', $message['text'], $matchInv)) {
        $startParam = trim($matchInv[1]);
    }

    if ($startParam) {
        // پایش و بررسی پویای لینک هوشمند استارت
        $stmtLink = $db->prepare("SELECT * FROM bot_invite_links WHERE bot_id = :bot_id AND code = :code LIMIT 1");
        $stmtLink->execute(['bot_id' => $botId, 'code' => $startParam]);
        $linkRow = $stmtLink->fetch();

        if (!$linkRow) {
            $tg->sendMessage($userId, "❌ <b>لینک عضوگیری نامعتبر است یا منقضی شده است!</b>");
            exit;
        }

        // بررسی زمان انقضای لینک
        if (strtotime($linkRow['expire_at']) < time()) {
            $tg->sendMessage($userId, "❌ <b>مهلت زمانی این لینک عضوگیری به پایان رسیده است!</b>");
            exit;
        }

        // بررسی محدودیت ظرفیت تعداد دفعات استفاده از لینک
        if ($linkRow['uses'] >= $linkRow['max_uses']) {
            $tg->sendMessage($userId, "❌ <b>ظرفیت پذیرش این لینک تکمیل شده است!</b>");
            exit;
        }

        // بررسی اینکه کاربر در حال حاضر تایید شده نباشد
        if ($status === 'approved') {
            $tg->sendMessage($userId, "⚠️ شما در حال حاضر عضو تایید شده تیم هستید و نیازی به استفاده از این لینک ندارید.");
            exit;
        }

        // افزایش تعداد دفعات استفاده از لینک
        $stmtInc = $db->prepare("UPDATE bot_invite_links SET uses = uses + 1 WHERE id = :id");
        $stmtInc->execute(['id' => $linkRow['id']]);

        if ($linkRow['is_locked'] == 0) {
            // عضویت مستقیم و بدون قفل
            FSM::setStatus($botId, $userId, 'approved');
            FSM::setRole($botId, $userId, 'translator'); // نقش دیفالت
            
            $tg->sendMessage($userId, "✅ <b>عضویت فوری شما تایید شد!</b>\n\nشما با موفقیت به دیتابیس تیم مانهوا ملحق شدید. دستور <code>/start</code> را بفرستید تا پنل شما فعال شود.");
        } else {
            // عضوگیری قفل‌دار (نیاز به تعیین نقش توسط کاربر و تایید نهایی ادمین)
            FSM::setStatus($botId, $userId, 'pending_test');
            FSM::setStep($botId, $userId, "waiting_test_translator"); // موقت تا انتخاب کند
            
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📝 مترجم', 'callback_data' => "user_role_translator"],
                        ['text' => '🖌 کلینر', 'callback_data' => "user_role_cleaner"]
                    ],
                    [
                        ['text' => '⌨️ تایپیست', 'callback_data' => "user_role_typesetter"]
                    ]
                ]
            ];
            $tg->sendMessage($userId, "🤝 <b>به عضوگیری قفل‌دار خوش آمدید.</b>\n\nلطفاً نقش مورد نظر خود را انتخاب کنید تا مشخصات شما جهت تایید فوری برای مدیریت فرستاده شود:", $keyboard);
        }
        exit;
    }

    // حالت منوی عادی شروع
    if ($status === 'approved') {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💰 میزان حقوق من', 'callback_data' => 'member_salary'],
                    ['text' => '📚 کارها و پروژه‌ها', 'callback_data' => 'member_tasks']
                ],
                [
                    ['text' => '🏆 آزمون‌های تمرینی', 'callback_data' => 'user_practice_exams'],
                    ['text' => '✉️ تیکت پشتیبانی ادمین', 'callback_data' => 'usr_tickets_p_1']
                ],
                [
                    ['text' => '❓ سوالات متداول (FAQ)', 'callback_data' => 'user_sys_faq_list_1']
                ]
            ]
        ];

        // در صورت ادمین یا مالک بودن، دکمه سوئیچ را اضافه می‌کنیم
        $isAdmin = ($user['role'] === 'owner' || strpos($user['role'], 'admin') !== false);
        if ($isAdmin) {
            $keyboard[] = [['text' => '🛡️ پنل ادمین', 'callback_data' => 'admin_sys_mode_admin']];
        }
        
        $roleFarsi = getRoleFarsi($role);
        $tg->sendMessage($userId, "👋 سلام <b>{$fullName}</b> عزیز، خوش آمدید.\n\nنقش شما در تیم: <b>{$roleFarsi}</b>\n\nلطفاً یکی از گزینه‌های زیر را انتخاب کنید:", $keyboard);
    } else {
        // داوطلب جدید (Guest)
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🤝 عضویت در تیم مانهوا', 'callback_data' => 'join_team']]
            ]
        ];
        
        $tg->sendMessage($userId, "👋 سلام <b>{$fullName}</b> گرامی!\n\nبه ربات رسمی مدیریت تیم مانهوا خوش آمدید. برای شروع عضویت دکمه زیر را لمس کنید:", $keyboard);
    }
    exit;
}

// ==========================================
// بخش ۴: پردازش رویدادهای کالبک شیشه‌ای (Callbacks)
// ==========================================
if ($callbackQuery) {
    $callbackData = $callbackQuery['data'];

    botLog($botId, $userId, 'info', 'User clicked inline button.', ['callback_data' => $callbackData]);

    if ($callbackData === 'join_team') {
        $tg->answerCallbackQuery($callbackId);
        
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

    elseif (strpos($callbackData, 'user_role_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $selectedRole = str_replace('user_role_', '', $callbackData);
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📥 دریافت فایل تست', 'callback_data' => "get_test_{$selectedRole}"]]
            ]
        ];
        
        $roleFarsi = getRoleFarsi($selectedRole);
        $tg->sendMessage($userId, "شما حوزه <b>{$roleFarsi}</b> را انتخاب کردید.\n\nبرای شروع فرآیند استخدام و دانلود فایل تست، روی دکمه زیر فشار دهید:", $keyboard);
        exit;
    }

    elseif (strpos($callbackData, 'get_test_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $testRole = str_replace('get_test_', '', $callbackData);

        $stmt = $db->prepare("SELECT file_id, instructions FROM test_templates WHERE bot_id = :bot_id AND role = :role LIMIT 1");
        $stmt->execute([
            'bot_id' => $botId,
            'role'   => $testRole
        ]);
        $template = $stmt->fetch();

        if (!$template || empty($template['file_id'])) {
            $tg->sendMessage($userId, "⚠️ متاسفانه در حال حاضر تستی برای این نقش توسط ادمین‌های ربات آپلود نشده است.");
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
        $captionText .= "لطفاً فایل را حل کرده و بفرستید:";

        $rawFileId = $template['file_id'];
        $fileType  = 'doc';
        $cleanFileId = $rawFileId;

        if (strpos($rawFileId, ':') !== false) {
            $parts = explode(':', $rawFileId, 2);
            $fileType    = $parts[0];
            $cleanFileId = $parts[1];
        }

        if ($fileType === 'photo') {
            $tg->sendPhoto($userId, $cleanFileId, $captionText, $keyboard);
        } else {
            $tg->sendDocument($userId, $cleanFileId, $captionText, $keyboard);
        }
        exit;
    }

    elseif (strpos($callbackData, 'prepare_submit_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $testRole = str_replace('prepare_submit_', '', $callbackData);

        if ($status === 'approved') {
            // فرآیند چندشغله شدن - تست مجدد
            FSM::setStep($botId, $userId, "user_waiting_retest_{$testRole}");
            $tg->sendMessage($userId, "📥 <b>بستر تست مجدد برای پرسنل فعال شد:</b>\n\nلطفاً فایل تست حل شده خود را جهت اخذ سمت دوم <b>«" . getRoleFarsi($testRole) . "»</b> ارسال کنید:");
        } else {
            // فرآیند استخدام عادی داوطلب جدید
            FSM::setStep($botId, $userId, "waiting_test_{$testRole}");
            $tg->sendMessage($userId, "📥 <b>بستر دریافت فایل تست فعال شد:</b>\n\nلطفاً پاسخ تست حل شده را به صورت سند یا تصویر بفرستید:");
        }
        exit;
    }

    elseif ($callbackData === 'member_salary') {
        $tg->answerCallbackQuery($callbackId);
        $earned  = number_format($user['total_earned'] ?? 0);
        $totalCh = (int)($user['total_chapters'] ?? 0);
        $monthCh = (int)($user['monthly_chapters'] ?? 0);

        $textSalary = "💰 <b>گزارش کیف پول و حقوق شما:</b>\n\n"
                    . "💸 کل حقوق ثبت شده شما: <code>{$earned}</code> تومان\n"
                    . "🔢 مجموع چپترهای انجام شده: <code>{$totalCh}</code> چپتر\n"
                    . "📅 چپترهای ثبت شده این ماه: <code>{$monthCh}</code> چپتر\n\n"
                    . "ℹ️ حقوق شما پس از تایید نهایی کارها توسط مدیریت فوراً افزایش می‌یابد.";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔙 بازگشت به منو', 'callback_data' => 'member_back_to_menu']]
            ]
        ];
        
        $tg->sendMessage($userId, $textSalary, $keyboard);
        exit;
    }

    elseif ($callbackData === 'member_tasks') {
        $tg->answerCallbackQuery($callbackId);
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
            $tg->sendMessage($userId, "⚠️ شما در حال حاضر روی هیچ پروژه فعال مانهوایی قرار نگرفته‌اید.", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'member_back_to_menu']]]
            ]);
        } else {
            $textTasks = "📚 <b>لیست پروژه‌های در دست اقدام شما:</b>\n\nجهت مشاهده جزئیات، روی مانهوا کلیک کنید:";
            $buttons = [];
            foreach ($tasks as $task) {
                $buttons[] = [['text' => "📚 " . $task['title'], 'callback_data' => "view_task_" . $task['id']]];
            }
            $buttons[] = [['text' => '🔙 بازگشت', 'callback_data' => 'member_back_to_menu']];

            $tg->sendMessage($userId, $textTasks, ['inline_keyboard' => $buttons]);
        }
        exit;
    }

    elseif (strpos($callbackData, 'view_task_') === 0) {
        $tg->answerCallbackQuery($callbackId);
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
        }
        exit;
    }

    // تیکت‌های پشتیبانی و تیکتینگ کاری
    elseif (strpos($callbackData, 'usr_tickets_p_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        
        // ۱. بررسی ساعت و روز کاری قبل از هر اقدام تیکتینگ
        $timeCheck = checkTicketWorkingTime($db, $botId);
        if (!$timeCheck['allowed']) {
            $tg->sendMessage($userId, $timeCheck['reason'], [
                'inline_keyboard' => [[['text' => '🔙 بازگشت به منو', 'callback_data' => 'member_back_to_menu']]]
            ]);
            exit;
        }

        $page = (int)str_replace('usr_tickets_p_', '', $callbackData);
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $stmtCount = $db->prepare("SELECT COUNT(*) as total FROM tickets WHERE bot_id = :bot_id AND user_id = :u_id");
        $stmtCount->execute(['bot_id' => $botId, 'u_id' => $userId]);
        $total = $stmtCount->fetch()['total'];
        $totalPages = max(1, ceil($total / $limit));

        $stmt = $db->prepare("SELECT id, subject, status FROM tickets WHERE bot_id = :bot_id AND user_id = :u_id ORDER BY id DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':bot_id', $botId, PDO::PARAM_INT);
        $stmt->bindValue(':u_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $tickets = $stmt->fetchAll();

        $textList = "✉️ <b>لیست تیکت‌های پشتیبانی شما (صفحه {$page} از {$totalPages}):</b>\n\nبرای ثبت تیکت جدید یا پیگیری از گزینه‌های زیر اقدام کنید:";
        $buttons = [];
        $buttons[] = [['text' => '➕ ثبت تیکت پشتیبانی جدید', 'callback_data' => 'user_open_ticket']];

        foreach ($tickets as $t) {
            $statusIcon = $t['status'] === 'closed' ? '✅ بسته‌شده' : '⏳ باز';
            $preview = mb_substr($t['subject'], 0, 15) . '...';
            $buttons[] = [
                ['text' => "{$statusIcon} تیکت #{$t['id']} | {$preview}", 'callback_data' => "usr_t_view_{$t['id']}"]
            ];
        }

        $navButtons = [];
        if ($page > 1) {
            $navButtons[] = ['text' => '◀️ قبلی', 'callback_data' => 'usr_tickets_p_' . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navButtons[] = ['text' => 'بعدی ▶️', 'callback_data' => 'usr_tickets_p_' . ($page + 1)];
        }
        if (!empty($navButtons)) {
            $buttons[] = $navButtons;
        }

        $buttons[] = [['text' => '🔙 بازگشت به منو', 'callback_data' => 'member_back_to_menu']];

        $tg->sendMessage($userId, $textList, ['inline_keyboard' => $buttons]);
        exit;
    }

    elseif (strpos($callbackData, 'usr_t_view_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $ticketId = (int)str_replace('usr_t_view_', '', $callbackData);

        $stmt = $db->prepare("SELECT * FROM tickets WHERE bot_id = :bot_id AND id = :id AND user_id = :u_id LIMIT 1");
        $stmt->execute([
            'bot_id' => $botId,
            'id'     => $ticketId,
            'u_id'   => $userId
        ]);
        $t = $stmt->fetch();

        if ($t) {
            $statusStr = $t['status'] === 'closed' ? '✅ پاسخ داده شده و بسته‌شده' : '⏳ در انتظار پاسخ مدیریت';
            $textMsg = "✉️ <b>جزئیات تیکت شماره #{$t['id']}</b>\n\n"
                     . "📌 وضعیت: <b>{$statusStr}</b>\n"
                     . "📝 <b>متن تیکت شما:</b>\n<i>{$t['subject']}</i>";

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🔙 بازگشت به لیست تیکت‌ها', 'callback_data' => 'usr_tickets_p_1']]
                ]
            ];

            $tg->sendMessage($userId, $textMsg, $keyboard);
        }
        exit;
    }

    elseif ($callbackData === 'user_open_ticket') {
        $tg->answerCallbackQuery($callbackId);
        
        $timeCheck = checkTicketWorkingTime($db, $botId);
        if (!$timeCheck['allowed']) {
            $tg->sendMessage($userId, $timeCheck['reason'], [
                'inline_keyboard' => [[['text' => '🔙 بازگشت به منو', 'callback_data' => 'member_back_to_menu']]]
            ]);
            exit;
        }

        // واکشی ادمین‌های لایو (مخفی نشده‌ها) جهت نمایش در لیست انتخاب اختصاصی
        $stmtAdmins = $db->prepare("
            SELECT u.tg_id, u.full_name 
            FROM users u
            LEFT JOIN admin_permissions ap ON u.bot_id = ap.bot_id AND u.tg_id = ap.user_id
            WHERE u.bot_id = :bot_id 
              AND u.status = 'approved'
              AND (u.role = 'owner' OR (u.role = 'admin' AND ap.perm_tickets_view = TRUE))
        ");
        $stmtAdmins->execute(['bot_id' => $botId]);
        $admins = $stmtAdmins->fetchAll();

        $buttons = [];
        $buttons[] = [['text' => '👥 تیکت عمومی (به کل ادمین‌ها)', 'callback_data' => 'user_send_ticket_general']];
        
        foreach ($admins as $ad) {
            // چک کردن اینکه آیا ادمین مخفی است یا خیر
            $stmtHide = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = :key LIMIT 1");
            $stmtHide->execute(['bot_id' => $botId, 'key' => "hide_admin_{$ad['tg_id']}"]);
            $hideRow = $stmtHide->fetch();
            $isHidden = $hideRow ? (int)$hideRow['value'] : 0;

            if ($isHidden === 1) {
                continue; // عدم نمایش ادمین مخفی شده در پنل کاربر عادی
            }

            // چک کردن نام نمایشی اختصاصی
            $stmtDisp = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = :key LIMIT 1");
            $stmtDisp->execute(['bot_id' => $botId, 'key' => "display_name_admin_{$ad['tg_id']}"]);
            $dispRow = $stmtDisp->fetch();
            $displayName = $dispRow ? $dispRow['value'] : $ad['full_name'];

            $buttons[] = [['text' => "👤 ادمین: {$displayName}", 'callback_data' => "user_send_ticket_to_{$ad['tg_id']}"]];
        }
        $buttons[] = [['text' => '🔙 بازگشت', 'callback_data' => 'usr_tickets_p_1']];

        $tg->sendMessage($userId, "✉️ <b>ارسال تیکت جدید:</b>\n\nمشخص کنید تمایل دارید تیکت شما به صورت عمومی ارسال شود یا مستقیماً به یک ادمین مشخص ارجاع داده شود:", ['inline_keyboard' => $buttons]);
        exit;
    }

    elseif (strpos($callbackData, 'user_send_ticket_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $targetAdmin = str_replace('user_send_ticket_', '', $callbackData);
        $adminId = ($targetAdmin === 'general') ? 'null' : (int)str_replace('to_', '', $targetAdmin);

        $timeCheck = checkTicketWorkingTime($db, $botId);
        if (!$timeCheck['allowed']) {
            $tg->sendMessage($userId, $timeCheck['reason']);
            exit;
        }

        FSM::setStep($botId, $userId, "user_typing_ticket_{$adminId}");

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ انصراف و لغو', 'callback_data' => 'user_cancel']]
            ]
        ];

        $tg->sendMessage($userId, "✍️ <b>لطفاً موضوع و متن مشکل خود را تایپ و ارسال کنید:</b>", $keyboard);
        exit;
    }

    // آرشیو سوالات متداول (FAQ Module for Members)
    elseif (strpos($callbackData, 'user_sys_faq_list_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $page = (int)str_replace('user_sys_faq_list_', '', $callbackData);
        $limit = 5;
        $offset = ($page - 1) * $limit;

        $stmtCount = $db->prepare("SELECT COUNT(*) as total FROM faq WHERE bot_id = :bot_id");
        $stmtCount->execute(['bot_id' => $botId]);
        $total = $stmtCount->fetch()['total'];
        $totalPages = max(1, ceil($total / $limit));

        $stmt = $db->prepare("SELECT id, title FROM faq WHERE bot_id = :bot_id ORDER BY id DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':bot_id', $botId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $faqs = $stmt->fetchAll();

        if (empty($faqs)) {
            $tg->sendMessage($userId, "⚠️ در حال حاضر هیچ سوال متداولی در دیتابیس ثبت نشده است.", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'member_back_to_menu']]]
            ]);
            exit;
        }

        $buttons = [];
        foreach ($faqs as $f) {
            $buttons[] = [['text' => "❓ " . $f['title'], 'callback_data' => "user_sys_faq_view_{$f['id']}"]];
        }

        $navButtons = [];
        if ($page > 1) {
            $navButtons[] = ['text' => '◀️ قبلی', 'callback_data' => "user_sys_faq_list_" . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navButtons[] = ['text' => 'بعدی ▶️', 'callback_data' => "user_sys_faq_list_" . ($page + 1)];
        }
        if (!empty($navButtons)) {
            $buttons[] = $navButtons;
        }
        $buttons[] = [['text' => '🔙 بازگشت به منو اصلی', 'callback_data' => 'member_back_to_menu']];

        $tg->sendMessage($userId, "❓ <b>راهنما و سوالات متداول پرسنل (صفحه {$page} از {$totalPages}):</b>\n\nبرای دیدن پاسخ هر سوال روی آن کلیک کنید:", ['inline_keyboard' => $buttons]);
        exit;
    }

    elseif (strpos($callbackData, 'user_sys_faq_view_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $faqId = (int)str_replace('user_sys_faq_view_', '', $callbackData);

        $stmt = $db->prepare("SELECT * FROM faq WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $faqId]);
        $faq = $stmt->fetch();

        if ($faq) {
            $textMsg = "❓ <b>سوال متداول: {$faq['title']}</b>\n\n"
                     . "💬 <b>پاسخ مدیریت:</b>\n<i>{$faq['content']}</i>";

            $tg->sendMessage($userId, $textMsg, [
                'inline_keyboard' => [[['text' => '🔙 بازگشت به لیست سوالات', 'callback_data' => 'user_sys_faq_list_1']]]
            ]);
        }
        exit;
    }

    // آزمون‌های تمرینی/دلخواه
    elseif ($callbackData === 'user_practice_exams') {
        $tg->answerCallbackQuery($callbackId);
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📝 آزمون‌های مترجم', 'callback_data' => 'user_view_exams_translator'],
                    ['text' => '🖌 آزمون‌های کلینر', 'callback_data' => 'user_view_exams_cleaner']
                ],
                [
                    ['text' => '⌨️ آزمون‌های تایپیست', 'callback_data' => 'user_view_exams_typesetter']
                ],
                [
                    ['text' => '🎯 تست برای نقش جدید (چندشغله شدن)', 'callback_data' => 'user_retest_start']
                ],
                [['text' => '🔙 بازگشت به منو', 'callback_data' => 'member_back_to_menu']]
            ]
        ];
        $tg->sendMessage($userId, "🏆 <b>بخش آزمون‌های تمرینی و دلخواه مانهوا:</b>\n\nبرای دریافت فایل آزمون و یادگیری استانداردهای جدید، نقش خود را انتخاب کنید:", $keyboard);
        exit;
    }

    // تست مجدد برای اخذ سمت دوم (پرسنل رسمی)
    elseif ($callbackData === 'user_retest_start') {
        $tg->answerCallbackQuery($callbackId);
        
        // استخراج نقش‌هایی که کاربر در حال حاضر ندارد
        $currentRoles = explode(',', $user['role']);
        $allRoles = ['translator' => 'مترجم', 'cleaner' => 'کلینر', 'typesetter' => 'تایپیست'];

        $buttons = [];
        foreach ($allRoles as $roleSlug => $roleFarsi) {
            if (!in_array($roleSlug, $currentRoles)) {
                $buttons[] = [['text' => "📥 دریافت تست نقش «{$roleFarsi}»", 'callback_data' => "get_test_{$roleSlug}"]];
            }
        }

        if (empty($buttons)) {
            $tg->sendMessage($userId, "⚠️ شما در حال حاضر تمام نقش‌های فنی (مترجم، کلینر و تایپیست) را در پروفایل خود دارید!", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'user_practice_exams']]]
            ]);
        } else {
            $buttons[] = [['text' => '🔙 بازگشت', 'callback_data' => 'user_practice_exams']];
            $tg->sendMessage($userId, "🎯 <b>سیستم چندشغله شدن پرسنل:</b>\n\nشما می‌توانید با دانلود و حل فایل تست نقش‌های دیگر، سمت جدیدی دریافت کنید و مبالغ تراکنش‌های مانهوا را تجمعی واریز بگیرید. نقش مورد نظر را لمس کنید:", ['inline_keyboard' => $buttons]);
        }
        exit;
    }

    elseif (strpos($callbackData, 'user_view_exams_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $targetRole = str_replace('user_view_exams_', '', $callbackData);

        $stmt = $db->prepare("SELECT id, title FROM practice_exams WHERE bot_id = :bot_id AND role = :role ORDER BY id DESC");
        $stmt->execute(['bot_id' => $botId, 'role' => $targetRole]);
        $exams = $stmt->fetchAll();

        if (empty($exams)) {
            $tg->sendMessage($userId, "⚠️ در حال حاضر هیچ آزمون یا پروژه تمرینی برای این نقش ثبت نشده است.", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'user_practice_exams']]]
            ]);
        } else {
            $textList = "🏆 <b>آزمون‌های تمرینی فعال برای " . getRoleFarsi($targetRole) . " :</b>";
            $buttons = [];
            foreach ($exams as $ex) {
                $buttons[] = [['text' => "📝 " . $ex['title'], 'callback_data' => "user_download_exam_{$ex['id']}"]];
            }
            $buttons[] = [['text' => '🔙 بازگشت', 'callback_data' => 'user_practice_exams']];

            $tg->sendMessage($userId, $textList, ['inline_keyboard' => $buttons]);
        }
        exit;
    }

    elseif (strpos($callbackData, 'user_download_exam_') === 0) {
        $tg->answerCallbackQuery($callbackId);
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
                     . "پس از حل کردن سوال، دکمه زیر را فشار داده و نسخه نهایی را بفرستید:";

            $rawFileId   = $exam['file_id'];
            $fileType    = 'doc';
            $cleanFileId = $rawFileId;

            if (strpos($rawFileId, ':') !== false) {
                $parts = explode(':', $rawFileId, 2);
                $fileType    = $parts[0];
                $cleanFileId = $parts[1];
            }

            if ($fileType === 'photo') {
                $tg->sendPhoto($userId, $cleanFileId, $caption, $keyboard);
            } else {
                $tg->sendDocument($userId, $cleanFileId, $caption, $keyboard);
            }
        } else {
            $tg->sendMessage($userId, "❌ آزمون یافت نشد.");
        }
        exit;
    }

    elseif (strpos($callbackData, 'user_submit_exam_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $examId = (int)str_replace('user_submit_exam_', '', $callbackData);

        FSM::setStep($botId, $userId, "user_waiting_exam_solve_{$examId}");

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ انصراف و لغو', 'callback_data' => 'user_cancel']]
            ]
        ];

        $tg->sendMessage($userId, "📥 <b>بستر دریافت فایل پاسخ فعال شد:</b>\n\nلطفاً پاسخ آزمون تمرینی را به صورت سند یا تصویر ارسال فرمایید:", $keyboard);
        exit;
    }

    elseif ($callbackData === 'member_back_to_menu') {
        $tg->answerCallbackQuery($callbackId);
        FSM::clearStep($botId, $userId);
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💰 میزان حقوق من', 'callback_data' => 'member_salary'],
                    ['text' => '📚 کارها و پروژه‌ها', 'callback_data' => 'member_tasks']
                ],
                [
                    ['text' => '🏆 آزمون‌های تمرینی', 'callback_data' => 'user_practice_exams'],
                    ['text' => '✉️ تیکت پشتیبانی ادمین', 'callback_data' => 'usr_tickets_p_1']
                ],
                [
                    ['text' => '❓ سوالات متداول (FAQ)', 'callback_data' => 'user_sys_faq_list_1']
                ]
            ]
        ];

        // سوئیچ مجدد به پنل مدیریت برای ادمین‌ها
        $isAdmin = ($user['role'] === 'owner' || strpos($user['role'], 'admin') !== false);
        if ($isAdmin) {
            $keyboard[] = [['text' => '🛡️ پنل ادمین', 'callback_data' => 'admin_sys_mode_admin']];
        }
        
        $tg->sendMessage($userId, "👋 منوی اعضا\n\nلطفاً گزینه مورد نظر خود را انتخاب کنید:", $keyboard);
        exit;
    }
}
