<?php
/**
 * Project: Arvan Create Bot Maker Platform (Super Uploader Engine)
 * File: child_uploader/group_panel.php
 * Role: Group Message Router & Standard Linkage Command Processor
 */

// ۱. اطمینان از صحت کانتکست و متغیرهای لود شده از روتر
if (!isset($botContext, $tg, $user, $db)) {
    exit;
}

$chatId   = $message['chat']['id'] ?? $callbackQuery['message']['chat']['id'] ?? null;
$userId   = $user['tg_id'];
$fullName = $user['full_name'];
$userRole = $user['role'];
$botId    = $botContext['bot_id'];

$text          = isset($message['text']) ? trim($message['text']) : '';
$callbackData  = $callbackQuery['data'] ?? null;
$callbackId    = $callbackQuery['id'] ?? null;
$messageId     = $callbackQuery['message']['message_id'] ?? null;

$isAdminInGroup = ($userRole === 'owner' || $userRole === 'admin');

// ==========================================
// فاز ۱: بررسی فعال بودن افزونه مدیریت تیم کاری (Team Workflow Extension Check)
// ==========================================
$stmtCheck = $db->prepare("
    SELECT 1 
    FROM bot_installed_plugins 
    WHERE bot_id = :bot_id 
      AND plugin_slug = 'team_workflow' 
      AND is_active = TRUE 
    LIMIT 1
");
$stmtCheck->execute(['bot_id' => $botId]);
$hasTeamWorkflow = (bool)$stmtCheck->fetch();

if ($hasTeamWorkflow) {
    // واگذاری کامل پردازش گروهی به افزونه مدیریت تیم کاری
    $teamGroupHandler = __DIR__ . "/plugins/team_workflow/group_handler.php";
    if (file_exists($teamGroupHandler)) {
        require_once $teamGroupHandler;
        exit;
    }
}

// ==========================================
// فاز ۲: پردازش دستورات شیشه‌ای گروه سوپر آپلودر (Callbacks)
// ==========================================
if ($callbackQuery && $callbackData) {
    // دکمه لغو و بستن منوهای شیشه‌ای در گروه
    if ($callbackData === 'grp_cancel') {
        $tg->answerCallbackQuery($callbackId);
        if ($isAdminInGroup) {
            $tg->deleteMessage($chatId, $messageId);
        } else {
            $tg->answerCallbackQuery($callbackId, "⚠️ این دکمه مخصوص مدیریت گروه است.", true);
        }
        exit;
    }
}

// ==========================================
// فاز ۳: پردازش دستورات متنی اسلشی و فارسی در گروه عادی (Fallback Commands)
// ==========================================
if (!empty($text)) {

    // ۱. اتصال گروه به یکی از مانهواها/پروژه‌ها جهت ارسال نوتیفیکیشن خودکار انتشار
    if (strpos($text, '/link_project') === 0 || strpos($text, 'اتصال پروژه') === 0) {
        if (!$isAdminInGroup) {
            $tg->sendMessage($chatId, "⚠️ این دستور مخصوص مدیریت تیم است.");
            exit;
        }

        // استخراج آیدی عددی مانهوا/پروژه از دستور (مثال: /link_project 12)
        $cleanText = str_replace('اتصال پروژه', '', $text);
        preg_match('/(?:\/link_project\s+|)(\d+)/', $cleanText, $matchId);
        $projectId = isset($matchId[1]) ? (int)$matchId[1] : null;

        if (!$projectId) {
            $tg->sendMessage($chatId, "❌ <b>فرمت دستور اشتباه است!</b>\n\nقالب استفاده:\n<code>/link_project [آیدی پروژه]</code>\n\nمثال: <code>/link_project 12</code>\n\n💡 آیدی پروژه را می‌توانید از بخش ویرایش اطلاعات مانهوا در پنل مدیریت ربات بردارید.");
            exit;
        }

        // بررسی وجود پروژه در دیتابیس
        $stmtCheckProj = $db->prepare("SELECT title FROM manhwas WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmtCheckProj->execute(['bot_id' => $botId, 'id' => $projectId]);
        $project = $stmtCheckProj->fetch();

        if (!$project) {
            $tg->sendMessage($chatId, "❌ پروژه‌ای با آیدی <code>#{$projectId}</code> در دیتابیس این ربات یافت نشد.");
            exit;
        }

        // متصل کردن آیدی گروه به مانهوا
        $stmtUpdate = $db->prepare("UPDATE manhwas SET group_id = :group_id WHERE bot_id = :bot_id AND id = :id");
        $stmtUpdate->execute([
            'group_id' => $chatId,
            'bot_id'   => $botId,
            'id'       => $projectId
        ]);

        $tg->sendMessage($chatId, "✅ <b>اتصال پروژه با موفقیت برقرار شد!</b>\n\n📚 این گروه به صورت رسمی به پروژه <b>«{$project['title']}»</b> متصل گردید.\n\nاز این پس تمامی نوتیفیکیشن‌ها و چپترهای جدید تایید شده مربوط به این مانهوا، به صورت خودکار در این گروه ارسال خواهند شد.");
        exit;
    }

    // ۲. قطع اتصال گروه از پروژه مانهوا
    elseif ($text === '/unlink_project' || $text === 'قطع اتصال') {
        if (!$isAdminInGroup) {
            $tg->sendMessage($chatId, "⚠️ این دستور مخصوص مدیریت تیم است.");
            exit;
        }

        // یافتن مانهوای متصل به این گروه
        $stmtFind = $db->prepare("SELECT id, title FROM manhwas WHERE bot_id = :bot_id AND group_id = :group_id LIMIT 1");
        $stmtFind->execute(['bot_id' => $botId, 'group_id' => $chatId]);
        $project = $stmtFind->fetch();

        if (!$project) {
            $tg->sendMessage($chatId, "⚠️ این گروه در حال حاضر به هیچ پروژه‌ای متصل نیست.");
            exit;
        }

        // قطع اتصال گروه
        $stmtUnlink = $db->prepare("UPDATE manhwas SET group_id = NULL WHERE bot_id = :bot_id AND id = :id");
        $stmtUnlink->execute(['bot_id' => $botId, 'id' => $project['id']]);

        $tg->sendMessage($chatId, "✅ اتصال گروه از پروژه <b>«{$project['title']}»</b> با موفقیت قطع گردید.");
        exit;
    }

    // ۳. وضعیت پروژه متصل به این گروه
    elseif ($text === '/status' || $text === 'وضعیت') {
        $stmt = $db->prepare("SELECT * FROM manhwas WHERE bot_id = :bot_id AND group_id = :group_id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'group_id' => $chatId]);
        $m = $stmt->fetch();

        if (!$m) {
            $tg->sendMessage($chatId, "⚠️ این گروه به هیچ مانهوا یا پروژه‌ای متصل نشده است. ادمین می‌تواند با دستور <code>/link_project [آیدی]</code> اتصال را برقرار کند.");
            exit;
        }

        $resp = "📚 <b>شناسنامه پروژه متصل شده:</b>\n\n"
              . "📌 عنوان اثر: <b>{$m['title']}</b>\n"
              . "🎭 ژانرها: {$m['genres']}\n"
              . "🔢 آخرین چپتر ثبت شده: <code>{$m['last_chapter']}</code>\n\n"
              . "💡 نوتیفیکیشن انتشار چپترهای جدید این اثر به صورت خودکار به این گروه ارسال می‌شود.";
              
        $tg->sendMessage($chatId, $resp);
        exit;
    }

    // ۴. راهنمای دستورات گروهی سوپر آپلودر
    elseif ($text === '/help' || $text === 'راهنما') {
        $helpText = "📖 <b>راهنمای دستورات گروهی سوپر آپلودر:</b>\n\n"
                  . "📌 <b>دستورات مدیریتی گروه:</b>\n"
                  . "├ <code>/link_project [آیدی]</code> ➔ متصل کردن گروه به یک اثر جهت دریافت نوتیفیکیشن\n"
                  . "├ <code>/unlink_project</code> ➔ قطع اتصال گروه از پروژه متصل شده\n"
                  . "└ <code>وضعیت</code> یا <code>/status</code> ➔ نمایش آخرین جزئیات و چپتر اثر متصل‌شده\n\n"
                  . "💡 <i>نکته: در صورتی که افزونه «مدیریت تیم کاری» را از بازارچه فعال کنید، تمام فرمان‌های تیمی مانهوا (مانند انتساب اعضا و تایید حقوق چپترها با ریپلای) به این بخش اضافه خواهند شد.</i>";

        $tg->sendMessage($chatId, $helpText);
        exit;
    }
}
