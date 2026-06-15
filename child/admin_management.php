<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: child/admin_management.php
 * Role: Human Resources, Recruitment, & Project Catalog (Complete implementation without shortcuts)
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
// فاز ۰: توابع کمکی اختصاصی پنل مدیریت مراجع
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

if (!function_exists('calculateAverageRating')) {
    function calculateAverageRating($db, $botId, $targetUserId) {
        $stmt = $db->prepare("SELECT AVG(score) as avg_score FROM member_ratings WHERE bot_id = :bot_id AND user_id = :user_id");
        $stmt->execute(['bot_id' => $botId, 'user_id' => $targetUserId]);
        $row = $stmt->fetch();
        return $row['avg_score'] ? round((float)$row['avg_score'], 2) : 0;
    }
}

// ==========================================
// فاز ۱: پردازش استپ‌های ورودی متنی (FSM State Processors)
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
        $tg->sendMessage($userId, "❌ <b>عملیات لغو شد.</b>\n\n👋 به کنترل پنل مدیریت خوش آمدید. شاخه مورد نظر را انتخاب کنید:", $keyboard);
        exit;
    }

    if (!empty($text)) {
        if ($step === 'admin_waiting_project_search') {
            FSM::clearStep($botId, $userId);
            if (!hasPermission($db, $botId, $userId, 'proj_edit')) exit;
            
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

        elseif ($step === 'admin_waiting_user_search') {
            FSM::clearStep($botId, $userId);
            if (!hasPermission($db, $botId, $userId, 'team_assign')) exit;
            
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

        elseif ($step === 'admin_waiting_manual_search') {
            FSM::clearStep($botId, $userId);
            if (!hasPermission($db, $botId, $userId, 'rec_translator')) exit;
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

        elseif ($step === 'admin_waiting_manual_table_input') {
            FSM::clearStep($botId, $userId);
            if (!hasPermission($db, $botId, $userId, 'rec_translator')) exit;
            $lines = explode("\n", $text);
            $successCount = 0;
            $failedEntries = [];

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                $parts = explode('|', $line);
                if (count($parts) !== 2) {
                    $failedEntries[] = $line . " (فرمت اشتباه)";
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
                    $failedEntries[] = $line . " (شغل نامعتبر)";
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
                    $failedEntries[] = $line . " (کاربر یافت نشد)";
                    continue;
                }

                FSM::setRole($botId, $targetUser['tg_id'], $roleSlug);
                FSM::setStatus($botId, $targetUser['tg_id'], 'approved');

                $roleFarsi = getRoleFarsiAdmin($roleSlug);
                $tg->sendMessage($targetUser['tg_id'], "🎉 <b>شما مستقیماً با سمت «{$roleFarsi}» به تیم اضافه شدید.</b>");
                $successCount++;
            }

            $reportText = "📋 <b>نتیجه ثبت دستی پرسنل به صورت گروهی:</b>\n\n"
                        . "✅ تعداد ثبت موفق: <code>{$successCount}</code> عضو جدید.\n\n";

            if (!empty($failedEntries)) {
                $reportText .= "⚠️ <b>لیست موارد ناموفق:</b>\n";
                foreach ($failedEntries as $fe) {
                    $reportText .= "❌ " . htmlspecialchars($fe) . "\n";
                }
            }

            $tg->sendMessage($userId, $reportText, [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_manual_recruit_menu']]]
            ]);
            exit;
        }

        elseif (strpos($step, 'admin_waiting_assign_') === 0) {
            if (!hasPermission($db, $botId, $userId, 'team_assign')) exit;
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

            $stmtInsert = $db->prepare("
                INSERT INTO team_assignments (bot_id, manhwa_id, role, user_id) 
                VALUES (:bot_id, :manhwa_id, :role, :user_id)
                ON CONFLICT (bot_id, manhwa_id, role) DO UPDATE SET user_id = EXCLUDED.user_id
            ");
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

        elseif (strpos($step, 'admin_waiting_m_rate_trans_') === 0) {
            if (!hasPermission($db, $botId, $userId, 'sal_rates_specific')) exit;
            $manhwaId = (int)str_replace('admin_waiting_m_rate_trans_', '', $step);
            FSM::clearStep($botId, $userId);

            $stmt = $db->prepare("UPDATE manhwas SET rate_translator = :val WHERE bot_id = :bot_id AND id = :id");
            $stmt->execute(['val' => (float)$text, 'bot_id' => $botId, 'id' => $manhwaId]);

            $tg->sendMessage($userId, "✅ نرخ مترجم این مانهوا با موفقیت روی <b>" . number_format((float)$text) . "</b> تومان تنظیم شد.", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => "admin_m_set_{$manhwaId}"]]]
            ]);
            exit;
        }

        elseif (strpos($step, 'admin_waiting_m_rate_clean_') === 0) {
            if (!hasPermission($db, $botId, $userId, 'sal_rates_specific')) exit;
            $manhwaId = (int)str_replace('admin_waiting_m_rate_clean_', '', $step);
            FSM::clearStep($botId, $userId);

            $stmt = $db->prepare("UPDATE manhwas SET rate_cleaner = :val WHERE bot_id = :bot_id AND id = :id");
            $stmt->execute(['val' => (float)$text, 'bot_id' => $botId, 'id' => $manhwaId]);

            $tg->sendMessage($userId, "✅ نرخ کلینر این مانهوا با موفقیت روی <b>" . number_format((float)$text) . "</b> تومان تنظیم شد.", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => "admin_m_set_{$manhwaId}"]]]
            ]);
            exit;
        }

        elseif (strpos($step, 'admin_waiting_m_rate_type_') === 0) {
            if (!hasPermission($db, $botId, $userId, 'sal_rates_specific')) exit;
            $manhwaId = (int)str_replace('admin_waiting_m_rate_type_', '', $step);
            FSM::clearStep($botId, $userId);

            $stmt = $db->prepare("UPDATE manhwas SET rate_typesetter = :val WHERE bot_id = :bot_id AND id = :id");
            $stmt->execute(['val' => (float)$text, 'bot_id' => $botId, 'id' => $manhwaId]);

            $tg->sendMessage($userId, "✅ نرخ تایپیست این مانهوا با موفقیت روی <b>" . number_format((float)$text) . "</b> تومان تنظیم شد.", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => "admin_m_set_{$manhwaId}"]]]
            ]);
            exit;
        }

        elseif (strpos($step, 'admin_waiting_warn_reason_') === 0) {
            if (!hasPermission($db, $botId, $userId, 'warn_user')) exit;
            $targetUserId = str_replace('admin_waiting_warn_reason_', '', $step);
            FSM::clearStep($botId, $userId);

            $stmt = $db->prepare("UPDATE users SET warnings = warnings + 1 WHERE bot_id = :bot_id AND tg_id::text = :tg_id RETURNING warnings");
            $stmt->execute(['bot_id' => $botId, 'tg_id' => $targetUserId]);
            $newWarnCount = $stmt->fetch()['warnings'];

            $tg->sendMessage($targetUserId, "⚠️ <b>اخطار انضباطی جدید برای شما ثبت شد!</b>\n\n💬 <b>دلیل اخطار:</b>\n<i>{$text}</i>\n\nتعداد کل اخطارهای شما: <code>{$newWarnCount}</code>");
            $tg->sendMessage($userId, "✅ اخطار با موفقیت ثبت شد و پیام برای کاربر فرستاده شد.", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت به کاربر', 'callback_data' => "admin_user_v_{$targetUserId}"]]]
            ]);
            exit;
        }

        elseif (strpos($step, 'admin_waiting_dm_text_') === 0) {
            if (!hasPermission($db, $botId, $userId, 'warn_user')) exit;
            $targetUserId = str_replace('admin_waiting_dm_text_', '', $step);
            FSM::clearStep($botId, $userId);

            $sent = $tg->sendMessage($targetUserId, "✉️ <b>پیام مستقیم مدیریت:</b>\n\n{$text}");
            if ($sent && isset($sent['ok']) && $sent['ok'] === true) {
                $tg->sendMessage($userId, "✅ پیام شما با موفقیت تحویل داده شد.", [
                    'inline_keyboard' => [[['text' => '🔙 بازگشت به کاربر', 'callback_data' => "admin_user_v_{$targetUserId}"]]]
                ]);
            } else {
                $tg->sendMessage($userId, "❌ خطا در ارسال پیام. کاربر ممکن است ربات را بلاک کرده باشد.");
            }
            exit;
        }

        // ==========================================
        // قابلیت‌های جدید: پردازش استپ‌های یادداشت، امتیاز، هدیه و لینک عضوگیری
        // ==========================================

        elseif (strpos($step, 'admin_waiting_mng_note_title_') === 0) {
            $targetUserId = str_replace('admin_waiting_mng_note_title_', '', $step);
            $encodedTitle = base64_encode($text);
            
            FSM::setStep($botId, $userId, "admin_waiting_mng_note_body_{$targetUserId}_{$encodedTitle}");
            $tg->sendMessage($userId, "✍️ عنوان ثبت شد.\n\nحالا متن یادداشت مورد نظر خود را بنویسید:");
            exit;
        }

        elseif (strpos($step, 'admin_waiting_mng_note_body_') === 0) {
            $params = str_replace('admin_waiting_mng_note_body_', '', $step);
            $parts = explode('_', $params);
            $targetUserId = $parts[0];
            $encodedTitle = $parts[1];
            $noteTitle = base64_decode($encodedTitle);

            FSM::clearStep($botId, $userId);

            $stmtNote = $db->prepare("INSERT INTO member_notes (bot_id, user_id, title, note) VALUES (:bot_id, :user_id, :title, :note)");
            $stmtNote->execute([
                'bot_id'  => $botId,
                'user_id' => $targetUserId,
                'title'   => $noteTitle,
                'note'    => $text
            ]);

            $tg->sendMessage($userId, "✅ <b>یادداشت مدیریتی جدید با موفقیت ثبت شد.</b>", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت به یادداشت‌ها', 'callback_data' => "admin_mng_notes_page_{$targetUserId}_1"]]]
            ]);
            exit;
        }

        elseif (strpos($step, 'admin_waiting_mng_rating_score_') === 0) {
            $params = str_replace('admin_waiting_mng_rating_score_', '', $step);
            $parts = explode('_', $params);
            $targetUserId = $parts[0];
            $catId        = (int)$parts[1];

            if (!is_numeric($text) || (float)$text < 0 || (float)$text > 100) {
                $tg->sendMessage($userId, "❌ نمره ارسالی نامعتبر است. نمره باید عددی بین 0 تا 100 باشد. لطفاً مجدداً وارد کنید:");
                exit;
            }

            FSM::clearStep($botId, $userId);

            $stmtScore = $db->prepare("
                INSERT INTO member_ratings (bot_id, user_id, category_id, score)
                VALUES (:bot_id, :user_id, :cat_id, :score)
                ON CONFLICT (bot_id, user_id, category_id) DO UPDATE SET score = EXCLUDED.score
            ");
            $stmtScore->execute([
                'bot_id'  => $botId,
                'user_id' => $targetUserId,
                'cat_id'  => $catId,
                'score'   => (float)$text
            ]);

            $tg->sendMessage($userId, "✅ <b>نمره کاربر با موفقیت ثبت و میانگین نمرات او به‌روزرسانی شد.</b>", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت به امتیازدهی', 'callback_data' => "admin_mng_ratings_page_{$targetUserId}"]]]
            ]);
            exit;
        }

        elseif (strpos($step, 'admin_waiting_mng_gift_') === 0) {
            $targetUserId = str_replace('admin_waiting_mng_gift_', '', $step);
            FSM::clearStep($botId, $userId);

            if (!is_numeric($text) || (float)$text <= 0) {
                $tg->sendMessage($userId, "❌ مبلغ نامعتبر است. لطفاً فقط عدد بزرگتر از صفر وارد کنید:");
                exit;
            }

            $giftAmount = (float)$text;

            $stmtUpUser = $db->prepare("UPDATE users SET total_earned = total_earned + :amount WHERE bot_id = :bot_id AND tg_id = :tg_id");
            $stmtUpUser->execute([
                'amount' => $giftAmount,
                'bot_id' => $botId,
                'tg_id'  => $targetUserId
            ]);

            $tg->sendMessage($targetUserId, "🎉 <b>مبلغ هدیه مدیریتی به موجودی شما اضافه شد!</b>\n\n🎁 مبلغ هدیه: <code>" . number_format($giftAmount) . "</code> تومان\n\nخسته نباشید و با تشکر از زحمات شما در تیم مانهوا! 💖");

            $tg->sendMessage($userId, "✅ مبلغ هدیه با موفقیت واریز و پیام تقدیر برای کاربر ارسال شد.", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت به کاربر', 'callback_data' => "admin_user_v_{$targetUserId}"]]]
            ]);
            exit;
        }

        elseif ($step === 'admin_waiting_mng_invite_limit') {
            if (!is_numeric($text) || (int)$text <= 0) {
                $tg->sendMessage($userId, "❌ ظرفیت نامعتبر است. لطفاً عدد بزرگتر از صفر ارسال کنید:");
                exit;
            }

            $limit = (int)$text;
            FSM::setStep($botId, $userId, "admin_waiting_mng_invite_expire_{$limit}");
            $tg->sendMessage($userId, "⏳ ظرفیت لینک <code>{$limit}</code> نفر تعیین شد.\n\nحالا مدت زمان انقضای این لینک را بر حسب ساعت (مثلاً 24) وارد کنید:");
            exit;
        }

        elseif (strpos($step, 'admin_waiting_mng_invite_expire_') === 0) {
            $limit = (int)str_replace('admin_waiting_mng_invite_expire_', '', $step);

            if (!is_numeric($text) || (int)$text <= 0) {
                $tg->sendMessage($userId, "❌ زمان نامعتبر است. مدت ساعت انقضا را به صورت عدد وارد کنید:");
                exit;
            }

            $duration = (int)$text;
            FSM::clearStep($botId, $userId);

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🔒 قفل‌دار (نیاز به تایید ادمین)', 'callback_data' => "admin_mng_invite_lock_{$limit}_{$duration}_locked"],
                        ['text' => '🔓 بدون قفل (عضویت خودکار)', 'callback_data' => "admin_mng_invite_lock_{$limit}_{$duration}_unlocked"]
                    ],
                    [['text' => '❌ انصراف', 'callback_data' => 'admin_recruit']]
                ]
            ];

            $tg->sendMessage($userId, "⚙️ <b>مرحله پایانی ساخت لینک عضوگیری:</b>\n\nمشخص کنید که آیا مایلید کاربران پس از ورود تایید شوند یا مستقیماً بدون نیاز به تایید ادمین عضو تیم شوند؟", $keyboard);
            exit;
        }
    }

    // فرآیند آپلود چپترهای خام (تکی یا دسته‌ای)
    if ($step && strpos($step, 'admin_waiting_mng_raw_upload_') === 0) {
        $manhwaId = (int)str_replace('admin_waiting_mng_raw_upload_', '', $step);

        $fileId   = null;
        $fileName = 'raw_file_' . time() . '.jpg';

        if (isset($message['document'])) {
            $fileId   = $message['document']['file_id'];
            $fileName = $message['document']['file_name'] ?? $fileName;
        } elseif (isset($message['photo'])) {
            $fileId   = end($message['photo'])['file_id'];
            $fileName = 'image_' . time() . '.jpg';
        }

        if (!$fileId) {
            $tg->sendMessage($userId, "❌ فایل ارسالی معتبر نیست. لطفاً یک فایل سند یا تصویر بفرستید:");
            exit;
        }

        // استخراج عدد چپتر از نام فایل به صورت اتوماتیک
        preg_match('/(\d+)/', $fileName, $matches);
        $chapterNum = isset($matches[1]) ? (int)$matches[1] : 1;

        $stmtRaw = $db->prepare("
            INSERT INTO manhwa_raw_chapters (bot_id, manhwa_id, chapter_num, file_id, file_name)
            VALUES (:bot_id, :manhwa_id, :ch_num, :file_id, :file_name)
        ");
        $stmtRaw->execute([
            'bot_id'     => $botId,
            'manhwa_id'  => $manhwaId,
            'ch_num'     => $chapterNum,
            'file_id'    => $fileId,
            'file_name'  => $fileName
        ]);

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🏁 اتمام و بازگشت به لیست', 'callback_data' => "admin_mng_raw_chapters_{$manhwaId}_1"]]
            ]
        ];

        $tg->sendMessage($userId, "✅ فایل <b>«{$fileName}»</b> به عنوان چپتر خام <code>{$chapterNum}</code> مانهوا با موفقیت ثبت شد.\n\nمی‌توانید فایل‌های خام دیگر را ارسال کنید یا دکمه اتمام را بفشارید:", $keyboard);
        exit;
    }
}

// ==========================================
// فاز ۲: پردازش رویدادهای کالبک شیشه‌ای (Callback Queries)
// ==========================================
if ($callbackQuery) {
    $callbackData = $callbackQuery['data'];
    $callbackId   = $callbackQuery['id'];
    $messageId    = $callbackQuery['message']['message_id'];
    $adminChatId  = $callbackQuery['message']['chat']['id'];

    if (strpos($callbackData, 'admin_usr_confirmban_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'user_ban')) exit;
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

        $tg->sendMessage($userId, "✅ کاربر از تمام گروه‌ها و ربات به طور کامل مسدود و اخراج شد.");
        exit;
    }

    elseif (strpos($callbackData, 'admin_usr_ban_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'user_ban')) exit;
        $targetUserId = str_replace('admin_usr_ban_', '', $callbackData);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ بله، اخراج و بن شود', 'callback_data' => "admin_usr_confirmban_{$targetUserId}"],
                    ['text' => '❌ لغو عملیات', 'callback_data' => "admin_user_v_{$targetUserId}"]
                ]
            ]
        ];

        $tg->sendMessage($userId, "⚠️ <b>تایید اخراج کامل:</b>\n\nآیا از مسدودسازی و اخراج کاربر مطمئن هستید؟ تمام دسترسی‌های او قطع خواهد شد.", $keyboard);
        exit;
    }

    elseif ($callbackData === 'admin_manual_recruit_menu') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'rec_translator')) exit;
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔍 جستجو و افزودن تک عضو', 'callback_data' => 'admin_manual_search']],
                [['text' => '📋 جدول ثبت گروهی اعضا', 'callback_data' => 'admin_manual_table']],
                [['text' => '🔙 بازگشت به عضوگیری', 'callback_data' => 'admin_recruit']]
            ]
        ];
        $tg->sendMessage($userId, "⚙️ <b>بخش ثبت دستی اعضا:</b>\n\nیکی از شیوه‌های زیر را انتخاب کنید:", $keyboard);
        exit;
    }

    elseif ($callbackData === 'admin_manual_search') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'rec_translator')) exit;
        FSM::setStep($botId, $userId, 'admin_waiting_manual_search');
        $tg->sendMessage($userId, "👤 <b>آیدی عددی تلگرام یا یوزرنیم کاربر را ارسال کنید:</b>", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_manual_recruit_menu']]]
        ]);
        exit;
    }

    elseif ($callbackData === 'admin_manual_table') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'rec_translator')) exit;
        FSM::setStep($botId, $userId, 'admin_waiting_manual_table_input');
        $formatMsg = "📋 <b>الگوی ثبت گروهی اعضا:</b>\n\n"
                   . "لیست را در یک پیام و با کاراکتر <code>|</code> ارسال کنید:\n\n"
                   . "<code>آیدی_یا_یوزرنیم|نقش</code>\n\n"
                   . "مثال:\n<code>@username|مترجم</code>\n<code>12345678|تایپیست</code>";
        $tg->sendMessage($userId, $formatMsg, [
            'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_manual_recruit_menu']]]
        ]);
        exit;
    }

    elseif (strpos($callbackData, 'admin_set_man_role_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'rec_translator')) exit;
        $data = str_replace('admin_set_man_role_', '', $callbackData);
        $parts = explode('_', $data);
        $targetId = $parts[0];
        $roleToSet = $parts[1];

        FSM::setRole($botId, $targetId, $roleToSet);
        FSM::setStatus($botId, $targetId, 'approved');

        $roleFarsi = getRoleFarsiAdmin($roleToSet);
        $tg->sendMessage($targetId, "🎉 <b>شما توسط مدیریت مستقیماً با سمت «{$roleFarsi}» به تیم اضافه شدید.</b>");
        $tg->sendMessage($userId, "✅ عضویت مستقیم با نقش <b>{$roleFarsi}</b> فعال شد.", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_recruit']]]
        ]);
        exit;
    }

    elseif (strpos($callbackData, 'admin_projects_page_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'proj_edit')) exit;
        $page = (int)str_replace('admin_projects_page_', '', $callbackData);
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $stmtCount = $db->prepare("SELECT COUNT(*) as total FROM manhwas WHERE bot_id = :bot_id");
        $stmtCount->execute(['bot_id' => $botId]);
        $total = $stmtCount->fetch()['total'];
        $totalPages = max(1, ceil($total / $limit));

        $stmt = $db->prepare("SELECT id, title FROM manhwas WHERE bot_id = :bot_id ORDER BY id DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':bot_id', $botId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $manhwas = $stmt->fetchAll();

        $textProj = "📚 <b>لیست مانهواهای ثبت شده (صفحه {$page} از {$totalPages}):</b>\n\nبرای دیدن جزییات و مانهواها کلیک کنید:";
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

    elseif ($callbackData === 'admin_project_search_init') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'proj_edit')) exit;
        FSM::setStep($botId, $userId, 'admin_waiting_project_search');
        $tg->sendMessage($userId, "🔍 نام یا بخشی از عنوان مانهوای مورد نظر را ارسال کنید:", [
            'inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'admin_projects_page_1']]]
        ]);
        exit;
    }

    elseif (strpos($callbackData, 'admin_view_manhwa_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'proj_edit')) exit;
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

            $staffList = ['translator' => [], 'cleaner' => [], 'typesetter' => []];
            foreach ($teamMembers as $tm) {
                $staffList[$tm['role']][] = "👤 " . $tm['full_name'] . " (<code>" . $tm['tg_id'] . "</code>)";
            }

            $currentStatus = $manhwa['status'] ?? 'active';
            $statusFarsi = $currentStatus === 'dropped' ? '❌ دراپ شده' : ($currentStatus === 'season_end' ? '⏸ اتمام فصل' : '✅ فعال/درحال پخش');

            $caption = "📚 <b>جزئیات مانهوا: {$manhwa['title']}</b>\n"
                     . "🎭 ژانرها: {$manhwa['genres']}\n"
                     . "🔢 آخرین چپتر: <code>{$manhwa['last_chapter']}</code>\n"
                     . "👥 آیدی گروه: <code>" . ($manhwa['group_id'] ?? 'ثبت نشده') . "</code>\n"
                     . "📌 وضعیت پروژه: <b>{$statusFarsi}</b>\n\n"
                     . "⚔️ <b>اعضای تیم متصل شده:</b>\n"
                     . "├ مترجمین: " . (empty($staffList['translator']) ? "❌ بدون انتساب" : implode('، ', $staffList['translator'])) . "\n"
                     . "├ کلینرها: " . (empty($staffList['cleaner']) ? "❌ بدون انتساب" : implode('، ', $staffList['cleaner'])) . "\n"
                     . "└ تایپیست‌ها: " . (empty($staffList['typesetter']) ? "❌ بدون انتساب" : implode('، ', $staffList['typesetter'])) . "\n\n"
                     . "⚙️ گزینه‌های مدیریتی پروژه را استفاده کنید:";

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
                        ['text' => '⚙️ قیمت اختصاصی کار', 'callback_data' => "admin_m_set_{$manhwaId}"],
                        ['text' => '📂 مدیریت چپترها', 'callback_data' => "admin_manage_ch_{$manhwaId}_1"]
                    ],
                    [
                        ['text' => '📂 آرشیو چپترهای خام', 'callback_data' => "admin_mng_raw_chapters_{$manhwaId}_1"]
                    ],
                    [
                        ['text' => '✅ فعال', 'callback_data' => "admin_mng_status_{$manhwaId}_active"],
                        ['text' => '⏸ پایان فصل', 'callback_data' => "admin_mng_status_{$manhwaId}_season_end"],
                        ['text' => '❌ دراپ', 'callback_data' => "admin_mng_status_{$manhwaId}_dropped"]
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

    elseif (strpos($callbackData, 'admin_mng_status_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'proj_edit')) exit;
        $data = str_replace('admin_mng_status_', '', $callbackData);
        $parts = explode('_', $data);
        $manhwaId = (int)$parts[0];
        array_shift($parts);
        $statusToSet = implode('_', $parts);

        $stmt = $db->prepare("UPDATE manhwas SET status = :status WHERE bot_id = :bot_id AND id = :id");
        $stmt->execute(['status' => $statusToSet, 'bot_id' => $botId, 'id' => $manhwaId]);

        $tg->sendMessage($userId, "✅ وضعیت مانهوا با موفقیت آپدیت شد.");
        exit;
    }

    elseif (strpos($callbackData, 'admin_dismiss_list_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'team_dismiss')) exit;
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

        $tg->sendMessage($userId, "👤 یکی از اعضا را جهت عزل انتخاب کنید:", ['inline_keyboard' => $buttons]);
        exit;
    }

    elseif (strpos($callbackData, 'admin_dismiss_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'team_dismiss')) exit;
        $params = str_replace('admin_dismiss_', '', $callbackData);
        $parts  = explode('_', $params);
        $mId    = (int)$parts[0];
        $role   = $parts[1];
        $targetId = $parts[2];

        $stmt = $db->prepare("DELETE FROM team_assignments WHERE bot_id = :bot_id AND manhwa_id = :manhwa_id AND role = :role AND user_id = :user_id");
        $stmt->execute(['bot_id' => $botId, 'manhwa_id' => $mId, 'role' => $role, 'user_id' => $targetId]);

        $tg->sendMessage($userId, "✅ عزل عضو با موفقیت انجام شد.", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت به مانهوا', 'callback_data' => "admin_view_manhwa_{$mId}"]]]
        ]);
        exit;
    }

    elseif (strpos($callbackData, 'admin_assign_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'team_assign')) exit;
        $params = str_replace('admin_assign_', '', $callbackData);
        FSM::setStep($botId, $userId, "admin_waiting_assign_{$params}");

        $tg->sendMessage($userId, "👤 <b>لطفاً آیدی تلگرام یا یوزرنیم عضو را ارسال کنید:</b>", [
            'inline_keyboard' => [[['text' => '❌ لغو عملیات', 'callback_data' => 'admin_cancel']]]
        ]);
        exit;
    }

    elseif ($callbackData === 'admin_recruit') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'rec_translator')) exit;

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📂 آخرین تست‌های حل شده', 'callback_data' => 'admin_view_tests']],
                [['text' => '➕ وارد کردن دستی عضو', 'callback_data' => 'admin_manual_recruit_menu']],
                [['text' => '📤 آپلود تست استخدامی جدید', 'callback_data' => 'admin_upload_rec_test']],
                [['text' => '⚙️ تغییر شرایط استخدام', 'callback_data' => 'admin_edit_rules']],
                [['text' => '🔗 تولید لینک عضوگیری جدید', 'callback_data' => 'admin_mng_invite_start']],
                [['text' => '📋 درخواست‌های تست مجدد اعضا', 'callback_data' => 'admin_mng_view_retests']],
                [['text' => '🔙 بازگشت به منوی ادمین', 'callback_data' => 'admin_back_to_menu']]
            ]
        ];
        
        $tg->sendMessage($userId, "👥 بخش استخدام و عضوگیری مانهوا:", $keyboard);
        exit;
    }

    // فرآیند پایش و بررسی تست‌های حل‌شده
    elseif ($callbackData === 'admin_view_tests') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'rec_translator')) exit;

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
                if ($t['role'] === 'translator' && !hasPermission($db, $botId, $userId, 'rec_translator')) continue;
                if ($t['role'] === 'cleaner' && !hasPermission($db, $botId, $userId, 'rec_cleaner')) continue;
                if ($t['role'] === 'typesetter' && !hasPermission($db, $botId, $userId, 'rec_typesetter')) continue;

                $roleFarsi = getRoleFarsiAdmin($t['role']);
                $msgText = "👤 کاربر: {$t['full_name']} (@{$t['username']})\n"
                         . "🆔 آیدی عددی: <code>{$t['user_id']}</code>\n"
                         . "⚔️ نقش انتخابی: <b>{$roleFarsi}</b>\n\n"
                         . "⚙️ گزینه مورد نظر جهت پردازش را لمس کنید:";

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

    elseif (strpos($callbackData, 'admin_check_test_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'rec_translator')) exit;
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

            $caption = "📄 فایل تست حل شده برای نقش <b>" . getRoleFarsiAdmin($testFile['role']) . "</b>";

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

    elseif (strpos($callbackData, 'admin_msg_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'warn_user')) exit;
        $targetId = str_replace('admin_msg_', '', $callbackData);
        FSM::setStep($botId, $userId, "admin_send_msg_{$targetId}");

        $tg->sendMessage($userId, "✍️ <b>لطفاً پیام خود را به داوطلب ارسال کنید:</b>", [
            'inline_keyboard' => [[['text' => '❌ لغو عملیات', 'callback_data' => 'admin_cancel']]]
        ]);
        exit;
    }

    elseif (strpos($callbackData, 'admin_reject_test_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'rec_translator')) exit;
        $testId = (int)str_replace('admin_reject_test_', '', $callbackData);

        $stmt = $db->prepare("SELECT user_id, role FROM submitted_tests WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $testId]);
        $test = $stmt->fetch();

        if ($test) {
            $stmtUpdateTest = $db->prepare("UPDATE submitted_tests SET status = 'rejected' WHERE bot_id = :bot_id AND id = :id");
            $stmtUpdateTest->execute(['bot_id' => $botId, 'id' => $testId]);

            FSM::setStatus($botId, $test['user_id'], 'rejected');

            $tg->sendMessage($test['user_id'], "❌ <b>درخواست عضویت شما رد شد.</b>\n\nتست شما برای نقش <b>" . getRoleFarsiAdmin($test['role']) . "</b> مورد قبول قرار نگرفت.");
            $tg->sendMessage($userId, "❌ درخواست استخدام کاربر رد شد.");
        }
        exit;
    }

    elseif (strpos($callbackData, 'admin_accept_test_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'rec_translator')) exit;
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
                $tg->sendMessage($userId, "⚠️ <b>کاربر تایید شد، اما لینک دعوت ارسال نشد!</b>\n\nابتدا آیدی گروه اصلی را وارد کنید.");
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
                $congratsText = "🎉 <b>شما در آزمون عضوگیری پذیرفته شدید!</b>\n\n"
                              . "⚔️ نقش تایید شده: <b>{$roleNameFarsi}</b>\n\n"
                              . "🔗 لینک دعوت گروه کار:\n{$inviteLink}";

                $tg->sendMessage($test['user_id'], $congratsText);
                $tg->sendMessage($userId, "✅ کاربر با نقش <b>{$roleNameFarsi}</b> تایید و لینک کار برای او فرستاده شد.");
            } else {
                $tg->sendMessage($userId, "❌ خطا در تولید لینک دعوت.");
            }
        }
        exit;
    }

    // تولید لینک‌های دعوت هوشمند عضوگیری
    elseif ($callbackData === 'admin_mng_invite_start') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'rec_translator')) exit;
        FSM::setStep($botId, $userId, 'admin_waiting_mng_invite_limit');
        $tg->sendMessage($userId, "🔗 <b>تولید لینک عضوگیری هوشمند:</b>\n\n۱. لطفاً حداکثر ظرفیت تعداد دفعات استفاده از لینک را به صورت عدد وارد کنید (مثلاً 5):");
        exit;
    }

    elseif (strpos($callbackData, 'admin_mng_invite_lock_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'rec_translator')) exit;
        $data = str_replace('admin_mng_invite_lock_', '', $callbackData);
        $parts = explode('_', $data);
        $limit    = (int)$parts[0];
        $duration = (int)$parts[1];
        $lockType = $parts[2]; // 'locked' or 'unlocked'

        $isLocked = ($lockType === 'locked') ? 1 : 0;
        $inviteCode = bin2hex(random_bytes(6));
        $expireAt = date('Y-m-d H:i:s', time() + ($duration * 3600));

        // ثبت اطلاعات در جدول لینک‌های هوشمند
        $stmtLink = $db->prepare("
            INSERT INTO bot_invite_links (bot_id, code, max_uses, is_locked, expire_at)
            VALUES (:bot_id, :code, :max_uses, :is_locked, :expire_at)
        ");
        $stmtLink->execute([
            'bot_id'   => $botId,
            'code'     => $inviteCode,
            'max_uses' => $limit,
            'is_locked' => $isLocked,
            'expire_at' => $expireAt
        ]);

        $botMe = $tg->getMe();
        $botUser = $botMe['result']['username'] ?? 'bot';
        $finalLink = "https://t.me/{$botUser}?start=invite_" . $inviteCode;

        $lockFarsi = $isLocked ? '🔒 قفل‌دار (نیاز به تایید مدیر)' : '🔓 بدون قفل (عضوگیری فوری)';
        $report = "🔗 <b>لینک عضوگیری اختصاصی شما با موفقیت تولید شد:</b>\n\n"
                . "💎 لینک استارت کار ممد: \n<code>{$finalLink}</code>\n\n"
                . "📌 ظرفیت لینک: <code>{$limit}</code> نفر\n"
                . "⏳ زمان انقضا: <code>{$duration}</code> ساعت دیگر\n"
                . "🛡️ نوع عضوگیری: <b>{$lockFarsi}</b>\n\n"
                . "💡 این لینک را کپی کرده و در گروه پرسنل قدیمی خود قرار دهید تا مستقیماً وارد دیتابیس ربات شوند.";

        $tg->sendMessage($userId, $report, [
            'inline_keyboard' => [[['text' => '🔙 بازگشت به عضوگیری', 'callback_data' => 'admin_recruit']]]
        ]);
        exit;
    }

    // درخواست تست مجدد اعضا برای شغل‌های دوم
    elseif ($callbackData === 'admin_mng_view_retests') {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'rec_translator')) exit;

        $stmt = $db->prepare("
            SELECT st.*, u.full_name, u.username, u.role as current_roles
            FROM submitted_tests st
            JOIN users u ON st.bot_id = u.bot_id AND st.user_id = u.tg_id
            WHERE st.bot_id = :bot_id AND st.status = 'pending_retest'
            ORDER BY st.created_at DESC
        ");
        $stmt->execute(['bot_id' => $botId]);
        $retests = $stmt->fetchAll();

        if (empty($retests)) {
            $tg->sendMessage($userId, "⚠️ هیچ درخواست تست مجددی برای اعضای تایید شده وجود ندارد.");
        } else {
            foreach ($retests as $r) {
                $roleFarsi = getRoleFarsiAdmin($r['role']);
                $msgText = "👤 کاربر رسمی: {$r['full_name']} (@{$r['username']})\n"
                         . "🆔 آیدی عددی: <code>{$r['user_id']}</code>\n"
                         . "⚔️ سمت فعلی: " . getRoleFarsiAdmin($r['current_roles']) . "\n"
                         . "🎯 متقاضی تست نقش دوم: <b>{$roleFarsi}</b>\n\n"
                         . "⚙️ گزینه مورد نظر را لمس کنید:";

                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => '🔍 چک کردن فایل تست', 'callback_data' => "admin_check_test_{$r['id']}"],
                            ['text' => '✉️ فرستادن پیام', 'callback_data' => "admin_msg_{$r['user_id']}"]
                        ],
                        [
                            ['text' => '✅ تایید و واگذاری نقش دوم', 'callback_data' => "admin_mng_accept_retest_{$r['id']}"],
                            ['text' => '❌ رد کردن', 'callback_data' => "admin_mng_reject_retest_{$r['id']}"]
                        ]
                    ]
                ];
                $tg->sendMessage($userId, $msgText, $keyboard);
            }
        }
        exit;
    }

    elseif (strpos($callbackData, 'admin_mng_accept_retest_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'rec_translator')) exit;
        $testId = (int)str_replace('admin_mng_accept_retest_', '', $callbackData);

        $stmt = $db->prepare("SELECT user_id, role FROM submitted_tests WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $testId]);
        $test = $stmt->fetch();

        if ($test) {
            $stmtUser = $db->prepare("SELECT role FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id LIMIT 1");
            $stmtUser->execute(['bot_id' => $botId, 'tg_id' => $test['user_id']]);
            $userRow = $stmtUser->fetch();

            if ($userRow) {
                $currentRoles = explode(',', $userRow['role']);
                if (!in_array($test['role'], $currentRoles)) {
                    $currentRoles[] = $test['role'];
                }
                $updatedRoles = implode(',', array_filter($currentRoles));

                $stmtUpdateUser = $db->prepare("UPDATE users SET role = :new_roles WHERE bot_id = :bot_id AND tg_id = :tg_id");
                $stmtUpdateUser->execute([
                    'new_roles' => $updatedRoles,
                    'bot_id'    => $botId,
                    'tg_id'     => $test['user_id']
                ]);

                $stmtUpdateTest = $db->prepare("UPDATE submitted_tests SET status = 'accepted' WHERE bot_id = :bot_id AND id = :id");
                $stmtUpdateTest->execute(['bot_id' => $botId, 'id' => $testId]);

                $roleNameFarsi = getRoleFarsiAdmin($test['role']);
                $tg->sendMessage($test['user_id'], "🎉 <b>با موفقیت در آزمون نقش دوم خود پذیرفته شدید!</b>\n\nسمت جدید <b>«{$roleNameFarsi}»</b> به پروفایل شما اضافه شد.");
                $tg->sendMessage($userId, "✅ نقش دوم کاربر تایید و به لیست سمت‌های او اضافه شد.");
            }
        }
        exit;
    }

    elseif (strpos($callbackData, 'admin_mng_reject_retest_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'rec_translator')) exit;
        $testId = (int)str_replace('admin_mng_reject_retest_', '', $callbackData);

        $stmt = $db->prepare("SELECT user_id, role FROM submitted_tests WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $testId]);
        $test = $stmt->fetch();

        if ($test) {
            $stmtUpdateTest = $db->prepare("UPDATE submitted_tests SET status = 'rejected' WHERE bot_id = :bot_id AND id = :id");
            $stmtUpdateTest->execute(['bot_id' => $botId, 'id' => $testId]);

            $tg->sendMessage($test['user_id'], "❌ درخواست تست مجدد شما برای نقش <b>" . getRoleFarsiAdmin($test['role']) . "</b> متاسفانه مورد قبول واقع نشد.");
            $tg->sendMessage($userId, "❌ درخواست تست مجدد کاربر رد شد.");
        }
        exit;
    }

    // آرشیو و مدیریت چپترهای خام
    elseif (strpos($callbackData, 'admin_mng_raw_chapters_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'proj_edit')) exit;
        $data = str_replace('admin_mng_raw_chapters_', '', $callbackData);
        $parts = explode('_', $data);
        $manhwaId = (int)$parts[0];
        $page     = (int)$parts[1];

        $limit = 10;
        $offset = ($page - 1) * $limit;

        $stmtCount = $db->prepare("SELECT COUNT(*) as total FROM manhwa_raw_chapters WHERE bot_id = :bot_id AND manhwa_id = :m_id");
        $stmtCount->execute(['bot_id' => $botId, 'm_id' => $manhwaId]);
        $total = $stmtCount->fetch()['total'];
        $totalPages = max(1, ceil($total / $limit));

        $stmt = $db->prepare("SELECT id, chapter_num, file_name, created_at FROM manhwa_raw_chapters WHERE bot_id = :bot_id AND manhwa_id = :m_id ORDER BY chapter_num DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':bot_id', $botId, PDO::PARAM_INT);
        $stmt->bindValue(':m_id', $manhwaId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $raws = $stmt->fetchAll();

        $textRaws = "📂 <b>آرشیو چپترهای خام مانهوا (صفحه {$page} از {$totalPages}):</b>\n\n"
                  . "لیست چپترهای خام آپلود شده جهت استفاده پرسنل کاری:";

        $buttons = [];
        $buttons[] = [['text' => '➕ افزودن فایل خام جدید (تکی یا گروهی)', 'callback_data' => "admin_mng_raw_add_{$manhwaId}"]];

        foreach ($raws as $r) {
            $buttons[] = [
                ['text' => "📁 چپتر {$r['chapter_num']} | {$r['file_name']}", 'callback_data' => "admin_mng_raw_chapters_{$manhwaId}_{$page}"],
                ['text' => '🗑 حذف', 'callback_data' => "admin_mng_raw_del_{$r['id']}_{$manhwaId}"]
            ];
        }

        $navButtons = [];
        if ($page > 1) {
            $navButtons[] = ['text' => '◀️ قبلی', 'callback_data' => "admin_mng_raw_chapters_{$manhwaId}_" . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navButtons[] = ['text' => 'بعدی ▶️', 'callback_data' => "admin_mng_raw_chapters_{$manhwaId}_" . ($page + 1)];
        }
        if (!empty($navButtons)) {
            $buttons[] = $navButtons;
        }
        $buttons[] = [['text' => '🔙 بازگشت به مانهوا', 'callback_data' => "admin_view_manhwa_{$manhwaId}"]];

        $tg->sendMessage($userId, $textRaws, ['inline_keyboard' => $buttons]);
        exit;
    }

    elseif (strpos($callbackData, 'admin_mng_raw_add_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'proj_edit')) exit;
        $manhwaId = (int)str_replace('admin_mng_raw_add_', '', $callbackData);

        FSM::setStep($botId, $userId, "admin_waiting_mng_raw_upload_{$manhwaId}");
        $tg->sendMessage($userId, "📥 <b>بستر دریافت چپترهای خام مانهوا فعال شد:</b>\n\n"
                    . "لطفاً فایل یا فایل‌های خام مانهوا (فایل فشرده Zip، سند داکیومنت، یا عکس) را به صورت تکی یا دسته‌ای ارسال کنید.\n\n"
                    . "💡 ربات به طور خودکار عدد چپتر را از نام فایل استخراج می‌کند.", [
            'inline_keyboard' => [[['text' => '🔙 انصراف و بازگشت', 'callback_data' => "admin_mng_raw_chapters_{$manhwaId}_1"]]]
        ]);
        exit;
    }

    elseif (strpos($callbackData, 'admin_mng_raw_del_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'proj_edit')) exit;
        $data = str_replace('admin_mng_raw_del_', '', $callbackData);
        $parts = explode('_', $data);
        $rawId    = (int)$parts[0];
        $manhwaId = (int)$parts[1];

        $stmt = $db->prepare("DELETE FROM manhwa_raw_chapters WHERE bot_id = :bot_id AND id = :id");
        $stmt->execute(['bot_id' => $botId, 'id' => $rawId]);

        $tg->sendMessage($userId, "✅ چپتر خام مانهوا با موفقیت از آرشیو حذف شد.", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت به مانهواها', 'callback_data' => "admin_mng_raw_chapters_{$manhwaId}_1"]]]
        ]);
        exit;
    }

    // نمایش اعضای رسمی به روش ۳ ستونه متقارن و کاملاً تراز
    elseif (strpos($callbackData, 'admin_team_list_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $page = (int)str_replace('admin_team_list_', '', $callbackData);
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $stmtCount = $db->prepare("SELECT COUNT(*) as total FROM users WHERE bot_id = :bot_id AND role != 'none' AND status = 'approved'");
        $stmtCount->execute(['bot_id' => $botId]);
        $total = $stmtCount->fetch()['total'];
        $totalPages = max(1, ceil($total / $limit));

        $stmt = $db->prepare("SELECT tg_id, full_name, role FROM users WHERE bot_id = :bot_id AND role != 'none' AND status = 'approved' ORDER BY joined_at ASC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':bot_id', $botId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $members = $stmt->fetchAll();

        $textList = "👥 <b>لیست اعضای رسمی تیم (صفحه {$page} از {$totalPages}):</b>\n\n"
                  . "جهت مدیریت کارکرد، یادداشت‌ها و امتیازات روی دکمه مشاهده کلیک کنید:";

        $buttons = [];
        $buttons[] = [['text' => '🔍 جستجوی عضو تیم', 'callback_data' => 'admin_user_search_init']];

        $buttons[] = [
            ['text' => '👁 مشاهده', 'callback_data' => 'dummy_header'],
            ['text' => '💵 حقوق ماه', 'callback_data' => 'dummy_header'],
            ['text' => '👤 نام عضو', 'callback_data' => 'dummy_header']
        ];

        foreach ($members as $m) {
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
            $monthlyEarned = (float)$stmtEarned->fetch()['m_earned'];

            $payFormatted = ($monthlyEarned == 0) ? '0' : number_format($monthlyEarned);
            $truncatedName = mb_strimwidth($m['full_name'], 0, 14, '...');

            $buttons[] = [
                ['text' => '👁', 'callback_data' => "admin_user_v_{$m['tg_id']}"],
                ['text' => $payFormatted, 'callback_data' => "admin_user_v_{$m['tg_id']}"],
                ['text' => $truncatedName, 'callback_data' => "admin_user_v_{$m['tg_id']}"]
            ];
        }

        $navButtons = [];
        if ($page > 1) {
            $navButtons[] = ['text' => '◀️ قبلی', 'callback_data' => 'admin_team_list_' . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navButtons[] = ['text' => 'بعدی ▶️', 'callback_data' => 'admin_team_list_' . ($page + 1)];
        }
        if (!empty($navButtons)) {
            $buttons[] = $navButtons;
        }

        $buttons[] = [['text' => '🔙 بازگشت به تنظیمات', 'callback_data' => 'admin_settings']];

        $tg->sendMessage($userId, $textList, ['inline_keyboard' => $buttons]);
        exit;
    }

    elseif ($callbackData === 'admin_user_search_init') {
        $tg->answerCallbackQuery($callbackId);
        FSM::setStep($botId, $userId, 'admin_waiting_user_search');
        $tg->sendMessage($userId, "🔍 نام یا یوزرنیم تلگرام کاربر مورد نظر را بفرستید:", [
            'inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => 'admin_team_list_1']]]
        ]);
        exit;
    }

    // مشاهده شناسنامه و پروفایل پرسنل
    elseif (strpos($callbackData, 'admin_user_v_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $targetUserId = str_replace('admin_user_v_', '', $callbackData);

        $stmt = $db->prepare("SELECT * FROM users WHERE bot_id = :bot_id AND tg_id::text = :tg_id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'tg_id' => $targetUserId]);
        $u = $stmt->fetch();

        if ($u) {
            $periods = [30 => '۱ ماهه', 90 => '۳ ماهه', 180 => '۶ ماهه', 270 => '۶ ماهه'];
            $statsText = "";

            foreach ($periods as $days => $label) {
                $stmtP = $db->prepare("
                    SELECT COUNT(*) as ch_count,
                           COALESCE(SUM(CASE 
                               WHEN translator_id = :u_id THEN translator_pay 
                               WHEN cleaner_id = :u_id THEN cleaner_pay 
                               WHEN typesetter_id = :u_id THEN typesetter_pay 
                               ELSE 0 
                           END), 0) as earnings
                    FROM chapters 
                    WHERE bot_id = :bot_id 
                      AND status = 'approved' 
                      AND (translator_id = :u_id OR cleaner_id = :u_id OR typesetter_id = :u_id)
                      AND created_at >= CURRENT_TIMESTAMP - (:days * INTERVAL '1 day')
                ");
                $stmtP->bindValue(':u_id', $u['tg_id'], PDO::PARAM_INT);
                $stmtP->bindValue(':bot_id', $botId, PDO::PARAM_INT);
                $stmtP->bindValue(':days', $days, PDO::PARAM_INT);
                $stmtP->execute();
                $pData = $stmtP->fetch();

                $statsText .= "🔹 <b>دوره {$label}:</b> <code>{$pData['ch_count']}</code> چپتر | <code>" . number_format($pData['earnings']) . "</code> تومان درآمد\n";
            }

            // محاسبه میانگین امتیازات
            $avgScore = calculateAverageRating($db, $botId, $u['tg_id']);

            $detailMsg = "📊 <b>آمار فعالیت‌های فردی کاربر:</b>\n\n"
                       . "👤 نام: <b>{$u['full_name']}</b>\n"
                       . "⚔️ سمت‌ها: <b>" . getRoleFarsiAdmin($u['role']) . "</b>\n"
                       . "🆔 آیدی تلگرام: <code>{$u['tg_id']}</code>\n"
                       . "⚠️ تعداد اخطارهای انضباطی: <code>" . ($u['warnings'] ?? 0) . "</code>\n"
                       . "⭐ میانگین امتیاز کیفی: <code>{$avgScore}</code> از 100\n\n"
                       . "📈 <b>گزارش کارکرد دوره‌ای:</b>\n"
                       . $statsText;

            $keyboard = [];
            
            // سطر اول: عملیات یادداشت‌ها و امتیازات
            $keyboard[] = [
                ['text' => '📝 یادداشت‌های ادمین', 'callback_data' => "admin_mng_notes_page_{$u['tg_id']}_1"],
                ['text' => '⭐ امتیازدهی کیفی', 'callback_data' => "admin_mng_ratings_page_{$u['tg_id']}"]
            ];

            // سطر دوم: مبالغ پاداش و هدیه نقدی
            $keyboard[] = [
                ['text' => '🎁 واریز پول هدیه', 'callback_data' => "admin_mng_gift_init_{$u['tg_id']}"]
            ];

            // سطر سوم: امنیت و هشدارها
            $keyboardRow3 = [];
            if (hasPermission($db, $botId, $userId, 'warn_user')) {
                $keyboardRow3[] = ['text' => '⚠️ اخطار کتبی', 'callback_data' => "admin_usr_warn_{$u['tg_id']}"];
            }
            if (hasPermission($db, $botId, $userId, 'user_ban')) {
                $keyboardRow3[] = ['text' => '⛔️ بن تیمی', 'callback_data' => "admin_usr_ban_{$u['tg_id']}"];
            }
            if (!empty($keyboardRow3)) {
                $keyboard[] = $keyboardRow3;
            }

            if (hasPermission($db, $botId, $userId, 'warn_user')) {
                $keyboard[] = [['text' => '✉️ ارسال پیام مستقیم پی‌وی', 'callback_data' => "admin_usr_dm_{$u['tg_id']}"]];
            }

            $keyboard[] = [['text' => '🔙 بازگشت به لیست اعضا', 'callback_data' => 'admin_team_list_1']];

            $tg->sendMessage($userId, $detailMsg, ['inline_keyboard' => $keyboard]);
        }
        exit;
    }

    elseif (strpos($callbackData, 'admin_usr_warn_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'warn_user')) exit;
        $targetUserId = str_replace('admin_usr_warn_', '', $callbackData);

        FSM::setStep($botId, $userId, "admin_waiting_warn_reason_{$targetUserId}");
        $tg->sendMessage($userId, "✍️ دلیل ارسال اخطار کتبی به فرد را وارد کنید:");
        exit;
    }

    elseif (strpos($callbackData, 'admin_usr_dm_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!hasPermission($db, $botId, $userId, 'warn_user')) exit;
        $targetUserId = str_replace('admin_usr_dm_', '', $callbackData);

        FSM::setStep($botId, $userId, "admin_waiting_dm_text_{$targetUserId}");
        $tg->sendMessage($userId, "✍️ متن پیام مستقیم به کاربر را تایپ کنید:");
        exit;
    }

    // یادداشت‌های ادمین (Notes Management)
    elseif (strpos($callbackData, 'admin_mng_notes_page_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $data = str_replace('admin_mng_notes_page_', '', $callbackData);
        $parts = explode('_', $data);
        $targetUserId = $parts[0];
        $page         = (int)$parts[1];

        $limit = 5;
        $offset = ($page - 1) * $limit;

        $stmtCount = $db->prepare("SELECT COUNT(*) as total FROM member_notes WHERE bot_id = :bot_id AND user_id = :u_id");
        $stmtCount->execute(['bot_id' => $botId, 'u_id' => $targetUserId]);
        $total = $stmtCount->fetch()['total'];
        $totalPages = max(1, ceil($total / $limit));

        $stmt = $db->prepare("SELECT id, title, created_at FROM member_notes WHERE bot_id = :bot_id AND user_id = :u_id ORDER BY id DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':bot_id', $botId, PDO::PARAM_INT);
        $stmt->bindValue(':u_id', $targetUserId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $notes = $stmt->fetchAll();

        $textNotes = "📋 <b>یادداشت‌های ثبت شده برای کاربر (صفحه {$page} از {$totalPages}):</b>\n\n"
                   . "این یادداشت‌ها کاملاً محرمانه بوده و فقط برای کادر مدیریت قابل مشاهده هستند:";

        $buttons = [];
        $buttons[] = [['text' => '➕ افزودن یادداشت جدید', 'callback_data' => "admin_mng_note_add_{$targetUserId}"]];

        foreach ($notes as $n) {
            $buttons[] = [
                ['text' => "📝 " . $n['title'], 'callback_data' => "admin_mng_note_view_{$n['id']}_{$targetUserId}"],
                ['text' => '🗑 حذف', 'callback_data' => "admin_mng_note_del_{$n['id']}_{$targetUserId}"]
            ];
        }

        $navButtons = [];
        if ($page > 1) {
            $navButtons[] = ['text' => '◀️ قبلی', 'callback_data' => "admin_mng_notes_page_{$targetUserId}_" . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navButtons[] = ['text' => 'بعدی ▶️', 'callback_data' => "admin_mng_notes_page_{$targetUserId}_" . ($page + 1)];
        }
        if (!empty($navButtons)) {
            $buttons[] = $navButtons;
        }
        $buttons[] = [['text' => '🔙 بازگشت به کاربر', 'callback_data' => "admin_user_v_{$targetUserId}"]];

        $tg->sendMessage($userId, $textNotes, ['inline_keyboard' => $buttons]);
        exit;
    }

    elseif (strpos($callbackData, 'admin_mng_note_add_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $targetUserId = str_replace('admin_mng_note_add_', '', $callbackData);

        FSM::setStep($botId, $userId, "admin_waiting_mng_note_title_{$targetUserId}");
        $tg->sendMessage($userId, "✍️ یک عنوان کوتاه برای یادداشت خود بنویسید (مثال: رفتار تیمی):");
        exit;
    }

    elseif (strpos($callbackData, 'admin_mng_note_view_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $data = str_replace('admin_mng_note_view_', '', $callbackData);
        $parts = explode('_', $data);
        $noteId       = (int)$parts[0];
        $targetUserId = $parts[1];

        $stmt = $db->prepare("SELECT * FROM member_notes WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $noteId]);
        $note = $stmt->fetch();

        if ($note) {
            $textMsg = "📝 <b>یادداشت: {$note['title']}</b>\n"
                     . "📅 تاریخ ثبت: <code>{$note['created_at']}</code>\n\n"
                     . "💬 <b>متن یادداشت:</b>\n<i>{$note['note']}</i>";

            $tg->sendMessage($userId, $textMsg, [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => "admin_mng_notes_page_{$targetUserId}_1"]]]
            ]);
        }
        exit;
    }

    elseif (strpos($callbackData, 'admin_mng_note_del_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $data = str_replace('admin_mng_note_del_', '', $callbackData);
        $parts = explode('_', $data);
        $noteId       = (int)$parts[0];
        $targetUserId = $parts[1];

        $stmt = $db->prepare("DELETE FROM member_notes WHERE bot_id = :bot_id AND id = :id");
        $stmt->execute(['bot_id' => $botId, 'id' => $noteId]);

        $tg->sendMessage($userId, "✅ یادداشت با موفقیت حذف شد.", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت به لیست یادداشت‌ها', 'callback_data' => "admin_mng_notes_page_{$targetUserId}_1"]]]
        ]);
        exit;
    }

    // امتیازدهی کیفی اعضا (Dynamic Ratings Engine)
    elseif (strpos($callbackData, 'admin_mng_ratings_page_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $targetUserId = str_replace('admin_mng_ratings_page_', '', $callbackData);

        // دریافت لیست سربرگ‌های پویا تعریف شده در سیستم
        $stmtCats = $db->prepare("SELECT id, title FROM rating_categories WHERE bot_id = :bot_id ORDER BY id ASC");
        $stmtCats->execute(['bot_id' => $botId]);
        $categories = $stmtCats->fetchAll();

        if (empty($categories)) {
            $tg->sendMessage($userId, "⚠️ هنوز هیچ سربرگ امتیازدهی (مانند اخلاق، سرعت و...) در سیستم تعریف نشده است.\n\nابتدا وارد بخش [تنظیمات تیم -> ثبت سربرگ امتیاز] شوید.", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت به کاربر', 'callback_data' => "admin_user_v_{$targetUserId}"]]]
            ]);
            exit;
        }

        $statsText = "";
        $buttons = [];

        foreach ($categories as $cat) {
            $stmtScore = $db->prepare("SELECT score FROM member_ratings WHERE bot_id = :bot_id AND user_id = :user_id AND category_id = :cat_id LIMIT 1");
            $stmtScore->execute([
                'bot_id'  => $botId,
                'user_id' => $targetUserId,
                'cat_id'  => $cat['id']
            ]);
            $scoreRow = $stmtScore->fetch();
            $score = $scoreRow ? (float)$scoreRow['score'] : 0;

            $statsText .= "🔸 <b>{$cat['title']}:</b> <code>{$score}</code> از 100\n";
            $buttons[] = [['text' => "⭐ ثبت/ویرایش نمره «{$cat['title']}»", 'callback_data' => "admin_mng_rating_set_{$targetUserId}_{$cat['id']}"]];
        }

        $avgScore = calculateAverageRating($db, $botId, $targetUserId);

        $textRatings = "⭐ <b>پنل ارزیابی و امتیازدهی کیفی اعضا:</b>\n\n"
                     . "📈 <b>نمرات ثبت شده فعلی:</b>\n"
                     . $statsText . "\n"
                     . "📊 <b>میانگین کل امتیازات فرد:</b> <code>{$avgScore}</code> از 100\n\n"
                     . "جهت تغییر یا ثبت نمره هر بخش روی دکمه متناظر کلیک کنید:";

        $buttons[] = [['text' => '🔙 بازگشت به کاربر', 'callback_data' => "admin_user_v_{$targetUserId}"]];

        $tg->sendMessage($userId, $textRatings, ['inline_keyboard' => $buttons]);
        exit;
    }

    elseif (strpos($callbackData, 'admin_mng_rating_set_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $data = str_replace('admin_mng_rating_set_', '', $callbackData);
        $parts = explode('_', $data);
        $targetUserId = $parts[0];
        $catId        = (int)$parts[1];

        $stmtCat = $db->prepare("SELECT title FROM rating_categories WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmtCat->execute(['bot_id' => $botId, 'id' => $catId]);
        $catName = $stmtCat->fetch()['title'] ?? 'شاخص';

        FSM::setStep($botId, $userId, "admin_waiting_mng_rating_score_{$targetUserId}_{$catId}");
        $tg->sendMessage($userId, "⭐ <b>نمره شاخص «{$catName}» را وارد کنید:</b>\n\nنمره باید عددی بین 0 تا 100 باشد:");
        exit;
    }

    elseif (strpos($callbackData, 'admin_mng_gift_init_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $targetUserId = str_replace('admin_mng_gift_init_', '', $callbackData);

        FSM::setStep($botId, $userId, "admin_waiting_mng_gift_{$targetUserId}");
        $tg->sendMessage($userId, "🎁 <b>واریز هدیه نقدی (پاداش مالی) مستقیم به کیف پول کاربر:</b>\n\nلطفاً مبلغ مورد نظر خود را به تومان (فقط به صورت عدد) وارد کنید:");
        exit;
    }
}
