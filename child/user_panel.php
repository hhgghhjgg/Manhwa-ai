<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: child/user_panel.php
 * Role: Member & Guest Dashboard Processor (Recruitment, Tasks, Salary)
 */

// اطمینان از سلامت کانتکست و متغیرهای تعریف شده در index.php و child/router.php
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
// فاز ۱: بررسی موتور پردازش مراحل FSM (دریافت تست حل شده کاربر)
// ==========================================
if (strpos($step, 'waiting_test_') === 0) {
    // خارج کردن نقش داوطلب استخدام از فیلد step دیتابیس
    $testRole = str_replace('waiting_test_', '', $step);

    $fileId = null;
    if (isset($message['document'])) {
        $fileId = $message['document']['file_id'];
    } elseif (isset($message['photo'])) {
        // دریافت بزرگ‌ترین سایز عکس ارسالی کاربر تلگرام
        $fileId = end($message['photo'])['file_id'];
    }

    if (!$fileId) {
        $tg->sendMessage($userId, "❌ <b>فایل نامعتبر است!</b>\n\nلطفاً فایل حل شده تست خود را فقط به صورت سند (Document) یا تصویر بفرستید:");
        exit;
    }

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

    // به‌روزرسانی وضعیت کاربر به انتظار برای بررسی و ریست کردن مرحله FSM
    FSM::setStatus($botId, $userId, 'pending_test');
    FSM::clearStep($botId, $userId);

    $tg->sendMessage($userId, "✅ <b>تست شما با موفقیت به بخش عضو گیری تیم فرستاده شد.</b>\n\nپس از بررسی و تایید آن توسط ادمین‌های محترم تیم، لینک دعوت یک‌بار مصرف ورود به گروه اختصاصی کار برای شما ارسال خواهد شد. لطفاً منتظر بمانید.");

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

// ==========================================
// فاز ۲: پردازش دستورات متنی (دریافت پیام متنی)
// ==========================================
if ($message) {
    $text = $message['text'] ?? '';

    if ($text === '/start') {
        FSM::clearStep($botId, $userId);

        if ($status === 'approved') {
            // اعضای تایید شده پنل اصلی خود را می‌بینند
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '💰 میزان حقوق من', 'callback_data' => 'member_salary'],
                        ['text' => '📚 کارها و پروژه‌ها', 'callback_data' => 'member_tasks']
                    ]
                ]
            ];
            
            $roleFarsi = getRoleFarsi($role);
            $tg->sendMessage($userId, "👋 سلام <b>{$fullName}</b> عزیز، خوش آمدید.\n\nنقش شما در تیم: <b>{$roleFarsi}</b>\n\nلطفاً یکی از گزینه‌های پنل شیشه‌ای زیر را انتخاب کنید:", $keyboard);
        } else {
            // کاربران مهمان یا در انتظار بررسی، فرآیند ثبت‌نام را می‌بینند
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
// فاز ۳: پردازش کلیک روی دکمه‌های شیشه‌ای (Callback Queries)
// ==========================================
if ($callbackQuery) {
    $callbackData = $callbackQuery['data'];
    $callbackId   = $callbackQuery['id'];
    $messageId    = $callbackQuery['message']['message_id'];

    $tg->answerCallbackQuery($callbackId);

    // ۱. درخواست شروع عضویت در تیم مانهوا
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

    // ۲. انتخاب حوزه فعالیت توسط کاربر
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

    // ۳. ارسال فایل تست خام ذخیره شده به ادمین به داوطلب
    elseif (strpos($callbackData, 'get_test_') === 0) {
        $testRole = str_replace('get_test_', '', $callbackData);

        $stmt = $db->prepare("SELECT file_id FROM test_templates WHERE bot_id = :bot_id AND role = :role LIMIT 1");
        $stmt->execute([
            'bot_id' => $botId,
            'role'   => $testRole
        ]);
        $template = $stmt->fetch();

        if (!$template) {
            $tg->sendMessage($userId, "⚠️ متاسفانه در حال حاضر تستی برای این نقش توسط ادمین‌های ربات آپلود نشده است. لطفاً بعداً تلاش کرده یا با ادمین در ارتباط باشید.");
            exit;
        }

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📤 فرستادن تست حل شده', 'callback_data' => "prepare_submit_{$testRole}"]]
            ]
        ];

        $tg->sendDocument($userId, $template['file_id'], "📖 فایل تست شما آماده دانلود است.\n\nلطفاً فایل را دانلود و حل کنید. پس از پایان کار، دکمه زیر را فشار داده و نسخه نهایی را به صورت فایل یا تصویر برای ما ارسال کنید:", $keyboard);
        exit;
    }

    // ۴. آمادگی سیستم برای دریافت تست حل شده
    elseif (strpos($callbackData, 'prepare_submit_') === 0) {
        $testRole = str_replace('prepare_submit_', '', $callbackData);

        FSM::setStep($botId, $userId, "waiting_test_{$testRole}");
        
        $tg->sendMessage($userId, "📥 <b>بستر دریافت فایل فعال شد.</b>\n\nلطفاً پاسخ تست حل شده خود را به صورت سند (Document) یا تصویر بفرستید:");
        exit;
    }

    // ۵. کلیک روی دکمه حقوق (مخصوص اعضای تایید شده)
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

    // ۶. کلیک روی دکمه کارها و مانهواهای منتسب شده به کاربر (مخصوص اعضا)
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

    // ۸. بازگشت به منوی اصلی اعضا
    elseif ($callbackData === 'member_back_to_menu') {
        FSM::clearStep($botId, $userId);
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💰 میزان حقوق من', 'callback_data' => 'member_salary'],
                    ['text' => '📚 کارها و پروژه‌ها', 'callback_data' => 'member_tasks']
                ]
            ]
        ];
        
        $tg->sendMessage($userId, "👋 منوی اعضا\n\nلطفاً گزینه مورد نظر خود را انتخاب کنید:", $keyboard);
        exit;
    }
}
