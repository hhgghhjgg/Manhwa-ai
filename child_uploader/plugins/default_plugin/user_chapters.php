<?php
/**
 * Project: Arvan Create Bot Maker Platform (Super Uploader Engine)
 * File: child_uploader/plugins/default_plugin/user_chapters.php
 * Role: Chapters Pager & Secure File Downloader with Read Logger
 */

// جلوگیری از لود مستقل بدون کانتکست و متغیرهای تعریف شده در هسته ربات
if (!isset($db, $tg, $botId, $userId)) {
    exit;
}

$callbackData = $callbackData ?? ($callbackQuery['data'] ?? '');
$messageId    = $messageId ?? ($callbackQuery['message']['message_id'] ?? null);
$chatId       = $chatId ?? ($callbackQuery['message']['chat']['id'] ?? $userId);

// ==========================================
// سناریوی الف: نمایش لیست چپترها/قسمت‌ها به صورت ورق‌زن (Chapters Paginated List)
// ==========================================
if (strpos($callbackData, 'def_chapters_list_') === 0) {
    $tg->answerCallbackQuery($callbackId);
    
    $params = str_replace('def_chapters_list_', '', $callbackData);
    $parts  = explode('_', $params);
    $mediaId = (int)$parts[0];
    $page    = isset($parts[1]) ? (int)$parts[1] : 1;
    if ($page <= 0) {
        $page = 1;
    }

    $limit  = 10;
    $offset = ($page - 1) * $limit;

    try {
        // ۱. واکشی عنوان و نوع محتوای اصلی پروژه از دیتابیس
        $stmtM = $db->prepare("SELECT title, bot_content_type FROM manhwas WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmtM->execute(['bot_id' => $botId, 'id' => $mediaId]);
        $mediaRow = $stmtM->fetch();
        
        $mTitle      = $mediaRow ? $mediaRow['title'] : 'پروژه';
        $contentType = $mediaRow ? ($mediaRow['bot_content_type'] ?? 'manhwa') : 'manhwa';

        // ۲. محاسبه کل صفحات چپترهای تایید شده
        $stmtCount = $db->prepare("
            SELECT COUNT(*) 
            FROM chapters 
            WHERE bot_id = :bot_id AND manhwa_id = :m_id AND status = 'approved'
        ");
        $stmtCount->execute(['bot_id' => $botId, 'm_id' => $mediaId]);
        $totalCh = $stmtCount->fetchColumn() ?: 0;
        $totalPages = ceil($totalCh / $limit);

        if ($totalCh == 0) {
            $textEmpty = "⚠️ <b>در حال حاضر هیچ فایلی برای اثر «{$mTitle}» ثبت و منتشر نشده است.</b>\n\n"
                       . "لطفاً بعداً مراجعه فرمایید یا وضعیت را از پشتیبانی پیگیری کنید.";
            
            $keyboardEmpty = [
                'inline_keyboard' => [
                    [['text' => '🔙 بازگشت به شناسنامه اثر', 'callback_data' => "def_view_media_{$mediaId}"]]
                ]
            ];
            $tg->editMessageText($chatId, $messageId, $textEmpty, $keyboardEmpty);
            exit;
        }

        // ۳. واکشی لیست چپترهای این صفحه از دیتابیس نئون
        $stmtCh = $db->prepare("
            SELECT id, chapter_num 
            FROM chapters 
            WHERE bot_id = :bot_id AND manhwa_id = :m_id AND status = 'approved' 
            ORDER BY chapter_num DESC LIMIT :limit OFFSET :offset
        ");
        $stmtCh->bindValue(':bot_id', $botId, PDO::PARAM_INT);
        $stmtCh->bindValue(':m_id', $mediaId, PDO::PARAM_INT);
        $stmtCh->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmtCh->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmtCh->execute();
        $chapters = $stmtCh->fetchAll();

        // ۴. شخصی‌سازی پویا برچسب و عنوان‌ها بر اساس سلیقه ادمین (مثال: تبدیل چپتر به قسمت یا ترک موسیقی)
        $defaultBtnLabel = ($contentType === 'movie') ? 'قسمت' : 'چپتر';
        $btnLabel = LayoutRenderer::getCustomLabel($db, $botId, 'chapter_btn_label', $defaultBtnLabel);

        $text = "📖 <b>لیست قسمت‌های آماده اثر «{$mTitle}» (صفحه {$page} از {$totalPages}):</b>\n\n"
              . "جهت دانلود مستقیم، روی شماره مورد نظر کلیک کنید:";
              
        $buttons = [];
        foreach ($chapters as $ch) {
            $buttons[] = [
                'text' => "📖 {$btnLabel} " . $ch['chapter_num'], 
                'callback_data' => "def_get_file_{$ch['id']}"
            ];
        }

        // چیدن دکمه‌ها در ردیف‌های ۲ ستونه به صورت گرید منظم تلگرام
        $keyboard = LayoutRenderer::makeGrid($buttons, 2);
        
        // ساخت ردیف ناوبری ورق‌زن با متد عمومی هسته
        $navRow = LayoutRenderer::makePaginationRow($page, $totalPages, "def_chapters_list_{$mediaId}");
        if (!empty($navRow)) {
            $keyboard[] = $navRow;
        }

        $keyboard[] = [['text' => '🔙 بازگشت به شناسنامه اثر', 'callback_data' => "def_view_media_{$mediaId}"]];

        $tg->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $keyboard]);

    } catch (PDOException $e) {
        error_log("Error rendering chapters list: " . $e->getMessage());
        $tg->sendMessage($userId, "❌ خطای سیستمی در رندر لیست چپترها.");
    }
    exit;
}

// ==========================================
// سناریوی ب: بررسی امنیت VIP، ارسال فایل نهایی و ثبت لاگ مطالعه (File Payout & Logger)
// ==========================================
elseif (strpos($callbackData, 'def_get_file_') === 0) {
    $chapterId = (int)str_replace('def_get_file_', '', $callbackData);

    try {
        // ۱. واکشی مشخصات فایل چپتر و مانهوا به صورت جوین شده
        $stmt = $db->prepare("
            SELECT c.*, m.title 
            FROM chapters c 
            JOIN manhwas m ON c.manhwa_id = m.id 
            WHERE c.bot_id = :bot_id AND c.id = :id AND c.status = 'approved' 
            LIMIT 1
        ");
        $stmt->execute(['bot_id' => $botId, 'id' => $chapterId]);
        $chapter = $stmt->fetch();

        if (!$chapter) {
            $tg->answerCallbackQuery($callbackId, "❌ فایل مورد نظر یافت نشد یا غیرفعال شده است.", true);
            exit;
        }

        // ۲. لایه فیلتر امنیتی پرداخت اشتراک ویژه (VIP Interceptor) [1]
        $isVipInstalled = PluginLoader::isPluginActive($db, $botId, 'vip_subscription');
        if ($isVipInstalled) {
            // استعلام لیست کاربران ویژه ربات از جدول تنظیمات
            $stmtVip = $db->prepare("
                SELECT setting_value 
                FROM bot_plugin_settings 
                WHERE bot_id = :bot_id AND plugin_slug = 'vip_subscription' AND setting_key = 'vip_users' 
                LIMIT 1
            ");
            $stmtVip->execute(['bot_id' => $botId]);
            $vipData = $stmtVip->fetchColumn();
            $vipArray = $vipData ? json_decode($vipData, true) : [];

            if (!in_array($userId, $vipArray)) {
                // لغو فرآیند دانلود و نمایش فلو خرید اشتراک ویژه
                $tg->answerCallbackQuery($callbackId);
                $tg->sendMessage($userId, "🔒 <b>محدودیت دسترسی (VIP)!</b>\n\nاین فایل مخصوص اعضای ویژه ربات است. جهت دانلود نامحدود کل آرشیو، لطفاً اشتراک ویژه تهیه کنید:", [
                    'inline_keyboard' => [
                        [['text' => '💎 خرید اشتراک ویژه', 'callback_data' => 'vip_buy_menu']],
                        [['text' => '🔙 بازگشت به لیست', 'callback_data' => "def_chapters_list_{$chapter['manhwa_id']}_1"]]
                    ]
                ]);
                exit;
            }
        }

        // ۳. ثبت لاگ تاریخچه مطالعه در جدول user_read_history جهت آپدیت زنده آمار پروفایل [1]
        $stmtLog = $db->prepare("
            INSERT INTO user_read_history (bot_id, user_id, chapter_id) 
            VALUES (:bot_id, :u_id, :ch_id) 
            ON CONFLICT (bot_id, user_id, chapter_id) DO NOTHING
        ");
        $stmtLog->execute([
            'bot_id' => $botId,
            'u_id'   => $userId,
            'ch_id'  => $chapterId
        ]);

        // ۴. ارسال مستقیم فایل سند (به صورت خام و ضد فشرده‌سازی خودکار تلگرام)
        $tg->answerCallbackQuery($callbackId, "📥 در حال ارسال فایل... لطفا شکیبا باشید.");
        
        $caption = "📥 <b>چپتر {$chapter['chapter_num']} مانهوای «{$chapter['title']}» خدمت شما.</b>\n\n"
                 . "🌟 از مطالعه اثر لذت ببرید!";

        $tg->sendDocument($userId, $chapter['file_id'], $caption);

    } catch (PDOException $e) {
        error_log("Error delivering chapter file: " . $e->getMessage());
        $tg->answerCallbackQuery($callbackId, "❌ خطای دیتابیس در زمان تحویل فایل.", true);
    }
    exit;
}
