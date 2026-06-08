<?php
/**
 * Project: Arvan Create Bot Maker Platform (Super Uploader Engine)
 * File: child_uploader/plugins/default_plugin/admin_chapter_manager.php
 * Role: Admin Chapters List & Secure Step-by-Step File Upload Wizard
 */

// جلوگیری از لود مستقل بدون کانتکست و متغیرهای تعریف شده در هسته ربات
if (!isset($db, $tg, $botId, $userId)) {
    exit;
}

$userStep     = $user['step'] ?? 'idle';
$callbackData = $callbackData ?? ($callbackQuery['data'] ?? '');
$messageId    = $messageId ?? ($callbackQuery['message']['message_id'] ?? null);
$chatId       = $chatId ?? ($callbackQuery['message']['chat']['id'] ?? $userId);

// ==========================================
// فاز ۱: پردازش متون ارسالی ماشین وضعیت (FSM Upload & Edit States)
// ==========================================
if ($userStep !== 'idle' && empty($callbackData)) {

    // پله اول: دریافت شماره چپتر جدید
    if (strpos($userStep, 'def_wait_chapter_num_') === 0) {
        $mediaId = (int)str_replace('def_wait_chapter_num_', '', $userStep);
        $chapterNum = trim($text);

        if (!is_numeric($chapterNum) || (float)$chapterNum <= 0) {
            $tg->sendMessage($userId, "❌ شماره چپتر فقط باید عدد (صحیح یا اعشاری بزرگتر از صفر) باشد. مجدداً ارسال کنید:");
            exit;
        }

        // بررسی یکتا بودن شماره چپتر در این مانهوا جهت جلوگیری از بازنویسی اشتباه
        $stmtCheck = $db->prepare("SELECT 1 FROM chapters WHERE bot_id = :bot_id AND manhwa_id = :m_id AND chapter_num = :num LIMIT 1");
        $stmtCheck->execute(['bot_id' => $botId, 'm_id' => $mediaId, 'num' => $chapterNum]);
        if ($stmtCheck->fetch()) {
            $tg->sendMessage($userId, "⚠️ چپتر شماره <b>«{$chapterNum}»</b> قبلاً برای این مانهوا آپلود شده است. لطفاً شماره دیگری انتخاب کنید یا برای ویرایش کار قبلی اقدام نمایید:");
            exit;
        }

        FSM::setStep($botId, $userId, "def_wait_chapter_file_{$mediaId}_{$chapterNum}");
        $tg->sendMessage($userId, "✅ شماره چپتر <b>«{$chapterNum}»</b> ثبت شد.\n\nحالا فایل نهایی چپتر (فایل سند Document یا تصویر Photo معمولی) را ارسال کنید:");
        exit;
    }

    // پله دوم: دریافت فایل چپتر جدید و ذخیره‌سازی خودکار در دیتابیس [1]
    elseif (strpos($userStep, 'def_wait_chapter_file_') === 0) {
        $params = str_replace('def_wait_chapter_file_', '', $userStep);
        $parts = explode('_', $params);
        $mediaId    = (int)$parts[0];
        $chapterNum = (float)$parts[1];

        $fileId = null;
        if (isset($message['document'])) {
            $fileId = $message['document']['file_id'];
        } elseif (isset($message['photo'])) {
            $fileId = end($message['photo'])['file_id'];
        }

        if (!$fileId) {
            $tg->sendMessage($userId, "❌ فایل ارسالی معتبر نیست. لطفاً چپتر را فقط به صورت فایل سند (Document) یا تصویر بفرستید:");
            exit;
        }

        $db->beginTransaction();
        try {
            // ۱. ثبت چپتر با وضعیت تایید شده (approved) به صورت پیش‌فرض برای ادمین [1]
            $stmtIns = $db->prepare("
                INSERT INTO chapters (bot_id, manhwa_id, chapter_num, file_id, status)
                VALUES (:bot_id, :m_id, :num, :file, 'approved')
            ");
            $stmtIns->execute([
                'bot_id' => $botId,
                'm_id'   => $mediaId,
                'num'    => $chapterNum,
                'file'   => $fileId
            ]);

            // ۲. به‌روزرسانی فیلد آخرین چپتر مانهوا به صورت داینامیک [1]
            $stmtUpM = $db->prepare("
                UPDATE manhwas 
                SET last_chapter = GREATEST(last_chapter, :num),
                    last_active_at = CURRENT_TIMESTAMP
                WHERE bot_id = :bot_id AND id = :id
            ");
            $stmtUpM->execute([
                'num'    => $chapterNum,
                'bot_id' => $botId,
                'id'     => $mediaId
            ]);

            $db->commit();
            FSM::clearStep($botId, $userId);

            $tg->sendMessage($userId, "✅ <b>چپتر شماره {$chapterNum} با موفقیت آپلود و به صورت خودکار در ربات منتشر شد!</b>", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت به مدیریت فایل‌ها', 'callback_data' => "def_manage_chapters_list_{$mediaId}_1"]]]
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            $tg->sendMessage($userId, "❌ خطای سیستم در ذخیره‌سازی اطلاعات فایل در پایگاه‌داده.");
            error_log("Failed to upload chapter file: " . $e->getMessage());
        }
        exit;
    }

    // پله سوم: ادیت فایل چپتر قبلی
    elseif (strpos($userStep, 'def_wait_edit_ch_file_') === 0) {
        $chapterId = (int)str_replace('def_wait_edit_ch_file_', '', $userStep);

        $fileId = null;
        if (isset($message['document'])) {
            $fileId = $message['document']['file_id'];
        } elseif (isset($message['photo'])) {
            $fileId = end($message['photo'])['file_id'];
        }

        if (!$fileId) {
            $tg->sendMessage($userId, "❌ فایل ارسالی معتبر نیست. مجدداً ارسال کنید:");
            exit;
        }

        $stmtUp = $db->prepare("UPDATE chapters SET file_id = :file WHERE bot_id = :bot_id AND id = :id");
        $stmtUp->execute(['file' => $fileId, 'bot_id' => $botId, 'id' => $chapterId]);

        // یافتن مانهوای مرتبط جهت ارجاع به منو
        $stmtM = $db->prepare("SELECT manhwa_id FROM chapters WHERE id = :id LIMIT 1");
        $stmtM->execute(['id' => $chapterId]);
        $mediaId = $stmtM->fetchColumn() ?: 0;

        FSM::clearStep($botId, $userId);
        $tg->sendMessage($userId, "✅ فایل چپتر با موفقیت ویرایش و جایگزین گردید.", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت به لیست', 'callback_data' => "def_manage_chapters_list_{$mediaId}_1"]]]
        ]);
        exit;
    }

    // پله چهارم: ادیت شماره چپتر قبلی
    elseif (strpos($userStep, 'def_wait_edit_ch_num_') === 0) {
        $chapterId = (int)str_replace('def_wait_edit_ch_num_', '', $userStep);
        $newNum = trim($text);

        if (!is_numeric($newNum) || (float)$newNum <= 0) {
            $tg->sendMessage($userId, "❌ شماره چپتر فقط باید عدد معتبر باشد. مجدداً ارسال کنید:");
            exit;
        }

        $stmtUp = $db->prepare("UPDATE chapters SET chapter_num = :num WHERE bot_id = :bot_id AND id = :id");
        $stmtUp->execute(['num' => (float)$newNum, 'bot_id' => $botId, 'id' => $chapterId]);

        $stmtM = $db->prepare("SELECT manhwa_id FROM chapters WHERE id = :id LIMIT 1");
        $stmtM->execute(['id' => $chapterId]);
        $mediaId = $stmtM->fetchColumn() ?: 0;

        FSM::clearStep($botId, $userId);
        $tg->sendMessage($userId, "✅ شماره چپتر با موفقیت ویرایش شد.", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت به لیست', 'callback_data' => "def_manage_chapters_list_{$mediaId}_1"]]]
        ]);
        exit;
    }
}

// ==========================================
// فاز ۲: پردازش کالبک‌کوئری‌های مدیریت چپترها (Callbacks)
// ==========================================
if ($callbackQuery) {

    // الف) کالبک نمایش لیست ورق‌زن قسمت‌ها/چپترها
    if (strpos($callbackData, 'def_manage_chapters_list_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        
        $params = str_replace('def_manage_chapters_list_', '', $callbackData);
        $parts = explode('_', $params);
        $mediaId = (int)$parts[0];
        $page    = isset($parts[1]) ? (int)$parts[1] : 1;
        $limit   = 10;
        $offset  = ($page - 1) * $limit;

        // استعلام مشخصات کاربری نام چپتر برای نمایش داینامیک دکمه
        $botContentType = LayoutRenderer::getBotContentType($db, $botId);
        $defaultChLabel = ($botContentType === 'movie') ? 'قسمت‌ها' : 'چپترها';
        $chLabel = LayoutRenderer::getCustomLabel($db, $botId, 'chapter_btn_label', $defaultChLabel);

        // محاسبه کل چپترها
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM chapters WHERE bot_id = :bot_id AND manhwa_id = :m_id");
        $stmtCount->execute(['bot_id' => $botId, 'm_id' => $mediaId]);
        $totalCh = $stmtCount->fetchColumn() ?: 0;
        $totalPages = ceil($totalCh / $limit);

        // واکشی داده‌های صفحه جاری
        $stmtCh = $db->prepare("
            SELECT id, chapter_num, status 
            FROM chapters 
            WHERE bot_id = :bot_id AND manhwa_id = :m_id 
            ORDER BY chapter_num DESC LIMIT :limit OFFSET :offset
        ");
        $stmtCh->bindValue(':bot_id', $botId, PDO::PARAM_INT);
        $stmtCh->bindValue(':m_id', $mediaId, PDO::PARAM_INT);
        $stmtCh->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmtCh->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmtCh->execute();
        $chapters = $stmtCh->fetchAll();

        $text = "📂 <b>بخش مدیریت فایل‌ها و {$chLabel} اثر (صفحه {$page} از {$totalPages}):</b>\n\n"
              . "در این منو می‌توانید فایل‌های متصل به پروژه را حذف کرده، شماره چپتر را تغییر دهید یا فایل خام جدیدی آپلود و جایگزین کنید:";

        $buttons = [];
        $buttons[] = [['text' => "➕ آپلود {$chLabel} جدید", 'callback_data' => "def_chapters_add_init_{$mediaId}"]];

        foreach ($chapters as $ch) {
            $statusIcon = $ch['status'] === 'approved' ? '✅' : '⏳';
            $buttons[] = [
                ['text' => "{$statusIcon} {$chLabel} {$ch['chapter_num']}", 'callback_data' => "def_chapters_edit_menu_{$ch['id']}"],
                ['text' => '⚙️ ادیت و حذف', 'callback_data' => "def_chapters_edit_menu_{$ch['id']}"]
            ];
        }

        $keyboard = LayoutRenderer::makeGrid($buttons, 1);
        $navRow = LayoutRenderer::makePaginationRow($page, $totalPages, "def_manage_chapters_list_{$mediaId}");
        if (!empty($navRow)) {
            $keyboard[] = $navRow;
        }
        $keyboard[] = [['text' => '🔙 بازگشت به شناسنامه اثر', 'callback_data' => "def_work_view_{$mediaId}"]];

        $tg->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $keyboard]);
        exit;
    }

    // ب) کالبک شروع جادوگر ثبت چپتر جدید
    elseif (strpos($callbackData, 'def_chapters_add_init_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $mediaId = (int)str_replace('def_chapters_add_init_', '', $callbackData);

        FSM::setStep($botId, $userId, "def_wait_chapter_num_{$mediaId}");
        $tg->sendMessage($userId, "✍️ <b>لطفاً شماره چپتر جدید را ارسال کنید:</b>\n\nمثال: <code>12</code> یا <code>12.5</code>", [
            'inline_keyboard' => [[['text' => '❌ لغو و بازگشت', 'callback_data' => "def_manage_chapters_list_{$mediaId}_1"]]]
        ]);
        exit;
    }

    // ج) کالبک نمایش منوی ویرایش تخصصی و حذف هر چپتر خاص
    elseif (strpos($callbackData, 'def_chapters_edit_menu_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $chapterId = (int)str_replace('def_chapters_edit_menu_', '', $callbackData);

        // واکشی مشخصات چپتر
        $stmt = $db->prepare("SELECT * FROM chapters WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $chapterId]);
        $ch = $stmt->fetch();

        if ($ch) {
            $text = "⚙️ <b>مدیریت چپتر شماره {$ch['chapter_num']}:</b>\n\n"
                  . "وضعیت تایید: <code>{$ch['status']}</code>\n\n"
                  . "جهت تغییر فیزیکی فایل، تغییر شماره یا حذف کامل دکمه‌های زیر را لمس کنید:";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✏️ ویرایش فایل', 'callback_data' => "def_chapters_edit_file_{$chapterId}"],
                        ['text' => '✏️ ویرایش شماره چپتر', 'callback_data' => "def_chapters_edit_num_{$chapterId}"]
                    ],
                    [['text' => '🗑️ حذف کامل چپتر', 'callback_data' => "def_chapters_del_confirm_{$chapterId}"]],
                    [['text' => '🔙 بازگشت به لیست', 'callback_data' => "def_manage_chapters_list_{$ch['manhwa_id']}_1"]]
                ]
            ];

            $tg->sendMessage($userId, $text, $keyboard);
        }
        exit;
    }

    // د) کالبک فعال‌سازی FSM جهت تعویض فایل چپتر قبلی
    elseif (strpos($callbackData, 'def_chapters_edit_file_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $chapterId = (int)str_replace('def_chapters_edit_file_', '', $callbackData);

        FSM::setStep($botId, $userId, "def_wait_edit_ch_file_{$chapterId}");
        $tg->sendMessage($userId, "📥 <b>لطفاً فایل جدید چپتر را ارسال کنید:</b>\n\nاین فایل جایگزین فایل قبلی دانلودر خواهد شد.", [
            'inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => "def_chapters_edit_menu_{$chapterId}"]]]
        ]);
        exit;
    }

    // ه) کالبک فعال‌سازی FSM جهت تغییر عدد چپتر قبلی
    elseif (strpos($callbackData, 'def_chapters_edit_num_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $chapterId = (int)str_replace('def_chapters_edit_num_', '', $callbackData);

        FSM::setStep($botId, $userId, "def_wait_edit_ch_num_{$chapterId}");
        $tg->sendMessage($userId, "✍️ <b>لطفاً شماره جدید مورد نظر خود را برای این چپتر بفرستید:</b>", [
            'inline_keyboard' => [[['text' => '❌ انصراف', 'callback_data' => "def_chapters_edit_menu_{$chapterId}"]]]
        ]);
        exit;
    }

    // و) تایید حذف فیزیکی یک چپتر از دیتابیس نئون
    elseif (strpos($callbackData, 'def_chapters_del_confirm_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $chapterId = (int)str_replace('def_chapters_del_confirm_', '', $callbackData);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🗑️ بله، چپتر حذف شود', 'callback_data' => "def_chapters_del_do_{$chapterId}"],
                    ['text' => '❌ لغو عملیات', 'callback_data' => "def_chapters_edit_menu_{$chapterId}"]
                ]
            ]
        ];

        $tg->sendMessage($userId, "⚠️ <b>آیا مطمئن هستید؟</b>\n\nبا تایید، این چپتر و فایل آن برای همیشه از ربات کاربران حذف خواهد شد.", $keyboard);
        exit;
    }

    // حذف چپتر از پایگاه‌داده
    elseif (strpos($callbackData, 'def_chapters_del_do_') === 0) {
        $chapterId = (int)str_replace('def_chapters_del_do_', '', $callbackData);

        // واکشی مانهوای مرتبط جهت ارجاع به لیست
        $stmtM = $db->prepare("SELECT manhwa_id FROM chapters WHERE id = :id LIMIT 1");
        $stmtM->execute(['id' => $chapterId]);
        $mediaId = $stmtM->fetchColumn() ?: 0;

        $stmtDel = $db->prepare("DELETE FROM chapters WHERE bot_id = :bot_id AND id = :id");
        $stmtDel->execute(['bot_id' => $botId, 'id' => $chapterId]);

        $tg->answerCallbackQuery($callbackId, "✅ چپتر با موفقیت حذف شد.", true);

        // رفرش لیست چپترها
        $callbackData = "def_manage_chapters_list_{$mediaId}_1";
        require __FILE__;
        exit;
    }
}
