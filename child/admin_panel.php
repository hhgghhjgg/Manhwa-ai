<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: child/admin_panel.php
 * Role: Sub-Infrastructure, General Configurations, Tickets, & FAQs (Complete implementation without shortcuts)
 */

if (!isset($botContext, $tg, $user, $db)) {
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
// فاز ۰: توابع کمکی و اعتبارسنجی ادمین
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
        
        $parts = explode(',', $roleName);
        $translated = [];
        foreach ($parts as $p) {
            $p = trim($p);
            $translated[] = $roles[$p] ?? $p;
        }
        return implode(' + ', $translated);
    }
}

if (!function_exists('hasPermission')) {
    function hasPermission($db, $botId, $userId, $permName) {
        $stmtUser = $db->prepare("SELECT role FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id LIMIT 1");
        $stmtUser->execute(['bot_id' => $botId, 'tg_id' => $userId]);
        $userRow = $stmtUser->fetch();
        if ($userRow && $userRow['role'] === 'owner') {
            return true;
        }

        $dbField = "perm_" . $permName;
        $whitelist = [
            'rec_translator', 'rec_cleaner', 'rec_typesetter', 'rec_rules',
            'proj_add', 'proj_edit', 'proj_delete',
            'team_assign', 'team_dismiss',
            'sal_chapter_approve', 'sal_chapter_reject', 'sal_rates_global', 'sal_rates_specific',
            'broadcast_groups', 'broadcast_users',
            'admin_add', 'admin_perms',
            'tickets_view', 'tickets_reply',
            'exams_manage',
            'warn_user', 'user_ban'
        ];
        
        if (!in_array($permName, $whitelist)) {
            return false;
        }

        $stmt = $db->prepare("SELECT {$dbField} FROM admin_permissions WHERE bot_id = :bot_id AND user_id = :user_id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ? (bool)$row[$dbField] : false;
    }
}

if (!function_exists('showAdminPermissionsMainPanel')) {
    function showAdminPermissionsMainPanel($db, $tg, $botId, $targetAdminId, $chatId, $messageId = null) {
        $stmtU = $db->prepare("SELECT full_name FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id LIMIT 1");
        $stmtU->execute(['bot_id' => $botId, 'tg_id' => $targetAdminId]);
        $uRow = $stmtU->fetch();
        $adminName = $uRow ? $uRow['full_name'] : 'ادمین مانهوا';

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '👥 ۱. دسترسی‌های استخدام', 'callback_data' => "admin_perms_sub_{$targetAdminId}_1"]],
                [['text' => '📚 ۲. دسترسی‌های پروژه‌ها', 'callback_data' => "admin_perms_sub_{$targetAdminId}_2"]],
                [['text' => '👥 ۳. دسترسی‌های مدیریت تیم', 'callback_data' => "admin_perms_sub_{$targetAdminId}_3"]],
                [['text' => '💸 ۴. دسترسی‌های مالی و مبالغ', 'callback_data' => "admin_perms_sub_{$targetAdminId}_4"]],
                [['text' => '⚙️ ۵. دسترسی‌های ابزارها و امنیت', 'callback_data' => "admin_perms_sub_{$targetAdminId}_5"]],
                [['text' => '🔙 بازگشت به مشخصات ادمین', 'callback_data' => "admin_user_v_{$targetAdminId}"]]
            ]
        ];

        $text = "🛡️ <b>بخش تنظیمات سطوح دسترسی ۲۲گانه ادمین:</b>\n\n"
              . "👤 نام ادمین: <b>{$adminName}</b>\n"
              . "🆔 شناسه تلگرام: <code>{$targetAdminId}</code>\n\n"
              . "دسترسی‌ها جهت جلوگیری از شلوغی پنل در ۵ دسته مجزا قرار گرفته‌اند. شاخه مورد نظر را انتخاب کنید:";

        if ($messageId) {
            $tg->editMessageText($chatId, $messageId, $text, $keyboard);
        } else {
            $tg->sendMessage($chatId, $text, $keyboard);
        }
    }
}

if (!function_exists('showAdminPermissionsSubPanel')) {
    function showAdminPermissionsSubPanel($db, $tg, $botId, $targetAdminId, $catId, $chatId, $messageId = null) {
        $stmt = $db->prepare("SELECT * FROM admin_permissions WHERE bot_id = :bot_id AND user_id = :user_id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'user_id' => $targetAdminId]);
        $perms = $stmt->fetch();

        if (!$perms) {
            $stmtIns = $db->prepare("INSERT INTO admin_permissions (bot_id, user_id) VALUES (:bot_id, :user_id) RETURNING *");
            $stmtIns->execute(['bot_id' => $botId, 'user_id' => $targetAdminId]);
            $perms = $stmtIns->fetch();
        }

        $buttons = [];
        $title = "";

        if ($catId == 1) {
            $title = "👥 <b>دسته اول: مدیریت استخدام و عضوگیری</b>";
            $buttons[] = [
                ['text' => ($perms['perm_rec_translator'] ? '✅' : '❌') . ' استخدام مترجم', 'callback_data' => "toggle_perm_{$targetAdminId}_rec_translator"],
                ['text' => ($perms['perm_rec_cleaner'] ? '✅' : '❌') . ' استخدام کلینر', 'callback_data' => "toggle_perm_{$targetAdminId}_rec_cleaner"]
            ];
            $buttons[] = [
                ['text' => ($perms['perm_rec_typesetter'] ? '✅' : '❌') . ' استخدام تایپیست', 'callback_data' => "toggle_perm_{$targetAdminId}_rec_typesetter"],
                ['text' => ($perms['perm_rec_rules'] ? '✅' : '❌') . ' شرایط استخدام', 'callback_data' => "toggle_perm_{$targetAdminId}_rec_rules"]
            ];
        } elseif ($catId == 2) {
            $title = "📚 <b>دسته دوم: مدیریت مانهواها و آثار</b>";
            $buttons[] = [
                ['text' => ($perms['perm_proj_add'] ? '✅' : '❌') . ' ثبت مانهوای جدید', 'callback_data' => "toggle_perm_{$targetAdminId}_proj_add"],
                ['text' => ($perms['perm_proj_edit'] ? '✅' : '❌') . ' ویرایش مانهوا', 'callback_data' => "toggle_perm_{$targetAdminId}_proj_edit"]
            ];
            $buttons[] = [
                ['text' => ($perms['perm_proj_delete'] ? '✅' : '❌') . ' حذف مانهوا', 'callback_data' => "toggle_perm_{$targetAdminId}_proj_delete"]
            ];
        } elseif ($catId == 3) {
            $title = "👥 <b>دسته سوم: مدیریت ساختار تیم</b>";
            $buttons[] = [
                ['text' => ($perms['perm_team_assign'] ? '✅' : '❌') . ' انتساب پرسنل', 'callback_data' => "toggle_perm_{$targetAdminId}_team_assign"],
                ['text' => ($perms['perm_team_dismiss'] ? '✅' : '❌') . ' عزل پرسنل', 'callback_data' => "toggle_perm_{$targetAdminId}_team_dismiss"]
            ];
        } elseif ($catId == 4) {
            $title = "💸 <b>دسته چهارم: محاسبات و تراکنش‌های مالی</b>";
            $buttons[] = [
                ['text' => ($perms['perm_sal_chapter_approve'] ? '✅' : '❌') . ' تایید چپتر و پرداخت', 'callback_data' => "toggle_perm_{$targetAdminId}_sal_chapter_approve"],
                ['text' => ($perms['perm_sal_chapter_reject'] ? '✅' : '❌') . ' رد چپتر مانهوا', 'callback_data' => "toggle_perm_{$targetAdminId}_sal_chapter_reject"]
            ];
            $buttons[] = [
                ['text' => ($perms['perm_sal_rates_global'] ? '✅' : '❌') . ' نرخ عمومی دستمزد', 'callback_data' => "toggle_perm_{$targetAdminId}_sal_rates_global"],
                ['text' => ($perms['perm_sal_rates_specific'] ? '✅' : '❌') . ' نرخ اختصاصی مانهوا', 'callback_data' => "toggle_perm_{$targetAdminId}_sal_rates_specific"]
            ];
        } elseif ($catId == 5) {
            $title = "⚙️ <b>دسته پنجم: ابزارها، تیکتینگ و امنیت کل</b>";
            $buttons[] = [
                ['text' => ($perms['perm_broadcast_groups'] ? '✅' : '❌') . ' پیام همگانی به گروه‌ها', 'callback_data' => "toggle_perm_{$targetAdminId}_broadcast_groups"],
                ['text' => ($perms['perm_broadcast_users'] ? '✅' : '❌') . ' پیام همگانی پی‌وی', 'callback_data' => "toggle_perm_{$targetAdminId}_broadcast_users"]
            ];
            $buttons[] = [
                ['text' => ($perms['perm_admin_add'] ? '✅' : '❌') . ' انتساب ادمین جدید', 'callback_data' => "toggle_perm_{$targetAdminId}_admin_add"],
                ['text' => ($perms['perm_admin_perms'] ? '✅' : '❌') . ' تنظیم دسترسی‌ها', 'callback_data' => "toggle_perm_{$targetAdminId}_admin_perms"]
            ];
            $buttons[] = [
                ['text' => ($perms['perm_tickets_view'] ? '✅' : '❌') . ' مشاهده تیکت‌ها', 'callback_data' => "toggle_perm_{$targetAdminId}_tickets_view"],
                ['text' => ($perms['perm_tickets_reply'] ? '✅' : '❌') . ' پاسخ به تیکت‌ها', 'callback_data' => "toggle_perm_{$targetAdminId}_tickets_reply"]
            ];
            $buttons[] = [
                ['text' => ($perms['perm_exams_manage'] ? '✅' : '❌') . ' آزمون‌های تمرینی', 'callback_data' => "toggle_perm_{$targetAdminId}_exams_manage"],
                ['text' => ($perms['perm_warn_user'] ? '✅' : '❌') . ' اخطار انضباطی', 'callback_data' => "toggle_perm_{$targetAdminId}_warn_user"]
            ];
            $buttons[] = [
                ['text' => ($perms['perm_user_ban'] ? '✅' : '❌') . ' مسدودسازی کامل', 'callback_data' => "toggle_perm_{$targetAdminId}_user_ban"]
            ];
        }

        $buttons[] = [['text' => '🔙 بازگشت به شاخه‌های دسترسی', 'callback_data' => "admin_perms_main_{$targetAdminId}"]];

        $text = "🛡️ <b>مدیریت جزییات دسترسی ادمین:</b>\n\n"
              . "👤 نام ادمین: <b>" . htmlspecialchars($perms['user_id'] == $targetAdminId ? $targetAdminId : 'ادمین') . "</b>\n"
              . "{$title}\n\n"
              . "جهت تغییر وضعیت هر دسترسی، روی دکمه مربوطه کلیک کنید:";

        $keyboard = ['inline_keyboard' => $buttons];

        if ($messageId) {
            $tg->editMessageText($chatId, $messageId, $text, $keyboard);
        } else {
            $tg->sendMessage($chatId, $text, $keyboard);
        }
    }
}

// ==========================================
// فاز ۱: پردازش وضعیت‌های ورودی متنی (FSM State Processors)
// ==========================================
if ($message) {
    $text = isset($message['text']) ? trim($message['text']) : '';

    if ($text === '/cancel' || $text === 'لغو') {
        FSM::clearStep($botId, $userId);
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

    if (!empty($text)) {
        if ($step === 'admin_waiting_warning_days') {
            if (!hasPermission($db, $botId, $userId, 'sal_rates_global')) exit;
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

        elseif (strpos($step, 'admin_waiting_rate_') === 0) {
            if (!hasPermission($db, $botId, $userId, 'sal_rates_global')) exit;
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

        elseif (strpos($step, 'admin_waiting_ticket_reply_') === 0 || strpos($step, 'admin_waiting_ticket_response_') === 0) {
            if (!hasPermission($db, $botId, $userId, 'tickets_reply')) exit;
            $ticketId = (int)str_replace(['admin_waiting_ticket_reply_', 'admin_waiting_ticket_response_'], '', $step);

            $stmtT = $db->prepare("SELECT user_id FROM tickets WHERE bot_id = :bot_id AND id = :id LIMIT 1");
            $stmtT->execute(['bot_id' => $botId, 'id' => $ticketId]);
            $ticket = $stmtT->fetch();

            if ($ticket) {
                $stmtUpdateTicket = $db->prepare("UPDATE tickets SET status = 'closed' WHERE bot_id = :bot_id AND id = :id");
                $stmtUpdateTicket->execute(['bot_id' => $botId, 'id' => $ticketId]);

                $userNotify = "✉️ <b>پاسخ مدیریت تیم مانهوا به تیکت پشتیبانی شما (#{$ticketId}):</b>\n\n" . $text;
                $tg->sendMessage($ticket['user_id'], $userNotify);

                FSM::clearStep($botId, $userId);
                $tg->sendMessage($userId, "✅ <b>پاسخ با موفقیت به پی‌وی کاربر ارسال و تیکت بسته شد.</b>", [
                    'inline_keyboard' => [[['text' => '🔙 بازگشت به لیست تیکت‌ها', 'callback_data' => 'admin_tickets_page_1']]]
                ]);
            } else {
                $tg->sendMessage($userId, "❌ تیکت یافت نشد.");
            }
            exit;
        }

        elseif ($step === 'admin_waiting_team_group_id') {
            if (!hasPermission($db, $botId, $userId, 'sal_rates_global')) exit;
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

        elseif ($step === 'admin_waiting_add_admin_id') {
            if (!hasPermission($db, $botId, $userId, 'admin_perms')) exit;
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

            $tg->sendMessage($text, "🎉 <b>شما به مقام ادمین ارتقا یافتید.</b>\n\nدستور <code>/start</code> را ارسال کنید تا پنل دسترسی‌ها فعال شود.");
            
            showAdminPermissionsMainPanel($db, $tg, $botId, $text, $userId);
            exit;
        }

        elseif ($step === 'admin_waiting_broadcast_groups') {
            if (!hasPermission($db, $botId, $userId, 'broadcast_groups')) exit;
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

        // ==========================================
        // قابلیت‌های جدید FSM: ثبت سربرگ امتیاز، تیکت، سوالات متداول و رینیم ادمین
        // ==========================================

        elseif ($step === 'admin_waiting_sys_add_rating_cat') {
            if (!hasPermission($db, $botId, $userId, 'sal_rates_global')) exit;
            FSM::clearStep($botId, $userId);

            $stmtAddCat = $db->prepare("INSERT INTO rating_categories (bot_id, title) VALUES (:bot_id, :title)");
            $stmtAddCat->execute(['bot_id' => $botId, 'title' => $text]);

            $tg->sendMessage($userId, "✅ <b>سربرگ امتیازدهی جدید با عنوان «{$text}» با موفقیت به سیستم اضافه شد.</b>", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت به لیست سربرگ‌ها', 'callback_data' => 'admin_sys_rating_cats_list']]]
            ]);
            exit;
        }

        elseif ($step === 'admin_waiting_sys_faq_title') {
            FSM::setStep($botId, $userId, "admin_waiting_sys_faq_body_" . base64_encode($text));
            $tg->sendMessage($userId, "✍️ عنوان ثبت شد.\n\nحالا متن پاسخ سوال را بنویسید:");
            exit;
        }

        elseif (strpos($step, 'admin_waiting_sys_faq_body_') === 0) {
            $encodedTitle = str_replace('admin_waiting_sys_faq_body_', '', $step);
            $faqTitle = base64_decode($encodedTitle);

            FSM::clearStep($botId, $userId);

            $stmtFaq = $db->prepare("INSERT INTO faq (bot_id, title, content) VALUES (:bot_id, :title, :content)");
            $stmtFaq->execute([
                'bot_id'  => $botId,
                'title'   => $faqTitle,
                'content' => $text
            ]);

            $tg->sendMessage($userId, "✅ <b>سوال متداول جدید با موفقیت به سیستم اضافه شد.</b>", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت به لیست سوالات', 'callback_data' => 'admin_sys_faqs_page_1']]]
            ]);
            exit;
        }

        elseif (strpos($step, 'admin_waiting_sys_faq_edit_body_') === 0) {
            $faqId = (int)str_replace('admin_waiting_sys_faq_edit_body_', '', $step);
            FSM::clearStep($botId, $userId);

            $stmtUp = $db->prepare("UPDATE faq SET content = :content WHERE bot_id = :bot_id AND id = :id");
            $stmtUp->execute([
                'content' => $text,
                'bot_id'  => $botId,
                'id'      => $faqId
            ]);

            $tg->sendMessage($userId, "✅ <b>متن سوال متداول با موفقیت ویرایش شد.</b>", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => "admin_sys_faq_view_{$faqId}"]]]
            ]);
            exit;
        }

        elseif ($step === 'admin_waiting_sys_ticket_hours') {
            FSM::clearStep($botId, $userId);

            $stmtHours = $db->prepare("INSERT INTO settings (bot_id, key, value) VALUES (:bot_id, 'ticket_working_hours', :value) ON CONFLICT (bot_id, key) DO UPDATE SET value = EXCLUDED.value");
            $stmtHours->execute(['bot_id' => $botId, 'value' => $text]);

            $tg->sendMessage($userId, "✅ <b>ساعات کاری پشتیبانی با موفقیت روی «{$text}» تنظیم شد.</b>", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_sys_ticket_settings']]]
            ]);
            exit;
        }

        elseif ($step === 'admin_waiting_sys_ticket_days') {
            FSM::clearStep($botId, $userId);

            $stmtDays = $db->prepare("INSERT INTO settings (bot_id, key, value) VALUES (:bot_id, 'ticket_working_days', :value) ON CONFLICT (bot_id, key) DO UPDATE SET value = EXCLUDED.value");
            $stmtDays->execute(['bot_id' => $botId, 'value' => $text]);

            $tg->sendMessage($userId, "✅ <b>روزهای کاری پشتیبانی با موفقیت روی «{$text}» تنظیم شد.</b>", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_sys_ticket_settings']]]
            ]);
            exit;
        }

        elseif (strpos($step, 'admin_waiting_sys_admin_rename_') === 0) {
            $adminTargetId = str_replace('admin_waiting_sys_admin_rename_', '', $step);
            FSM::clearStep($botId, $userId);

            $stmtRename = $db->prepare("INSERT INTO settings (bot_id, key, value) VALUES (:bot_id, :key, :value) ON CONFLICT (bot_id, key) DO UPDATE SET value = EXCLUDED.value");
            $stmtRename->execute([
                'bot_id' => $botId,
                'key'    => "display_name_admin_{$adminTargetId}",
                'value'  => $text
            ]);

            $tg->sendMessage($userId, "✅ <b>نام نمایشی ادمین تیکتینگ با موفقیت به «{$text}» تغییر یافت.</b>", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت به ادمین‌ها', 'callback_data' => 'admin_sys_ticket_admins_list']]]
            ]);
            exit;
        }
    }
}

// ==========================================
// فاز ۲: پردازش کلیک دکمه‌های شیشه‌ای (Callbacks)
// ==========================================
if ($callbackQuery) {
    $callbackData = $callbackQuery['data'];
    $callbackId   = $callbackQuery['id'];
    $messageId    = $callbackQuery['message']['message_id'];
    $adminChatId  = $callbackQuery['message']['chat']['id'];

    if ($callbackData === 'admin_back_to_menu' || $callbackData === 'admin_cancel') {
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
                [
                    ['text' => '❓ سوالات متداول (FAQ)', 'callback_data' => 'admin_sys_faqs_page_1']
                ],
                [['text' => '🔙 بازگشت به منوی ادمین', 'callback_data' => 'admin_back_to_menu']]
            ]
        ];
        $tg->sendMessage($userId, "⚙️ <b>بخش تنظیمات تیم و پیکربندی حقوق‌ها:</b>", $keyboard);
        exit;
    }

    elseif ($callbackData === 'admin_general_settings') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'sal_rates_global')) exit;
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '⏳ تنظیم روزهای اخطار عدم فعالیت', 'callback_data' => 'admin_set_warning_days']],
                [['text' => '⭐ افزودن سربرگ امتیازات', 'callback_data' => 'admin_sys_add_rating_cat_init']],
                [['text' => '📋 لیست سربرگ‌های امتیازدهی', 'callback_data' => 'admin_sys_rating_cats_list']],
                [['text' => '🔌 خاموش / روشن کردن ربات', 'callback_data' => 'admin_sys_power_toggle']],
                [['text' => '🔙 بازگشت به تنظیمات', 'callback_data' => 'admin_settings']]
            ]
        ];
        $tg->sendMessage($userId, "⚙️ <b>بخش تنظیمات عمومی تیم:</b>", $keyboard);
        exit;
    }

    // خاموش و روشن کردن ربات (Bot Power Switch)
    elseif ($callbackData === 'admin_sys_power_toggle') {
        $tg->answerCallbackQuery($callbackId);
        
        $stmtStatus = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = 'bot_active_status' LIMIT 1");
        $stmtStatus->execute(['bot_id' => $botId]);
        $rowStatus = $stmtStatus->fetch();
        $currentStatus = $rowStatus ? $rowStatus['value'] : 'on';
        $statusStr = $currentStatus === 'off' ? '❌ خاموش' : '✅ روشن';

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🟢 روشن کردن ربات', 'callback_data' => 'admin_sys_power_confirm_on'],
                    ['text' => '🔴 خاموش کردن ربات', 'callback_data' => 'admin_sys_power_confirm_off']
                ],
                [['text' => '🔙 بازگشت', 'callback_data' => 'admin_general_settings']]
            ]
        ];

        $tg->sendMessage($userId, "🔌 <b>سیستم خاموش/روشن کردن کل ربات مانهوا:</b>\n\nوضعیت فعلی ربات: <b>{$statusStr}</b>\n\nتوجه: خاموش کردن ربات باعث می‌شود کاربران عادی به منو دسترسی نداشته باشند، اما ادمین‌ها همچنان دسترسی کامل دارند.", $keyboard);
        exit;
    }

    elseif (strpos($callbackData, 'admin_sys_power_confirm_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $statusToSet = str_replace('admin_sys_power_confirm_', '', $callbackData);

        $stmtPower = $db->prepare("INSERT INTO settings (bot_id, key, value) VALUES (:bot_id, 'bot_active_status', :value) ON CONFLICT (bot_id, key) DO UPDATE SET value = EXCLUDED.value");
        $stmtPower->execute(['bot_id' => $botId, 'value' => $statusToSet]);

        $statusStr = $statusToSet === 'off' ? '❌ خاموش' : '✅ روشن';
        $tg->sendMessage($userId, "✅ وضعیت ربات با موفقیت به حالت <b>«{$statusStr}»</b> تغییر یافت.", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_general_settings']]]
        ]);
        exit;
    }

    // مدیریت سربرگ‌های امتیازدهی پویا
    elseif ($callbackData === 'admin_sys_add_rating_cat_init') {
        $tg->answerCallbackQuery($callbackId);
        FSM::setStep($botId, $userId, 'admin_waiting_sys_add_rating_cat');
        $tg->sendMessage($userId, "⭐ <b>لطفاً عنوان سربرگ امتیازدهی جدید را ارسال کنید:</b>\n\nمثال: «اخلاق»، «سرعت عمل»، «دقت کار»", [
            'inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'admin_general_settings']]]
        ]);
        exit;
    }

    elseif ($callbackData === 'admin_sys_rating_cats_list') {
        $tg->answerCallbackQuery($callbackId);
        $stmtCats = $db->prepare("SELECT id, title FROM rating_categories WHERE bot_id = :bot_id ORDER BY id ASC");
        $stmtCats->execute(['bot_id' => $botId]);
        $categories = $stmtCats->fetchAll();

        $textCats = "📋 <b>لیست سربرگ‌های امتیازدهی فعال سیستم:</b>\n\nجهت حذف هر کدام روی دکمه حذف کلیک کنید:";
        $buttons = [];
        foreach ($categories as $cat) {
            $buttons[] = [
                ['text' => "⭐ " . $cat['title'], 'callback_data' => 'admin_sys_rating_cats_list'],
                ['text' => '🗑 حذف', 'callback_data' => "admin_sys_rating_cat_del_{$cat['id']}"]
            ];
        }
        $buttons[] = [['text' => '🔙 بازگشت', 'callback_data' => 'admin_general_settings']];

        $tg->sendMessage($userId, $textCats, ['inline_keyboard' => $buttons]);
        exit;
    }

    elseif (strpos($callbackData, 'admin_sys_rating_cat_del_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $catId = (int)str_replace('admin_sys_rating_cat_del_', '', $callbackData);

        $db->beginTransaction();
        try {
            $stmtDelCat = $db->prepare("DELETE FROM rating_categories WHERE bot_id = :bot_id AND id = :id");
            $stmtDelCat->execute(['bot_id' => $botId, 'id' => $catId]);

            $stmtDelScores = $db->prepare("DELETE FROM member_ratings WHERE bot_id = :bot_id AND category_id = :cat_id");
            $stmtDelScores->execute(['bot_id' => $botId, 'cat_id' => $catId]);

            $db->commit();
            $tg->sendMessage($userId, "✅ سربرگ امتیازدهی و تمام نمرات ثبت شده اعضا در آن با موفقیت حذف شدند.", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت به لیست', 'callback_data' => 'admin_sys_rating_cats_list']]]
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            $tg->sendMessage($userId, "❌ خطا در حذف سربرگ امتیازدهی.");
        }
        exit;
    }

    // تیکت‌های پشتیبانی داینامیک
    elseif (strpos($callbackData, 'admin_tickets_page_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'tickets_view')) exit;

        $page = (int)str_replace('admin_tickets_page_', '', $callbackData);
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $stmtCount = $db->prepare("SELECT COUNT(*) as total FROM tickets WHERE bot_id = :bot_id");
        $stmtCount->execute(['bot_id' => $botId]);
        $total = $stmtCount->fetch()['total'];
        $totalPages = max(1, ceil($total / $limit));

        $stmt = $db->prepare("SELECT id, user_id, subject, status FROM tickets WHERE bot_id = :bot_id ORDER BY id DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':bot_id', $botId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $tickets = $stmt->fetchAll();

        $ticketText = "✉️ <b>بخش مدیریت تیکت‌های اعضا (صفحه {$page} از {$totalPages}):</b>\n\nبرای پاسخ و تنظیمات تیکت‌ها از دکمه‌های زیر استفاده کنید:";
        $buttons = [];

        $buttons[] = [['text' => '⚙️ تنظیمات تیکت و ساعات کاری', 'callback_data' => 'admin_sys_ticket_settings']];

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

    elseif ($callbackData === 'admin_sys_ticket_settings') {
        $tg->answerCallbackQuery($callbackId);
        
        $stmtHours = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = 'ticket_working_hours' LIMIT 1");
        $stmtHours->execute(['bot_id' => $botId]);
        $rowHours = $stmtHours->fetch();
        $currentHours = $rowHours ? $rowHours['value'] : 'ثبت نشده';

        $stmtDays = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = 'ticket_working_days' LIMIT 1");
        $stmtDays->execute(['bot_id' => $botId]);
        $rowDays = $stmtDays->fetch();
        $currentDays = $rowDays ? $rowDays['value'] : 'ثبت نشده';

        $stmtStatus = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = 'ticket_active_status' LIMIT 1");
        $stmtStatus->execute(['bot_id' => $botId]);
        $rowStatus = $stmtStatus->fetch();
        $currentStatus = $rowStatus ? $rowStatus['value'] : 'open';
        $statusStr = $currentStatus === 'closed' ? '❌ کاملاً بسته شده' : '✅ باز و فعال';

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '⏳ تنظیم ساعت کاری پشتیبانی', 'callback_data' => 'admin_sys_ticket_hours_init']],
                [['text' => '📅 تنظیم روزهای کاری پشتیبانی', 'callback_data' => 'admin_sys_ticket_days_init']],
                [['text' => '👤 لیست ادمین‌ها و تغییر نام نمایشی', 'callback_data' => 'admin_sys_ticket_admins_list']],
                [
                    ['text' => '✅ باز کردن بخش تیکت', 'callback_data' => 'admin_sys_ticket_toggle_open'],
                    ['text' => '❌ بستن بخش تیکت', 'callback_data' => 'admin_sys_ticket_toggle_closed']
                ],
                [['text' => '🔙 بازگشت به تیکت‌ها', 'callback_data' => 'admin_tickets_page_1']]
            ]
        ];

        $textSetting = "⚙️ <b>بخش تنظیمات تیکتینگ و ساعات کاری:</b>\n\n"
                     . "📅 روزهای کاری فعال: <code>{$currentDays}</code>\n"
                     . "⏳ ساعات کاری فعال: <code>{$currentHours}</code>\n"
                     . "📌 وضعیت کلی بخش تیکت: <b>{$statusStr}</b>\n\n"
                     . "برای اعمال تغییرات از دکمه‌های زیر اقدام کنید:";

        $tg->sendMessage($userId, $textSetting, $keyboard);
        exit;
    }

    elseif (strpos($callbackData, 'admin_sys_ticket_toggle_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $toggleStatus = str_replace('admin_sys_ticket_toggle_', '', $callbackData);

        $stmtToggle = $db->prepare("INSERT INTO settings (bot_id, key, value) VALUES (:bot_id, 'ticket_active_status', :value) ON CONFLICT (bot_id, key) DO UPDATE SET value = EXCLUDED.value");
        $stmtToggle->execute(['bot_id' => $botId, 'value' => $toggleStatus]);

        $statusStr = $toggleStatus === 'closed' ? '❌ کاملاً بسته شده' : '✅ باز و فعال';
        $tg->sendMessage($userId, "✅ وضعیت سیستم تیکتینگ با موفقیت به حالت <b>«{$statusStr}»</b> تغییر یافت.", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_sys_ticket_settings']]]
        ]);
        exit;
    }

    elseif ($callbackData === 'admin_sys_ticket_hours_init') {
        $tg->answerCallbackQuery($callbackId);
        FSM::setStep($botId, $userId, 'admin_waiting_sys_ticket_hours');
        $tg->sendMessage($userId, "✍️ <b>ساعت کاری باز بودن تیکت را وارد کنید (مثال: 08:00-16:00):</b>");
        exit;
    }

    elseif ($callbackData === 'admin_sys_ticket_days_init') {
        $tg->answerCallbackQuery($callbackId);
        FSM::setStep($botId, $userId, 'admin_waiting_sys_ticket_days');
        $tg->sendMessage($userId, "✍️ <b>روزهای کاری فعال تیکتینگ را وارد کنید:</b>\n\nمثال: شنبه,یکشنبه,دوشنبه,سه شنبه,چهارشنبه");
        exit;
    }

    elseif ($callbackData === 'admin_sys_ticket_admins_list') {
        $tg->answerCallbackQuery($callbackId);
        
        // استخراج تمام کاربران ادمین و مالک
        $stmtAdmins = $db->prepare("SELECT tg_id, full_name, role FROM users WHERE bot_id = :bot_id AND (role = 'admin' OR role = 'owner') AND status = 'approved'");
        $stmtAdmins->execute(['bot_id' => $botId]);
        $admins = $stmtAdmins->fetchAll();

        $textAdmins = "👤 <b>لیست ادمین‌های مسئول بخش تیکتینگ مانهوا:</b>\n\n"
                    . "برای پنهان‌سازی ادمین از لیست منوی تیکت پرسنل یا برای تغییر نام نمایشی او اقدام کنید:";

        $buttons = [];
        foreach ($admins as $ad) {
            // واکشی نام نمایشی اختصاصی اگر تنظیم شده باشد
            $stmtDisp = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = :key LIMIT 1");
            $stmtDisp->execute(['bot_id' => $botId, 'key' => "display_name_admin_{$ad['tg_id']}"]);
            $dispRow = $stmtDisp->fetch();
            $displayName = $dispRow ? $dispRow['value'] : $ad['full_name'];

            // بررسی وضعیت قفل یا مخفی‌سازی ادمین در منوی پرسنل
            $stmtHide = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = :key LIMIT 1");
            $stmtHide->execute(['bot_id' => $botId, 'key' => "hide_admin_{$ad['tg_id']}"]);
            $hideRow = $stmtHide->fetch();
            $isHidden = $hideRow ? (bool)$hideRow['value'] : false;

            $statusText = $isHidden ? '❌ مخفی' : '✅ نمایان';

            $buttons[] = [
                ['text' => "👤 {$displayName} ({$statusText})", 'callback_data' => 'admin_sys_ticket_admins_list'],
                ['text' => '👁 تغییر وضعیت', 'callback_data' => "admin_sys_ticket_admin_toggle_{$ad['tg_id']}"],
                ['text' => '🔄 رینیم', 'callback_data' => "admin_sys_ticket_admin_rename_init_{$ad['tg_id']}"]
            ];
        }

        $buttons[] = [['text' => '🔙 بازگشت به تنظیمات تیکت', 'callback_data' => 'admin_sys_ticket_settings']];

        $tg->sendMessage($userId, $textAdmins, ['inline_keyboard' => $buttons]);
        exit;
    }

    elseif (strpos($callbackData, 'admin_sys_ticket_admin_toggle_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $adminTargetId = str_replace('admin_sys_ticket_admin_toggle_', '', $callbackData);

        $stmtHide = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = :key LIMIT 1");
        $stmtHide->execute(['bot_id' => $botId, 'key' => "hide_admin_{$adminTargetId}"]);
        $hideRow = $stmtHide->fetch();
        $currentHide = $hideRow ? (int)$hideRow['value'] : 0;

        $newHide = $currentHide === 1 ? 0 : 1;

        $stmtUp = $db->prepare("INSERT INTO settings (bot_id, key, value) VALUES (:bot_id, :key, :value) ON CONFLICT (bot_id, key) DO UPDATE SET value = EXCLUDED.value");
        $stmtUp->execute([
            'bot_id' => $botId,
            'key'    => "hide_admin_{$adminTargetId}",
            'value'  => (string)$newHide
        ]);

        $tg->sendMessage($userId, "✅ وضعیت نمایش ادمین در منوی کاربران با موفقیت تغییر یافت.", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_sys_ticket_admins_list']]]
        ]);
        exit;
    }

    elseif (strpos($callbackData, 'admin_sys_ticket_admin_rename_init_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $adminTargetId = str_replace('admin_sys_ticket_admin_rename_init_', '', $callbackData);

        FSM::setStep($botId, $userId, "admin_waiting_sys_admin_rename_{$adminTargetId}");
        $tg->sendMessage($userId, "✍️ <b>نام نمایشی دلخواه خود را برای این ادمین وارد کنید (این نام در منوی تیکتینگ برای پرسنل نمایش داده خواهد شد):</b>");
        exit;
    }

    // سوالات متداول (FAQ Management)
    elseif (strpos($callbackData, 'admin_sys_faqs_page_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $page = (int)str_replace('admin_sys_faqs_page_', '', $callbackData);
        $limit = 10;
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

        $textFaq = "❓ <b>بخش مدیریت سوالات متداول (FAQ) - (صفحه {$page} از {$totalPages}):</b>\n\n"
                 . "برای افزودن، حذف یا ادیت سوالات متداول پرسنل مانهوا اقدام کنید:";

        $buttons = [];
        $buttons[] = [['text' => '➕ افزودن سوال متداول جدید', 'callback_data' => 'admin_sys_faq_add_init']];

        foreach ($faqs as $f) {
            $buttons[] = [
                ['text' => "❓ " . $f['title'], 'callback_data' => "admin_sys_faq_view_{$f['id']}"],
                ['text' => '🗑 حذف', 'callback_data' => "admin_sys_faq_del_{$f['id']}"]
            ];
        }

        $navButtons = [];
        if ($page > 1) {
            $navButtons[] = ['text' => '◀️ قبلی', 'callback_data' => 'admin_sys_faqs_page_' . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navButtons[] = ['text' => 'بعدی ▶️', 'callback_data' => 'admin_sys_faqs_page_' . ($page + 1)];
        }
        if (!empty($navButtons)) {
            $buttons[] = $navButtons;
        }
        $buttons[] = [['text' => '🔙 بازگشت به تنظیمات', 'callback_data' => 'admin_settings']];

        $tg->sendMessage($userId, $textFaq, ['inline_keyboard' => $buttons]);
        exit;
    }

    elseif ($callbackData === 'admin_sys_faq_add_init') {
        $tg->answerCallbackQuery($callbackId);
        FSM::setStep($botId, $userId, 'admin_waiting_sys_faq_title');
        $tg->sendMessage($userId, "✍️ <b>عنوان سوال متداول را ارسال کنید (مثال: چطور چپتر ثبت کنم؟):</b>");
        exit;
    }

    elseif (strpos($callbackData, 'admin_sys_faq_view_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $faqId = (int)str_replace('admin_sys_faq_view_', '', $callbackData);

        $stmt = $db->prepare("SELECT * FROM faq WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $faqId]);
        $faq = $stmt->fetch();

        if ($faq) {
            $textMsg = "❓ <b>عنوان: {$faq['title']}</b>\n\n"
                     . "💬 <b>پاسخ کامل سوال:</b>\n<i>{$faq['content']}</i>";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🔄 ادیت متن سوال', 'callback_data' => "admin_sys_faq_edit_init_{$faqId}"],
                        ['text' => '🗑 حذف سوال', 'callback_data' => "admin_sys_faq_del_{$faqId}"]
                    ],
                    [['text' => '🔙 بازگشت به لیست', 'callback_data' => 'admin_sys_faqs_page_1']]
                ]
            ];

            $tg->sendMessage($userId, $textMsg, $keyboard);
        }
        exit;
    }

    elseif (strpos($callbackData, 'admin_sys_faq_del_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $faqId = (int)str_replace('admin_sys_faq_del_', '', $callbackData);

        $stmt = $db->prepare("DELETE FROM faq WHERE bot_id = :bot_id AND id = :id");
        $stmt->execute(['bot_id' => $botId, 'id' => $faqId]);

        $tg->sendMessage($userId, "✅ سوال متداول با موفقیت حذف شد.", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت به لیست', 'callback_data' => 'admin_sys_faqs_page_1']]]
        ]);
        exit;
    }

    elseif (strpos($callbackData, 'admin_sys_faq_edit_init_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $faqId = (int)str_replace('admin_sys_faq_edit_init_', '', $callbackData);

        $stmt = $db->prepare("SELECT content FROM faq WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $faqId]);
        $faq = $stmt->fetch();

        if ($faq) {
            $tg->sendMessage($userId, "📝 <b>متن فعلی سوال متداول:</b>\n\n<code>{$faq['content']}</code>\n\nبرای ویرایش متن، روی دکمه ادیت زیر کلیک کنید:");
            
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '✍️ شروع ویرایش', 'callback_data' => "admin_sys_faq_edit_start_{$faqId}"]],
                    [['text' => '🔙 لغو عملیات', 'callback_data' => 'admin_sys_faqs_page_1']]
                ]
            ];
            $tg->sendMessage($userId, "جهت وارد کردن متن جدید دکمه زیر را فشار دهید:", $keyboard);
        }
        exit;
    }

    elseif (strpos($callbackData, 'admin_sys_faq_edit_start_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $faqId = (int)str_replace('admin_sys_faq_edit_start_', '', $callbackData);

        FSM::setStep($botId, $userId, "admin_waiting_sys_faq_edit_body_{$faqId}");
        $tg->sendMessage($userId, "✍️ <b>لطفاً متن جدید و کامل سوال متداول را ارسال کنید:</b>");
        exit;
    }

    // ادامه بقیه کالبک‌های تیکت و تنظیمات دسترسی ۲۲گانه (اصلی سیستم)
    elseif (strpos($callbackData, 'admin_ticket_view_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'tickets_view')) exit;
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

    elseif (strpos($callbackData, 'admin_ticket_reply_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'tickets_reply')) exit;
        $ticketId = (int)str_replace('admin_ticket_reply_', '', $callbackData);

        FSM::setStep($botId, $userId, "admin_waiting_ticket_response_{$ticketId}");
        $tg->sendMessage($userId, "✍️ لطفا پاسخ خود را برای کاربر تایپ کرده و بفرستید:");
        exit;
    }

    elseif (strpos($callbackData, 'admin_ticket_close_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'tickets_reply')) exit;
        $ticketId = (int)str_replace('admin_ticket_close_', '', $callbackData);

        $stmt = $db->prepare("UPDATE tickets SET status = 'closed' WHERE bot_id = :bot_id AND id = :id");
        $stmt->execute(['bot_id' => $botId, 'id' => $ticketId]);

        $tg->sendMessage($userId, "✅ تیکت شماره #{$ticketId} بسته شد.", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت به تیکت‌ها', 'callback_data' => 'admin_tickets_page_1']]]
        ]);
        exit;
    }

    elseif (strpos($callbackData, 'admin_reply_ticket_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $ticketId = (int)str_replace('admin_reply_ticket_', '', $callbackData);
        
        if (!hasPermission($db, $botId, $userId, 'tickets_reply')) {
            $tg->sendMessage($userId, "⚠️ شما سطح دسترسی برای پاسخگویی به تیکت‌ها را ندارید.");
            exit;
        }

        FSM::setStep($botId, $userId, "admin_waiting_ticket_reply_{$ticketId}");
        $tg->sendMessage($userId, "✍️ <b>لطفاً پاسخ خود را تایپ و ارسال کنید:</b>", [
            'inline_keyboard' => [[['text' => '❌ لغو عملیات', 'callback_data' => 'admin_cancel']]]
        ]);
        exit;
    }

    elseif (strpos($callbackData, 'admin_perms_main_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'admin_perms')) exit;
        $targetAdminId = (int)str_replace('admin_perms_main_', '', $callbackData);
        showAdminPermissionsMainPanel($db, $tg, $botId, $targetAdminId, $adminChatId, $messageId);
        exit;
    }

    elseif (strpos($callbackData, 'admin_perms_sub_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'admin_perms')) exit;
        $data = str_replace('admin_perms_sub_', '', $callbackData);
        $parts = explode('_', $data);
        $targetAdminId = (int)$parts[0];
        $catId         = (int)$parts[1];
        showAdminPermissionsSubPanel($db, $tg, $botId, $targetAdminId, $catId, $adminChatId, $messageId);
        exit;
    }

    elseif (strpos($callbackData, 'toggle_perm_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        
        $stmtCheckOwner = $db->prepare("SELECT role FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id LIMIT 1");
        $stmtCheckOwner->execute(['bot_id' => $botId, 'tg_id' => $userId]);
        $ownerCheck = $stmtCheckOwner->fetch();

        if (!$ownerCheck || $ownerCheck['role'] !== 'owner') {
            $tg->sendMessage($userId, "⚠️ <b>خطای دسترسی!</b>\n\nفقط مالک اصلی تیم دسترسی دارد.");
            exit;
        }

        $data = str_replace('toggle_perm_', '', $callbackData);
        $parts = explode('_', $data);
        $targetAdminId = (int)$parts[0];
        array_shift($parts);
        $permKey = implode('_', $parts);

        $whitelist = [
            'rec_translator', 'rec_cleaner', 'rec_typesetter', 'rec_rules',
            'proj_add', 'proj_edit', 'proj_delete',
            'team_assign', 'team_dismiss',
            'sal_chapter_approve', 'sal_chapter_reject', 'sal_rates_global', 'sal_rates_specific',
            'broadcast_groups', 'broadcast_users',
            'admin_add', 'admin_perms',
            'tickets_view', 'tickets_reply',
            'exams_manage',
            'warn_user', 'user_ban'
        ];

        if (in_array($permKey, $whitelist)) {
            $dbField = "perm_" . $permKey;
            
            $stmtCheckExist = $db->prepare("SELECT 1 FROM admin_permissions WHERE bot_id = :bot_id AND user_id = :user_id LIMIT 1");
            $stmtCheckExist->execute(['bot_id' => $botId, 'user_id' => $targetAdminId]);
            if (!$stmtCheckExist->fetch()) {
                $stmtIns = $db->prepare("INSERT INTO admin_permissions (bot_id, user_id) VALUES (:bot_id, :user_id)");
                $stmtIns->execute(['bot_id' => $botId, 'user_id' => $targetAdminId]);
            }

            $stmtToggle = $db->prepare("
                UPDATE admin_permissions 
                SET {$dbField} = NOT {$dbField} 
                WHERE bot_id = :bot_id AND user_id = :user_id
            ");
            $stmtToggle->execute([
                'bot_id'  => $botId,
                'user_id' => $targetAdminId
            ]);

            $catId = 5;
            if (in_array($permKey, ['rec_translator', 'rec_cleaner', 'rec_typesetter', 'rec_rules'])) {
                $catId = 1;
            } elseif (in_array($permKey, ['proj_add', 'proj_edit', 'proj_delete'])) {
                $catId = 2;
            } elseif (in_array($permKey, ['team_assign', 'team_dismiss'])) {
                $catId = 3;
            } elseif (in_array($permKey, ['sal_chapter_approve', 'sal_chapter_reject', 'sal_rates_global', 'sal_rates_specific'])) {
                $catId = 4;
            }

            showAdminPermissionsSubPanel($db, $tg, $botId, $targetAdminId, $catId, $adminChatId, $messageId);
        }
        exit;
    }

    elseif ($callbackData === 'admin_salary_rates') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'sal_rates_global')) exit;

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

    elseif (strpos($callbackData, 'admin_change_rate_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'sal_rates_global')) exit;
        $roleToSetRate = str_replace('admin_change_rate_', '', $callbackData);
        FSM::setStep($botId, $userId, "admin_waiting_rate_{$roleToSetRate}");

        $tg->sendMessage($userId, "✍️ <b>لطفاً مبلغ جدید را برای " . getRoleFarsiAdmin($roleToSetRate) . " به عدد (تومان) وارد کنید:</b>", [
            'inline_keyboard' => [[['text' => '❌ لغو عملیات', 'callback_data' => 'admin_cancel']]]
        ]);
        exit;
    }

    elseif ($callbackData === 'admin_set_warning_days') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'sal_rates_global')) exit;
        FSM::setStep($botId, $userId, 'admin_waiting_warning_days');

        $tg->sendMessage($userId, "✍️ <b>تعداد روزهای راکد ماندن پروژه را وارد کنید (مثال: 7):</b>", [
            'inline_keyboard' => [[['text' => '❌ لغو عملیات', 'callback_data' => 'admin_cancel']]]
        ]);
        exit;
    }

    elseif ($callbackData === 'admin_set_team_group') {
        $tg->answerCallbackQuery($callbackId);
        FSM::setStep($botId, $userId, 'admin_waiting_team_group_id');

        $tg->sendMessage($userId, "🔗 <b>لطفاً آیدی عددی گروه تلگرام اصلی تیم خود را بفرستید:</b>", [
            'inline_keyboard' => [[['text' => '❌ لغو عملیات', 'callback_data' => 'admin_cancel']]]
        ]);
        exit;
    }

    elseif ($callbackData === 'admin_add_admin') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'admin_perms')) exit;
        FSM::setStep($botId, $userId, 'admin_waiting_add_admin_id');

        $tg->sendMessage($userId, "🛡️ <b>لطفاً آیدی عددی تلگرام کاربر را بفرستید:</b>", [
            'inline_keyboard' => [[['text' => '❌ لغو عملیات', 'callback_data' => 'admin_cancel']]]
        ]);
        exit;
    }

    elseif ($callbackData === 'admin_broadcast') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'broadcast_groups')) exit;
        FSM::setStep($botId, $userId, 'admin_waiting_broadcast_groups');

        $tg->sendMessage($userId, "✍️ <b>لطفاً پیام همگانی خود را بنویسید (این پیام به تمام گروه‌های متصل ارسال خواهد شد):</b>", [
            'inline_keyboard' => [[['text' => '❌ لغو عملیات', 'callback_data' => 'admin_cancel']]]
        ]);
        exit;
    }
}

// ==========================================
// فاز ۳: پردازش دستورات متنی منوی شروع (Start Commands)
// ==========================================
if ($message && isset($message['text']) && $message['text'] === '/start') {
    FSM::clearStep($botId, $userId);

    $keyboard = [
        'inline_keyboard' => [
            [['text' => '📚 لیست کارها (پروژه‌ها)', 'callback_data' => 'admin_projects_page_1']],
            [['text' => '👥 مدیریت عضوگیری', 'callback_data' => 'admin_recruit']],
            [['text' => '⚙️ تنظیمات تیم', 'callback_data' => 'admin_settings']]
        ]
    ];

    $tg->sendMessage($userId, "👋 سلام مدیر گرامی <b>{$fullName}</b>، خوش آمدید.\n\nاز گزینه‌های زیر جهت شروع سازماندهی تیم مانهوا استفاده کنید:", $keyboard);
    exit;
}
