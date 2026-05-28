<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: child/group_panel.php
 * Role: Group & Supergroup Command Processor (Add Manhwa, Add Team, Chapter Submissions)
 */

// اطمینان از صحت متغیرها و کانتکست لود شده
if (!isset($botContext) || !isset($tg) || !isset($user) || !isset($db)) {
    exit;
}

$chatId    = $message['chat']['id'] ?? $callbackQuery['message']['chat']['id'] ?? null;
$userId    = $user['tg_id'];
$fullName  = $user['full_name'];
$username  = $user['username'] ?? '';
$userRole  = $user['role'];
$userStep  = $user['step'];
$botId     = $botContext['bot_id'];

$text    = $message['text'] ?? '';
$caption = $message['caption'] ?? '';

$isAdminInGroup = ($userRole === 'owner' || $userRole === 'admin');

// تابع کمکی برای یافتن آیدی عددی کاربر بر اساس یوزرنیم یا آیدی عددی
if (!function_exists('findUserByUsernameOrId')) {
    function findUserByUsernameOrId($db, $botId, $input) {
        $input = trim($input);
        if (is_numeric($input)) {
            $stmt = $db->prepare("SELECT tg_id FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id LIMIT 1");
            $stmt->execute(['bot_id' => $botId, 'tg_id' => (int)$input]);
        } else {
            $stmt = $db->prepare("SELECT tg_id FROM users WHERE bot_id = :bot_id AND username = :username LIMIT 1");
            $stmt->execute(['bot_id' => $botId, 'username' => $input]);
        }
        $row = $stmt->fetch();
        return $row ? $row['tg_id'] : null;
    }
}

// ==========================================
// ۱. فاز پردازش FSM گروهی (ثبت شناسنامه مانهوا با ارسال کاور و کپشن)
// ==========================================
if ($userStep === 'waiting_group_manhwa_info' && $isAdminInGroup) {
    // مانهوا باید حتماً به صورت عکس و با کپشن ارسال شود
    if (isset($message['photo']) && !empty($caption)) {
        $coverFileId = end($message['photo'])['file_id'];

        // پارس کردن کپشن با استفاده از عبارات با قاعده (Regex)
        preg_match('/اسم:\s*(.+)/u', $caption, $matchName);
        preg_match('/خلاصه:\s*(.+)/u', $caption, $matchSummary);
        preg_match('/ژانر:\s*(.+)/u', $caption, $matchGenres);

        $title   = isset($matchName[1]) ? trim($matchName[1]) : '';
        $summary = isset($matchSummary[1]) ? trim($matchSummary[1]) : '';
        $genres  = isset($matchGenres[1]) ? trim($matchGenres[1]) : '';

        if (empty($title) || empty($summary) || empty($genres)) {
            $tg->sendMessage($chatId, "❌ <b>خطا در ثبت مانهوا!</b>\n\nمشخصات در کپشن تصویر به طور کامل یا با الگوی ارسالی انطباق ندارد. لطفاً عکس کاور را مجدداً ارسال کرده و کپشن را دقیقاً بر اساس فرمت زیر پر کنید:\n\n<code>اسم: نام مانهوا\nخلاصه: خلاصه داستان را اینجا بنویسید\nژانر: ژانرهای مانهوا</code>");
            exit;
        }

        // بررسی اینکه آیا این گروه قبلاً به مانهوای دیگری متصل شده است یا خیر
        $stmtCheck = $db->prepare("SELECT id, title FROM manhwas WHERE bot_id = :bot_id AND group_id = :group_id LIMIT 1");
        $stmtCheck->execute(['bot_id' => $botId, 'group_id' => $chatId]);
        $existing = $stmtCheck->fetch();

        if ($existing) {
            $tg->sendMessage($chatId, "⚠️ این گروه در حال حاضر به پروژه مانهوای <b>«{$existing['title']}»</b> متصل است. هر گروه فقط می‌تواند میزبان یک مانهوا باشد.");
            FSM::clearStep($botId, $userId);
            exit;
        }

        // ثبت مانهوا در دیتابیس نئون و تخصیص گروه جاری به آن
        $stmtInsert = $db->prepare("
            INSERT INTO manhwas (bot_id, title, cover_file_id, summary, genres, group_id)
            VALUES (:bot_id, :title, :cover_file_id, :summary, :genres, :group_id)
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

// ==========================================
// ۲. فاز پردازش دستورات متنی گروهی
// ==========================================
if (!empty($text)) {

    // دستور آغاز فرآیند اضافه کردن مانهوا به گروه
    if (strpos($text, '/add_manhwa') === 0) {
        if (!$isAdminInGroup) {
            $tg->sendMessage($chatId, "⚠️ این دستور مخصوص ادمین‌ها و مالک ربات است.");
            exit;
        }

        FSM::setStep($botId, $userId, 'waiting_group_manhwa_info');
        
        $tg->sendMessage($chatId, "📥 <b>شروع فرآیند ثبت مانهوا برای این گروه:</b>\n\nلطفاً یک تصویر (به عنوان کاور مانهوا) ارسال کنید و در کپشن (Caption) آن، مشخصات را دقیقاً با الگوی زیر بنویسید:\n\n<code>اسم: نام مانهوا\nخلاصه: خلاصه داستان را اینجا بنویسید\nژانر: ژانرهای مانهوا</code>");
        exit;
    }

    // دستور انتساب اعضای تیم به مانهوای متصل به این گروه
    elseif (strpos($text, '/add_team') === 0) {
        if (!$isAdminInGroup) {
            $tg->sendMessage($chatId, "⚠️ این دستور مخصوص ادمین‌ها و مالک ربات است.");
            exit;
        }

        // پیدا کردن مانهوای متصل به این گروه
        $stmtM = $db->prepare("SELECT id, title FROM manhwas WHERE bot_id = :bot_id AND group_id = :group_id LIMIT 1");
        $stmtM->execute(['bot_id' => $botId, 'group_id' => $chatId]);
        $manhwa = $stmtM->fetch();

        if (!$manhwa) {
            $tg->sendMessage($chatId, "❌ ابتدا باید این گروه را با دستور <code>/add_manhwa</code> به یک پروژه مانهوا متصل کنید.");
            exit;
        }

        // پارس کردن فرمت ارسالی ادمین
        // فرمت: تایپ[@xxxxx]-> کلین[@xxxxx]-> ترجمه[@xxxxx]->
        preg_match('/تایپ\[@?([a-zA-Z0-9_]+)\]/', $text, $matchType);
        preg_match('/کلین\[@?([a-zA-Z0-9_]+)\]/', $text, $matchClean);
        preg_match('/ترجمه\[@?([a-zA-Z0-9_]+)\]/', $text, $matchTrans);

        $typeInput  = isset($matchType[1]) ? $matchType[1] : null;
        $cleanInput = isset($matchClean[1]) ? $matchClean[1] : null;
        $transInput = isset($matchTrans[1]) ? $matchTrans[1] : null;

        if (!$typeInput || !$cleanInput || !$transInput) {
            $tg->sendMessage($chatId, "❌ <b>الگوی ارسال دستور اشتباه است!</b>\n\nلطفاً دستور را دقیقاً با فرمت زیر پر کرده و بفرستید (با یوزرنیم یا آیدی عددی تلگرام اعضا):\n\n<code>/add_team\nتایپ[@username]->\nکلین[@username]->\nترجمه[@username]-></code>");
            exit;
        }

        // پیدا کردن آیدی‌های عددی اعضای تیم در دیتابیس
        $typesetterId = findUserByUsernameOrId($db, $botId, $typeInput);
        $cleanerId    = findUserByUsernameOrId($db, $botId, $cleanInput);
        $translatorId = findUserByUsernameOrId($db, $botId, $transInput);

        if (!$typesetterId || !$cleanerId || !$translatorId) {
            $errText = "❌ <b>ثبت تیم با شکست مواجه شد!</b>\n\nبرخی از یوزرنیم‌های ارسال شده هنوز وارد ربات نشده و دکمه استارت را نزده‌اند:\n";
            $errText .= "├ تایپیست: " . ($typesetterId ? "✅ یافت شد" : "❌ یافت نشد (باید ربات را استارت بزند)") . "\n";
            $errText .= "├ کلینر: " . ($cleanerId ? "✅ یافت شد" : "❌ یافت نشد (باید ربات را استارت بزند)") . "\n";
            $errText .= "└ مترجم: " . ($translatorId ? "✅ یافت شد" : "❌ یافت نشد (باید ربات را استارت بزند)");
            $tg->sendMessage($chatId, $errText);
            exit;
        }

        // ثبت یا به‌روزرسانی اعضای تیم در جدول team_assignments
        $db->beginTransaction();
        try {
            $stmtDel = $db->prepare("DELETE FROM team_assignments WHERE bot_id = :bot_id AND manhwa_id = :manhwa_id");
            $stmtDel->execute(['bot_id' => $botId, 'manhwa_id' => $manhwa['id']]);

            $stmtIns = $db->prepare("INSERT INTO team_assignments (bot_id, manhwa_id, role, user_id) VALUES (:bot_id, :manhwa_id, :role, :user_id)");
            
            $stmtIns->execute(['bot_id' => $botId, 'manhwa_id' => $manhwa['id'], 'role' => 'typesetter', 'user_id' => $typesetterId]);
            $stmtIns->execute(['bot_id' => $botId, 'manhwa_id' => $manhwa['id'], 'role' => 'cleaner', 'user_id' => $cleanerId]);
            $stmtIns->execute(['bot_id' => $botId, 'manhwa_id' => $manhwa['id'], 'role' => 'translator', 'user_id' => $translatorId]);

            $db->commit();

            $tg->sendMessage($chatId, "✅ <b>اعضای تیم پروژه «{$manhwa['title']}» با موفقیت ست شدند!</b>\n\n📝 مترجم: <code>{$transInput}</code>\n🖌 کلینر: <code>{$cleanInput}</code>\n⌨️ تایپیست: <code>{$typeInput}</code>");
        } catch (Exception $e) {
            $db->rollBack();
            $tg->sendMessage($chatId, "❌ خطا در همگام‌سازی دیتابیس تیم مانهوا.");
        }
        exit;
    }

    // دستور ارسال فایل چپتر برای تایید و ثبت حقوق اعضا
    elseif (strpos($text, '/add_file_chpter') === 0) {
        // حتماً دستور باید روی فایل ریپلای شده باشد
        $replyTo = $message['reply_to_message'] ?? null;
        if (!$replyTo) {
            $tg->sendMessage($chatId, "❌ این دستور باید حتماً بر روی فایل چپتر نهایی ریپلای شود.");
            exit;
        }

        // استخراج فایل ارسالی از ریپلای
        $repliedFileId = $replyTo['document']['file_id'] ?? $replyTo['photo'][0]['file_id'] ?? null;
        if (!$repliedFileId) {
            $tg->sendMessage($chatId, "❌ پیغام ریپلای شده حاوی فایل سند (Document) یا تصویر معتبر نیست.");
            exit;
        }

        // استخراج شماره چپتر از جلوی دستور
        preg_match('/\/add_file_chpter\s+(\d+)/', $text, $matchChNum);
        $chapterNum = isset($matchChNum[1]) ? (int)$matchChNum[1] : null;

        if (!$chapterNum) {
            $tg->sendMessage($chatId, "❌ شماره چپتر را در مقابل دستور وارد کنید.\n\nمثال: <code>/add_file_chpter 9</code>");
            exit;
        }

        // پیدا کردن مانهوای متصل به این گروه
        $stmtM = $db->prepare("SELECT id, title FROM manhwas WHERE bot_id = :bot_id AND group_id = :group_id LIMIT 1");
        $stmtM->execute(['bot_id' => $botId, 'group_id' => $chatId]);
        $manhwa = $stmtM->fetch();

        if (!$manhwa) {
            $tg->sendMessage($chatId, "❌ ابتدا باید این گروه را با دستور <code>/add_manhwa</code> به یک پروژه مانهوا متصل کنید.");
            exit;
        }

        // پیدا کردن کادر اعضای ست شده روی این پروژه مانهوا
        $stmtTeam = $db->prepare("SELECT role, user_id FROM team_assignments WHERE bot_id = :bot_id AND manhwa_id = :manhwa_id");
        $stmtTeam->execute(['bot_id' => $botId, 'manhwa_id' => $manhwa['id']]);
        $team = $stmtTeam->fetchAll();

        $assigned = ['translator' => null, 'cleaner' => null, 'typesetter' => null];
        foreach ($team as $m) {
            $assigned[$m['role']] = $m['user_id'];
        }

        if (!$assigned['translator'] || !$assigned['cleaner'] || !$assigned['typesetter']) {
            $tg->sendMessage($chatId, "⚠️ لطفاً ابتدا تیم پروژه را با دستور <code>/add_team</code> ست کنید. بدون ست کردن تیم، مبالغ حقوق چپتر قابل پردازش نیست.");
            exit;
        }

        // دریافت نرخ دستمزد حقوق لحظه‌ای از بخش تنظیمات
        $stmtRates = $db->prepare("SELECT key, value FROM settings WHERE bot_id = :bot_id AND key IN ('rate_translator', 'rate_cleaner', 'rate_typesetter')");
        $stmtRates->execute(['bot_id' => $botId]);
        $ratesRows = $stmtRates->fetchAll();
        
        $rates = ['rate_translator' => 0, 'rate_cleaner' => 0, 'rate_typesetter' => 0];
        foreach ($ratesRows as $r) {
            $rates[$r['key']] = (float)$r['value'];
        }

        // ثبت چپتر جدید به حالت pending (انتظار برای تایید ادمین) در دیتابیس نئون
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
            't_id'        => $assigned['translator'],
            'c_id'        => $assigned['cleaner'],
            'ty_id'       => $assigned['typesetter'],
            't_pay'       => $rates['rate_translator'],
            'c_pay'       => $rates['rate_cleaner'],
            'ty_pay'      => $rates['rate_typesetter']
        ]);
        $newChapterId = $stmtCh->fetch()['id'];

        $tg->sendMessage($chatId, "📥 چپتر <code>{$chapterNum}</code> مانهوای <b>«{$manhwa['title']}»</b> با موفقیت دریافت شد و جهت تایید نهایی و واریز حقوق برای ادمین کل فرستاده شد.");

        // ارسال فایل چپتر برای تمامی ادمین‌ها و مالک ربات جهت تایید دکمه‌ای
        $stmtAdmins = $db->prepare("SELECT tg_id FROM users WHERE bot_id = :bot_id AND (role = 'admin' || role = 'owner')");
        $stmtAdmins->execute(['bot_id' => $botId]);
        $admins = $stmtAdmins->fetchAll();

        $adminCaption = "📥 <b>چپتر جدید جهت بررسی و پرداخت دستمزد:</b>\n\n"
                      . "📚 مانهوا: <b>{$manhwa['title']}</b>\n"
                      . "🔢 شماره چپتر: <b>{$chapterNum}</b>\n"
                      . "👤 ارسال کننده: {$fullName} (@{$username})\n\n"
                      . "💰 مبالغ محاسبه شده چپتر:\n"
                      . "├ مترجم: " . number_format($rates['rate_translator']) . " تومان\n"
                      . "├ کلینر: " . number_format($rates['rate_cleaner']) . " تومان\n"
                      . "└ تایپیست: " . number_format($rates['rate_typesetter']) . " تومان\n\n"
                      . "💡 تایید چپتر باعث واریز خودکار مبالغ بالا به کیف پول اعضا و آپدیت چپتر مانهوا می‌شود.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ تایید و پرداخت حقوق', 'callback_data' => "admin_approve_ch_{$newChapterId}"],
                    ['text' => '❌ رد چپتر', 'callback_data' => "admin_reject_ch_{$newChapterId}"]
                ]
            ]
        ];

        foreach ($admins as $ad) {
            $tg->sendDocument($ad['tg_id'], $repliedFileId, $adminCaption, $keyboard);
        }
        exit;
    }

    // دستور راهنمای ربات در گروه
    elseif ($text === '/help' || $text === 'راهنما') {
        $helpText = "📖 <b>راهنمای دستورات گروه مانهوا مانپین:</b>\n\n"
                  . "📌 <b>دستورات مخصوص مدیریت مانهوا (ویژه ادمین‌ها):</b>\n"
                  . "├ <code>/add_manhwa</code> ➔ شروع فرآیند ثبت مانهوا و اتصال این گروه به پروژه مانهوای جدید\n"
                  . "└ <code>/add_team</code> ➔ متصل کردن اعضای تیم به مانهوای این گروه\n"
                  . "   <i>مثال فرمت استفاده:</i>\n"
                  . "   <code>/add_team\nتایپ[@username]->\nکلین[@username]->\nترجمه[@username]-></code>\n\n"
                  . "📌 <b>دستورات عمومی کارها (ویژه اعضا):</b>\n"
                  . "└ <code>/add_file_chpter [شماره چپتر]</code> ➔ ثبت فایل چپتر نهایی کار شده جهت بررسی ادمین و واریز حقوق\n"
                  . "   <i>نکته: تایپیست محترم باید این دستور را روی فایل یا سند چپتر حل شده ریپلای کند.</i>";
        
        $tg->sendMessage($chatId, $helpText);
        exit;
    }
  }
