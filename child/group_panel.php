<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: child/group_panel.php
 * Role: Group & Supergroup Command Processor with Multiple Staff Support & 6 New Advanced Commands
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
            $stmt = $db->prepare("SELECT tg_id FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id AND status = 'approved' LIMIT 1");
            $stmt->execute(['bot_id' => $botId, 'tg_id' => (int)$input]);
        } else {
            // پاک‌سازی علامت @ در صورتی که کاربر به همراه هندل کاربری وارد کرده باشد
            $cleanInput = ltrim($input, '@');
            $stmt = $db->prepare("SELECT tg_id FROM users WHERE bot_id = :bot_id AND username = :username AND status = 'approved' LIMIT 1");
            $stmt->execute(['bot_id' => $botId, 'username' => $cleanInput]);
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

        // ثبت مانهوا در دیتابیس و تخصیص گروه جاری به آن (اصلاح باگ ۸: مقداردهی اولیه last_active_at)
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

    // دستور انتساب اعضای تیم به مانهوای متصل به این گروه (پشتیبانی از تخصیص چندگانه اعضا)
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
        preg_match('/تایپ\[@?([a-zA-Z0-9_]+)\]/', $text, $matchType);
        preg_match('/کلین\[@?([a-zA-Z0-9_]+)\]/', $text, $matchClean);
        preg_match('/ترجمه\[@?([a-zA-Z0-9_]+)\]/', $text, $matchTrans);

        $typeInput  = isset($matchType[1]) ? $matchType[1] : null;
        $cleanInput = isset($matchClean[1]) ? $matchClean[1] : null;
        $transInput = isset($matchTrans[1]) ? $matchTrans[1] : null;

        if (!$typeInput || !$cleanInput || !$transInput) {
            $tg->sendMessage($chatId, "❌ <b>الگوی ارسال دستور اشتباه است!</b>\n\nلطفاً دستور را دقیقاً با فرمت زیر پر کرده و بفرستید:\n\n<code>/add_team\nتایپ[@username]->\nکلین[@username]->\nترجمه[@username]-></code>");
            exit;
        }

        // پیدا کردن آیدی‌های عددی اعضای تیم در دیتابیس
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

        // ثبت اعضای تیم در جدول team_assignments
        $db->beginTransaction();
        try {
            $stmtIns = $db->prepare("INSERT INTO team_assignments (bot_id, manhwa_id, role, user_id) VALUES (:bot_id, :manhwa_id, :role, :user_id)");
            
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

    // دستور ارسال فایل چپتر برای تایید و ثبت حقوق اعضا (سازگار با بستر انتساب چندگانه اعضا)
    elseif (strpos($text, '/add_file_chpter') === 0) {
        $replyTo = $message['reply_to_message'] ?? null;
        if (!$replyTo) {
            $tg->sendMessage($chatId, "❌ این دستور باید حتماً بر روی فایل چپتر نهایی ریپلای شود.");
            exit;
        }

        // استخراج فایل ارسالی از ریپلای (پشتیبانی هوشمند از سند و تصویر با بالاترین کیفیت)
        $repliedFileId = $replyTo['document']['file_id'] ?? end($replyTo['photo'])['file_id'] ?? null;
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
        $stmtM = $db->prepare("SELECT * FROM manhwas WHERE bot_id = :bot_id AND group_id = :group_id LIMIT 1");
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

        // تجمیع اعضا به تفکیک نقش جهت پشتیبانی کامل از اعضای موازی
        $assigned = ['translator' => [], 'cleaner' => [], 'typesetter' => []];
        foreach ($team as $m) {
            $assigned[$m['role']][] = $m['user_id'];
        }

        if (empty($assigned['translator']) || empty($assigned['cleaner']) || empty($assigned['typesetter'])) {
            $tg->sendMessage($chatId, "⚠️ لطفاً ابتدا تیم پروژه را با دستور <code>/add_team</code> ست کنید. بدون ست کردن تیم، مبالغ حقوق چپتر قابل پردازش نیست.");
            exit;
        }

        // موتور تشخیص هوشمند توزیع چپتر در حالت همکاری موازی:
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

        // رفع باگ ۲ (محاسبات ایمن مبالغ بدون بروز خطای کشنده بولین در PHP 8.2)
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

        // ثبت چپتر جدید به حالت pending (انتظار برای تایید ادمین) در دیتابیس
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

        // رفع باگ ۶: کنترل تعداد ادمین‌های دریافت‌کننده جهت برطرف کردن تاخیر طولانی و لوپ وب‌هوک
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

        // در صورت پشتیبانی سرور از ارسال زودهنگام تاییدیه دریافت به تلگرام جهت رفع نهایی مشکل وب‌هوک‌ها
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

    // دستور ۱: تنظیم مبالغ اختصاصی دستمزد مانهوا به صورت مستقیم از داخل گروه کاری
    elseif (strpos($text, '/set_rates') === 0) {
        if (!$isAdminInGroup) {
            $tg->sendMessage($chatId, "⚠️ این دستور مخصوص مدیریت است.");
            exit;
        }

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

    // دستور ۲: ثبت قوانین و استانداردهای گروه کاری
    elseif (strpos($text, '/set_rules') === 0) {
        if (!$isAdminInGroup) {
            $tg->sendMessage($chatId, "⚠️ این دستور مخصوص مدیریت است.");
            exit;
        }
        $rulesText = trim(str_replace('/set_rules', '', $text));

        if (empty($rulesText)) {
            $tg->sendMessage($chatId, "❌ لطفا متن قوانین را مقابل دستور بنویسید.");
            exit;
        }

        $stmt = $db->prepare("INSERT INTO group_rules (bot_id, group_id, rules) VALUES (:bot_id, :g_id, :rules) ON CONFLICT (bot_id, group_id) DO UPDATE SET rules = EXCLUDED.rules");
        $stmt->execute(['bot_id' => $botId, 'g_id' => $chatId, 'rules' => $rulesText]);

        $tg->sendMessage($chatId, "✅ <b>قوانین و استانداردهای کار تیمی این گروه با موفقیت ثبت شد.</b>");
        exit;
    }

    // دستور ۳: دریافت و نمایش قوانین کاری گروه برای اعضا
    elseif ($text === '/rules') {
        $stmt = $db->prepare("SELECT rules FROM group_rules WHERE bot_id = :bot_id AND group_id = :g_id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'g_id' => $chatId]);
        $row = $stmt->fetch();

        if ($row) {
            $tg->sendMessage($chatId, "📖 <b>قوانین و استانداردهای کار تیمی این گروه:</b>\n\n{$row['rules']}");
        } else {
            $tg->sendMessage($chatId, "⚠️ هیچ قانون اختصاصی برای این گروه کاری ثبت نشده است.");
        }
        exit;
    }

    // دستور ۴: دریافت آخرین وضعیت پروژه مانهوا و تیم متصل به آن
    elseif ($text === '/status') {
        $stmt = $db->prepare("SELECT * FROM manhwas WHERE bot_id = :bot_id AND group_id = :g_id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'g_id' => $chatId]);
        $m = $stmt->fetch();

        if ($m) {
            $stmtTeam = $db->prepare("
                SELECT ta.role, u.full_name 
                FROM team_assignments ta 
                JOIN users u ON ta.user_id = u.tg_id 
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

    // دستور ۵: دریافت آمار فعالیت تیمی و چپترهای ثبت شده گروه
    elseif ($text === '/stats') {
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

    // دستور ۶: عزل کل اعضای انتساب یافته به یک نقش خاص از داخل گروه
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

            // اختصاص نام فارسی نقش
            $roleFarsiName = 'نامشخص';
            if ($roleToUnassign === 'translator') {
                $roleFarsiName = 'مترجم';
            } elseif ($roleToUnassign === 'cleaner') {
                $roleFarsiName = 'کلینر';
            } elseif ($roleToUnassign === 'typesetter') {
                $roleFarsiName = 'تایپیست';
            }

            $tg->sendMessage($chatId, "✅ تمامی افراد متصل به نقش <b>{$roleFarsiName}</b> با موفقیت از این پروژه عزل شدند.");
        }
        exit;
    }

    // دستور راهنمای ربات در گروه (رفع باگ: تغییر نام به تیم مانهوا)
    elseif ($text === '/help' || $text === 'راهنما') {
        $helpText = "📖 <b>راهنمای دستورات گروه تیم مانهوا:</b>\n\n"
                  . "📌 <b>دستورات مخصوص ادمین‌ها:</b>\n"
                  . "├ <code>/add_manhwa</code> ➔ متصل کردن گروه کاری به پروژه جدید مانهوا\n"
                  . "├ <code>/add_team</code> ➔ افزودن اعضا به لیست انجام‌دهندگان پروژه\n"
                  . "├ <code>/set_rates [مترجم] [کلینر] [تایپیست]</code> ➔ تنظیم حقوق مانهوای این گروه\n"
                  . "├ <code>/set_rules [متن]</code> ➔ ثبت استانداردهای تیمی گروه کاری\n"
                  . "└ <code>/unassign [translator|cleaner|typesetter]</code> ➔ عزل کامل اعضا از نقش مشخص‌شده\n\n"
                  . "📌 <b>دستورات عمومی اعضا:</b>\n"
                  . "├ <code>/add_file_chpter [شماره]</code> ➔ ثبت چپتر نهایی ریپلای‌شده جهت بررسی و واریز حقوق\n"
                  . "├ <code>/rules</code> ➔ نمایش قوانین تیمی و استانداردهای کار\n"
                  . "├ <code>/status</code> ➔ وضعیت آخرین چپتر و اعضای تیم متصل\n"
                  . "└ <code>/stats</code> ➔ مجموع چپترهای انجام‌شده این پروژه";
        
        $tg->sendMessage($chatId, $helpText);
        exit;
    }
}
