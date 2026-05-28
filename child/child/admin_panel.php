<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: child/admin_panel.php
 * Role: Advanced Admin & Owner Dashboard Processor
 */

// اطمینان از صحت کانتکست و متغیرها
if (!isset($botContext) || !isset($tg) || !isset($user) || !isset($db)) {
    exit;
}

$userId    = $user['tg_id'];
$fullName  = $user['full_name'];
$username  = $user['username'] ?? '';
$step      = $user['step'];
$botId     = $botContext['bot_id'];

// تابع کمکی تبدیل نقش‌ها به فارسی
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

// ==========================================
// فاز ۱: پردازش وضعیت‌های ورودی متنی FSM (ادمین در حال تایپ مشخصات است)
// ==========================================
if ($message && !empty($message['text'])) {
    $text = trim($message['text']);

    // الف) دریافت شرایط و قوانین جدید
    if ($step === 'admin_waiting_rules') {
        $stmt = $db->prepare("INSERT INTO settings (bot_id, key, value) VALUES (:bot_id, 'rules', :rules) ON CONFLICT (bot_id, key) DO UPDATE SET value = EXCLUDED.value");
        $stmt->execute(['bot_id' => $botId, 'rules' => $text]);

        FSM::clearStep($botId, $userId);
        $tg->sendMessage($userId, "✅ <b>شرایط و قوانین استخدام با موفقیت به‌روزرسانی شد.</b>", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت به منوی عضوگیری', 'callback_data' => 'admin_recruit']]]
        ]);
        exit;
    }

    // ب) دریافت نرخ جدید حقوق برای نقش‌ها
    elseif (strpos($step, 'admin_waiting_rate_') === 0) {
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
        $tg->sendMessage($userId, "✅ <b>نرخ دستمزد نقشه " . getRoleFarsiAdmin($roleToUpdate) . " با موفقیت به {$text} تومان تغییر یافت.</b>", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت به نرخ‌ها', 'callback_data' => 'admin_salary_rates']]]
        ]);
        exit;
    }

    // ج) ارسال پیام مستقیم به کاربر از طریق آیدی او
    elseif (strpos($step, 'admin_send_msg_') === 0) {
        $targetUserId = (int)str_replace('admin_send_msg_', '', $step);

        $sent = $tg->sendMessage($targetUserId, "✉️ <b>پیام مدیریت تیم مانهوا برای شما:</b>\n\n" . $text);
        
        FSM::clearStep($botId, $userId);
        if ($sent && isset($sent['ok']) && $sent['ok'] === true) {
            $tg->sendMessage($userId, "✅ پیام شما با موفقیت برای کاربر ارسال شد.");
        } else {
            $tg->sendMessage($userId, "❌ خطا در ارسال پیام. احتمالاً کاربر ربات را بلاک کرده است.");
        }
        exit;
    }

    // د) دریافت آیدی عددی گروه اصلی تیم
    elseif ($step === 'admin_waiting_team_group_id') {
        if (!is_numeric($text)) {
            $tg->sendMessage($userId, "❌ آیدی عددی گروه تلگرام باید یک عدد منفی بزرگ باشد (مثال: -100123456789):");
            exit;
        }

        $stmt = $db->prepare("INSERT INTO settings (bot_id, key, value) VALUES (:bot_id, 'team_group_id', :value) ON CONFLICT (bot_id, key) DO UPDATE SET value = EXCLUDED.value");
        $stmt->execute(['bot_id' => $botId, 'value' => $text]);

        FSM::clearStep($botId, $userId);
        $tg->sendMessage($userId, "✅ <b>آیدی عددی گروه اصلی با موفقیت ثبت شد.</b>", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت به تنظیمات', 'callback_data' => 'admin_settings']]]
        ]);
        exit;
    }

    // ه) دریافت آیدی کاربر جهت ارتقا به ادمین مانهوا
    elseif ($step === 'admin_waiting_add_admin_id') {
        if (!is_numeric($text)) {
            $tg->sendMessage($userId, "❌ آیدی عددی تلگرام فقط باید عدد باشد:");
            exit;
        }

        // بررسی اینکه آیا کاربر قبلاً در ربات عضو بوده است
        $stmt = $db->prepare("SELECT * FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'tg_id' => (int)$text]);
        $targetUser = $stmt->fetch();

        if (!$targetUser) {
            $tg->sendMessage($userId, "❌ کاربری با این آیدی عددی هنوز ربات را استارت نکرده است.");
            exit;
        }

        FSM::setRole($botId, (int)$text, 'admin');
        FSM::setStatus($botId, (int)$text, 'approved');
        FSM::clearStep($botId, $userId);

        $tg->sendMessage((int)$text, "🎉 <b>شما توسط مدیریت مانهوا به مقام ادمین تیم ارتقا یافتید!</b>\n\nربات را مجدد <code>/start</code> کنید تا دسترسی‌ها فعال شوند.");
        $tg->sendMessage($userId, "✅ <b>کاربر {$targetUser['full_name']} با موفقیت به ادمین ارتقا یافت.</b>", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_settings']]]
        ]);
        exit;
    }

    // و) فرستادن پیام همگانی به تمام گروه‌های متصل به پروژه‌ها
    elseif ($step === 'admin_waiting_broadcast_groups') {
        $stmt = $db->prepare("SELECT DISTINCT group_id FROM manhwas WHERE bot_id = :bot_id AND group_id IS NOT NULL");
        $stmt->execute(['bot_id' => $botId]);
        $groups = $stmt->fetchAll();

        if (empty($groups)) {
            $tg->sendMessage($userId, "⚠️ هیچ گروه تلگرامی به پروژه‌های مانهوای شما متصل نشده است.");
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
        $tg->sendMessage($userId, "✅ پیام شما با موفقیت به <code>{$successCount}</code> گروه مانهوا ارسال شد.", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_settings']]]
        ]);
        exit;
    }

    // ز) دریافت مشخصات عضو جهت انتساب به یک پروژه
    elseif (strpos($step, 'admin_waiting_assign_') === 0) {
        // ساختار مرحله: admin_waiting_assign_MANHWAID_ROLE
        $paramsStr = str_replace('admin_waiting_assign_', '', $step);
        $parts     = explode('_', $paramsStr);
        $manhwaId  = (int)$parts[0];
        $roleToSet = $parts[1]; // translator, cleaner, typesetter

        // جستجو بر اساس یوزرنیم یا آیدی عددی تلگرام
        $targetUser = null;
        if (strpos($text, '@') === 0) {
            $searchUser = str_replace('@', '', $text);
            $stmt = $db->prepare("SELECT tg_id, full_name FROM users WHERE bot_id = :bot_id AND username = :username LIMIT 1");
            $stmt->execute(['bot_id' => $botId, 'username' => $searchUser]);
            $targetUser = $stmt->fetch();
        } elseif (is_numeric($text)) {
            $stmt = $db->prepare("SELECT tg_id, full_name FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id LIMIT 1");
            $stmt->execute(['bot_id' => $botId, 'tg_id' => (int)$text]);
            $targetUser = $stmt->fetch();
        }

        if (!$targetUser) {
            $tg->sendMessage($userId, "❌ کاربری با این مشخصات یافت نشد. لطفاً آیدی عددی معتبر یا آیدی کاربری تلگرام (با @) بفرستید:");
            exit;
        }

        // ثبت انتساب تیم در جدول team_assignments دیتابیس نئون
        $stmtAssign = $db->prepare("
            INSERT INTO team_assignments (bot_id, manhwa_id, role, user_id)
            VALUES (:bot_id, :manhwa_id, :role, :user_id)
            ON CONFLICT (bot_id, manhwa_id, role) DO UPDATE SET user_id = EXCLUDED.user_id
        ");
        
        // توجه: برای استفاده از ON CONFLICT بالا مطمئن شوید کلید یکتا (bot_id, manhwa_id, role) در دیتابیس باشد.
        // در غیر این صورت، ساختار سنتی بدون کانفلیکت با دیلیت قبلی پیاده می‌شود:
        $stmtDelete = $db->prepare("DELETE FROM team_assignments WHERE bot_id = :bot_id AND manhwa_id = :manhwa_id AND role = :role");
        $stmtDelete->execute(['bot_id' => $botId, 'manhwa_id' => $manhwaId, 'role' => $roleToSet]);

        $stmtInsert = $db->prepare("INSERT INTO team_assignments (bot_id, manhwa_id, role, user_id) VALUES (:bot_id, :manhwa_id, :role, :user_id)");
        $stmtInsert->execute([
            'bot_id'    => $botId,
            'manhwa_id' => $manhwaId,
            'role'      => $roleToSet,
            'user_id'   => $targetUser['tg_id']
        ]);

        FSM::clearStep($botId, $userId);
        $tg->sendMessage($userId, "✅ کاربر <b>{$targetUser['full_name']}</b> با موفقیت به عنوان <b>" . getRoleFarsiAdmin($roleToSet) . "</b> این پروژه منتسب شد.", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت به مانهوا', 'callback_data' => "admin_view_manhwa_{$manhwaId}"]]]
        ]);
        exit;
    }
}

// ==========================================
// فاز ۲: پردازش کلیک روی دکمه‌های شیشه‌ای (Callback Queries)
// ==========================================
if ($callbackQuery) {
    $callbackData = $callbackQuery['data'];
    $callbackId   = $callbackQuery['id'];
    $messageId    = $callbackQuery['message']['message_id'];

    $tg->answerCallbackQuery($callbackId);

    // ۱. منوی اصلی ادمین
    if ($callbackData === 'admin_back_to_menu') {
        FSM::clearStep($botId, $userId);

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📚 لیست کارها (پروژه‌ها)', 'callback_data' => 'admin_projects']],
                [['text' => '👥 مدیریت عضوگیری', 'callback_data' => 'admin_recruit']],
                [['text' => '⚙️ تنظیمات تیم', 'callback_data' => 'admin_settings']]
            ]
        ];
        
        $tg->sendMessage($userId, "👋 به پنل مدیریت تیم خوش آمدید. بخش مورد نظر را انتخاب کنید:", $keyboard);
        exit;
    }

    // ۲. ورود به بخش لیست کارها (پروژه‌ها)
    elseif ($callbackData === 'admin_projects') {
        $stmt = $db->prepare("SELECT id, title FROM manhwas WHERE bot_id = :bot_id ORDER BY id DESC");
        $stmt->execute(['bot_id' => $botId]);
        $manhwas = $stmt->fetchAll();

        if (empty($manhwas)) {
            $tg->sendMessage($userId, "⚠️ هنوز هیچ مانهوایی ثبت نشده است. برای اضافه کردن باید دستور <code>/add_manhwa</code> را در گروه ثبت کنید.", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_back_to_menu']]]
            ]);
        } else {
            $textProj = "📚 <b>لیست مانهواهای ثبت شده در دیتابیس:</b>\n\nبرای دیدن جزییات و ویرایش تیم مانهواها کلیک کنید:";
            $buttons = [];
            foreach ($manhwas as $m) {
                $buttons[] = [['text' => "📚 " . $m['title'], 'callback_data' => "admin_view_manhwa_{$m['id']}"]];
            }
            $buttons[] = [['text' => '🔙 بازگشت', 'callback_data' => 'admin_back_to_menu']];

            $tg->sendMessage($userId, $textProj, ['inline_keyboard' => $buttons]);
        }
        exit;
    }

    // ۳. جزییات یک مانهوای خاص با تیم متصل شده به آن
    elseif (strpos($callbackData, 'admin_view_manhwa_') === 0) {
        $manhwaId = (int)str_replace('admin_view_manhwa_', '', $callbackData);

        // واکشی مشخصات مانهوا
        $stmt = $db->prepare("SELECT * FROM manhwas WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $manhwaId]);
        $manhwa = $stmt->fetch();

        if ($manhwa) {
            // دریافت جزییات تیم انتصابی
            $stmtTeam = $db->prepare("
                SELECT ta.role, u.full_name, u.tg_id 
                FROM team_assignments ta
                JOIN users u ON ta.bot_id = u.bot_id AND ta.user_id = u.tg_id
                WHERE ta.bot_id = :bot_id AND ta.manhwa_id = :manhwa_id
            ");
            $stmtTeam->execute(['bot_id' => $botId, 'manhwa_id' => $manhwaId]);
            $teamMembers = $stmtTeam->fetchAll();

            $teamAssigned = [
                'translator' => '❌ بدون انتساب',
                'cleaner'    => '❌ بدون انتساب',
                'typesetter' => '❌ بدون انتساب'
            ];
            foreach ($teamMembers as $tm) {
                $teamAssigned[$tm['role']] = "👤 " . $tm['full_name'] . " (<code>" . $tm['tg_id'] . "</code>)";
            }

            $caption = "📚 <b>جزئیات مانهوا: {$manhwa['title']}</b>\n"
                     . "🎭 ژانرها: {$manhwa['genres']}\n"
                     . "🔢 آخرین چپتر: <code>{$manhwa['last_chapter']}</code>\n"
                     . "👥 آیدی گروه: <code>" . ($manhwa['group_id'] ?? 'ثبت نشده') . "</code>\n\n"
                     . "⚔️ <b>اعضای تیم متصل شده:</b>\n"
                     . "├ مترجم: {$teamAssigned['translator']}\n"
                     . "├ کلینر: {$teamAssigned['cleaner']}\n"
                     . "└ تایپیست: {$teamAssigned['typesetter']}\n\n"
                     . "⚙️ برای عزل یا نصب مستقیم اعضا روی مانهوا از گزینه‌های زیر استفاده کنید:";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '➕ انتساب مترجم', 'callback_data' => "admin_assign_{$manhwaId}_translator"],
                        ['text' => '❌ عزل مترجم', 'callback_data' => "admin_dismiss_{$manhwaId}_translator"]
                    ],
                    [
                        ['text' => '➕ انتساب کلینر', 'callback_data' => "admin_assign_{$manhwaId}_cleaner"],
                        ['text' => '❌ عزل کلینر', 'callback_data' => "admin_dismiss_{$manhwaId}_cleaner"]
                    ],
                    [
                        ['text' => '➕ انتساب تایپیست', 'callback_data' => "admin_assign_{$manhwaId}_typesetter"],
                        ['text' => '❌ عزل تایپیست', 'callback_data' => "admin_dismiss_{$manhwaId}_typesetter"]
                    ],
                    [['text' => '🔙 بازگشت به لیست مانهواها', 'callback_data' => 'admin_projects']]
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

    // ۴. عزل مستقیم یک عضو از مانهوا
    elseif (strpos($callbackData, 'admin_dismiss_') === 0) {
        $params = str_replace('admin_dismiss_', '', $callbackData);
        $parts  = explode('_', $params);
        $mId    = (int)$parts[0];
        $role   = $parts[1];

        $stmt = $db->prepare("DELETE FROM team_assignments WHERE bot_id = :bot_id AND manhwa_id = :manhwa_id AND role = :role");
        $stmt->execute(['bot_id' => $botId, 'manhwa_id' => $mId, 'role' => $role]);

        $tg->sendMessage($userId, "✅ عزل " . getRoleFarsiAdmin($role) . " با موفقیت انجام شد.", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت به مانهوا', 'callback_data' => "admin_view_manhwa_{$mId}"]]]
        ]);
        exit;
    }

    // ۵. آغاز فرآیند انتساب مستقیم یک عضو به مانهوا
    elseif (strpos($callbackData, 'admin_assign_') === 0) {
        $params = str_replace('admin_assign_', '', $callbackData);
        
        FSM::setStep($botId, $userId, "admin_waiting_assign_{$params}");

        $tg->sendMessage($userId, "👤 <b>لطفاً شناسه عضو جدید را ارسال کنید:</b>\n\nمی‌توانید آیدی عددی تلگرام کاربر را بفرستید، یا آیدی کاربری او را (با علامت @) ارسال کنید:");
        exit;
    }

    // ۶. ورود به بخش مدیریت عضوگیری
    elseif ($callbackData === 'admin_recruit') {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📂 آخرین تست‌های حل شده', 'callback_data' => 'admin_view_tests']],
                [['text' => '⚙️ تغییر شرایط و قوانین استخدام', 'callback_data' => 'admin_edit_rules']],
                [['text' => '🔙 بازگشت به منوی ادمین', 'callback_data' => 'admin_back_to_menu']]
            ]
        ];
        
        $tg->sendMessage($userId, "👥 بخش استخدام و عضوگیری مانهوا:", $keyboard);
        exit;
    }

    // ۷. مشاهده شرایط و قوانین جهت ادیت
    elseif ($callbackData === 'admin_edit_rules') {
        FSM::setStep($botId, $userId, 'admin_waiting_rules');
        $tg->sendMessage($userId, "✍️ <b>لطفاً قوانین و شرایط جدید مانهوا را به صورت یک پیام بنویسید و بفرستید:</b>");
        exit;
    }

    // ۸. مشاهده ۱۰ تست حل شده بررسی نشده آخر
    elseif ($callbackData === 'admin_view_tests') {
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

    // ۹. ادمین فایل حل شده تست کاربر را دریافت و چک می‌کند
    elseif (strpos($callbackData, 'admin_check_test_') === 0) {
        $testId = (int)str_replace('admin_check_test_', '', $callbackData);

        $stmt = $db->prepare("SELECT file_id, role FROM submitted_tests WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $testId]);
        $testFile = $stmt->fetch();

        if ($testFile) {
            $tg->sendDocument($userId, $testFile['file_id'], "📄 فایل تست حل شده داوطلب برای نقش <b>" . getRoleFarsiAdmin($testFile['role']) . "</b>");
        } else {
            $tg->sendMessage($userId, "❌ فایل یافت نشد.");
        }
        exit;
    }

    // ۱۰. باز شدن موتور تعاملی پیام مستقیم ادمین به داوطلب
    elseif (strpos($callbackData, 'admin_msg_') === 0) {
        $targetId = str_replace('admin_msg_', '', $callbackData);
        FSM::setStep($botId, $userId, "admin_send_msg_{$targetId}");
        $tg->sendMessage($userId, "✍️ <b>لطفاً پیام خود را خطاب به داوطلب استخدام تایپ کنید و بفرستید:</b>");
        exit;
    }

    // ۱۱. رد کردن مستقیم تست داوطلب
    elseif (strpos($callbackData, 'admin_reject_test_') === 0) {
        $testId = (int)str_replace('admin_reject_test_', '', $callbackData);

        $stmt = $db->prepare("SELECT user_id, role FROM submitted_tests WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $testId]);
        $test = $stmt->fetch();

        if ($test) {
            // آپدیت وضعیت تست و ریست وضعیت کاربر در دیتابیس
            $stmtUpdateTest = $db->prepare("UPDATE submitted_tests SET status = 'rejected' WHERE bot_id = :bot_id AND id = :id");
            $stmtUpdateTest->execute(['bot_id' => $botId, 'id' => $testId]);

            FSM::setStatus($botId, $test['user_id'], 'rejected');

            $tg->sendMessage($test['user_id'], "❌ <b>درخواست عضویت شما رد شد.</b>\n\nمتاسفانه تست شما برای نقش <b>" . getRoleFarsiAdmin($test['role']) . "</b> مورد قبول ادمین‌های مانهوا قرار نگرفت. با آرزوی موفقیت در دفعات بعدی.");
            $tg->sendMessage($userId, "❌ درخواست استخدام کاربر رد شد و به او اطلاع داده شد.");
        }
        exit;
    }

    // ۱۲. تایید استخدام و ارسال خودکار لینک دعوت یک‌بار مصرف
    elseif (strpos($callbackData, 'admin_accept_test_') === 0) {
        $testId = (int)str_replace('admin_accept_test_', '', $callbackData);

        $stmt = $db->prepare("SELECT user_id, role FROM submitted_tests WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $testId]);
        $test = $stmt->fetch();

        if ($test) {
            // دریافت آیدی عددی گروه اصلی تیم از جدول تنظیمات
            $stmtGroup = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = 'team_group_id' LIMIT 1");
            $stmtGroup->execute(['bot_id' => $botId]);
            $groupRow = $stmtGroup->fetch();
            $teamGroupId = $groupRow ? $groupRow['value'] : null;

            if (empty($teamGroupId)) {
                $tg->sendMessage($userId, "⚠️ <b>کاربر تایید شد، اما لینک دعوت ارسال نشد!</b>\n\nابتدا باید آیدی عددی گروه مانهوای تیم خود را در پنل ادمین بخش [تنظیمات تیم -> ثبت گروه اصلی] وارد کنید تا ربات بتواند لینک دعوت بسازد.");
                
                // ارتقای نقش کاربر در دیتابیس بدون ارسال لینک
                FSM::setRole($botId, $test['user_id'], $test['role']);
                FSM::setStatus($botId, $test['user_id'], 'approved');
                exit;
            }

            // تولید لینک دعوت یک‌بار مصرف
            $inviteLink = $tg->createChatInviteLink($teamGroupId, 86400); // انقضا در ۲۴ ساعت

            if ($inviteLink) {
                // آپدیت وضعیت تست به پذیرفته‌شده
                $stmtUpdateTest = $db->prepare("UPDATE submitted_tests SET status = 'accepted' WHERE bot_id = :bot_id AND id = :id");
                $stmtUpdateTest->execute(['bot_id' => $botId, 'id' => $testId]);

                FSM::setRole($botId, $test['user_id'], $test['role']);
                FSM::setStatus($botId, $test['user_id'], 'approved');

                $roleNameFarsi = getRoleFarsiAdmin($test['role']);
                $congratsText = "🎉 <b>تبریک می‌گویم! شما در آزمون عضوگیری تیم پذیرفته شدید.</b>\n\n"
                              . "⚔️ نقش تایید شده شما: <b>{$roleNameFarsi}</b>\n\n"
                              . "🔗 در زیر لینک ورود اختصاصی، یک‌بار مصرف و ۲۴ ساعته شما به گروه کار قرار گرفته است. روی آن بزنید و وارد شوید:\n\n"
                              . "👉 {$inviteLink}";

                $tg->sendMessage($test['user_id'], $congratsText);
                $tg->sendMessage($userId, "✅ <b>کاربر با موفقیت استخدام شد!</b>\n\nنقش کاربر روی <b>{$roleNameFarsi}</b> قرار گرفت و لینک دعوت اختصاصی تلگرام برای او ارسال شد.");
            } else {
                $tg->sendMessage($userId, "❌ خطا: ربات نتوانست لینک دعوت یک‌بار مصرف بسازد. لطفاً مطمئن شوید ربات در گروه اصلی شما ادمین با دسترسی Invite Users است.");
            }
        }
        exit;
    }

    // ۱۳. ورود به بخش تنظیمات تیم مانهوا
    elseif ($callbackData === 'admin_settings') {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💸 تنظیم میزان حقوق‌ها', 'callback_data' => 'admin_salary_rates'],
                    ['text' => '🏆 پرکارترین اعضای ماه', 'callback_data' => 'admin_most_active']
                ],
                [
                    ['text' => '👥 لیست کامل اعضای تیم', 'callback_data' => 'admin_team_list'],
                    ['text' => '📊 اطلاعات و آمار مانهوا', 'callback_data' => 'admin_team_info']
                ],
                [
                    ['text' => '📢 فرستادن پیام همگانی', 'callback_data' => 'admin_broadcast'],
                    ['text' => '🛡️ اضافه کردن ادمین', 'callback_data' => 'admin_add_admin']
                ],
                [
                    ['text' => ' ثبت گروه اصلی', 'callback_data' => 'admin_set_team_group']
                ],
                [['text' => '🔙 بازگشت به منوی ادمین', 'callback_data' => 'admin_back_to_menu']]
            ]
        ];
        $tg->sendMessage($userId, "⚙️ <b>بخش تنظیمات تیم و پیکربندی حقوق‌ها:</b>", $keyboard);
        exit;
    }

    // ۱۴. نمایش صفحه ویرایش حقوق‌ها
    elseif ($callbackData === 'admin_salary_rates') {
        // دریافت نرخ‌های فعلی حقوق از دیتابیس نئون
        $stmtRates = $db->prepare("SELECT key, value FROM settings WHERE bot_id = :bot_id AND key IN ('rate_translator', 'rate_cleaner', 'rate_typesetter')");
        $stmtRates->execute(['bot_id' => $botId]);
        $ratesRows = $stmtRates->fetchAll();

        $rates = ['rate_translator' => '0', 'rate_cleaner' => '0', 'rate_typesetter' => '0'];
        foreach ($ratesRows as $row) {
            $rates[$row['key']] = $row['value'];
        }

        $rateText = "💸 <b>نرخ‌های فعلی حقوق به ازای هر چپتر کار شده:</b>\n\n"
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

    // ۱۵. آغاز فرآیند ویرایش نرخ دستمزد یک نقش خاص
    elseif (strpos($callbackData, 'admin_change_rate_') === 0) {
        $roleToSetRate = str_replace('admin_change_rate_', '', $callbackData);
        FSM::setStep($botId, $userId, "admin_waiting_rate_{$roleToSetRate}");
        $tg->sendMessage($userId, "✍️ <b>لطفاً مبلغ جدید حقوق به ازای هر چپتر را برای " . getRoleFarsiAdmin($roleToSetRate) . " به عدد (به تومان) وارد کنید:</b>");
        exit;
    }

    // ۱۶. نمایش پرکارترین اعضای تیم
    elseif ($callbackData === 'admin_most_active') {
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

    // ۱۷. نمایش آمار مالی کلان مانهوا
    elseif ($callbackData === 'admin_team_info') {
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
                  . "🔢 مجموع چپترهای ترجمه و تایپ شده: <code>{$totalChapters}</code> چپتر\n"
                  . "💸 مجموع کل دستمزدهای کسب شده کل اعضا: <code>{$totalEarned}</code> تومان";

        $tg->sendMessage($userId, $infoText, [
            'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_settings']]]
        ]);
        exit;
    }

    // ۱۸. فرستادن پیام همگانی به کل گروه‌ها
    elseif ($callbackData === 'admin_broadcast') {
        FSM::setStep($botId, $userId, 'admin_waiting_broadcast_groups');
        $tg->sendMessage($userId, "✍️ <b>لطفاً پیام همگانی ادمین را بنویسید:</b>\n\nاین پیام به صورت اتوماتیک به تمامی گروه‌های تلگرامی متصل به پروژه‌های فعال شما ارسال خواهد شد.");
        exit;
    }

    // ۱۹. ارتقا به ادمین مانهوا
    elseif ($callbackData === 'admin_add_admin') {
        FSM::setStep($botId, $userId, 'admin_waiting_add_admin_id');
        $tg->sendMessage($userId, "🛡️ <b>لطفاً آیدی عددی تلگرام کاربر مورد نظر جهت ارتقا به ادمین را بفرستید:</b>\n\nادمین جدید تمام دسترسی‌های ثبت حقوق، تایید تست و ویرایش قوانین را خواهد داشت.");
        exit;
    }

    // ۲۰. ست کردن آیدی گروه اصلی
    elseif ($callbackData === 'admin_set_team_group') {
        FSM::setStep($botId, $userId, 'admin_waiting_team_group_id');
        $tg->sendMessage($userId, "🔗 <b>لطفاً آیدی عددی گروه تلگرام اصلی تیم خود را بفرستید:</b>\n\nبرای این کار ربات را در گروه تیم ادمین کنید و آیدی آن را (که عددی منفی بزرگ است مثل <code>-100123456789</code>) کپی کرده و در اینجا بفرستید:");
        exit;
    }

    // ۲۱. نمایش لیست کامل اعضای تیم
    elseif ($callbackData === 'admin_team_list') {
        $stmt = $db->prepare("
            SELECT full_name, role, total_chapters, monthly_chapters, total_earned, joined_at 
            FROM users 
            WHERE bot_id = :bot_id AND status = 'approved' AND role != 'none'
            ORDER BY joined_at ASC
        ");
        $stmt->execute(['bot_id' => $botId]);
        $members = $stmt->fetchAll();

        if (empty($members)) {
            $tg->sendMessage($userId, "⚠️ هیچ عضو فعالی یافت نشد.");
        } else {
            $textList = "👥 <b>لیست کلیه اعضای رسمی تیم مانهوا شما:</b>\n\n";
            foreach ($members as $m) {
                $roleFarsi = getRoleFarsiAdmin($m['role']);
                $earned = number_format($m['total_earned']);
                $joinDate = date('Y/m/d', strtotime($m['joined_at']));

                $textList .= "👤 <b>{$m['full_name']}</b>\n"
                           . "├ سمت: {$roleFarsi}\n"
                           . "├ تاریخ عضویت: {$joinDate}\n"
                           . "├ چپتر کل / چپتر این ماه: <code>{$m['total_chapters']}</code> / <code>{$m['monthly_chapters']}</code> چپتر\n"
                           . "└ مجموع پول کسب شده: <code>{$earned}</code> تومان\n\n";
            }
            $tg->sendMessage($userId, $textList, [
                'inline_keyboard' => [[['text' => '🔙 بازگشت', 'callback_data' => 'admin_settings']]]
            ]);
        }
        exit;
    }
}

// ==========================================
// فاز ۳: استارت اولیه پنل ادمین
// ==========================================
if ($message && $message['text'] === '/start') {
    FSM::clearStep($botId, $userId);

    $keyboard = [
        'inline_keyboard' => [
            [['text' => '📚 لیست کارها (پروژه‌ها)', 'callback_data' => 'admin_projects']],
            [['text' => '👥 مدیریت عضوگیری', 'callback_data' => 'admin_recruit']],
            [['text' => '⚙️ تنظیمات تیم', 'callback_data' => 'admin_settings']]
        ]
    ];

    $tg->sendMessage($userId, "👋 سلام مدیر گرامی <b>{$fullName}</b>، خوش آمدید.\n\nبه کنترل پنل شیشه‌ای مانهوا خوش آمدید. لطفاً یکی از شاخه‌های مدیریتی زیر را جهت شروع سازماندهی تیم انتخاب کنید:", $keyboard);
    exit;
}
