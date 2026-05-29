<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: child/admin_panel.php
 * Role: Full Advanced Admin Panel Processor with Pagination, Search, Manual Member Import, Ticket System & Multi-staff Tools
 */

// ۱. لود خودکار موتور تسویه حساب، پایش مانهوا و سیستم ریست ماهانه
require_once __DIR__ . '/salary_system.php';

// اطمینان از صحت کانتکست و متغیرها
if (!isset($botContext) || !isset($tg) || !isset($user) || !isset($db)) {
    exit;
}

$userId    = $user['tg_id'];
$fullName  = $user['full_name'];
$username  = $user['username'] ?? '';
$step      = $user['step'];
$botId     = $botContext['bot_id'];

$message       = $botContext['update']['message'] ?? null;
$callbackQuery = $botContext['update']['callback_query'] ?? null;

// ==========================================
// فاز ۱: توابع کمکی و اعتبارسنجی سطوح دسترسی
// ==========================================

if (!function_exists('getRoleFarsiAdmin')) {
    function getRoleFarsiAdmin($roleName) {
        $roles = [
            'translator' => 'مترجم',
            'cleaner'    => 'کلینر',
            'typesetter' => 'تایپیست',
            'admin'      => 'ادمین مانهوا',
            'owner'      => 'مالک و ادمین کل',
            'none'       => 'مهمان'
        ];
        return $roles[$roleName] ?? 'نامشخص';
    }
}

if (!function_exists('hasPermission')) {
    /**
     * بررسی هوشمند دسترسی ادمین بر اساس سطوح ۱۰گانه در دیتابیس نئون
     */
    function hasPermission($db, $botId, $userId, $permName) {
        // مالک اصلی ربات به صورت پیش‌فرض به تمام بخش‌ها دسترسی دارد
        $stmtUser = $db->prepare("SELECT role FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id LIMIT 1");
        $stmtUser->execute(['bot_id' => $botId, 'tg_id' => $userId]);
        $userRow = $stmtUser->fetch();
        if ($userRow && $userRow['role'] === 'owner') {
            return true;
        }

        $dbField = "perm_" . $permName;
        $whitelist = ['recruit', 'manhwa', 'team', 'salary', 'settings', 'broadcast', 'add_admin', 'tickets', 'exams', 'warning'];
        
        if (!in_array($permName, $whitelist)) {
            return false;
        }

        $stmt = $db->prepare("SELECT {$dbField} FROM admin_permissions WHERE bot_id = :bot_id AND user_id = :user_id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ? (bool)$row[$dbField] : false;
    }
}

if (!function_exists('showAdminPermissionsPanel')) {
    /**
     * ترسیم پنل شیشه‌ای تنظیم سطوح دسترسی ۱۰گانه برای یک ادمین
     */
    function showAdminPermissionsPanel($db, $tg, $botId, $targetAdminId, $chatId, $messageId = null) {
        $stmt = $db->prepare("SELECT * FROM admin_permissions WHERE bot_id = :bot_id AND user_id = :user_id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'user_id' => $targetAdminId]);
        $perms = $stmt->fetch();

        if (!$perms) {
            $stmtIns = $db->prepare("INSERT INTO admin_permissions (bot_id, user_id) VALUES (:bot_id, :user_id) RETURNING *");
            $stmtIns->execute(['bot_id' => $botId, 'user_id' => $targetAdminId]);
            $perms = $stmtIns->fetch();
        }

        // واکشی نام ادمین جهت نمایش
        $stmtU = $db->prepare("SELECT full_name FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id LIMIT 1");
        $stmtU->execute(['bot_id' => $botId, 'tg_id' => $targetAdminId]);
        $uRow = $stmtU->fetch();
        $adminName = $uRow ? $uRow['full_name'] : 'ادمین مانهوا';

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => ($perms['perm_recruit'] ? '✅' : '❌') . ' ۱. استخدام/عضوگیری', 'callback_data' => "toggle_perm_{$targetAdminId}_recruit"],
                    ['text' => ($perms['perm_manhwa'] ? '✅' : '❌') . ' ۲. ثبت مانهوا', 'callback_data' => "toggle_perm_{$targetAdminId}_manhwa"]
                ],
                [
                    ['text' => ($perms['perm_team'] ? '✅' : '❌') . ' ۳. انتساب پروژه', 'callback_data' => "toggle_perm_{$targetAdminId}_team"],
                    ['text' => ($perms['perm_salary'] ? '✅' : '❌') . ' ۴. تایید حقوق/چپتر', 'callback_data' => "toggle_perm_{$targetAdminId}_salary"]
                ],
                [
                    ['text' => ($perms['perm_settings'] ? '✅' : '❌') . ' ۵. نرخ‌های دستمزد', 'callback_data' => "toggle_perm_{$targetAdminId}_settings"],
                    ['text' => ($perms['perm_broadcast'] ? '✅' : '❌') . ' ۶. ارسال همگانی', 'callback_data' => "toggle_perm_{$targetAdminId}_broadcast"]
                ],
                [
                    ['text' => ($perms['perm_add_admin'] ? '✅' : '❌') . ' ۷. مدیریت ادمین‌ها', 'callback_data' => "toggle_perm_{$targetAdminId}_add_admin"],
                    ['text' => ($perms['perm_tickets'] ? '✅' : '❌') . ' ۸. مدیریت تیکت‌ها', 'callback_data' => "toggle_perm_{$targetAdminId}_tickets"]
                ],
                [
                    ['text' => ($perms['perm_exams'] ? '✅' : '❌') . ' ۹. مدیریت آزمون‌ها', 'callback_data' => "toggle_perm_{$targetAdminId}_exams"],
                    ['text' => ($perms['perm_warning'] ? '✅' : '❌') . ' ۱۰. تنظیمات آلارم', 'callback_data' => "toggle_perm_{$targetAdminId}_warning"]
                ],
                [['text' => '🔙 ثبت نهایی و بازگشت', 'callback_data' => 'admin_settings']]
            ]
        ];

        $text = "🛡️ <b>مدیریت سطوح دسترسی ۱۰گانه ادمین:</b>\n\n👤 نام ادمین: <b>{$adminName}</b>\n🆔 شناسه تلگرام: <code>{$targetAdminId}</code>\n\nبرای فعال یا غیرفعال کردن هر دسترسی، روی گزینه مربوطه کلیک کنید:";

        if ($messageId) {
            $tg->editMessageText($chatId, $messageId, $text, $keyboard);
        } else {
            $tg->sendMessage($chatId, $text, $keyboard);
        }
    }
}

// ==========================================
// فاز ۲: پردازش وضعیت‌های ورودی متنی و فایلی FSM ادمین
// ==========================================
if ($message) {
    $text = isset($message['text']) ? trim($message['text']) : '';

    // سیستم لغو عمومی عملیات جاری از طریق دستور متنی
    if ($text === '/cancel' || $text === 'لغو' || (isset($callbackQuery) && $callbackQuery['data'] === 'admin_cancel')) {
        FSM::clearStep($botId, $userId);
        
        if (isset($callbackQuery) && isset($callbackId)) {
            $tg->answerCallbackQuery($callbackId, "عملیات لغو شد.");
        }

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📚 لیست کارها (پروژه‌ها)', 'callback_data' => 'admin_projects_page_1']],
                [['text' => '👥 مدیریت عضوگیری', 'callback_data' => 'admin_recruit']],
                [['text' => '⚙️ تنظیمات تیم', 'callback_data' => 'admin_settings']]
            ]
        ];
        
        $tg->sendMessage($userId, "❌ <b>عملیات لغو شد.</b>\n\n👋 به پنل مدیریت تیم خوش آمدید. بخش مورد نظر را انتخاب کنید:", $keyboard);
        exit;
    }

    // پردازش پیام‌های متنی FSM ادمین
    if (!empty($text)) {
        // جستجوی مانهوا در لیست پروژه‌ها
        if ($step === 'admin_waiting_project_search') {
            FSM::clearStep($botId, $userId);
            if (!hasPermission($db, $botId, $userId, 'manhwa')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت مانهواها را ندارید.");
                exit;
            }
            $stmt = $db->prepare("SELECT id, title FROM manhwas WHERE bot_id = :bot_id AND title ILIKE :q ORDER BY id DESC LIMIT 10");
            $stmt->execute(['bot_id' => $botId, 'q' => "%{$text}%"]);
            $results = $stmt->fetchAll();

            if (empty($results)) {
                $tg->sendMessage($userId, "🔍 مانهوایی با عنوان <b>«{$text}»</b> یافت نشد.", [
                    'inline_keyboard' => [[['text' => '📚 لیست مانهواها', 'callback_data' => 'admin_projects_page_1']]]
                ]);
            } else {
                $buttons = [];
                foreach ($results as $m) {
                    $buttons[] = [['text' => "📚 " . $m['title'], 'callback_data' => "admin_view_manhwa_{$m['id']}"]];
                }
                $buttons[] = [['text' => '🔙 بازگشت', 'callback_data' => 'admin_projects_page_1']];
                $tg->sendMessage($userId, "🔍 نتایج جستجو برای: <b>{$text}</b>", ['inline_keyboard' => $buttons]);
            }
            exit;
        }

        // جستجوی اعضا در لیست کل تیم
        elseif ($step === 'admin_waiting_user_search') {
            FSM::clearStep($botId, $userId);
            if (!hasPermission($db, $botId, $userId, 'team')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت اعضای تیم را ندارید.");
                exit;
            }
            $stmt = $db->prepare("SELECT tg_id, full_name, role FROM users WHERE bot_id = :bot_id AND (full_name ILIKE :q OR username ILIKE :q) AND role != 'none' LIMIT 10");
            $stmt->execute(['bot_id' => $botId, 'q' => "%{$text}%"]);
            $results = $stmt->fetchAll();

            if (empty($results)) {
                $tg->sendMessage($userId, "👤 کاربری با مشخصات <b>«{$text}»</b> یافت نشد.", [
                    'inline_keyboard' => [[['text' => '👥 لیست اعضا', 'callback_data' => 'admin_team_list_1']]]
                ]);
            } else {
                $buttons = [];
                foreach ($results as $u) {
                    $buttons[] = [['text' => "👤 {$u['full_name']} (" . getRoleFarsiAdmin($u['role']) . ")", 'callback_data' => "admin_user_v_{$u['tg_id']}"]];
                }
                $buttons[] = [['text' => '🔙 بازگشت', 'callback_data' => 'admin_team_list_1']];
                $tg->sendMessage($userId, "🔍 نتایج جستجوی کاربر: <b>{$text}</b>", ['inline_keyboard' => $buttons]);
            }
            exit;
        }

        // گام FSM جستجوی دستی عضو و پرسش برای انتساب نقش
        elseif ($step === 'admin_waiting_manual_search') {
            FSM::clearStep($botId, $userId);
            if (!hasPermission($db, $botId, $userId, 'recruit')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت عضوگیری را ندارید.");
                exit;
            }
            $input = trim($text);
            $targetUser = null;

            if (strpos($input, '@') === 0) {
                $searchUser = str_replace('@', '', $input);
                $stmt = $db->prepare("SELECT tg_id, full_name FROM users WHERE bot_id = :bot_id AND username = :username LIMIT 1");
                $stmt->execute(['bot_id' => $botId, 'username' => $searchUser]);
                $targetUser = $stmt->fetch();
            } elseif (is_numeric($input)) {
                $stmt = $db->prepare("SELECT tg_id, full_name FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id LIMIT 1");
                $stmt->execute(['bot_id' => $botId, 'tg_id' => $input]);
                $targetUser = $stmt->fetch();
            } else {
                $stmt = $db->prepare("SELECT tg_id, full_name FROM users WHERE bot_id = :bot_id AND username = :username LIMIT 1");
                $stmt->execute(['bot_id' => $botId, 'username' => $input]);
                $targetUser = $stmt->fetch();
            }

            if (!$targetUser) {
                $tg->sendMessage($userId, "❌ کاربری با این مشخصات یافت نشد. مطمئن شوید که کاربر ابتدا ربات را استارت کرده است.", [
                    'inline_keyboard' => [[['text' => '🔙 بازگشت به منو دستی', 'callback_data' => 'admin_manual_recruit_menu']]]
                ]);
            } else {
                $keyboard = [
                    'inline_keyboard' => [
                        [
                          ['text' => '📝 مترجم', 'callback_data' => "admin_set_man_role_{$targetUser['tg_id']}_translator"],
                          ['text' => '🖌 کلینر', 'callback_data' => "admin_set_man_role_{$targetUser['tg_id']}_cleaner"]
                        ],
                        [
                          ['text' => '⌨️ تایپیست', 'callback_data' => "admin_set_man_role_{$targetUser['tg_id']}_typesetter"]
                        ],
                        [['text' => '❌ لغو عملیات', 'callback_data' => 'admin_manual_recruit_menu']]
                    ]
                ];
                $tg->sendMessage($userId, "👤 کاربر <b>{$targetUser['full_name']}</b> یافت شد.\n\nلطفاً نقش مورد نظر جهت انتساب مستقیم این فرد به تیم را انتخاب کنید:", $keyboard);
            }
            exit;
        }

        // گام FSM ثبت گروهی اعضا به صورت جدول متنی
        elseif ($step === 'admin_waiting_manual_table_input') {
            FSM::clearStep($botId, $userId);
            if (!hasPermission($db, $botId, $userId, 'recruit')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت عضوگیری را ندارید.");
                exit;
            }
            $lines = explode("\n", $text);
            $successCount = 0;
            $failedEntries = [];

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                $parts = explode('|', $line);
                if (count($parts) !== 2) {
                    $failedEntries[] = $line . " (فرمت اشتباه - خط چین | یافت نشد)";
                    continue;
                }

                $inputUser = trim($parts[0]);
                $inputRole = trim($parts[1]);

                $roleSlug = '';
                $cleanRole = mb_strtolower($inputRole);
                if ($cleanRole === 'مترجم' || $cleanRole === 'translator') {
                    $roleSlug = 'translator';
                } elseif ($cleanRole === 'کلینر' || $cleanRole === 'cleaner') {
                    $roleSlug = 'cleaner';
                } elseif ($cleanRole === 'تایپیست' || $cleanRole === 'تایپ' || $cleanRole === 'typesetter') {
                    $roleSlug = 'typesetter';
                } else {
                    $failedEntries[] = $line . " (شغل نامعتبر است)";
                    continue;
                }

                $targetUser = null;
                if (strpos($inputUser, '@') === 0) {
                    $searchUser = str_replace('@', '', $inputUser);
                    $stmt = $db->prepare("SELECT tg_id, full_name FROM users WHERE bot_id = :bot_id AND username = :username LIMIT 1");
                    $stmt->execute(['bot_id' => $botId, 'username' => $searchUser]);
                    $targetUser = $stmt->fetch();
                } elseif (is_numeric($inputUser)) {
                    $stmt = $db->prepare("SELECT tg_id, full_name FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id LIMIT 1");
                    $stmt->execute(['bot_id' => $botId, 'tg_id' => $inputUser]);
                    $targetUser = $stmt->fetch();
                } else {
                    $stmt = $db->prepare("SELECT tg_id, full_name FROM users WHERE bot_id = :bot_id AND username = :username LIMIT 1");
                    $stmt->execute(['bot_id' => $botId, 'username' => $inputUser]);
                    $targetUser = $stmt->fetch();
                }

                if (!$targetUser) {
                    $failedEntries[] = $line . " (کاربر ربات را استارت نزده است)";
                    continue;
                }

                FSM::setRole($botId, $targetUser['tg_id'], $roleSlug);
                FSM::setStatus($botId, $targetUser['tg_id'], 'approved');

                $roleFarsi = getRoleFarsiAdmin($roleSlug);
                $tg->sendMessage($targetUser['tg_id'], "🎉 <b>تبریک می‌گویم! شما توسط مدیریت مستقیماً به عنوان عضو رسمی با سمت «{$roleFarsi}» به تیم اضافه شدید.</b>\n\nدستور <code>/start</code> را ارسال کنید تا پنل کاربری شما فعال شود.");
                $successCount++;
            }

            $reportText = "📋 <b>نتیجه ثبت دستی اعضا به صورت جدول:</b>\n\n"
                        . "✅ تعداد ثبت موفق: <code>{$successCount}</code> عضو جدید به تیم اضافه شدند.\n\n";

            if (!empty($failedEntries)) {
                $reportText .= "⚠️ <b>لیست موارد ناموفق (اشتباه):</b>\n";
                foreach ($failedEntries as $fe) {
                    $reportText .= "❌ " . htmlspecialchars($fe) . "\n";
                }
            }

            $tg->sendMessage($userId, $reportText, [
                'inline_keyboard' => [[['text' => '🔙 بازگشت به منوی عضوگیری', 'callback_data' => 'admin_recruit']]]
            ]);
            exit;
        }

        // ثبت قوانین جدید استخدام
        elseif ($step === 'admin_waiting_rules') {
            if (!hasPermission($db, $botId, $userId, 'recruit')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای تغییر شرایط استخدام را ندارید.");
                exit;
            }
            $stmt = $db->prepare("INSERT INTO settings (bot_id, key, value) VALUES (:bot_id, 'rules', :rules) ON CONFLICT (bot_id, key) DO UPDATE SET value = EXCLUDED.value");
            $stmt->execute(['bot_id' => $botId, 'rules' => $text]);

            FSM::clearStep($botId, $userId);
            $tg->sendMessage($userId, "✅ <b>شرایط و قوانین استخدام با موفقیت به‌روزرسانی شد.</b>", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_recruit']]]
            ]);
            exit;
        }

        // تنظیم روزهای مجاز راکد ماندن پروژه‌ها قبل اخطار
        elseif ($step === 'admin_waiting_warning_days') {
            if (!hasPermission($db, $botId, $userId, 'warning')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی لازم را ندارید.");
                exit;
            }
            if (!is_numeric($text) || (int)$text <= 0) {
                $tg->sendMessage($userId, "❌ لطفاً فقط عدد صحیح بزرگتر از صفر وارد کنید:");
                exit;
            }

            $stmt = $db->prepare("INSERT INTO settings (bot_id, key, value) VALUES (:bot_id, 'inactivity_warning_days', :value) ON CONFLICT (bot_id, key) DO UPDATE SET value = EXCLUDED.value");
            $stmt->execute(['bot_id' => $botId, 'value' => $text]);

            FSM::clearStep($botId, $userId);
            $tg->sendMessage($userId, "✅ <b>روزهای راکد ماندن مانهواها با موفقیت روی {$text} روز تنظیم شد.</b>", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_general_settings']]]
            ]);
            exit;
        }

        // دریافت نرخ جدید حقوق برای نقش‌ها
        elseif (strpos($step, 'admin_waiting_rate_') === 0) {
            if (!hasPermission($db, $botId, $userId, 'settings')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی تنظیمات حقوق را ندارید.");
                exit;
            }
            $roleToUpdate = str_replace('admin_waiting_rate_', '', $step);
            
            if (!is_numeric($text) || (int)$text < 0) {
                $tg->sendMessage($userId, "❌ لطفاً فقط عدد بزرگ‌تر از صفر وارد کنید:");
                exit;
            }

            $stmt = $db->prepare("INSERT INTO settings (bot_id, key, value) VALUES (:bot_id, :key, :value) ON CONFLICT (bot_id, key) DO UPDATE SET value = EXCLUDED.value");
            $stmt->execute([
                'bot_id' => $botId,
                'key'    => "rate_{$roleToUpdate}",
                'value'  => $text
            ]);

            FSM::clearStep($botId, $userId);
            $tg->sendMessage($userId, "✅ <b>نرخ دستمزد " . getRoleFarsiAdmin($roleToUpdate) . " به {$text} تومان تغییر یافت.</b>", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت به نرخ‌ها', 'callback_data' => 'admin_salary_rates']]]
            ]);
            exit;
        }

        // ارسال پاسخ تیکت پشتیبانی به کاربر
        elseif (strpos($step, 'admin_waiting_ticket_reply_') === 0) {
            if (!hasPermission($db, $botId, $userId, 'tickets')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی مدیریت تیکت‌ها را ندارید.");
                exit;
            }
            $ticketId = (int)str_replace('admin_waiting_ticket_reply_', '', $step);

            $stmtT = $db->prepare("SELECT user_id FROM tickets WHERE bot_id = :bot_id AND id = :id LIMIT 1");
            $stmtT->execute(['bot_id' => $botId, 'id' => $ticketId]);
            $ticket = $stmtT->fetch();

            if ($ticket) {
                $stmtUpdateTicket = $db->prepare("UPDATE tickets SET status = 'closed' WHERE bot_id = :bot_id AND id = :id");
                $stmtUpdateTicket->execute(['bot_id' => $botId, 'id' => $ticketId]);

                $userNotify = "✉️ <b>پاسخ مدیریت تیم مانهوا به تیکت پشتیبانی شما (#{$ticketId}):</b>\n\n" . $text;
                $tg->sendMessage($ticket['user_id'], $userNotify);

                FSM::clearStep($botId, $userId);
                $tg->sendMessage($userId, "✅ <b>پاسخ تیکت با موفقیت ارسال شد و وضعیت تیکت به «مختومه» تغییر یافت.</b>");
            } else {
                $tg->sendMessage($userId, "❌ تیکت یافت نشد.");
            }
            exit;
        }

        // ارسال پیام مستقیم به کاربر عادی با آیدی عددی
        elseif (strpos($step, 'admin_send_msg_') === 0) {
            if (!hasPermission($db, $botId, $userId, 'recruit') && !hasPermission($db, $botId, $userId, 'team')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی ارسال پیام مستقیم را ندارید.");
                exit;
            }
            $targetUserId = str_replace('admin_send_msg_', '', $step);

            $sent = $tg->sendMessage($targetUserId, "✉️ <b>پیام مدیریت تیم مانهوا برای شما:</b>\n\n" . $text);
            
            FSM::clearStep($botId, $userId);
            if ($sent && isset($sent['ok']) && $sent['ok'] === true) {
                $tg->sendMessage($userId, "✅ پیام شما با موفقیت برای کاربر ارسال شد.");
            } else {
                $tg->sendMessage($userId, "❌ خطا در ارسال پیام. احتمالاً کاربر ربات را بلاک کرده است.");
            }
            exit;
        }

        // ثبت آیدی عددی گروه مانهوا اصلی جهت تولید لینک‌های دعوت
        elseif ($step === 'admin_waiting_team_group_id') {
            if (!hasPermission($db, $botId, $userId, 'settings')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی تنظیمات گروه اصلی را ندارید.");
                exit;
            }
            if (!is_numeric($text)) {
                $tg->sendMessage($userId, "❌ آیدی عددی گروه تلگرام باید عدد منفی بزرگ باشد:");
                exit;
            }

            $stmt = $db->prepare("INSERT INTO settings (bot_id, key, value) VALUES (:bot_id, 'team_group_id', :value) ON CONFLICT (bot_id, key) DO UPDATE SET value = EXCLUDED.value");
            $stmt->execute(['bot_id' => $botId, 'value' => $text]);

            FSM::clearStep($botId, $userId);
            $tg->sendMessage($userId, "✅ <b>آیدی عددی گروه اصلی با موفقیت ثبت شد.</b>", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_settings']]]
            ]);
            exit;
        }

        // ثبت ادمین جدید و نمایش پنل سطوح دسترسی دهگانه
        elseif ($step === 'admin_waiting_add_admin_id') {
            if (!hasPermission($db, $botId, $userId, 'add_admin')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت ادمین‌ها را ندارید.");
                exit;
            }
            if (!is_numeric($text)) {
                $tg->sendMessage($userId, "❌ آیدی عددی تلگرام فقط باید عدد باشد:");
                exit;
            }

            $stmt = $db->prepare("SELECT * FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id LIMIT 1");
            $stmt->execute(['bot_id' => $botId, 'tg_id' => $text]);
            $targetUser = $stmt->fetch();

            if (!$targetUser) {
                $tg->sendMessage($userId, "❌ کاربری با این آیدی عددی هنوز ربات را استارت نکرده است.");
                exit;
            }

            FSM::setRole($botId, $text, 'admin');
            FSM::setStatus($botId, $text, 'approved');
            FSM::clearStep($botId, $userId);

            $tg->sendMessage($text, "🎉 <b>شما توسط مدیریت مانهوا به مقام ادمین ارتقا یافتید.</b>\n\nدستور <code>/start</code> را ارسال کنید تا پنل دسترسی‌ها فعال شود.");
            
            showAdminPermissionsPanel($db, $tg, $botId, $text, $userId);
            exit;
        }

        // فرستادن پیام همگانی به تمام گروه‌های متصل به پروژه‌ها
        elseif ($step === 'admin_waiting_broadcast_groups') {
            if (!hasPermission($db, $botId, $userId, 'broadcast')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی فرستادن پیام همگانی را ندارید.");
                exit;
            }
            $stmt = $db->prepare("SELECT DISTINCT group_id FROM manhwas WHERE bot_id = :bot_id AND group_id IS NOT NULL");
            $stmt->execute(['bot_id' => $botId]);
            $groups = $stmt->fetchAll();

            if (empty($groups)) {
                $tg->sendMessage($userId, "⚠️ هیچ گروه تلگرامی به پروژه‌های فعال شما متصل نشده است.");
                exit;
            }

            $successCount = 0;
            foreach ($groups as $group) {
                $sent = $tg->sendMessage($group['group_id'], "📢 <b>پیام همگانی ادمین کل برای تیم:</b>\n\n" . $text);
                if ($sent && isset($sent['ok']) && $sent['ok'] === true) {
                    $successCount++;
                }
            }

            FSM::clearStep($botId, $userId);
            $tg->sendMessage($userId, "✅ پیام شما با موفقیت به <code>{$successCount}</code> گروه ارسال شد.", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_settings']]]
            ]);
            exit;
        }

        // انتساب مستقیم اعضا به پروژه‌ها
        elseif (strpos($step, 'admin_waiting_assign_') === 0) {
            if (!hasPermission($db, $botId, $userId, 'team')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای انتساب پرسنل به پروژه‌ها را ندارید.");
                exit;
            }
            $paramsStr = str_replace('admin_waiting_assign_', '', $step);
            $parts     = explode('_', $paramsStr);
            $manhwaId  = (int)$parts[0];
            $roleToSet = $parts[1];

            $targetUser = null;
            if (strpos($text, '@') === 0) {
                $searchUser = str_replace('@', '', $text);
                $stmt = $db->prepare("SELECT tg_id, full_name FROM users WHERE bot_id = :bot_id AND username = :username LIMIT 1");
                $stmt->execute(['bot_id' => $botId, 'username' => $searchUser]);
                $targetUser = $stmt->fetch();
            } elseif (is_numeric($text)) {
                $stmt = $db->prepare("SELECT tg_id, full_name FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id LIMIT 1");
                $stmt->execute(['bot_id' => $botId, 'tg_id' => $text]);
                $targetUser = $stmt->fetch();
            }

            if (!$targetUser) {
                $tg->sendMessage($userId, "❌ کاربری با این مشخصات یافت نشد. لطفاً آیدی عددی معتبر یا آیدی کاربری تلگرام (با @) بفرستید:");
                exit;
            }

            $stmtInsert = $db->prepare("INSERT INTO team_assignments (bot_id, manhwa_id, role, user_id) VALUES (:bot_id, :manhwa_id, :role, :user_id)");
            $stmtInsert->execute([
                'bot_id'    => $botId,
                'manhwa_id' => $manhwaId,
                'role'      => $roleToSet,
                'user_id'   => $targetUser['tg_id']
            ]);

            FSM::clearStep($botId, $userId);
            $tg->sendMessage($userId, "✅ کاربر <b>{$targetUser['full_name']}</b> با موفقیت به عنوان <b>" . getRoleFarsiAdmin($roleToSet) . "</b> این پروژه منتسب شد.", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => "admin_view_manhwa_{$manhwaId}"]]]
            ]);
            exit;
        }

        // ثبت نهایی عنوان آزمون تمرینی دلخواه
        elseif (strpos($step, 'admin_waiting_exam_title_') === 0) {
            if (!hasPermission($db, $botId, $userId, 'exams')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت آزمون‌ها را ندارید.");
                exit;
            }
            $examRole = str_replace('admin_waiting_exam_title_', '', $step);

            $stmtFile = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = :key LIMIT 1");
            $stmtFile->execute([
                'bot_id' => $botId,
                'key'    => "temp_exam_file_{$examRole}"
            ]);
            $fileRow = $stmtFile->fetch();
            $examFile = $fileRow ? $fileRow['value'] : null;

            if (!$examFile) {
                $tg->sendMessage($userId, "❌ خطای سیستمی: فایل آزمون یافت نشد. مجدداً آپلود کنید.");
                FSM::clearStep($botId, $userId);
                exit;
            }

            $stmtIns = $db->prepare("
                INSERT INTO practice_exams (bot_id, role, title, file_id, instructions)
                VALUES (:bot_id, :role, :title, :file_id, :instructions)
            ");
            $stmtIns->execute([
                'bot_id'       => $botId,
                'role'         => $examRole,
                'title'        => $text,
                'file_id'      => $examFile,
                'instructions' => 'آزمون تمرینی و دلخواه برای اعضای رسمی جهت ارزیابی و بهبود مهارت.'
            ]);

            FSM::clearStep($botId, $userId);
            $tg->sendMessage($userId, "✅ <b>آزمون تمرینی جدید با موفقیت در دیتابیس ثبت شد.</b>", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت به منو', 'callback_data' => 'admin_manage_exams_page_1']]]
            ]);
            exit;
        }

        // دریافت توضیحات تست استخدامی و ثبت نهایی
        elseif (strpos($step, 'admin_waiting_rec_test_rules_') === 0) {
            if (!hasPermission($db, $botId, $userId, 'recruit')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت عضوگیری را ندارید.");
                exit;
            }
            $recRole = str_replace('admin_waiting_rec_test_rules_', '', $step);

            $stmtFile = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = :key LIMIT 1");
            $stmtFile->execute([
                'bot_id' => $botId,
                'key'    => "temp_rec_test_file_{$recRole}"
            ]);
            $fileRow = $stmtFile->fetch();
            $recFile = $fileRow ? $fileRow['value'] : null;

            if (!$recFile) {
                $tg->sendMessage($userId, "❌ خطای سیستمی: فایل تست استخدامی یافت نشد. مجدداً تکرار کنید.");
                FSM::clearStep($botId, $userId);
                exit;
            }

            $stmt = $db->prepare("
                INSERT INTO test_templates (bot_id, role, file_id, instructions)
                VALUES (:bot_id, :role, :file_id, :instructions)
                ON CONFLICT (bot_id, role) DO UPDATE 
                SET file_id = EXCLUDED.file_id, instructions = EXCLUDED.instructions
            ");
            $stmt->execute([
                'bot_id'       => $botId,
                'role'         => $recRole,
                'file_id'      => $recFile,
                'instructions' => $text
            ]);

            FSM::clearStep($botId, $userId);
            $tg->sendMessage($userId, "✅ <b>فایل تست استخدامی به همراه قوانین اختصاصی آن برای نقش " . getRoleFarsiAdmin($recRole) . " با موفقیت ثبت شد.</b>", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_recruit']]]
            ]);
            exit;
        }

        // ثبت نهایی دلیل رد چپتر مانهوا
        elseif (strpos($step, 'admin_waiting_reject_reason_') === 0) {
            if (!hasPermission($db, $botId, $userId, 'salary')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی مالی برای رد یا تایید چپترها را ندارید.");
                exit;
            }
            $chapterId = (int)str_replace('admin_waiting_reject_reason_', '', $step);
            FSM::clearStep($botId, $userId);

            $stmt = $db->prepare("SELECT c.*, m.group_id, m.title FROM chapters c JOIN manhwas m ON c.manhwa_id = m.id WHERE c.bot_id = :bot_id AND c.id = :id LIMIT 1");
            $stmt->execute(['bot_id' => $botId, 'id' => $chapterId]);
            $chapter = $stmt->fetch();

            if ($chapter) {
                $stmtUpdate = $db->prepare("UPDATE chapters SET status = 'rejected' WHERE bot_id = :bot_id AND id = :id");
                $stmtUpdate->execute(['bot_id' => $botId, 'id' => $chapterId]);

                if (!empty($chapter['group_id'])) {
                    $rejectAlert = "❌ <b>چپتر {$chapter['chapter_num']} مانهوای «{$chapter['title']}» تایید نشد.</b>\n\n⚠️ <b>دلیل رد توسط مدیریت:</b>\n<i>{$text}</i>\n\nتایپیست محترم لطفا فایل اصلاح‌شده را مجدداً ارسال فرمایید.";
                    $tg->sendMessage($chapter['group_id'], $rejectAlert);
                }

                if (!empty($chapter['typesetter_id'])) {
                    $tg->sendMessage($chapter['typesetter_id'], "❌ کار شما روی چپتر {$chapter['chapter_num']} مانهوای «{$chapter['title']}» رد شد.\nدلیل: {$text}");
                }

                $tg->sendMessage($userId, "✅ دلیل رد ثبت شد و برای گروه مانهوا ارسال گردید.");
            }
            exit;
        }

        // ثبت قیمت اختصاصی مانهوا - مترجم
        elseif (strpos($step, 'admin_waiting_m_rate_trans_') === 0) {
            if (!hasPermission($db, $botId, $userId, 'settings')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای ویرایش نرخ دستمزد مانهوا را ندارید.");
                exit;
            }
            $manhwaId = (int)str_replace('admin_waiting_m_rate_trans_', '', $step);
            FSM::clearStep($botId, $userId);

            $stmt = $db->prepare("UPDATE manhwas SET rate_translator = :val WHERE bot_id = :bot_id AND id = :id");
            $stmt->execute(['val' => (float)$text, 'bot_id' => $botId, 'id' => $manhwaId]);

            $tg->sendMessage($userId, "✅ نرخ مترجم این مانهوا با موفقیت روی <b>" . number_format((float)$text) . "</b> تومان تنظیم شد.", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => "admin_m_set_{$manhwaId}"]]]
            ]);
            exit;
        }

        // ثبت قیمت اختصاصی مانهوا - کلینر
        elseif (strpos($step, 'admin_waiting_m_rate_clean_') === 0) {
            if (!hasPermission($db, $botId, $userId, 'settings')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای ویرایش نرخ دستمزد مانهوا را ندارید.");
                exit;
            }
            $manhwaId = (int)str_replace('admin_waiting_m_rate_clean_', '', $step);
            FSM::clearStep($botId, $userId);

            $stmt = $db->prepare("UPDATE manhwas SET rate_cleaner = :val WHERE bot_id = :bot_id AND id = :id");
            $stmt->execute(['val' => (float)$text, 'bot_id' => $botId, 'id' => $manhwaId]);

            $tg->sendMessage($userId, "✅ نرخ کلینر این مانهوا با موفقیت روی <b>" . number_format((float)$text) . "</b> تومان تنظیم شد.", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => "admin_m_set_{$manhwaId}"]]]
            ]);
            exit;
        }

        // ثبت قیمت اختصاصی مانهوا - تایپیست
        elseif (strpos($step, 'admin_waiting_m_rate_type_') === 0) {
            if (!hasPermission($db, $botId, $userId, 'settings')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای ویرایش نرخ دستمزد مانهوا را ندارید.");
                exit;
            }
            $manhwaId = (int)str_replace('admin_waiting_m_rate_type_', '', $step);
            FSM::clearStep($botId, $userId);

            $stmt = $db->prepare("UPDATE manhwas SET rate_typesetter = :val WHERE bot_id = :bot_id AND id = :id");
            $stmt->execute(['val' => (float)$text, 'bot_id' => $botId, 'id' => $manhwaId]);

            $tg->sendMessage($userId, "✅ نرخ تایپیست این مانهوا با موفقیت روی <b>" . number_format((float)$text) . "</b> تومان تنظیم شد.", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => "admin_m_set_{$manhwaId}"]]]
            ]);
            exit;
        }

        // نوشتن پاسخ تیکت در پنل مدیریت تیکت‌ها
        elseif (strpos($step, 'admin_waiting_ticket_response_') === 0) {
            if (!hasPermission($db, $botId, $userId, 'tickets')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی مدیریت تیکت‌ها را ندارید.");
                exit;
            }
            $ticketId = (int)str_replace('admin_waiting_ticket_response_', '', $step);
            FSM::clearStep($botId, $userId);

            $stmt = $db->prepare("SELECT user_id FROM tickets WHERE bot_id = :bot_id AND id = :id LIMIT 1");
            $stmt->execute(['bot_id' => $botId, 'id' => $ticketId]);
            $ticket = $stmt->fetch();

            if ($ticket) {
                $stmtUp = $db->prepare("UPDATE tickets SET status = 'closed' WHERE bot_id = :bot_id AND id = :id");
                $stmtUp->execute(['bot_id' => $botId, 'id' => $ticketId]);

                $tg->sendMessage($ticket['user_id'], "✉️ <b>پاسخ تیکت شماره #{$ticketId} از طرف مدیریت:</b>\n\n{$text}");
                $tg->sendMessage($userId, "✅ پاسخ شما با موفقیت به کاربر ارسال شد و وضعیت تیکت به «بسته شده» تغییر یافت.");
            }
            exit;
        }

        // ثبت اخطار برای اعضای تیم در بخش جزئیات عضو
        elseif (strpos($step, 'admin_waiting_warn_reason_') === 0) {
            if (!hasPermission($db, $botId, $userId, 'warning')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای ویرایش و ثبت اخطارها را ندارید.");
                exit;
            }
            $targetUserId = str_replace('admin_waiting_warn_reason_', '', $step);
            FSM::clearStep($botId, $userId);

            $stmt = $db->prepare("UPDATE users SET warnings = warnings + 1 WHERE bot_id = :bot_id AND tg_id = :tg_id RETURNING warnings");
            $stmt->execute(['bot_id' => $botId, 'tg_id' => $targetUserId]);
            $newWarnCount = $stmt->fetch()['warnings'];

            $tg->sendMessage($targetUserId, "⚠️ <b>اخطار انضباطی جدید برای شما ثبت شد!</b>\n\n💬 <b>دلیل اخطار:</b>\n<i>{$text}</i>\n\nتعداد کل اخطارهای شما: <code>{$newWarnCount}</code>");
            $tg->sendMessage($userId, "✅ اخطار با موفقیت ثبت شد و پیام انضباطی برای کاربر فرستاده شد.");
            exit;
        }

        // ارسال پیام مستقیم (DM) به کاربر در بخش جزئیات عضو
        elseif (strpos($step, 'admin_waiting_dm_text_') === 0) {
            if (!hasPermission($db, $botId, $userId, 'warning') && !hasPermission($db, $botId, $userId, 'team')) {
                $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای فرستادن پیام مستقیم را ندارید.");
                exit;
            }
            $targetUserId = str_replace('admin_waiting_dm_text_', '', $step);
            FSM::clearStep($botId, $userId);

            $sent = $tg->sendMessage($targetUserId, "✉️ <b>پیام مستقیم مدیریت:</b>\n\n{$text}");
            if ($sent && isset($sent['ok']) && $sent['ok'] === true) {
                $tg->sendMessage($userId, "✅ پیام شما مستقیماً به پی‌وی کاربر تحویل داده شد.");
            } else {
                $tg->sendMessage($userId, "❌ ارسال ناموفق بود. کاربر احتمالاً ربات را بلاک کرده است.");
            }
            exit;
        }
    }

    // فرآیند بارگزاری فایل آزمون تمرینی جدید
    if ($step === 'admin_waiting_exam_file') {
        if (!hasPermission($db, $botId, $userId, 'exams')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت آزمون‌ها را ندارید.");
            exit;
        }
        $fileId   = null;
        $fileType = 'doc';

        if (isset($message['document'])) {
            $fileId   = $message['document']['file_id'];
            $fileType = 'doc';
        } elseif (isset($message['photo'])) {
            $fileId   = end($message['photo'])['file_id'];
            $fileType = 'photo';
        }
        
        if (!$fileId) {
            $tg->sendMessage($userId, "❌ فایل ارسالی معتبر نیست. لطفاً یک فایل سند یا تصویر بفرستید:");
            exit;
        }

        $prefixedFileId = $fileType . ":" . $fileId;

        $stmtRole = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = 'temp_exam_role' LIMIT 1");
        $stmtRole->execute(['bot_id' => $botId]);
        $roleRow = $stmtRole->fetch();
        $examRole = $roleRow ? $roleRow['value'] : 'translator';

        $stmtTemp = $db->prepare("
            INSERT INTO settings (bot_id, key, value) 
            VALUES (:bot_id, :key, :value)
            ON CONFLICT (bot_id, key) DO UPDATE SET value = EXCLUDED.value
        ");
        $stmtTemp->execute([
            'bot_id' => $botId,
            'key'    => "temp_exam_file_{$examRole}",
            'value'  => $prefixedFileId
        ]);

        FSM::setStep($botId, $userId, "admin_waiting_exam_title_{$examRole}");
        $tg->sendMessage($userId, "✍️ <b>فایل آزمون دریافت شد.</b>\n\nحالا یک عنوان مناسب برای این آزمون بنویسید و ارسال کنید:");
        exit;
    }

    // دریافت فایل تست استخدامی و ذخیره موقت فایل‌آیدی
    if (strpos($step, 'admin_waiting_rec_test_file_') === 0) {
        if (!hasPermission($db, $botId, $userId, 'recruit')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت عضوگیری را ندارید.");
            exit;
        }
        $recRole = str_replace('admin_waiting_rec_test_file_', '', $step);
        
        $fileId   = null;
        $fileType = 'doc';

        if (isset($message['document'])) {
            $fileId   = $message['document']['file_id'];
            $fileType = 'doc';
        } elseif (isset($message['photo'])) {
            $fileId   = end($message['photo'])['file_id'];
            $fileType = 'photo';
        }
        
        if (!$fileId) {
            $tg->sendMessage($userId, "❌ فایل ارسالی معتبر نیست. لطفاً یک فایل سند یا تصویر بفرستید:");
            exit;
        }

        $prefixedFileId = $fileType . ":" . $fileId;

        $stmtTemp = $db->prepare("
            INSERT INTO settings (bot_id, key, value) 
            VALUES (:bot_id, :key, :value)
            ON CONFLICT (bot_id, key) DO UPDATE SET value = EXCLUDED.value
        ");
        $stmtTemp->execute([
            'bot_id' => $botId,
            'key'    => "temp_rec_test_file_{$recRole}",
            'value'  => $prefixedFileId
        ]);

        FSM::setStep($botId, $userId, "admin_waiting_rec_test_rules_{$recRole}");
        $tg->sendMessage($userId, "✍️ <b>فایل تست استخدام دریافت شد.</b>\n\nحالا قوانین و توضیحات اختصاصی که می‌خواهید کاربر هنگام دریافت این تست مشاهده کند را بنویسید و بفرستید:");
        exit;
    }
}

// ==========================================
// فاز ۳: پردازش دکمه‌های شیشه‌ای کالبک‌کوئری ادمین
// ==========================================
if ($callbackQuery) {
    $callbackData = $callbackQuery['data'];
    $callbackId   = $callbackQuery['id'];
    $messageId    = $callbackQuery['message']['message_id'];
    $adminChatId  = $callbackQuery['message']['chat']['id'];

    // ۱. تایید نهایی بن کاربر (قرارگیری قبل از شرط عمومی usr_ban_ جهت رفع باگ تداخل دکمه‌ها)
    if (strpos($callbackData, 'admin_usr_confirmban_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'add_admin') && !hasPermission($db, $botId, $userId, 'team')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت اعضا را ندارید.");
            exit;
        }
        $targetUserId = str_replace('admin_usr_confirmban_', '', $callbackData);

        FSM::setStatus($botId, $targetUserId, 'banned');
        FSM::setRole($botId, $targetUserId, 'none');

        $stmtTeamGroup = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = 'team_group_id' LIMIT 1");
        $stmtTeamGroup->execute(['bot_id' => $botId]);
        $tgRow = $stmtTeamGroup->fetch();
        $teamGroupId = $tgRow ? $tgRow['value'] : null;

        $stmtGroups = $db->prepare("SELECT DISTINCT group_id FROM manhwas WHERE bot_id = :bot_id AND group_id IS NOT NULL");
        $stmtGroups->execute(['bot_id' => $botId]);
        $groups = $stmtGroups->fetchAll();

        if ($teamGroupId) {
            $tg->apiRequest('banChatMember', ['chat_id' => $teamGroupId, 'user_id' => $targetUserId]);
        }
        foreach ($groups as $g) {
            $tg->apiRequest('banChatMember', ['chat_id' => $g['group_id'], 'user_id' => $targetUserId]);
        }

        $tg->sendMessage($userId, "✅ کاربر از تمام گروه‌ها و ربات با موفقیت بن و مسدود گردید.");
        exit;
    }

    // ۲. نمایش تاییدیه قبل بن کاربر از تمام گروه‌ها
    elseif (strpos($callbackData, 'admin_usr_ban_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'add_admin') && !hasPermission($db, $botId, $userId, 'team')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت اعضا را ندارید.");
            exit;
        }
        $targetUserId = str_replace('admin_usr_ban_', '', $callbackData);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ بله، بن شود', 'callback_data' => "admin_usr_confirmban_{$targetUserId}"],
                    ['text' => '❌ لغو عملیات', 'callback_data' => "admin_user_v_{$targetUserId}"]
                ]
            ]
        ];

        $tg->sendMessage($userId, "⚠️ <b>آیا مطمئن هستید که می‌خواهید کاربر را اخراج و بن کنید؟</b>\n\nپس از تایید، کاربر از تمام گروه‌های مانهوا و گروه اصلی تیم اخراج و بن خواهد شد و دسترسی او به ربات قطع می‌گردد.", $keyboard);
        exit;
    }

    // کالبک دکمه افزودن دستی عضو - نمایش زیرمنو
    elseif ($callbackData === 'admin_manual_recruit_menu') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'recruit')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت عضوگیری را ندارید.");
            exit;
        }
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔍 جستجو و افزودن تک عضو', 'callback_data' => 'admin_manual_search']],
                [['text' => '📋 جدول ثبت گروهی اعضا', 'callback_data' => 'admin_manual_table']],
                [['text' => '🔙 بازگشت به عضوگیری', 'callback_data' => 'admin_recruit']]
            ]
        ];
        $tg->sendMessage($userId, "⚙️ <b>بخش وارد کردن دستی اعضا به تیم:</b>\n\nیکی از شیوه‌های زیر را برای عضوگیری مستقیم پرسنل انتخاب کنید:", $keyboard);
        exit;
    }

    // کالبک شروع جستجوی دستی عضو
    elseif ($callbackData === 'admin_manual_search') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'recruit')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت عضوگیری را ندارید.");
            exit;
        }
        FSM::setStep($botId, $userId, 'admin_waiting_manual_search');
        $tg->sendMessage($userId, "👤 <b>لطفاً آیدی عددی تلگرام یا یوزرنیم کاربر مورد نظر را ارسال کنید:</b>\n\nکاربر ابتدا باید ربات را استارت کرده باشد.", [
            'inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'admin_manual_recruit_menu']]]
        ]);
        exit;
    }

    // کالبک شروع ثبت گروهی اعضا
    elseif ($callbackData === 'admin_manual_table') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'recruit')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت عضوگیری را ندارید.");
            exit;
        }
        FSM::setStep($botId, $userId, 'admin_waiting_manual_table_input');
        $formatMsg = "📋 <b>جدول ثبت دستی پرسنل به صورت گروهی:</b>\n\n"
                   . "لطفاً لیست کاربران را دقیقاً بر اساس الگو و کاراکتر <code>|</code> در یک پیام بنویسید و بفرستید:\n\n"
                   . "<code>آیدی یا یوزرنیم|شغل</code>\n\n"
                   . "💡 <b>مثال:</b>\n"
                   . "<code>123456789|مترجم</code>\n"
                   . "<code>@some_username|کلینر</code>\n"
                   . "<code>@typesetter_username|تایپیست</code>\n\n"
                   . "⚠️ نام شغل‌ها فقط می‌تواند شامل موارد زیر باشد:\n"
                   . "«مترجم»، «کلینر»، «تایپیست»";
        $tg->sendMessage($userId, $formatMsg, [
            'inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'admin_manual_recruit_menu']]]
        ]);
        exit;
    }

    // کالبک ثبت نهایی نقش دستی کاربر در جستجو
    elseif (strpos($callbackData, 'admin_set_man_role_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'recruit')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت عضوگیری را ندارید.");
            exit;
        }
        $data = str_replace('admin_set_man_role_', '', $callbackData);
        $parts = explode('_', $data);
        $targetId = $parts[0];
        $roleToSet = $parts[1];

        FSM::setRole($botId, $targetId, $roleToSet);
        FSM::setStatus($botId, $targetId, 'approved');

        $roleFarsi = getRoleFarsiAdmin($roleToSet);
        $tg->sendMessage($targetId, "🎉 <b>تبریک می‌گویم! شما توسط مدیریت مستقیماً به عنوان عضو رسمی با سمت «{$roleFarsi}» به تیم اضافه شدید.</b>\n\nدستور <code>/start</code> را ارسال کنید تا پنل کاربری شما فعال شود.");
        $tg->sendMessage($userId, "✅ نقش کاربر با موفقیت روی <b>{$roleFarsi}</b> قرار گرفت و عضویت او فعال شد.", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_recruit']]]
        ]);
        exit;
    }

    // بازگشت به منوی ادمین و لغو عمومی از طریق دکمه شیشه‌ای
    elseif ($callbackData === 'admin_back_to_menu' || $callbackData === 'admin_cancel') {
        $tg->answerCallbackQuery($callbackId, "انجام شد.");
        FSM::clearStep($botId, $userId);

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📚 لیست کارها (پروژه‌ها)', 'callback_data' => 'admin_projects_page_1']],
                [['text' => '👥 مدیریت عضوگیری', 'callback_data' => 'admin_recruit']],
                [['text' => '⚙️ تنظیمات تیم', 'callback_data' => 'admin_settings']]
            ]
        ];
        
        $tg->sendMessage($userId, "👋 به پنل مدیریت تیم خوش آمدید. بخش مورد نظر را انتخاب کنید:", $keyboard);
        exit;
    }

    // ورود به بخش لیست کارها با صفحه‌بندی ۱۰تایی و سرچ
    elseif (strpos($callbackData, 'admin_projects_page_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'manhwa')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت مانهواها را ندارید.");
            exit;
        }
        $page = (int)str_replace('admin_projects_page_', '', $callbackData);
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $stmtCount = $db->prepare("SELECT COUNT(*) as total FROM manhwas WHERE bot_id = :bot_id");
        $stmtCount->execute(['bot_id' => $botId]);
        $total = $stmtCount->fetch()['total'];
        $totalPages = ceil($total / $limit);

        $stmt = $db->prepare("SELECT id, title FROM manhwas WHERE bot_id = :bot_id ORDER BY id DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':bot_id', $botId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $manhwas = $stmt->fetchAll();

        $textProj = "📚 <b>لیست مانهواهای ثبت شده (صفحه {$page} از {$totalPages}):</b>\n\nبرای دیدن جزییات و ویرایش تیم مانهواها کلیک کنید:";
        $buttons = [];
        $buttons[] = [['text' => '🔍 جستجوی مانهوا', 'callback_data' => 'admin_project_search_init']];

        foreach ($manhwas as $m) {
            $buttons[] = [['text' => "📚 " . $m['title'], 'callback_data' => "admin_view_manhwa_{$m['id']}"]];
        }

        $navButtons = [];
        if ($page > 1) {
            $navButtons[] = ['text' => '◀️ قبلی', 'callback_data' => 'admin_projects_page_' . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navButtons[] = ['text' => 'بعدی ▶️', 'callback_data' => 'admin_projects_page_' . ($page + 1)];
        }
        if (!empty($navButtons)) {
            $buttons[] = $navButtons;
        }

        $buttons[] = [['text' => '🔙 بازگشت', 'callback_data' => 'admin_back_to_menu']];

        $tg->sendMessage($userId, $textProj, ['inline_keyboard' => $buttons]);
        exit;
    }

    // شروع فرآیند جستجوی مانهوا
    elseif ($callbackData === 'admin_project_search_init') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'manhwa')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت مانهواها را ندارید.");
            exit;
        }
        FSM::setStep($botId, $userId, 'admin_waiting_project_search');
        $tg->sendMessage($userId, "🔍 نام یا بخشی از عنوان مانهوای مورد نظر را ارسال کنید:", [
            'inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'admin_projects_page_1']]]
        ]);
        exit;
    }

    // جزئیات مانهوا به همراه تیم متصل به آن و قیمت اختصاصی
    elseif (strpos($callbackData, 'admin_view_manhwa_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'manhwa')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت مانهواها را ندارید.");
            exit;
        }
        $manhwaId = (int)str_replace('admin_view_manhwa_', '', $callbackData);

        $stmt = $db->prepare("SELECT * FROM manhwas WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $manhwaId]);
        $manhwa = $stmt->fetch();

        if ($manhwa) {
            $stmtTeam = $db->prepare("
                SELECT ta.role, u.full_name, u.tg_id 
                FROM team_assignments ta
                JOIN users u ON ta.bot_id = u.bot_id AND ta.user_id = u.tg_id
                WHERE ta.bot_id = :bot_id AND ta.manhwa_id = :manhwa_id
            ");
            $stmtTeam->execute(['bot_id' => $botId, 'manhwa_id' => $manhwaId]);
            $teamMembers = $stmtTeam->fetchAll();

            $staffList = [
                'translator' => [],
                'cleaner'    => [],
                'typesetter' => []
            ];
            foreach ($teamMembers as $tm) {
                $staffList[$tm['role']][] = "👤 " . $tm['full_name'] . " (<code>" . $tm['tg_id'] . "</code>)";
            }

            $caption = "📚 <b>جزئیات مانهوا: {$manhwa['title']}</b>\n"
                     . "🎭 ژانرها: {$manhwa['genres']}\n"
                     . "🔢 آخرین چپتر: <code>{$manhwa['last_chapter']}</code>\n"
                     . "👥 آیدی گروه: <code>" . ($manhwa['group_id'] ?? 'ثبت نشده') . "</code>\n\n"
                     . "⚔️ <b>اعضای تیم متصل شده:</b>\n"
                     . "├ مترجمین: " . (empty($staffList['translator']) ? "❌ بدون انتساب" : implode('، ', $staffList['translator'])) . "\n"
                     . "├ کلینرها: " . (empty($staffList['cleaner']) ? "❌ بدون انتساب" : implode('، ', $staffList['cleaner'])) . "\n"
                     . "└ تایپیست‌ها: " . (empty($staffList['typesetter']) ? "❌ بدون انتساب" : implode('، ', $staffList['typesetter'])) . "\n\n"
                     . "⚙️ برای مدیریت اعضا، مبالغ اختصاصی و چپترهای این پروژه از دکمه‌های زیر استفاده کنید:";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '➕ انتساب مترجم', 'callback_data' => "admin_assign_{$manhwaId}_translator"],
                        ['text' => '❌ عزل مترجمین', 'callback_data' => "admin_dismiss_list_{$manhwaId}_translator"]
                    ],
                    [
                        ['text' => '➕ انتساب کلینر', 'callback_data' => "admin_assign_{$manhwaId}_cleaner"],
                        ['text' => '❌ عزل کلینرها', 'callback_data' => "admin_dismiss_list_{$manhwaId}_cleaner"]
                    ],
                    [
                        ['text' => '➕ انتساب تایپیست', 'callback_data' => "admin_assign_{$manhwaId}_typesetter"],
                        ['text' => '❌ عزل تایپیست‌ها', 'callback_data' => "admin_dismiss_list_{$manhwaId}_typesetter"]
                    ],
                    [
                        ['text' => '⚙️ قیمت اختصاصی این کار', 'callback_data' => "admin_m_set_{$manhwaId}"],
                        ['text' => '📂 مدیریت چپترها', 'callback_data' => "admin_manage_ch_{$manhwaId}_1"]
                    ],
                    [['text' => '🔙 بازگشت به لیست مانهواها', 'callback_data' => 'admin_projects_page_1']]
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

    // نمایش لیست اعضا جهت عزل مانهوا برای حمایت از انتساب چندگانه
    elseif (strpos($callbackData, 'admin_dismiss_list_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'team')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت اعضا را ندارید.");
            exit;
        }
        $params = str_replace('admin_dismiss_list_', '', $callbackData);
        $parts  = explode('_', $params);
        $manhwaId = (int)$parts[0];
        $role     = $parts[1];

        $stmt = $db->prepare("
            SELECT ta.user_id, u.full_name 
            FROM team_assignments ta
            JOIN users u ON ta.user_id = u.tg_id AND ta.bot_id = u.bot_id
            WHERE ta.bot_id = :bot_id AND ta.manhwa_id = :m_id AND ta.role = :role
        ");
        $stmt->execute(['bot_id' => $botId, 'm_id' => $manhwaId, 'role' => $role]);
        $assignedUsers = $stmt->fetchAll();

        if (empty($assignedUsers)) {
            $tg->sendMessage($userId, "⚠️ هیچ کاربری با نقش " . getRoleFarsiAdmin($role) . " به این پروژه متصل نیست.");
            exit;
        }

        $buttons = [];
        foreach ($assignedUsers as $au) {
            $buttons[] = [['text' => "❌ عزل {$au['full_name']}", 'callback_data' => "admin_dismiss_{$manhwaId}_{$role}_{$au['user_id']}"]];
        }
        $buttons[] = [['text' => '🔙 بازگشت', 'callback_data' => "admin_view_manhwa_{$manhwaId}"]];

        $tg->sendMessage($userId, "👤 یکی از اعضای منتسب به نقش <b>" . getRoleFarsiAdmin($role) . "</b> را جهت عزل و حذف از این پروژه انتخاب کنید:", ['inline_keyboard' => $buttons]);
        exit;
    }

    // عزل قطعی یک عضو خاص از نقش پروژه مانهوا
    elseif (strpos($callbackData, 'admin_dismiss_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $params = str_replace('admin_dismiss_', '', $callbackData);
        $parts  = explode('_', $params);
        $mId    = (int)$parts[0];
        $role   = $parts[1];
        $targetId = $parts[2];

        if (!hasPermission($db, $botId, $userId, 'team')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت اعضای پروژه را ندارید.");
            exit;
        }

        $stmt = $db->prepare("DELETE FROM team_assignments WHERE bot_id = :bot_id AND manhwa_id = :manhwa_id AND role = :role AND user_id = :user_id");
        $stmt->execute(['bot_id' => $botId, 'manhwa_id' => $mId, 'role' => $role, 'user_id' => $targetId]);

        $tg->sendMessage($userId, "✅ عزل عضو با موفقیت انجام شد.", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت به مانهوا', 'callback_data' => "admin_view_manhwa_{$mId}"]]]
        ]);
        exit;
    }

    // آغاز فرآیند انتساب مستقیم یک عضو به مانهوا
    elseif (strpos($callbackData, 'admin_assign_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $params = str_replace('admin_assign_', '', $callbackData);
        
        if (!hasPermission($db, $botId, $userId, 'team')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای انتساب اعضای پروژه را ندارید.");
            exit;
        }

        FSM::setStep($botId, $userId, "admin_waiting_assign_{$params}");

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ لغو عملیات', 'callback_data' => 'admin_cancel']]
            ]
        ];

        $tg->sendMessage($userId, "👤 <b>لطفاً شناسه عضو جدید را ارسال کنید:</b>\n\nمی‌توانید آیدی عددی تلگرام کاربر را بفرستید، یا آیدی کاربری او را (با علامت @) ارسال کنید:", $keyboard);
        exit;
    }

    // ورود به بخش مدیریت عضوگیری
    elseif ($callbackData === 'admin_recruit') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'recruit')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت عضوگیری را ندارید.");
            exit;
        }

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📂 آخرین تست‌های حل شده', 'callback_data' => 'admin_view_tests']],
                [['text' => '➕ وارد کردن دستی عضو', 'callback_data' => 'admin_manual_recruit_menu']],
                [['text' => '📤 آپلود تست استخدامی جدید', 'callback_data' => 'admin_upload_rec_test']],
                [['text' => '⚙️ تغییر شرایط و قوانین استخدام', 'callback_data' => 'admin_edit_rules']],
                [['text' => '🔙 بازگشت به منوی ادمین', 'callback_data' => 'admin_back_to_menu']]
            ]
        ];
        
        $tg->sendMessage($userId, "👥 بخش استخدام و عضوگیری مانهوا:", $keyboard);
        exit;
    }

    // شروع آپلود تست استخدامی
    elseif ($callbackData === 'admin_upload_rec_test') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'recruit')) {
            $tg->sendMessage($userId, "⚠️ شما دسترسی لازم را ندارید.");
            exit;
        }
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📝 تست استخدام مترجم', 'callback_data' => 'admin_set_rec_test_translator'],
                    ['text' => '🖌 تست استخدام کلینر', 'callback_data' => 'admin_set_rec_test_cleaner']
                ],
                [
                    ['text' => '⌨️ تست استخدام تایپیست', 'callback_data' => 'admin_set_rec_test_typesetter']
                ],
                [['text' => '🔙 بازگشت', 'callback_data' => 'admin_recruit']]
            ]
        ];
        $tg->sendMessage($userId, "📥 <b>آپلود تست استخدامی جدید:</b>\n\nمشخص کنید این فایل تست استخدام مربوط به کدام نقش است:", $keyboard);
        exit;
    }

    // کالبک شروع فرآیند فایل تست استخدام
    elseif (strpos($callbackData, 'admin_set_rec_test_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'recruit')) {
            $tg->sendMessage($userId, "⚠️ شما دسترسی لازم را ندارید.");
            exit;
        }
        $recRole = str_replace('admin_set_rec_test_', '', $callbackData);
        FSM::setStep($botId, $userId, "admin_waiting_rec_test_file_{$recRole}");

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ لغو عملیات', 'callback_data' => 'admin_cancel']]
            ]
        ];

        $tg->sendMessage($userId, "📥 <b>لطفاً فایل تست استخدام برای نقش " . getRoleFarsiAdmin($recRole) . " را بفرستید:</b>", $keyboard);
        exit;
    }

    // تغییر مرحله FSM برای تغییر قوانین استخدام
    elseif ($callbackData === 'admin_edit_rules') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'recruit')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای تغییر قوانین استخدام را ندارید.");
            exit;
        }
        FSM::setStep($botId, $userId, 'admin_waiting_rules');

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ لغو عملیات', 'callback_data' => 'admin_cancel']]
            ]
        ];

        $tg->sendMessage($userId, "✍️ <b>لطفاً قوانین و شرایط جدید مانهوا را به صورت یک پیام بنویسید و بفرستید:</b>", $keyboard);
        exit;
    }

    // مشاهده ۱۰ تست حل شده بررسی نشده آخر
    elseif ($callbackData === 'admin_view_tests') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'recruit')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای بررسی تست‌ها را ندارید.");
            exit;
        }

        $stmt = $db->prepare("
            SELECT st.*, u.full_name, u.username 
            FROM submitted_tests st
            JOIN users u ON st.bot_id = u.bot_id AND st.user_id = u.tg_id
            WHERE st.bot_id = :bot_id AND st.status = 'pending'
            ORDER BY st.created_at DESC LIMIT 10
        ");
        $stmt->execute(['bot_id' => $botId]);
        $tests = $stmt->fetchAll();

        if (empty($tests)) {
            $tg->sendMessage($userId, "⚠️ هیچ پاسخ تست بررسی نشده‌ای وجود ندارد.");
        } else {
            foreach ($tests as $t) {
                $roleFarsi = getRoleFarsiAdmin($t['role']);
                $msgText = "👤 کاربر: {$t['full_name']} (@{$t['username']})\n"
                         . "🆔 آیدی عددی: <code>{$t['user_id']}</code>\n"
                         . "⚔️ نقش انتخابی: <b>{$roleFarsi}</b>\n\n"
                         . "⚙️ گزینه مورد نظر جهت پردازش استخدام را لمس کنید:";

                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '🔍 چک کردن فایل تست', 'callback_data' => "admin_check_test_{$t['id']}"],
                            ['text' => '✉️ فرستادن پیام مستقیم', 'callback_data' => "admin_msg_{$t['user_id']}"]
                        ],
                        [
                            ['text' => '✅ قبول کردن', 'callback_data' => "admin_accept_test_{$t['id']}"],
                            ['text' => '❌ رد کردن', 'callback_data' => "admin_reject_test_{$t['id']}"]
                        ]
                    ]
                ];
                $tg->sendMessage($userId, $msgText, $keyboard);
            }
        }
        exit;
    }

    // دریافت فایل حل شده تست داوطلب
    elseif (strpos($callbackData, 'admin_check_test_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'recruit')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای بررسی تست‌ها را ندارید.");
            exit;
        }
        $testId = (int)str_replace('admin_check_test_', '', $callbackData);

        $stmt = $db->prepare("SELECT file_id, role FROM submitted_tests WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $testId]);
        $testFile = $stmt->fetch();

        if ($testFile) {
            $rawFileId = $testFile['file_id'];
            $fileType  = 'doc';
            $cleanFileId = $rawFileId;

            if (strpos($rawFileId, ':') !== false) {
                $parts = explode(':', $rawFileId, 2);
                $fileType    = $parts[0];
                $cleanFileId = $parts[1];
            }

            $caption = "📄 فایل تست حل شده داوطلب برای نقش <b>" . getRoleFarsiAdmin($testFile['role']) . "</b>";

            if ($fileType === 'photo') {
                $tg->sendPhoto($userId, $cleanFileId, $caption);
            } else {
                $tg->sendDocument($userId, $cleanFileId, $caption);
            }
        } else {
            $tg->sendMessage($userId, "❌ فایل یافت نشد.");
        }
        exit;
    }

    // باز شدن پیام مستقیم ادمین به داوطلب
    elseif (strpos($callbackData, 'admin_msg_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'recruit') && !hasPermission($db, $botId, $userId, 'team')) {
            $tg->sendMessage($userId, "⚠️ شما دسترسی لازم را ندارید.");
            exit;
        }
        $targetId = str_replace('admin_msg_', '', $callbackData);
        FSM::setStep($botId, $userId, "admin_send_msg_{$targetId}");

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ لغو عملیات', 'callback_data' => 'admin_cancel']]
            ]
        ];

        $tg->sendMessage($userId, "✍️ <b>لطفاً پیام خود را خطاب به داوطلب استخدام تایپ کنید و بفرستید:</b>", $keyboard);
        exit;
    }

    // رد کردن تست داوطلب
    elseif (strpos($callbackData, 'admin_reject_test_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'recruit')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت عضوگیری را ندارید.");
            exit;
        }
        $testId = (int)str_replace('admin_reject_test_', '', $callbackData);

        $stmt = $db->prepare("SELECT user_id, role FROM submitted_tests WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $testId]);
        $test = $stmt->fetch();

        if ($test) {
            $stmtUpdateTest = $db->prepare("UPDATE submitted_tests SET status = 'rejected' WHERE bot_id = :bot_id AND id = :id");
            $stmtUpdateTest->execute(['bot_id' => $botId, 'id' => $testId]);

            FSM::setStatus($botId, $test['user_id'], 'rejected');

            $tg->sendMessage($test['user_id'], "❌ <b>درخواست عضویت شما رد شد.</b>\n\nمتاسفانه تست شما برای نقش <b>" . getRoleFarsiAdmin($test['role']) . "</b> مورد قبول قرار نگرفت.");
            $tg->sendMessage($userId, "❌ درخواست استخدام کاربر رد شد.");
        }
        exit;
    }

    // تایید استخدام و ارسال لینک دعوت گروه اصلی
    elseif (strpos($callbackData, 'admin_accept_test_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'recruit')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت عضوگیری را ندارید.");
            exit;
        }
        $testId = (int)str_replace('admin_accept_test_', '', $callbackData);

        $stmt = $db->prepare("SELECT user_id, role FROM submitted_tests WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $testId]);
        $test = $stmt->fetch();

        if ($test) {
            $stmtGroup = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = 'team_group_id' LIMIT 1");
            $stmtGroup->execute(['bot_id' => $botId]);
            $groupRow = $stmtGroup->fetch();
            $teamGroupId = $groupRow ? $groupRow['value'] : null;

            if (empty($teamGroupId)) {
                $tg->sendMessage($userId, "⚠️ <b>کاربر تایید شد، اما لینک دعوت ارسال نشد!</b>\n\nابتدا باید آیدی گروه را در [تنظیمات تیم -> ثبت گروه اصلی] وارد کنید.");
                
                FSM::setRole($botId, $test['user_id'], $test['role']);
                FSM::setStatus($botId, $test['user_id'], 'approved');
                exit;
            }

            $inviteLink = $tg->createChatInviteLink($teamGroupId, 86400);

            if ($inviteLink) {
                $stmtUpdateTest = $db->prepare("UPDATE submitted_tests SET status = 'accepted' WHERE bot_id = :bot_id AND id = :id");
                $stmtUpdateTest->execute(['bot_id' => $botId, 'id' => $testId]);

                FSM::setRole($botId, $test['user_id'], $test['role']);
                FSM::setStatus($botId, $test['user_id'], 'approved');

                $roleNameFarsi = getRoleFarsiAdmin($test['role']);
                $congratsText = "🎉 <b>تبریک می‌گویم! شما در آزمون عضوگیری تیم پذیرفته شدید.</b>\n\n"
                              . "⚔️ نقش تایید شده شما: <b>{$roleNameFarsi}</b>\n\n"
                              . "🔗 لینک ۲۴ ساعته گروه کار:\n\n"
                              . "👉 {$inviteLink}";

                $tg->sendMessage($test['user_id'], $congratsText);
                $tg->sendMessage($userId, "✅ <b>کاربر با موفقیت استخدام شد!</b>\n\nنقش کاربر روی <b>{$roleNameFarsi}</b> قرار گرفت و لینک دعوت ارسال شد.");
            } else {
                $tg->sendMessage($userId, "❌ خطا: ربات نتوانست لینک دعوت یک‌بار مصرف بسازد.");
            }
        }
        exit;
    }

    // ورود به بخش تنظیمات تیم مانهوا
    elseif ($callbackData === 'admin_settings') {
        $tg->answerCallbackQuery($callbackId);
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💸 تنظیم میزان حقوق‌ها', 'callback_data' => 'admin_salary_rates'],
                    ['text' => '🏆 پرکارترین اعضای ماه', 'callback_data' => 'admin_most_active']
                ],
                [
                    ['text' => '👥 لیست کامل اعضای تیم', 'callback_data' => 'admin_team_list_1'],
                    ['text' => '📊 اطلاعات و آمار مانهوا', 'callback_data' => 'admin_team_info']
                ],
                [
                    ['text' => '📢 فرستادن پیام همگانی', 'callback_data' => 'admin_broadcast'],
                    ['text' => '🛡️ اضافه کردن ادمین', 'callback_data' => 'admin_add_admin']
                ],
                [
                    ['text' => '🔗 ثبت گروه اصلی', 'callback_data' => 'admin_set_team_group'],
                    ['text' => '⚙️ تنظیمات عمومی', 'callback_data' => 'admin_general_settings']
                ],
                [
                    ['text' => '🏆 مدیریت آزمون‌های تمرینی', 'callback_data' => 'admin_manage_exams_page_1'],
                    ['text' => '✉️ تیکت‌های پشتیبانی', 'callback_data' => 'admin_tickets_page_1']
                ],
                [['text' => '🔙 بازگشت به منوی ادمین', 'callback_data' => 'admin_back_to_menu']]
            ]
        ];
        $tg->sendMessage($userId, "⚙️ <b>بخش تنظیمات تیم و پیکربندی حقوق‌ها:</b>", $keyboard);
        exit;
    }

    // منوی تنظیمات عمومی
    elseif ($callbackData === 'admin_general_settings') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'settings')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت تنظیمات عمومی را ندارید.");
            exit;
        }
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '⏳ تنظیم روزهای اخطار عدم فعالیت', 'callback_data' => 'admin_set_warning_days']],
                [['text' => '🔙 بازگشت به تنظیمات', 'callback_data' => 'admin_settings']]
            ]
        ];
        $tg->sendMessage($userId, "⚙️ <b>بخش تنظیمات عمومی تیم:</b>", $keyboard);
        exit;
    }

    // آغاز فرآیند تغییر روزهای اخطار رکود
    elseif ($callbackData === 'admin_set_warning_days') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'warning')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای تغییر روزهای هشدار عدم فعالیت را ندارید.");
            exit;
        }
        FSM::setStep($botId, $userId, 'admin_waiting_warning_days');

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ لغو عملیات', 'callback_data' => 'admin_cancel']]
            ]
        ];

        $tg->sendMessage($userId, "✍️ <b>تعداد روزهای راکد ماندن پروژه را وارد کنید:</b>", $keyboard);
        exit;
    }

    // بخش مدیریت آزمون‌های تمرینی
    elseif (strpos($callbackData, 'admin_manage_exams_page_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'exams')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت آزمون‌های تمرینی را ندارید.");
            exit;
        }

        $page = (int)str_replace('admin_manage_exams_page_', '', $callbackData);
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $stmtCount = $db->prepare("SELECT COUNT(*) as total FROM practice_exams WHERE bot_id = :bot_id");
        $stmtCount->execute(['bot_id' => $botId]);
        $total = $stmtCount->fetch()['total'];
        $totalPages = ceil($total / $limit);

        $stmt = $db->prepare("SELECT id, role, title FROM practice_exams WHERE bot_id = :bot_id ORDER BY id DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':bot_id', $botId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $exams = $stmt->fetchAll();

        $textEx = "🏆 <b>بخش مدیریت آزمون‌های تمرینی (صفحه {$page} از {$totalPages}):</b>\n\nبرای اضافه کردن، حذف یا تغییر فایل آزمون‌ها اقدام کنید:";
        $buttons = [];
        $buttons[] = [['text' => '➕ ثبت آزمون تمرینی جدید', 'callback_data' => 'admin_add_practice_exam']];

        foreach ($exams as $ex) {
            $roleFarsi = getRoleFarsiAdmin($ex['role']);
            $buttons[] = [
                ['text' => "📝 [{$roleFarsi}] {$ex['title']}", 'callback_data' => "admin_ex_detail_{$ex['id']}"],
                ['text' => '🔄 تغییر فایل', 'callback_data' => "admin_ex_edit_{$ex['id']}"],
                ['text' => '🗑 حذف', 'callback_data' => "admin_ex_del_{$ex['id']}"]
            ];
        }

        $navButtons = [];
        if ($page > 1) {
            $navButtons[] = ['text' => '◀️ قبلی', 'callback_data' => 'admin_manage_exams_page_' . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navButtons[] = ['text' => 'بعدی ▶️', 'callback_data' => 'admin_manage_exams_page_' . ($page + 1)];
        }
        if (!empty($navButtons)) {
            $buttons[] = $navButtons;
        }

        $buttons[] = [['text' => '🔙 بازگشت به تنظیمات', 'callback_data' => 'admin_settings']];

        $tg->sendMessage($userId, $textEx, ['inline_keyboard' => $buttons]);
        exit;
    }

    // حذف آزمون تمرینی
    elseif (strpos($callbackData, 'admin_ex_del_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'exams')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای حذف آزمون‌ها را ندارید.");
            exit;
        }
        $examId = (int)str_replace('admin_ex_del_', '', $callbackData);

        $stmt = $db->prepare("DELETE FROM practice_exams WHERE bot_id = :bot_id AND id = :id");
        $stmt->execute(['bot_id' => $botId, 'id' => $examId]);

        $tg->sendMessage($userId, "✅ آزمون تمرینی با موفقیت حذف شد.", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_manage_exams_page_1']]]
        ]);
        exit;
    }

    // تغییر فایل آزمون تمرینی
    elseif (strpos($callbackData, 'admin_ex_edit_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'exams')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای تغییر فایل آزمون‌ها را ندارید.");
            exit;
        }
        $examId = (int)str_replace('admin_ex_edit_', '', $callbackData);

        FSM::setStep($botId, $userId, "admin_waiting_exam_file");
        $stmt = $db->prepare("INSERT INTO settings (bot_id, key, value) VALUES (:bot_id, 'temp_exam_role', (SELECT role FROM practice_exams WHERE id = :id)) ON CONFLICT (bot_id, key) DO UPDATE SET value = EXCLUDED.value");
        $stmt->execute(['bot_id' => $botId, 'id' => $examId]);

        $tg->sendMessage($userId, "📥 لطفا فایل یا تصویر جدید آزمون را ارسال کنید تا با فایل قبلی جایگزین شود:");
        exit;
    }

    // ثبت آزمون تمرینی جدید (انتخاب نقش)
    elseif ($callbackData === 'admin_add_practice_exam') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'exams')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت آزمون‌ها را ندارید.");
            exit;
        }
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📝 آزمون مترجم', 'callback_data' => 'admin_select_exam_role_translator'],
                    ['text' => '🖌 آزمون کلینر', 'callback_data' => 'admin_select_exam_role_cleaner']
                ],
                [
                    ['text' => '⌨️ آزمون تایپیست', 'callback_data' => 'admin_select_exam_role_typesetter']
                ],
                [['text' => '🔙 بازگشت', 'callback_data' => 'admin_manage_exams_page_1']]
            ]
        ];
        $tg->sendMessage($userId, "🏆 <b>انتخاب نقش آزمون:</b>\n\nلطفاً مشخص کنید این آزمون تمرینی برای کدام نقش ایجاد می‌شود:", $keyboard);
        exit;
    }

    // دریافت فایل آزمون تمرینی جدید
    elseif (strpos($callbackData, 'admin_select_exam_role_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'exams')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت آزمون‌ها را ندارید.");
            exit;
        }
        $examRole = str_replace('admin_select_exam_role_', '', $callbackData);
        
        $stmt = $db->prepare("INSERT INTO settings (bot_id, key, value) VALUES (:bot_id, 'temp_exam_role', :value) ON CONFLICT (bot_id, key) DO UPDATE SET value = EXCLUDED.value");
        $stmt->execute(['bot_id' => $botId, 'value' => $examRole]);

        FSM::setStep($botId, $userId, 'admin_waiting_exam_file');

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ لغو عملیات', 'callback_data' => 'admin_cancel']]
            ]
        ];

        $tg->sendMessage($userId, "📥 <b>لطفاً فایل یا تصویر آزمون تمرینی را ارسال کنید:</b>", $keyboard);
        exit;
    }

    // تیکت‌های پشتیبانی با ورق‌زدن ۱۰تایی
    elseif (strpos($callbackData, 'admin_tickets_page_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'tickets')) {
            $tg->sendMessage($userId, "⚠️ شما دسترسی به بخش مدیریت تیکت‌ها را ندارید.");
            exit;
        }

        $page = (int)str_replace('admin_tickets_page_', '', $callbackData);
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $stmtCount = $db->prepare("SELECT COUNT(*) as total FROM tickets WHERE bot_id = :bot_id");
        $stmtCount->execute(['bot_id' => $botId]);
        $total = $stmtCount->fetch()['total'];
        $totalPages = ceil($total / $limit);

        $stmt = $db->prepare("SELECT id, user_id, subject, status FROM tickets WHERE bot_id = :bot_id ORDER BY id DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':bot_id', $botId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $tickets = $stmt->fetchAll();

        $ticketText = "✉️ <b>بخش مدیریت تیکت‌های اعضا (صفحه {$page} از {$totalPages}):</b>\n\nبرای پاسخ و مشاهده جزئیات تیکت روی دکمه مشاهده بزنید:";
        $buttons = [];

        foreach ($tickets as $t) {
            $statusIcon = $t['status'] === 'closed' ? '✅' : '⏳';
            $preview = mb_substr($t['subject'], 0, 15) . '...';
            $buttons[] = [
                ['text' => "{$statusIcon} تیکت #{$t['id']} | {$preview}", 'callback_data' => "admin_ticket_view_{$t['id']}"],
                ['text' => '👁 مشاهده', 'callback_data' => "admin_ticket_view_{$t['id']}"]
            ];
        }

        $navButtons = [];
        if ($page > 1) {
            $navButtons[] = ['text' => '◀️ قبلی', 'callback_data' => 'admin_tickets_page_' . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navButtons[] = ['text' => 'بعدی ▶️', 'callback_data' => 'admin_tickets_page_' . ($page + 1)];
        }
        if (!empty($navButtons)) {
            $buttons[] = $navButtons;
        }

        $buttons[] = [['text' => '🔙 بازگشت به تنظیمات', 'callback_data' => 'admin_settings']];

        $tg->sendMessage($userId, $ticketText, ['inline_keyboard' => $buttons]);
        exit;
    }

    // مشاهده تک تیکت
    elseif (strpos($callbackData, 'admin_ticket_view_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'tickets')) {
            $tg->sendMessage($userId, "⚠️ شما دسترسی به بخش مدیریت تیکت‌ها را ندارید.");
            exit;
        }
        $ticketId = (int)str_replace('admin_ticket_view_', '', $callbackData);

        $stmt = $db->prepare("SELECT t.*, u.full_name, u.username FROM tickets t JOIN users u ON t.user_id = u.tg_id WHERE t.bot_id = :bot_id AND t.id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $ticketId]);
        $t = $stmt->fetch();

        if ($t) {
            $statusStr = $t['status'] === 'closed' ? '✅ پاسخ داده شده و بسته شده' : '⏳ باز (در انتظار پاسخ ادمین)';
            $text = "✉️ <b>تیکت پشتیبانی #{$t['id']}</b>\n\n"
                  . "👤 کاربر: <b>{$t['full_name']}</b> (@{$t['username']})\n"
                  . "📌 وضعیت: {$statusStr}\n"
                  . "📅 تاریخ ثبت: <code>{$t['created_at']}</code>\n\n"
                  . "📝 <b>متن تیکت:</b>\n<i>{$t['subject']}</i>";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '💬 پاسخ به تیکت', 'callback_data' => "admin_ticket_reply_{$ticketId}"],
                        ['text' => '🔒 بستن تیکت', 'callback_data' => "admin_ticket_close_{$ticketId}"]
                    ],
                    [['text' => '🔙 بازگشت به لیست', 'callback_data' => 'admin_tickets_page_1']]
                ]
            ];

            $tg->sendMessage($userId, $text, $keyboard);
        }
        exit;
    }

    // پاسخ تیکت
    elseif (strpos($callbackData, 'admin_ticket_reply_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'tickets')) {
            $tg->sendMessage($userId, "⚠️ شما دسترسی به بخش مدیریت تیکت‌ها را ندارید.");
            exit;
        }
        $ticketId = (int)str_replace('admin_ticket_reply_', '', $callbackData);

        FSM::setStep($botId, $userId, "admin_waiting_ticket_response_{$ticketId}");
        $tg->sendMessage($userId, "✍️ لطفا پاسخ خود را برای کاربر تایپ کرده و بفرستید:");
        exit;
    }

    // بستن تیکت
    elseif (strpos($callbackData, 'admin_ticket_close_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'tickets')) {
            $tg->sendMessage($userId, "⚠️ شما دسترسی به بخش مدیریت تیکت‌ها را ندارید.");
            exit;
        }
        $ticketId = (int)str_replace('admin_ticket_close_', '', $callbackData);

        $stmt = $db->prepare("UPDATE tickets SET status = 'closed' WHERE bot_id = :bot_id AND id = :id");
        $stmt->execute(['bot_id' => $botId, 'id' => $ticketId]);

        $tg->sendMessage($userId, "✅ تیکت شماره #{$ticketId} بسته شد.");
        exit;
    }

    // دکمه پاسخ به تیکت پشتیبانی کاربر
    elseif (strpos($callbackData, 'admin_reply_ticket_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $ticketId = (int)str_replace('admin_reply_ticket_', '', $callbackData);
        
        if (!hasPermission($db, $botId, $userId, 'tickets')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای پاسخگویی به تیکت‌ها را ندارید.");
            exit;
        }

        FSM::setStep($botId, $userId, "admin_waiting_ticket_reply_{$ticketId}");

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ لغو عملیات', 'callback_data' => 'admin_cancel']]
            ]
        ];

        $tg->sendMessage($userId, "✍️ <b>لطفاً پاسخ خود به این تیکت را بنویسید و ارسال کنید:</b>", $keyboard);
        exit;
    }

    // تغییر سطح دسترسی ادمین
    elseif (strpos($callbackData, 'toggle_perm_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $stmtCheckOwner = $db->prepare("SELECT role FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id LIMIT 1");
        $stmtCheckOwner->execute(['bot_id' => $botId, 'tg_id' => $userId]);
        $ownerCheck = $stmtCheckOwner->fetch();

        if (!$ownerCheck || $ownerCheck['role'] !== 'owner') {
            $tg->sendMessage($userId, "⚠️ <b>خطای دسترسی!</b>\n\nفقط مالک اصلی تیم مانهوا دسترسی ترفیع، خلع و تغییر دسترسی دیگر ادمین‌ها را دارد.");
            exit;
        }

        $data = str_replace('toggle_perm_', '', $callbackData);
        $parts = explode('_', $data);
        $targetAdminId = (int)$parts[0];
        $permKey       = $parts[1];

        $whitelist = ['recruit', 'manhwa', 'team', 'salary', 'settings', 'broadcast', 'add_admin', 'tickets', 'exams', 'warning'];
        if (in_array($permKey, $whitelist)) {
            $dbField = "perm_" . $permKey;
            
            $stmt = $db->prepare("
                UPDATE admin_permissions 
                SET {$dbField} = NOT {$dbField} 
                WHERE bot_id = :bot_id AND user_id = :user_id
            ");
            $stmt->execute([
                'bot_id'  => $botId,
                'user_id' => $targetAdminId
            ]);

            showAdminPermissionsPanel($db, $tg, $botId, $targetAdminId, $adminChatId, $messageId);
        }
        exit;
    }

    // نمایش صفحه ویرایش حقوق‌ها
    elseif ($callbackData === 'admin_salary_rates') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'settings')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت نرخ‌های مالی مانهوا را ندارید.");
            exit;
        }

        $stmtRates = $db->prepare("SELECT key, value FROM settings WHERE bot_id = :bot_id AND key IN ('rate_translator', 'rate_cleaner', 'rate_typesetter')");
        $stmtRates->execute(['bot_id' => $botId]);
        $ratesRows = $stmtRates->fetchAll();

        $rates = ['rate_translator' => '0', 'rate_cleaner' => '0', 'rate_typesetter' => '0'];
        foreach ($ratesRows as $row) {
            $rates[$row['key']] = $row['value'];
        }

        $rateText = "💸 <b>نرخ‌های عمومی حقوق به ازای هر چپتر کار شده:</b>\n\n"
                  . "📝 مترجم: <code>{$rates['rate_translator']}</code> تومان\n"
                  . "🖌 کلینر: <code>{$rates['rate_cleaner']}</code> تومان\n"
                  . "⌨️ تایپیست: <code>{$rates['rate_typesetter']}</code> تومان\n\n"
                  . "👇 روی گزینه مورد نظر جهت ویرایش کلیک کنید:";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📝 ویرایش حقوق مترجم', 'callback_data' => 'admin_change_rate_translator']],
                [['text' => '🖌 ویرایش حقوق کلینر', 'callback_data' => 'admin_change_rate_cleaner']],
                [['text' => '⌨️ ویرایش حقوق تایپیست', 'callback_data' => 'admin_change_rate_typesetter']],
                [['text' => '🔙 بازگشت به تنظیمات', 'callback_data' => 'admin_settings']]
            ]
        ];
        $tg->sendMessage($userId, $rateText, $keyboard);
        exit;
    }

    // ویرایش نرخ دستمزد عمومی
    elseif (strpos($callbackData, 'admin_change_rate_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'settings')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای ویرایش نرخ‌ها را ندارید.");
            exit;
        }
        $roleToSetRate = str_replace('admin_change_rate_', '', $callbackData);
        FSM::setStep($botId, $userId, "admin_waiting_rate_{$roleToSetRate}");

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ لغو عملیات', 'callback_data' => 'admin_cancel']]
            ]
        ];

        $tg->sendMessage($userId, "✍️ <b>لطفاً مبلغ جدید حقوق به ازای هر چپتر را برای " . getRoleFarsiAdmin($roleToSetRate) . " به عدد (به تومان) وارد کنید:</b>", $keyboard);
        exit;
    }

    // نمایش پرکارترین اعضای تیم
    elseif ($callbackData === 'admin_most_active') {
        $tg->answerCallbackQuery($callbackId);
        $stmt = $db->prepare("
            SELECT full_name, role, total_chapters 
            FROM users 
            WHERE bot_id = :bot_id AND status = 'approved' AND role IN ('translator', 'cleaner', 'typesetter')
            ORDER BY total_chapters DESC LIMIT 10
        ");
        $stmt->execute(['bot_id' => $botId]);
        $members = $stmt->fetchAll();

        if (empty($members)) {
            $tg->sendMessage($userId, "⚠️ هنوز رکوردی ثبت نشده است.");
        } else {
            $textList = "🏆 <b>پرکارترین اعضای تیم مانهوا (بر اساس کل چپترها):</b>\n\n";
            $rank = 1;
            foreach ($members as $m) {
                $roleName = getRoleFarsiAdmin($m['role']);
                $textList .= "{$rank}. <b>{$m['full_name']}</b> ({$roleName}) ➔ <code>{$m['total_chapters']}</code> چپتر\n";
                $rank++;
            }
            $tg->sendMessage($userId, $textList, [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_settings']]]
            ]);
        }
        exit;
    }

    // آمار مالی مانهوا
    elseif ($callbackData === 'admin_team_info') {
        $tg->answerCallbackQuery($callbackId);
        $stmtCount = $db->prepare("SELECT COUNT(*) as total_m FROM manhwas WHERE bot_id = :bot_id");
        $stmtCount->execute(['bot_id' => $botId]);
        $totalManhwas = $stmtCount->fetch()['total_m'];

        $stmtChCount = $db->prepare("SELECT COALESCE(SUM(last_chapter), 0) as total_c FROM manhwas WHERE bot_id = :bot_id");
        $stmtChCount->execute(['bot_id' => $botId]);
        $totalChapters = $stmtChCount->fetch()['total_c'];

        $stmtEarn = $db->prepare("SELECT COALESCE(SUM(total_earned), 0) as total_e FROM users WHERE bot_id = :bot_id");
        $stmtEarn->execute(['bot_id' => $botId]);
        $totalEarned = number_format($stmtEarn->fetch()['total_e']);

        $infoText = "📊 <b>آمار کلان و گزارش مانهواهای تیم شما:</b>\n\n"
                  . "📚 کل پروژه‌های مانهوای ثبت شده: <code>{$totalManhwas}</code> عدد\n"
                  . "🔢 مجموع چپترهای کار شده: <code>{$totalChapters}</code> چپتر\n"
                  . "💸 مجموع کل دستمزدهای اعضا: <code>{$totalEarned}</code> تومان";

        $tg->sendMessage($userId, $infoText, [
            'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_settings']]]
        ]);
        exit;
    }

    // فرستادن پیام همگانی
    elseif ($callbackData === 'admin_broadcast') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'broadcast')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای ارسال پیام‌های همگانی گروهی را ندارید.");
            exit;
        }
        FSM::setStep($botId, $userId, 'admin_waiting_broadcast_groups');

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ لغو عملیات', 'callback_data' => 'admin_cancel']]
            ]
        ];

        $tg->sendMessage($userId, "✍️ <b>لطفاً پیام همگانی خود را بنویسید:</b>\n\nاین پیام به صورت اتوماتیک به تمامی گروه‌های تلگرامی متصل ارسال خواهد شد.", $keyboard);
        exit;
    }

    // ارتقا به ادمین مانهوا
    elseif ($callbackData === 'admin_add_admin') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'add_admin')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای مدیریت و افزودن ادمین جدید را ندارید.");
            exit;
        }
        FSM::setStep($botId, $userId, 'admin_waiting_add_admin_id');

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ لغو عملیات', 'callback_data' => 'admin_cancel']]
            ]
        ];

        $tg->sendMessage($userId, "🛡️ <b>لطفاً آیدی عددی تلگرام کاربر مورد نظر جهت ارتقا به ادمین را بفرستید:</b>", $keyboard);
        exit;
    }

    // ثبت آیدی گروه اصلی
    elseif ($callbackData === 'admin_set_team_group') {
        $tg->answerCallbackQuery($callbackId);
        FSM::setStep($botId, $userId, 'admin_waiting_team_group_id');

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '❌ لغو عملیات', 'callback_data' => 'admin_cancel']]
            ]
        ];

        $tg->sendMessage($userId, "🔗 <b>لطفاً آیدی عددی گروه تلگرام اصلی تیم خود را بفرستید:</b>", $keyboard);
        exit;
    }

    // لیست کامل اعضای تیم به صورت ۱۰تایی همراه با سرچ و جزئیات فعالیت ۱، ۳، ۶ و ۹ ماهه
    elseif (strpos($callbackData, 'admin_team_list_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $page = (int)str_replace('admin_team_list_', '', $callbackData);
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $stmtCount = $db->prepare("SELECT COUNT(*) as total FROM users WHERE bot_id = :bot_id AND role != 'none' AND status = 'approved'");
        $stmtCount->execute(['bot_id' => $botId]);
        $total = $stmtCount->fetch()['total'];
        $totalPages = ceil($total / $limit);

        $stmt = $db->prepare("SELECT tg_id, full_name, role, monthly_chapters FROM users WHERE bot_id = :bot_id AND role != 'none' AND status = 'approved' ORDER BY joined_at ASC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':bot_id', $botId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $members = $stmt->fetchAll();

        $textList = "👥 <b>لیست کلیه اعضای رسمی تیم (صفحه {$page} از {$totalPages}):</b>\n\nبرای مشاهده آمار فعالیت دوره‌ای و مدیریت اعضا روی دکمه جزئیات کلیک کنید:";
        $buttons = [];
        $buttons[] = [['text' => '🔍 جستجوی عضو', 'callback_data' => 'admin_user_search_init']];

        foreach ($members as $m) {
            // محاسبه دستمزد این ماه بر اساس تجمیع چپترهای تایید شده در ۳۰ روز گذشته
            $stmtEarned = $db->prepare("
                SELECT COALESCE(SUM(CASE 
                    WHEN translator_id = :u_id THEN translator_pay 
                    WHEN cleaner_id = :u_id THEN cleaner_pay 
                    WHEN typesetter_id = :u_id THEN typesetter_pay 
                    ELSE 0 
                END), 0) as m_earned
                FROM chapters 
                WHERE bot_id = :bot_id 
                  AND status = 'approved' 
                  AND (translator_id = :u_id OR cleaner_id = :u_id OR typesetter_id = :u_id)
                  AND created_at >= CURRENT_TIMESTAMP - INTERVAL '30 day'
            ");
            $stmtEarned->execute(['bot_id' => $botId, 'u_id' => $m['tg_id']]);
            $monthlyEarned = $stmtEarned->fetch()['m_earned'];

            $buttons[] = [
                ['text' => "👤 {$m['full_name']} (" . number_format($monthlyEarned) . " ت این ماه)", 'callback_data' => "admin_user_v_{$m['tg_id']}"],
                ['text' => '📊 جزئیات', 'callback_data' => "admin_user_v_{$m['tg_id']}"]
            ];
        }

        $navButtons = [];
        if ($page > 1) {
            $navButtons[] = ['text' => '◀️ قبلی', 'callback_data' => 'admin_team_list_' . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navButtons[] = ['text' =>
