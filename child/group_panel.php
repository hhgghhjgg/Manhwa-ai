<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: child/group_panel.php
 * Role: Group Command Processor with Dual Mode & Collision-Free Team Assignments (ON CONFLICT Resolution)
 */

// اطمینان از صحت متغیرها و کانتکست لود شده
if (!isset($botContext, $tg, $user, $db)) {
    exit;
}

$chatId    = $message['chat']['id'] ?? $callbackQuery['message']['chat']['id'] ?? null;
$userId    = $user['tg_id'];
$fullName  = $user['full_name'];
$username  = $user['username'] ?? '';
$userRole  = $user['role'];
$userStep  = $user['step'];
$botId     = $botContext['bot_id'];

$text          = isset($message['text']) ? trim($message['text']) : '';
$caption       = isset($message['caption']) ? trim($message['caption']) : '';
$callbackData  = $callbackQuery['data'] ?? null;
$callbackId    = $callbackQuery['id'] ?? null;
$messageId     = $callbackQuery['message']['message_id'] ?? null;

$isAdminInGroup = ($userRole === 'owner' || $userRole === 'admin');

// تابع کمکی برای یافتن آیدی عددی کاربر بر اساس یوزرنیم یا آیدی عددی
if (!function_exists('findUserByUsernameOrId')) {
    function findUserByUsernameOrId($db, $botId, $input) {
        $input = trim($input);
        if (is_numeric($input)) {
            $stmt = $db->prepare("SELECT tg_id FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id AND status = 'approved' LIMIT 1");
            $stmt->execute(['bot_id' => $botId, 'tg_id' => (int)$input]);
        } else {
            $cleanInput = ltrim($input, '@');
            $stmt = $db->prepare("SELECT tg_id FROM users WHERE bot_id = :bot_id AND username = :username AND status = 'approved' LIMIT 1");
            $stmt->execute(['bot_id' => $botId, 'username' => $cleanInput]);
        }
        $row = $stmt->fetch();
        return $row ? $row['tg_id'] : null;
    }
}

// ==========================================
// فاز ۱: پردازش ورودی‌های متنی FSM در گروه (ثبت مانهوا، انتساب شیشه‌ای و قوانین)
// ==========================================

// الف) فرآیند سنتی متصل کردن گروه به پروژه مانهوا
if ($userStep === 'waiting_group_manhwa_info' && $isAdminInGroup) {
    if (isset($message['photo']) && !empty($caption)) {
        $coverFileId = end($message['photo'])['file_id'];

        preg_match('/اسم:\s*(.+)/u', $caption, $matchName);
        preg_match('/خلاصه:\s*(.+)/u', $caption, $matchSummary);
        preg_match('/ژانر:\s*(.+)/u', $caption, $matchGenres);

        $title   = isset($matchName[1]) ? trim($matchName[1]) : '';
        $summary = isset($matchSummary[1]) ? trim($matchSummary[1]) : '';
        $genres  = isset($matchGenres[1]) ? trim($matchGenres[1]) : '';

        if (empty($title) || empty($summary) || empty($genres)) {
            $tg->sendMessage($chatId, "❌ <b>خطا در ثبت مانهوا!</b>\n\nمشخصات در کپشن تصویر با الگوی ارسالی انطباق ندارد. لطفاً عکس کاور را مجدداً ارسال کرده و کپشن را دقیقاً بر اساس فرمت زیر پر کنید:\n\n<code>اسم: نام مانهوا\nخلاصه: خلاصه داستان را اینجا بنویسید\nژانر: ژانرهای مانهوا</code>");
            exit;
        }

        $stmtCheck = $db->prepare("SELECT id, title FROM manhwas WHERE bot_id = :bot_id AND group_id = :group_id LIMIT 1");
        $stmtCheck->execute(['bot_id' => $botId, 'group_id' => $chatId]);
        $existing = $stmtCheck->fetch();

        if ($existing) {
            $tg->sendMessage($chatId, "⚠️ این گروه در حال حاضر به پروژه مانهوای <b>«{$existing['title']}»</b> متصل است.");
            FSM::clearStep($botId, $userId);
            exit;
        }

        $stmtInsert = $db->prepare("
            INSERT INTO manhwas (bot_id, title, cover_file_id, summary, genres, group_id, last_active_at)
            VALUES (:bot_id, :title, :cover_file_id, :summary, :genres, :group_id, CURRENT_TIMESTAMP)
        ");
        $stmtInsert->execute([
            'bot_id'        => $botId,
            'title'         => $title,
            'cover_file_id' => $coverFileId,
            'summary'       => $summary,
            'genres'        => $genres,
            'group_id'      => $chatId
        ]);

        FSM::clearStep($botId, $userId);
        $tg->sendMessage($chatId, "✅ <b>پروژه مانهوا با موفقیت ثبت شد!</b>\n\n📚 نام: <b>{$title}</b>\n🎭 ژانرها: {$genres}\n🔗 این گروه با موفقیت به عنوان گروه رسمی این مانهوا ثبت شد.");
        exit;
    }
}

// ب) فرآیند جستجو و ثبت شیشه‌ای اعضای تیم با پشتیبانی از سرچ دقیق
elseif (strpos($userStep, 'group_waiting_search_') === 0 && $isAdminInGroup) {
    $roleToAssign = str_replace('group_waiting_search_', '', $userStep);
    FSM::clearStep($botId, $userId);

    $stmtM = $db->prepare("SELECT id, title FROM manhwas WHERE bot_id = :bot_id AND group_id = :group_id LIMIT 1");
    $stmtM->execute(['bot_id' => $botId, 'group_id' => $chatId]);
    $manhwa = $stmtM->fetch();

    if (!$manhwa) {
        $tg->sendMessage($chatId, "❌ ابتدا باید این گروه را با دستور <code>/add_manhwa</code> به یک پروژه مانهوا متصل کنید.");
        exit;
    }

    $q = "%{$text}%";
    if (is_numeric($text)) {
        $stmtU = $db->prepare("SELECT tg_id, full_name, username FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id AND status = 'approved' LIMIT 10");
        $stmtU->execute(['bot_id' => $botId, 'tg_id' => (int)$text]);
    } elseif (strpos($text, '@') === 0) {
        $cleanUser = ltrim($text, '@');
        $stmtU = $db->prepare("SELECT tg_id, full_name, username FROM users WHERE bot_id = :bot_id AND username ILIKE :username AND status = 'approved' LIMIT 10");
        $stmtU->execute(['bot_id' => $botId, 'username' => $cleanUser]);
    } else {
        $stmtU = $db->prepare("SELECT tg_id, full_name, username FROM users WHERE bot_id = :bot_id AND (full_name ILIKE :q OR username ILIKE :q) AND status = 'approved' LIMIT 10");
        $stmtU->execute(['bot_id' => $botId, 'q' => $q]);
    }
    $foundUsers = $stmtU->fetchAll();

    if (empty($foundUsers)) {
        $tg->sendMessage($chatId, "❌ کاربری با مشخصات وارد شده پیدا نشد. داوطلب ابتدا باید ربات را استارت کرده باشد.");
        exit;
    }

    $buttons = [];
    $roleFarsi = ($roleToAssign === 'translator') ? 'مترجم' : (($roleToAssign === 'cleaner') ? 'کلینر' : 'تایپیست');

    foreach ($foundUsers as $u) {
        $usernameDisplay = $u['username'] ? " (@{$u['username']})" : "";
        $buttons[] = [[
            'text' => "👤 {$u['full_name']}{$usernameDisplay}",
            'callback_data' => "grp_assign_confirm_{$roleToAssign}_{$u['tg_id']}"
        ]];
    }
    $buttons[] = [['text' => '❌ لغو عملیات', 'callback_data' => 'grp_cancel']];

    $tg->sendMessage($chatId, "🔍 <b>لیست کاربران یافت شده:</b>\n\nشخص مورد نظر جهت انتساب به عنوان <b>«{$roleFarsi}»</b> مانهوای <b>«{$manhwa['title']}»</b> را انتخاب کنید:", [
        'inline_keyboard' => $buttons
    ]);
    exit;
}

// ج) فرآیند دریافت متنی تنظیم نرخ اختصاصی از داخل گروه
elseif (strpos($userStep, 'group_waiting_rate_') === 0 && $isAdminInGroup) {
    $roleToUpdate = str_replace('group_waiting_rate_', '', $userStep);
    FSM::clearStep($botId, $userId);

    if (!is_numeric($text) || (float)$text < 0) {
        $tg->sendMessage($chatId, "❌ لطفاً فقط عدد بزرگتر از صفر ارسال کنید.");
        exit;
    }

    $stmtM = $db->prepare("SELECT id, title FROM manhwas WHERE bot_id = :bot_id AND group_id = :group_id LIMIT 1");
    $stmtM->execute(['bot_id' => $botId, 'group_id' => $chatId]);
    $manhwa = $stmtM->fetch();

    if (!$manhwa) {
        $tg->sendMessage($chatId, "❌ ابتدا گروه را به پروژه مانهوا متصل کنید.");
        exit;
    }

    $dbField = ($roleToUpdate === 'translator') ? 'rate_translator' : (($roleToUpdate === 'cleaner') ? 'rate_cleaner' : 'rate_typesetter');
    $stmtUpdate = $db->prepare("UPDATE manhwas SET {$dbField} = :val WHERE bot_id = :bot_id AND id = :id");
    $stmtUpdate->execute(['val' => (float)$text, 'bot_id' => $botId, 'id' => $manhwa['id']]);

    $roleFarsi = ($roleToUpdate === 'translator') ? 'مترجم' : (($roleToUpdate === 'cleaner') ? 'کلینر' : 'تایپیست');
    $tg->sendMessage($chatId, "✅ دستمزد اختصاصی نقش <b>«{$roleFarsi}»</b> برای مانهوای <b>«{$manhwa['title']}»</b> با موفقیت روی <code>" . number_format($text) . "</code> تومان تنظیم شد.");
    exit;
}

// د) فرآیند دریافت عنوان قانون جدید
elseif ($userStep === 'group_waiting_rule_title' && $isAdminInGroup) {
    if (empty($text)) {
        $tg->sendMessage($chatId, "❌ عنوان قانون نمی‌تواند خالی باشد.");
        exit;
    }

    FSM::setStep($botId, $userId, "group_waiting_rule_desc_" . base64_encode($text));
    $tg->sendMessage($chatId, "✍️ عنوان <b>«{$text}»</b> ثبت شد.\n\nحالا متن توضیحات و قوانین مربوط به این عنوان را بنویسید و بفرستید:");
    exit;
}

// ه) فرآیند دریافت توضیحات و ثبت نهایی قانون تیمی
elseif (strpos($userStep, 'group_waiting_rule_desc_') === 0 && $isAdminInGroup) {
    $encodedTitle = str_replace('group_waiting_rule_desc_', '', $userStep);
    $ruleTitle    = base64_decode($encodedTitle);
    FSM::clearStep($botId, $userId);

    if (empty($text)) {
        $tg->sendMessage($chatId, "❌ توضیحات قانون نمی‌تواند خالی باشد.");
        exit;
    }

    $stmtRule = $db->prepare("INSERT INTO group_rules_list (bot_id, group_id, title, description) VALUES (:bot_id, :group_id, :title, :description)");
    $stmtRule->execute([
        'bot_id'      => $botId,
        'group_id'    => $chatId,
        'title'       => $ruleTitle,
        'description' => $text
    ]);

    $tg->sendMessage($chatId, "✅ قانون جدید با عنوان <b>«{$ruleTitle}»</b> با موفقیت به آرشیو قوانین تیمی اضافه شد.");
    exit;
}

// ==========================================
// فاز ۲: پردازش دکمه‌های شیشه‌ای درون گروهی (Callback Queries)
// ==========================================
if ($callbackQuery && $callbackData) {

    if ($callbackData === 'grp_cancel') {
        $tg->answerCallbackQuery($callbackId);
        if (!$isAdminInGroup) exit;
        FSM::clearStep($botId, $userId);
        $tg->deleteMessage($chatId, $messageId);
        exit;
    }

    // فرآیند انتساب شیشه‌ای - فعال‌سازی FSM جهت سرچ کاربر
    elseif (strpos($callbackData, 'grp_assign_init_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!$isAdminInGroup) exit;
        $roleToSet = str_replace('grp_assign_init_', '', $callbackData);

        FSM::setStep($botId, $userId, "group_waiting_search_{$roleToSet}");
        $roleFarsi = ($roleToSet === 'translator') ? 'مترجم' : (($roleToSet === 'cleaner') ? 'کلینر' : 'تایپیست');

        $tg->sendMessage($chatId, "🔍 <b>بخش انتساب شیشه‌ای:</b>\n\nلطفاً نام، یوزرنیم (همراه با @ یا بدون آن) یا آیدی عددی شخص مورد نظر جهت انتساب به عنوان <b>«{$roleFarsi}»</b> را بنویسید و بفرستید:", [
            'reply_to_message_id' => $messageId
        ]);
        exit;
    }

    // فرآیند انتساب شیشه‌ای - درخواست تایید نهایی عضو
    elseif (strpos($callbackData, 'grp_assign_confirm_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!$isAdminInGroup) exit;
        $data = str_replace('grp_assign_confirm_', '', $callbackData);
        $parts = explode('_', $data);
        $roleToSet    = $parts[0];
        $targetUserId = $parts[1];

        $stmtU = $db->prepare("SELECT full_name FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id LIMIT 1");
        $stmtU->execute(['bot_id' => $botId, 'tg_id' => $targetUserId]);
        $targetName = $stmtU->fetch()['full_name'] ?? 'کاربر';

        $roleFarsi = ($roleToSet === 'translator') ? 'مترجم' : (($roleToSet === 'cleaner') ? 'کلینر' : 'تایپیست');

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ تایید و ثبت نهایی', 'callback_data' => "grp_assign_do_{$roleToSet}_{$targetUserId}"],
                    ['text' => '❌ لغو عملیات', 'callback_data' => 'grp_cancel']
                ]
            ]
        ];

        $tg->editMessageText($chatId, $messageId, "⚠️ <b>آیا مطمئن هستید؟</b>\n\nمی‌خواهید کاربر <b>{$targetName}</b> را به عنوان <b>«{$roleFarsi}»</b> پروژه ثبت کنید؟", $keyboard);
        exit;
    }

    // فرآیند انتساب شیشه‌ای - ذخیره‌سازی با ساختار پیشرفته ضد کرش (ON CONFLICT)
    elseif (strpos($callbackData, 'grp_assign_do_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!$isAdminInGroup) exit;
        $data = str_replace('grp_assign_do_', '', $callbackData);
        $parts = explode('_', $data);
        $roleToSet    = $parts[0];
        $targetUserId = $parts[1];

        $stmtM = $db->prepare("SELECT id, title FROM manhwas WHERE bot_id = :bot_id AND group_id = :group_id LIMIT 1");
        $stmtM->execute(['bot_id' => $botId, 'group_id' => $chatId]);
        $manhwa = $stmtM->fetch();

        if ($manhwa) {
            // حل نهایی باگ همپوشانی نقش (به روز رسانی خودکار عضو در صورت وجود تخصیص قبلی بدون بروز ارور ۵۰۰)
            $stmtInsert = $db->prepare("
                INSERT INTO team_assignments (bot_id, manhwa_id, role, user_id) 
                VALUES (:bot_id, :manhwa_id, :role, :user_id)
                ON CONFLICT (bot_id, manhwa_id, role) 
                DO UPDATE SET user_id = EXCLUDED.user_id
            ");
            $stmtInsert->execute([
                'bot_id'    => $botId,
                'manhwa_id' => $manhwa['id'],
                'role'      => $roleToSet,
                'user_id'   => $targetUserId
            ]);

            $stmtU = $db->prepare("SELECT full_name FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id LIMIT 1");
            $stmtU->execute(['bot_id' => $botId, 'tg_id' => $targetUserId]);
            $targetName = $stmtU->fetch()['full_name'] ?? 'کاربر';

            $roleFarsi = ($roleToSet === 'translator') ? 'مترجم' : (($roleToSet === 'cleaner') ? 'کلینر' : 'تایپیست');
            $tg->editMessageText($chatId, $messageId, "✅ کاربر <b>{$targetName}</b> با موفقیت به عنوان <b>«{$roleFarsi}»</b> پروژه مانهوای <b>«{$manhwa['title']}»</b> منتسب شد.");
        }
        exit;
    }

    // فرآیند تنظیم نرخ شیشه‌ای - فعال‌سازی FSM دریافت قیمت
    elseif (strpos($callbackData, 'grp_rate_init_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!$isAdminInGroup) exit;
        $roleToUpdate = str_replace('grp_rate_init_', '', $callbackData);

        FSM::setStep($botId, $userId, "group_waiting_rate_{$roleToUpdate}");
        $roleFarsi = ($roleToUpdate === 'translator') ? 'مترجم' : (($roleToUpdate === 'cleaner') ? 'کلینer' : 'تایپیست');

        $tg->sendMessage($chatId, "💸 لطفاً مبلغ دستمزد اختصاصی جدید نقش <b>«{$roleFarsi}»</b> را به عدد (به تومان) وارد کنید:", [
            'reply_to_message_id' => $messageId
        ]);
        exit;
    }

    // فرآیند ثبت قانون شیشه‌ای - فعال‌سازی FSM دریافت عنوان قانون
    elseif ($callbackData === 'grp_rule_add_title') {
        $tg->answerCallbackQuery($callbackId);
        if (!$isAdminInGroup) exit;

        FSM::setStep($botId, $userId, 'group_waiting_rule_title');
        $tg->sendMessage($chatId, "✍️ لطفاً عنوان قانون جدید را ارسال کنید (مثال: نحوه تحویل فایل):", [
            'reply_to_message_id' => $messageId
        ]);
        exit;
    }

    // نمایش لیست شیشه‌ای قوانین برای ادمین جهت مدیریت و حذف
    elseif (strpos($callbackData, 'grp_rules_list_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!$isAdminInGroup) exit;
        $page = (int)str_replace('grp_rules_list_', '', $callbackData);
        $limit = 5;
        $offset = ($page - 1) * $limit;

        $stmtCount = $db->prepare("SELECT COUNT(*) as total FROM group_rules_list WHERE bot_id = :bot_id AND group_id = :group_id");
        $stmtCount->execute(['bot_id' => $botId, 'group_id' => $chatId]);
        $total = $stmtCount->fetch()['total'];
        $totalPages = ceil($total / $limit);

        $stmtRules = $db->prepare("SELECT id, title FROM group_rules_list WHERE bot_id = :bot_id AND group_id = :group_id ORDER BY id DESC LIMIT :limit OFFSET :offset");
        $stmtRules->bindValue(':bot_id', $botId, PDO::PARAM_INT);
        $stmtRules->bindValue(':group_id', $chatId, PDO::PARAM_INT);
        $stmtRules->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmtRules->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmtRules->execute();
        $rules = $stmtRules->fetchAll();

        $buttons = [];
        foreach ($rules as $r) {
            $buttons[] = [
                ['text' => "📖 " . $r['title'], 'callback_data' => "grp_rules_view_{$r['id']}"],
                ['text' => '🗑 حذف', 'callback_data' => "grp_rules_del_{$r['id']}"]
            ];
        }

        $navButtons = [];
        if ($page > 1) {
            $navButtons[] = ['text' => '◀️ قبلی', 'callback_data' => 'grp_rules_list_' . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navButtons[] = ['text' => 'بعدی ▶️', 'callback_data' => 'grp_rules_list_' . ($page + 1)];
        }
        if (!empty($navButtons)) {
            $buttons[] = $navButtons;
        }
        $buttons[] = [['text' => '🔙 بازگشت', 'callback_data' => 'grp_cancel']];

        $tg->editMessageText($chatId, $messageId, "📖 <b>لیست قوانین تیمی گروه (صفحه {$page} از {$totalPages}):</b>", ['inline_keyboard' => $buttons]);
        exit;
    }

    // مشاهده جزییات قانون برای ادمین
    elseif (strpos($callbackData, 'grp_rules_view_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!$isAdminInGroup) exit;
        $ruleId = (int)str_replace('grp_rules_view_', '', $callbackData);

        $stmt = $db->prepare("SELECT * FROM group_rules_list WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $ruleId]);
        $rule = $stmt->fetch();

        if ($rule) {
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🔙 بازگشت به لیست', 'callback_data' => 'grp_rules_list_1']]
                ]
            ];
            $tg->editMessageText($chatId, $messageId, "📖 <b>قانون: {$rule['title']}</b>\n\n{$rule['description']}", $keyboard);
        }
        exit;
    }

    // حذف قانون تیمی توسط ادمین گروه
    elseif (strpos($callbackData, 'grp_rules_del_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        if (!$isAdminInGroup) exit;
        $ruleId = (int)str_replace('grp_rules_del_', '', $callbackData);

        $stmt = $db->prepare("DELETE FROM group_rules_list WHERE bot_id = :bot_id AND id = :id");
        $stmt->execute(['bot_id' => $botId, 'id' => $ruleId]);

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔙 بازگشت به لیست قوانین', 'callback_data' => 'grp_rules_list_1']]
            ]
        ];
        $tg->editMessageText($chatId, $messageId, "✅ قانون مورد نظر با موفقیت حذف شد.", $keyboard);
        exit;
    }

    // مشاهده لیست قوانین توسط کاربران و اعضای عادی گروه
    elseif (strpos($callbackData, 'grp_user_rules_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $page = (int)str_replace('grp_user_rules_', '', $callbackData);
        $limit = 5;
        $offset = ($page - 1) * $limit;

        $stmtCount = $db->prepare("SELECT COUNT(*) as total FROM group_rules_list WHERE bot_id = :bot_id AND group_id = :group_id");
        $stmtCount->execute(['bot_id' => $botId, 'group_id' => $chatId]);
        $total = $stmtCount->fetch()['total'];
        $totalPages = ceil($total / $limit);

        $stmtRules = $db->prepare("SELECT id, title FROM group_rules_list WHERE bot_id = :bot_id AND group_id = :group_id ORDER BY id DESC LIMIT :limit OFFSET :offset");
        $stmtRules->bindValue(':bot_id', $botId, PDO::PARAM_INT);
        $stmtRules->bindValue(':group_id', $chatId, PDO::PARAM_INT);
        $stmtRules->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmtRules->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmtRules->execute();
        $rules = $stmtRules->fetchAll();

        if (empty($rules)) {
            $tg->editMessageText($chatId, $messageId, "⚠️ هیچ قانونی برای این گروه ثبت نشده است.");
            exit;
        }

        $buttons = [];
        foreach ($rules as $r) {
            $buttons[] = [['text' => "📖 " . $r['title'], 'callback_data' => "grp_user_rule_view_{$r['id']}"]];
        }

        $navButtons = [];
        if ($page > 1) {
            $navButtons[] = ['text' => '◀️ قبلی', 'callback_data' => 'grp_user_rules_' . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navButtons[] = ['text' => 'بعدی ▶️', 'callback_data' => 'grp_user_rules_' . ($page + 1)];
        }
        if (!empty($navButtons)) {
            $buttons[] = $navButtons;
        }
        $buttons[] = [['text' => '❌ بستن منو', 'callback_data' => 'grp_cancel']];

        $tg->editMessageText($chatId, $messageId, "📖 <b>لیست قوانین و استانداردهای این گروه (صفحه {$page} از {$totalPages}):</b>", ['inline_keyboard' => $buttons]);
        exit;
    }

    // مشاهده جزییات قانون برای کاربر عادی گروه
    elseif (strpos($callbackData, 'grp_user_rule_view_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $ruleId = (int)str_replace('grp_user_rule_view_', '', $callbackData);

        $stmt = $db->prepare("SELECT * FROM group_rules_list WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $ruleId]);
        $rule = $stmt->fetch();

        if ($rule) {
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🔙 بازگشت به لیست قوانین', 'callback_data' => 'grp_user_rules_1']]
                ]
            ];
            $tg->editMessageText($chatId, $messageId, "📖 <b>قانون: {$rule['title']}</b>\n\n{$rule['description']}", $keyboard);
        }
        exit;
    }
}

// ==========================================
// فاز ۳: پردازش دستورات متنی اسلشی و فارسی نوین گروهی
// ==========================================
if (!empty($text)) {

    // ۱. دستور افزودن تیم (با دو مدل پردازش متنی و شیشه‌ای)
    if ($text === 'افزودن تیم' || strpos($text, '/add_team') === 0) {
        if (!$isAdminInGroup) {
            $tg->sendMessage($chatId, "⚠️ این دستور مخصوص مدیریت است.");
            exit;
        }

        // مدل اول: دستور اسلشی سنتی (جایگزینی هوشمند با ON CONFLICT جهت جلوگیری از کرش دیتابیس)
        if (strpos($text, '/add_team') === 0) {
            $stmtM = $db->prepare("SELECT id, title FROM manhwas WHERE bot_id = :bot_id AND group_id = :group_id LIMIT 1");
            $stmtM->execute(['bot_id' => $botId, 'group_id' => $chatId]);
            $manhwa = $stmtM->fetch();

            if (!$manhwa) {
                $tg->sendMessage($chatId, "❌ ابتدا باید این گروه را با دستور <code>/add_manhwa</code> به یک پروژه مانهوا متصل کنید.");
                exit;
            }

            preg_match('/تایپ\[@?([a-zA-Z0-9_]+)\]/', $text, $matchType);
            preg_match('/کلین\[@?([a-zA-Z0-9_]+)\]/', $text, $matchClean);
            preg_match('/ترجمه\[@?([a-zA-Z0-9_]+)\]/', $text, $matchTrans);

            $typeInput  = $matchType[1] ?? null;
            $cleanInput = $matchClean[1] ?? null;
            $transInput = $matchTrans[1] ?? null;

            if (!$typeInput || !$cleanInput || !$transInput) {
                $tg->sendMessage($chatId, "❌ <b>الگوی ارسال دستور اشتباه است!</b>\n\nلطفاً دستور را دقیقاً با فرمت زیر پر کرده و بفرستید:\n\n<code>/add_team\nتایپ[@username]->\nکلین[@username]->\nترجمه[@username]-></code>");
                exit;
            }

            $typesetterId = findUserByUsernameOrId($db, $botId, $typeInput);
            $cleanerId    = findUserByUsernameOrId($db, $botId, $cleanInput);
            $translatorId = findUserByUsernameOrId($db, $botId, $transInput);

            if (!$typesetterId || !$cleanerId || !$translatorId) {
                $errText = "❌ <b>ثبت تیم با شکست مواجه شد!</b>\n\nبرخی از یوزرنیم‌های ارسال شده هنوز وارد ربات نشده و دکمه استارت را نزده‌اند یا تایید رسمی نشده‌اند:\n";
                $errText .= "├ تایپیست: " . ($typesetterId ? "✅ یافت شد" : "❌ یافت نشد") . "\n";
                $errText .= "├ کلینر: " . ($cleanerId ? "✅ یافت شد" : "❌ یافت نشد") . "\n";
                $errText .= "└ مترجم: " . ($translatorId ? "✅ یافت شد" : "❌ یافت نشد");
                $tg->sendMessage($chatId, $errText);
                exit;
            }

            $db->beginTransaction();
            try {
                // پی‌ریزی مجدد دستورات درج با قید یکتا جهت جلوگیری از بروز باگ کلید تکراری
                $stmtIns = $db->prepare("
                    INSERT INTO team_assignments (bot_id, manhwa_id, role, user_id) 
                    VALUES (:bot_id, :manhwa_id, :role, :user_id)
                    ON CONFLICT (bot_id, manhwa_id, role) 
                    DO UPDATE SET user_id = EXCLUDED.user_id
                ");
                $stmtIns->execute(['bot_id' => $botId, 'manhwa_id' => $manhwa['id'], 'role' => 'typesetter', 'user_id' => $typesetterId]);
                $stmtIns->execute(['bot_id' => $botId, 'manhwa_id' => $manhwa['id'], 'role' => 'cleaner', 'user_id' => $cleanerId]);
                $stmtIns->execute(['bot_id' => $botId, 'manhwa_id' => $manhwa['id'], 'role' => 'translator', 'user_id' => $translatorId]);
                $db->commit();

                $tg->sendMessage($chatId, "✅ <b>اعضای جدید با موفقیت به تیم پروژه «{$manhwa['title']}» ملحق شدند!</b>\n\n📝 مترجم: <code>{$transInput}</code>\n🖌 کلینر: <code>{$cleanInput}</code>\n⌨️ تایپیست: <code>{$typeInput}</code>");
            } catch (Exception $e) {
                $db->rollBack();
                $tg->sendMessage($chatId, "❌ خطا در همگام‌سازی دیتابیس تیم مانهوا.");
            }
            exit;
        }

        // مدل دوم: پیام متنی فارسی نوین (ارائه پنل شیشه‌ای افزودن تک‌تک اعضا)
        else {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📝 افزودن مترجم', 'callback_data' => 'grp_assign_init_translator'],
                        ['text' => '🖌 افزودن کلینر', 'callback_data' => 'grp_assign_init_cleaner']
                    ],
                    [
                        ['text' => '⌨️ افزودن تایپیست', 'callback_data' => 'grp_assign_init_typesetter']
                    ],
                    [['text' => '❌ بستن منو', 'callback_data' => 'grp_cancel']]
                ]
            ];
            $tg->sendMessage($chatId, "👥 <b>منوی شیشه‌ای مدیریت تیم کاری:</b>\n\nقصد انتساب کدام تخصص را به این پروژه مانهوا دارید؟", $keyboard);
            exit;
        }
    }

    // ۲. دستور تنظیم مبالغ اختصاصی دستمزد آثار مانهوا
    elseif ($text === 'تنظیم قیمت' || strpos($text, '/set_rates') === 0) {
        if (!$isAdminInGroup) {
            $tg->sendMessage($chatId, "⚠️ این دستور مخصوص مدیریت است.");
            exit;
        }

        // مدل اول: دستور اسلشی سنتی مستقیم
        if (strpos($text, '/set_rates') === 0) {
            preg_match('/\/set_rates\s+(\d+)\s+(\d+)\s+(\d+)/', $text, $matchRates);
            if (!$matchRates) {
                $tg->sendMessage($chatId, "❌ <b>فرمت دستور اشتباه است!</b>\n\nقالب استفاده:\n<code>/set_rates [مترجم] [کلینر] [تایپیست]</code>\n\nمثال: <code>/set_rates 12000 8000 9000</code>");
                exit;
            }

            $stmtM = $db->prepare("UPDATE manhwas SET rate_translator = :t, rate_cleaner = :c, rate_typesetter = :ty WHERE bot_id = :bot_id AND group_id = :g_id");
            $stmtM->execute([
                't' => (float)$matchRates[1],
                'c' => (float)$matchRates[2],
                'ty' => (float)$matchRates[3],
                'bot_id' => $botId,
                'g_id' => $chatId
            ]);

            $tg->sendMessage($chatId, "✅ <b>نرخ‌های اختصاصی مانهوای این گروه با موفقیت تنظیم شد:</b>\n\n📝 مترجم: " . number_format($matchRates[1]) . " ت\n🖌 کلینر: " . number_format($matchRates[2]) . " ت\n⌨️ تایپیست: " . number_format($matchRates[3]) . " ت");
            exit;
        }

        // مدل دوم: پیام فارسی نوین (پنل شیشه‌ای مجزا برای هر نرخ دستمزد)
        else {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📝 قیمت مترجم', 'callback_data' => 'grp_rate_init_translator'],
                        ['text' => '🖌 قیمت کلینر', 'callback_data' => 'grp_rate_init_cleaner']
                    ],
                    [
                        ['text' => '⌨️ قیمت تایپیست', 'callback_data' => 'grp_rate_init_typesetter']
                    ],
                    [['text' => '❌ بستن منو', 'callback_data' => 'grp_cancel']]
                ]
            ];
            $tg->sendMessage($chatId, "💸 <b>بخش تنظیم دستمزد اختصاصی مانهوا:</b>\n\nدستمزد کدام سمت کاری را می‌خواهید برای مانهوای این گروه ویرایش کنید؟", $keyboard);
            exit;
        }
    }

    // ۳. دستور مدیریت قوانین گروه کاری (مخصوص مدیریت)
    elseif ($text === 'مدیریت قانون' || strpos($text, '/set_rules') === 0) {
        if (!$isAdminInGroup) {
            $tg->sendMessage($chatId, "⚠️ این دستور مخصوص مدیریت است.");
            exit;
        }

        // مدل اول: دستور اسلشی قدیمی
        if (strpos($text, '/set_rules') === 0) {
            $rulesText = trim(str_replace('/set_rules', '', $text));
            if (empty($rulesText)) {
                $tg->sendMessage($chatId, "❌ لطفا متن قوانین را مقابل دستور بنویسید.");
                exit;
            }

            $stmt = $db->prepare("INSERT INTO group_rules_list (bot_id, group_id, title, description) VALUES (:bot_id, :g_id, 'قوانین عمومی', :rules)");
            $stmt->execute(['bot_id' => $botId, 'g_id' => $chatId, 'rules' => $rulesText]);

            $tg->sendMessage($chatId, "✅ <b>قوانین تیمی با موفقیت ثبت شد.</b>");
            exit;
        }

        // مدل دوم: منوی تعاملی قوانین به صورت شیشه‌ای عنوان‌دار
        else {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '➕ افزودن قانون جدید', 'callback_data' => 'grp_rule_add_title'],
                        ['text' => '📖 لیست قوانین گروه', 'callback_data' => 'grp_rules_list_1']
                    ],
                    [['text' => '❌ بستن منو', 'callback_data' => 'grp_cancel']]
                ]
            ];
            $tg->sendMessage($chatId, "📖 <b>بخش مدیریت قوانین تیمی این مانهوا:</b>\n\nیکی از گزینه‌های مدیریتی زیر را لمس کنید:", $keyboard);
            exit;
        }
    }

    // ۴. دستور مشاهده قوانین کاری (عمومی اعضا)
    elseif ($text === 'قوانین' || $text === 'قوانین گروه' || $text === '/rules') {
        // باز کردن هوشمند آرشیو قوانین به صورت شیشه‌ای با ورق‌زن
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📖 مشاهده قوانین و استانداردها', 'callback_data' => 'grp_user_rules_1']],
                [['text' => '❌ بستن منو', 'callback_data' => 'grp_cancel']]
            ]
        ];
        $tg->sendMessage($chatId, "📖 <b>بخش استانداردهای تیمی مانهوای گروه:</b>\n\nبرای دسترسی به طبقه‌بندی قوانین، دکمه زیر را فشار دهید:", $keyboard);
        exit;
    }

    // ۵. دستور وضعیت جاری پروژه مانهوا
    elseif ($text === 'وضعیت' || $text === '/status') {
        $stmt = $db->prepare("SELECT * FROM manhwas WHERE bot_id = :bot_id AND group_id = :g_id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'g_id' => $chatId]);
        $m = $stmt->fetch();

        if ($m) {
            $stmtTeam = $db->prepare("
                SELECT ta.role, u.full_name 
                FROM team_assignments ta 
                JOIN users u ON ta.user_id = u.tg_id AND ta.bot_id = u.bot_id
                WHERE ta.bot_id = :bot_id AND ta.manhwa_id = :m_id
            ");
            $stmtTeam->execute(['bot_id' => $botId, 'm_id' => $m['id']]);
            $team = $stmtTeam->fetchAll();

            $staff = ['translator' => [], 'cleaner' => [], 'typesetter' => []];
            foreach ($team as $member) {
                $staff[$member['role']][] = $member['full_name'];
            }

            $resp = "📚 <b>وضعیت پروژه: «{$m['title']}»</b>\n"
                  . "🔢 آخرین چپتر ثبت شده: <code>{$m['last_chapter']}</code>\n"
                  . "🎭 ژانرها: {$m['genres']}\n\n"
                  . "👥 <b>اعضای تیم فعال مانهوا:</b>\n"
                  . "├ مترجمین: " . (empty($staff['translator']) ? "❌ بدون انتساب" : implode('، ', $staff['translator'])) . "\n"
                  . "├ کلینرها: " . (empty($staff['cleaner']) ? "❌ بدون انتساب" : implode('، ', $staff['cleaner'])) . "\n"
                  . "└ تایپیست‌ها: " . (empty($staff['typesetter']) ? "❌ بدون انتساب" : implode('، ', $staff['typesetter'])) . "\n\n"
                  . "💡 برای ثبت چپتر، تایپیست باید دستور <code>/add_file_chpter [شماره]</code> را روی کار ریپلای کند.";
            $tg->sendMessage($chatId, $resp);
        }
        exit;
    }

    // ۶. دستور آمار کارکرد گروه کاری
    elseif ($text === 'آمار' || $text === '/stats') {
        $stmt = $db->prepare("SELECT id, title FROM manhwas WHERE bot_id = :bot_id AND group_id = :g_id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'g_id' => $chatId]);
        $m = $stmt->fetch();

        if ($m) {
            $stmtCh = $db->prepare("SELECT COUNT(*) as total_ch FROM chapters WHERE bot_id = :bot_id AND manhwa_id = :m_id AND status = 'approved'");
            $stmtCh->execute(['bot_id' => $botId, 'm_id' => $m['id']]);
            $totalCh = $stmtCh->fetch()['total_ch'];

            $statsText = "📊 <b>آمار کارکرد و پیشرفت پروژه «{$m['title']}»:</b>\n\n"
                       . "📈 مجموع چپترهای تایید نهایی شده در این گروه: <code>{$totalCh}</code> چپتر\n"
                       . "🕒 وضعیت نظارت بر کارکرد: فعال و زنده";
            $tg->sendMessage($chatId, $statsText);
        }
        exit;
    }

    // ۷. دستور آغاز فرآیند اضافه کردن مانهوا به گروه (سنتی)
    elseif (strpos($text, '/add_manhwa') === 0) {
        if (!$isAdminInGroup) {
            $tg->sendMessage($chatId, "⚠️ این دستور مخصوص مدیریت است.");
            exit;
        }

        FSM::setStep($botId, $userId, 'waiting_group_manhwa_info');
        $tg->sendMessage($chatId, "📥 <b>شروع فرآیند ثبت مانهوا برای این گروه:</b>\n\nلطفاً یک تصویر (کاور مانهوا) ارسال کنید و در کپشن (Caption) آن، مشخصات را دقیقاً با الگوی زیر بنویسید:\n\n<code>اسم: نام مانهوا\nخلاصه: خلاصه داستان را اینجا بنویسید\nژانر: ژانرهای مانهوا</code>");
        exit;
    }

    // ۸. دستور ارسال فایل چپتر برای تایید و ثبت حقوق اعضا (سازگار با بستر انتساب چندگانه اعضا)
    elseif (strpos($text, '/add_file_chpter') === 0) {
        $replyTo = $message['reply_to_message'] ?? null;
        if (!$replyTo) {
            $tg->sendMessage($chatId, "❌ این دستور باید حتماً بر روی فایل چپتر نهایی ریپلای شود.");
            exit;
        }

        $repliedFileId = $replyTo['document']['file_id'] ?? end($replyTo['photo'])['file_id'] ?? null;
        if (!$repliedFileId) {
            $tg->sendMessage($chatId, "❌ پیغام ریپلای شده حاوی فایل سند (Document) یا تصویر معتبر نیست.");
            exit;
        }

        preg_match('/\/add_file_chpter\s+(\d+)/', $text, $matchChNum);
        $chapterNum = isset($matchChNum[1]) ? (int)$matchChNum[1] : null;

        if (!$chapterNum) {
            $tg->sendMessage($chatId, "❌ شماره چپتر را در مقابل دستور وارد کنید.\n\nمثال: <code>/add_file_chpter 9</code>");
            exit;
        }

        $stmtM = $db->prepare("SELECT * FROM manhwas WHERE bot_id = :bot_id AND group_id = :group_id LIMIT 1");
        $stmtM->execute(['bot_id' => $botId, 'group_id' => $chatId]);
        $manhwa = $stmtM->fetch();

        if (!$manhwa) {
            $tg->sendMessage($chatId, "❌ ابتدا باید این گروه را با دستور <code>/add_manhwa</code> به یک پروژه مانهوا متصل کنید.");
            exit;
        }

        $stmtTeam = $db->prepare("SELECT role, user_id FROM team_assignments WHERE bot_id = :bot_id AND manhwa_id = :manhwa_id");
        $stmtTeam->execute(['bot_id' => $botId, 'manhwa_id' => $manhwa['id']]);
        $team = $stmtTeam->fetchAll();

        $assigned = ['translator' => [], 'cleaner' => [], 'typesetter' => []];
        foreach ($team as $m) {
            $assigned[$m['role']][] = $m['user_id'];
        }

        if (empty($assigned['translator']) || empty($assigned['cleaner']) || empty($assigned['typesetter'])) {
            $tg->sendMessage($chatId, "⚠️ لطفاً ابتدا تیم پروژه را با دستور <code>/add_team</code> ست کنید. بدون ست کردن تیم، مبالغ حقوق چپتر قابل پردازش نیست.");
            exit;
        }

        $t_id  = $assigned['translator'][0];
        $c_id  = $assigned['cleaner'][0];
        $ty_id = $assigned['typesetter'][0];

        if (in_array($userId, $assigned['translator'])) {
            $t_id = $userId;
        }
        if (in_array($userId, $assigned['cleaner'])) {
            $c_id = $userId;
        }
        if (in_array($userId, $assigned['typesetter'])) {
            $ty_id = $userId;
        }

        $stmtRateT = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = 'rate_translator' LIMIT 1");
        $stmtRateT->execute(['bot_id' => $botId]);
        $rowRateT = $stmtRateT->fetch();
        $rateT = $manhwa['rate_translator'] !== null ? (float)$manhwa['rate_translator'] : (float)($rowRateT ? ($rowRateT['value'] ?? 0) : 0);

        $stmtRateC = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = 'rate_cleaner' LIMIT 1");
        $stmtRateC->execute(['bot_id' => $botId]);
        $rowRateC = $stmtRateC->fetch();
        $rateC = $manhwa['rate_cleaner'] !== null ? (float)$manhwa['rate_cleaner'] : (float)($rowRateC ? ($rowRateC['value'] ?? 0) : 0);

        $stmtRateTy = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = 'rate_typesetter' LIMIT 1");
        $stmtRateTy->execute(['bot_id' => $botId]);
        $rowRateTy = $stmtRateTy->fetch();
        $rateTy = $manhwa['rate_typesetter'] !== null ? (float)$manhwa['rate_typesetter'] : (float)($rowRateTy ? ($rowRateTy['value'] ?? 0) : 0);

        $stmtCh = $db->prepare("
            INSERT INTO chapters (bot_id, manhwa_id, chapter_num, file_id, status, translator_id, cleaner_id, typesetter_id, translator_pay, cleaner_pay, typesetter_pay)
            VALUES (:bot_id, :manhwa_id, :chapter_num, :file_id, 'pending', :t_id, :c_id, :ty_id, :t_pay, :c_pay, :ty_pay)
            RETURNING id
        ");
        $stmtCh->execute([
            'bot_id'      => $botId,
            'manhwa_id'   => $manhwa['id'],
            'chapter_num' => $chapterNum,
            'file_id'     => $repliedFileId,
            't_id'        => $t_id,
            'c_id'        => $c_id,
            'ty_id'       => $ty_id,
            't_pay'       => $rateT,
            'c_pay'       => $rateC,
            'ty_pay'      => $rateTy
        ]);
        $newChapterId = $stmtCh->fetch()['id'];

        $tg->sendMessage($chatId, "📥 چپتر <code>{$chapterNum}</code> مانهوای <b>«{$manhwa['title']}»</b> با موفقیت دریافت شد و جهت تایید نهایی و واریز حقوق برای ادمین کل فرستاده شد.");

        $stmtAdmins = $db->prepare("SELECT tg_id FROM users WHERE bot_id = :bot_id AND (role = 'admin' OR role = 'owner') AND status = 'approved' LIMIT 5");
        $stmtAdmins->execute(['bot_id' => $botId]);
        $admins = $stmtAdmins->fetchAll();

        $adminCaption = "📥 <b>چپتر جدید جهت بررسی و پرداخت دستمزد:</b>\n\n"
                      . "📚 مانهوا: <b>{$manhwa['title']}</b>\n"
                      . "🔢 شماره چپتر: <b>{$chapterNum}</b>\n"
                      . "👤 ارسال کننده: {$fullName} (@{$username})\n\n"
                      . "💰 مبالغ محاسبه شده چپتر:\n"
                      . "├ مترجم: " . number_format($rateT) . " تومان\n"
                      . "├ کلینر: " . number_format($rateC) . " تومان\n"
                      . "└ تایپیست: " . number_format($rateTy) . " تومان\n\n"
                      . "💡 تایید چپتر باعث واریز خودکار مبالغ بالا به کیف پول اعضا و آپدیت چپتر مانهوا می‌شود.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ تایید و پرداخت حقوق', 'callback_data' => "admin_approve_ch_{$newChapterId}"],
                    ['text' => '❌ رد چپتر', 'callback_data' => "admin_ch_rej_init_{$newChapterId}"]
                ]
            ]
        ];

        if (function_exists('fastcgi_finish_request')) {
            echo json_encode(['ok' => true]);
            session_write_close();
            fastcgi_finish_request();
        }

        foreach ($admins as $ad) {
            $tg->sendDocument($ad['tg_id'], $repliedFileId, $adminCaption, $keyboard);
        }
        exit;
    }

    // ۹. دستور عزل پرسنل از نقش مشخص شده
    elseif (strpos($text, '/unassign') === 0) {
        if (!$isAdminInGroup) {
            $tg->sendMessage($chatId, "⚠️ مخصوص ادمین‌ها.");
            exit;
        }
        preg_match('/\/unassign\s+(translator|cleaner|typesetter)/', $text, $matchRole);
        
        if (!$matchRole) {
            $tg->sendMessage($chatId, "❌ <b>دستور نامعتبر است.</b>\n\nقالب استفاده:\n<code>/unassign [role]</code>\n\nمثال: <code>/unassign translator</code>");
            exit;
        }

        $roleToUnassign = $matchRole[1];
        $stmtM = $db->prepare("SELECT id FROM manhwas WHERE bot_id = :bot_id AND group_id = :g_id LIMIT 1");
        $stmtM->execute(['bot_id' => $botId, 'g_id' => $chatId]);
        $m = $stmtM->fetch();

        if ($m) {
            $stmtDel = $db->prepare("DELETE FROM team_assignments WHERE bot_id = :bot_id AND manhwa_id = :m_id AND role = :role");
            $stmtDel->execute(['bot_id' => $botId, 'm_id' => $m['id'], 'role' => $roleToUnassign]);

            $roleFarsiName = ($roleToUnassign === 'translator') ? 'مترجم' : (($roleToUnassign === 'cleaner') ? 'کلینر' : 'تایپیست');
            $tg->sendMessage($chatId, "✅ تمامی افراد متصل به نقش <b>{$roleFarsiName}</b> با موفقیت از این پروژه عزل شدند.");
        }
        exit;
    }

    // ۱۰. راهنمای ربات در گروه
    elseif ($text === '/help' || $text === 'راهنما') {
        $helpText = "📖 <b>راهنمای دستورات گروه تیم مانهوا:</b>\n\n"
                  . "📌 <b>دستورات شیشه‌ای و فارسی جدید (با ارسال مستقیم کلمات):</b>\n"
                  . "├ <code>افزودن تیم</code> ➔ مدیریت شیشه‌ای و انتساب پرسنل با جستجو\n"
                  . "├ <code>تنظیم قیمت</code> ➔ پیکربندی نرخ شیشه‌ای دستمزد آثار\n"
                  . "├ <code>مدیریت قانون</code> ➔ ثبت و حذف عنوان‌دار قوانین تیمی\n"
                  . "├ <code>قوانین</code> ➔ نمایش و ورق زدن آرشیو قوانین برای اعضا\n"
                  . "├ <code>وضعیت</code> ➔ جزییات کامل تیمی مانهوای این گروه\n"
                  . "└ <code>آمار</code> ➔ گزارش و چپترهای انجام‌شده این پروژه\n\n"
                  . "📌 <b>دستورات اسلشی سنتی:</b>\n"
                  . "├ <code>/add_manhwa</code> ➔ متصل کردن گروه کاری به پروژه جدید مانهوا\n"
                  . "├ <code>/add_team</code> ➔ ثبت سنتی اعضای تیم کاری\n"
                  . "├ <code>/add_file_chpter [شماره]</code> ➔ ثبت چپتر نهایی جهت بررسی و پرداخت\n"
                  . "├ <code>/set_rates [مترجم] [کلینر] [تایپیست]</code> ➔ تنظیم حقوق مانهوا\n"
                  . "├ <code>/set_rules [متن]</code> ➔ ثبت قوانین به روش قدیمی\n"
                  . "└ <code>/unassign [role]</code> ➔ عزل کامل اعضا از نقش مشخص‌شده";
        
        $tg->sendMessage($chatId, $helpText);
        exit;
    }
}
