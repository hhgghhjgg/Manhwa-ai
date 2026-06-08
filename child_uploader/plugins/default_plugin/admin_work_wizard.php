<?php
/**
 * Project: Arvan Create Bot Maker Platform (Super Uploader Engine)
 * File: child_uploader/plugins/default_plugin/admin_work_wizard.php
 * Role: Admin Works Manager & Dynamic ACF-driven Setup Wizard (JSONB Seeding)
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
// بخش کمکی: مدیریت سشن موقت ادمین برای جادوگر ثبت کار (Wizard Session Helpers)
// ==========================================
if (!function_exists('getAdminWizardSession')) {
    function getAdminWizardSession($db, $botId, $userId) {
        $stmt = $db->prepare("SELECT setting_value FROM bot_plugin_settings WHERE bot_id = :bot_id AND plugin_slug = 'default_plugin' AND setting_key = :key LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'key' => "wizard_session_{$userId}"]);
        $val = $stmt->fetchColumn();
        return $val ? json_decode($val, true) : null;
    }
}

if (!function_exists('saveAdminWizardSession')) {
    function saveAdminWizardSession($db, $botId, $userId, $session) {
        $val = json_encode($session);
        $stmt = $db->prepare("
            INSERT INTO bot_plugin_settings (bot_id, plugin_slug, setting_key, setting_value) 
            VALUES (:bot_id, 'default_plugin', :key, :val)
            ON CONFLICT (bot_id, plugin_slug, setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value
        ");
        $stmt->execute(['bot_id' => $botId, 'key' => "wizard_session_{$userId}", 'val' => $val]);
    }
}

if (!function_exists('clearAdminWizardSession')) {
    function clearAdminWizardSession($db, $botId, $userId) {
        $stmt = $db->prepare("DELETE FROM bot_plugin_settings WHERE bot_id = :bot_id AND plugin_slug = 'default_plugin' AND setting_key = :key");
        $stmt->execute(['bot_id' => $botId, 'key' => "wizard_session_{$userId}"]);
    }
}

// ==========================================
// فاز ۱: پردازش متون ارسالی ماشین وضعیت (FSM Setup Wizard States)
// ==========================================
if ($userStep !== 'idle' && empty($callbackData)) {

    // پله اول: دریافت تایتل اثر
    if ($userStep === 'def_wait_work_title') {
        $title = trim($text);
        if (empty($title) || mb_strlen($title) > 200) {
            $tg->sendMessage($userId, "❌ عنوان اثر نامعتبر است. مجدداً بفرستید:");
            exit;
        }

        // ایجاد سشن جادوگر
        $session = [
            'title' => $title,
            'cover_file_id' => null,
            'genres' => '',
            'values' => []
        ];
        saveAdminWizardSession($db, $botId, $userId, $session);

        FSM::setStep($botId, $userId, 'def_wait_work_cover');
        $tg->sendMessage($userId, "✅ عنوان <b>«{$title}»</b> ثبت شد.\n\nحالا تصویر کاور این کار را ارسال کنید (یا در صورت تمایل به عدم ثبت کاور، دستور <code>/skip</code> را بفرستید):");
        exit;
    }

    // پله دوم: دریافت فایل کاور (سند یا عکس)
    elseif ($userStep === 'def_wait_work_cover') {
        $coverFileId = null;

        if ($text === '/skip' || $text === 'رد کردن') {
            $coverFileId = null;
        } else {
            if (isset($message['photo'])) {
                $coverFileId = end($message['photo'])['file_id'];
            } elseif (isset($message['document'])) {
                $coverFileId = $message['document']['file_id'];
            }
        }

        if ($coverFileId === null && $text !== '/skip' && $text !== 'رد کردن') {
            $tg->sendMessage($userId, "❌ لطفاً تصویر کاور را به صورت عکس یا فایل سند بفرستید (یا جهت عبور /skip را ارسال کنید):");
            exit;
        }

        $session = getAdminWizardSession($db, $botId, $userId);
        if ($session) {
            $session['cover_file_id'] = $coverFileId;
            saveAdminWizardSession($db, $botId, $userId, $session);
        }

        FSM::setStep($botId, $userId, 'def_wait_work_genres');
        $tg->sendMessage($userId, "📥 کاور ثبت شد.\n\nحالا ژانرها یا کتگوری‌های این کار را به صورت متنی بفرستید (مثلاً: اکشن، درام، فانتزی):");
        exit;
    }

    // پله سوم: دریافت ژانرها و شروع فلو داینامیک فیلدهای اطلاعاتی ادمین [1]
    elseif ($userStep === 'def_wait_work_genres') {
        $genres = trim($text);
        if (empty($genres)) {
            $tg->sendMessage($userId, "❌ ژانرها نمی‌توانند خالی باشند. مجدداً بفرستید:");
            exit;
        }

        $session = getAdminWizardSession($db, $botId, $userId);
        if (!$session) {
            $tg->sendMessage($userId, "❌ خطای سشن جادوگر. لطفاً مجدداً کار را ثبت کنید.");
            FSM::clearStep($botId, $userId);
            exit;
        }

        $session['genres'] = $genres;

        // استخراج لیست فیلدهای اطلاعاتی داینامیکی که ادمین در بخش «تنظیم اطلاعات» ساخته است
        $stmtFields = $db->prepare("SELECT setting_value FROM bot_plugin_settings WHERE bot_id = :bot_id AND plugin_slug = 'default_plugin' AND setting_key = 'custom_fields_list' LIMIT 1");
        $stmtFields->execute(['bot_id' => $botId]);
        $fieldsData = $stmtFields->fetchColumn();
        $customFields = $fieldsData ? json_decode($fieldsData, true) : [];

        if (empty($customFields)) {
            // سناریوی الف: فیلد پویایی وجود ندارد؛ کار بلافاصله ذخیره می‌شود
            $stmtIns = $db->prepare("
                INSERT INTO manhwas (bot_id, title, cover_file_id, summary, genres, custom_metadata) 
                VALUES (:bot_id, :title, :cover, '', :genres, '{}'::jsonb)
            ");
            $stmtIns->execute([
                'bot_id' => $botId,
                'title'  => $session['title'],
                'cover'  => $session['cover_file_id'],
                'genres' => $session['genres']
            ]);

            clearAdminWizardSession($db, $botId, $userId);
            FSM::clearStep($botId, $userId);

            $tg->sendMessage($userId, "🎉 <b>پروژه جدید با موفقیت به آرشیو ربات اضافه شد!</b>", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت به کارها', 'callback_data' => 'def_manage_work_list_1']]]
            ]);
        } else {
            // سناریوی ب: آغاز جادوگر پله‌پله فیلدهای داینامیک ادمین [1]
            $session['fields'] = $customFields;
            $session['current_index'] = 0;
            saveAdminWizardSession($db, $botId, $userId, $session);

            $firstField = $customFields[0];
            FSM::setStep($botId, $userId, "def_wait_work_custom_{$firstField['id']}");
            
            $typeFarsi = $firstField['type'] === 'number' ? 'عدد' : 'متن عادی';
            $tg->sendMessage($userId, "📥 ژانرها ثبت شدند.\n\nحالا وارد بخش زمینه‌های داینامیک اثر می‌شویم:\n\nلطفاً مقدار فیلد <b>«{$firstField['title']}»</b> را به صورت <b>({$typeFarsi})</b> وارد کنید:");
        }
        exit;
    }

    // پله چهارم: حلقه پردازش فیلدهای اطلاعاتی داینامیک ادمین (Dynamic Fields FSM Loop) [1]
    elseif (strpos($userStep, 'def_wait_work_custom_') === 0) {
        $fieldId = (int)str_replace('def_wait_work_custom_', '', $userStep);
        $value = trim($text);

        $session = getAdminWizardSession($db, $botId, $userId);
        if (!$session) {
            $tg->sendMessage($userId, "❌ خطای نشست. مجدداً فرآیند را تکرار کنید.");
            FSM::clearStep($botId, $userId);
            exit;
        }

        $fields = $session['fields'];
        $currentIndex = $session['current_index'];
        $currentField = $fields[$currentIndex];

        // اعتبارسنجی نوع فیلد پویا (مثلاً اگر عدد باشد)
        if ($currentField['type'] === 'number' && !is_numeric($value)) {
            $tg->sendMessage($userId, "❌ فیلد <b>«{$currentField['title']}»</b> فقط باید به صورت عدد وارد شود. مجدداً عدد را ارسال کنید:");
            exit;
        }

        // ثبت مقدار در سشن
        $session['values'][$fieldId] = $value;
        $nextIndex = $currentIndex + 1;
        $session['current_index'] = $nextIndex;

        if ($nextIndex < count($fields)) {
            // هنوز فیلد پاسخ‌نداده وجود دارد؛ سوال پله بعدی را می‌پرسیم
            $nextField = $fields[$nextIndex];
            saveAdminWizardSession($db, $botId, $userId, $session);

            FSM::setStep($botId, $userId, "def_wait_work_custom_{$nextField['id']}");
            $typeFarsi = $nextField['type'] === 'number' ? 'عدد' : 'متن عادی';
            $tg->sendMessage($userId, "📥 مقدار ثبت شد.\n\nلطفاً فیلد بعدی یعنی <b>«{$nextField['title']}»</b> را به صورت <b>({$typeFarsi})</b> وارد کنید:");
        } else {
            // تمام فیلدهای داینامیک پاسخ داده شدند؛ ثبت نهایی کار در دیتابیس نئون با فرمت JSONB [1]
            $customMetadataJson = json_encode($session['values']);

            // کپی خلاصه داستان از فیلدهای داینامیک در صورت وجود
            $summary = "";
            foreach ($fields as $fd) {
                if ($fd['title'] === 'خلاصه داستان') {
                    $summary = $session['values'][$fd['id']] ?? '';
                }
            }

            $stmtIns = $db->prepare("
                INSERT INTO manhwas (bot_id, title, cover_file_id, summary, genres, custom_metadata) 
                VALUES (:bot_id, :title, :cover, :summary, :genres, :metadata::jsonb)
            ");
            $stmtIns->execute([
                'bot_id'   => $botId,
                'title'    => $session['title'],
                'cover'    => $session['cover_file_id'],
                'summary'  => $summary,
                'genres'   => $session['genres'],
                'metadata' => $customMetadataJson
            ]);

            clearAdminWizardSession($db, $botId, $userId);
            FSM::clearStep($botId, $userId);

            $tg->sendMessage($userId, "🎉 <b>تبریک می‌گویم! اثر جدید همراه با تمام مشخصات داینامیک با موفقیت به آرشیو ربات اضافه شد.</b>", [
                'inline_keyboard' => [[['text' => '🔙 بازگشت به کارها', 'callback_data' => 'def_manage_work_list_1']]]
            ]);
        }
        exit;
    }
}

// ==========================================
// فاز ۲: پردازش کالبک‌کوئری‌های مدیریت کارها (Callbacks)
// ==========================================
if ($callbackQuery) {

    // الف) نمایش لیست کارهای آپلود شده به صورت ورق‌زن ۱۰ تایی
    if (strpos($callbackData, 'def_manage_work_list_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $page = (int)str_replace('def_manage_work_list_', '', $callbackData);
        $limit = 10;
        $offset = ($page - 1) * $limit;

        // محاسبه کل کارها
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM manhwas WHERE bot_id = :bot_id");
        $stmtCount->execute(['bot_id' => $botId]);
        $totalWorks = $stmtCount->fetchColumn() ?: 0;
        $totalPages = ceil($totalWorks / $limit);

        // واکشی داده‌ها
        $stmtWorks = $db->prepare("SELECT id, title FROM manhwas WHERE bot_id = :bot_id ORDER BY id DESC LIMIT :limit OFFSET :offset");
        $stmtWorks->bindValue(':bot_id', $botId, PDO::PARAM_INT);
        $stmtWorks->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmtWorks->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmtWorks->execute();
        $works = $stmtWorks->fetchAll();

        $text = "📁 <b>بخش مدیریت کارهای آرشیو (صفحه {$page} از {$totalPages}):</b>\n\n"
              . "در این منو می‌توانید فایل‌ها، چپترها، کاورها و جزئیات تمام آثار آپلود شده را مدیریت کنید.\n\n"
              . "برای افزودن کار جدید، روی دکمه افزودن کار در بالای لیست بزنید:";

        $buttons = [];
        $buttons[] = [['text' => '➕ افزودن کار جدید', 'callback_data' => 'def_work_add_init']];

        foreach ($works as $w) {
            $buttons[] = [
                ['text' => "📚 " . $w['title'], 'callback_data' => "def_work_view_{$w['id']}"],
                ['text' => '👁 مشاهده', 'callback_data' => "def_work_view_{$w['id']}"]
            ];
        }

        $keyboard = LayoutRenderer::makeGrid($buttons, 1);
        
        $navRow = LayoutRenderer::makePaginationRow($page, $totalPages, 'def_manage_work_list');
        if (!empty($navRow)) {
            $keyboard[] = $navRow;
        }
        $keyboard[] = [['text' => '🔙 بازگشت به مدیریت', 'callback_data' => 'def_management_menu']];

        $tg->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $keyboard]);
        exit;
    }

    // ب) شروع فرآیند و جادوگر پله‌پله ثبت کار جدید
    elseif ($callbackData === 'def_work_add_init') {
        $tg->answerCallbackQuery($callbackId);
        FSM::setStep($botId, $userId, 'def_wait_work_title');

        $tg->sendMessage($userId, "✍️ <b>شروع فرآیند ثبت اثر جدید به صورت جادوگر داینامیک:</b>\n\nلطفاً ابتدا <b>عنوان اصلی اثر</b> (مانند نام فیلم یا مانهوا) را وارد کنید:", [
            'inline_keyboard' => [[['text' => '❌ انصراف و لغو', 'callback_data' => 'def_manage_work_list_1']]]
        ]);
        exit;
    }

    // ج) مشاهده جزئیات و پنل ادیت تخصصی یک اثر خاص ادمین
    elseif (strpos($callbackData, 'def_work_view_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $mediaId = (int)str_replace('def_work_view_', '', $callbackData);

        // واکشی اطلاعات اصلی کار
        $stmt = $db->prepare("SELECT * FROM manhwas WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $mediaId]);
        $media = $stmt->fetch();

        if ($media) {
            // شخصی‌سازی داینامیک نام دکمه چپتر بر اساس سلیقه ادمین در شخصی‌سازی
            $botContentType = LayoutRenderer::getBotContentType($db, $botId);
            $defaultChLabel = ($botContentType === 'movie') ? 'قسمت‌ها' : 'چپترها';
            $chLabel = LayoutRenderer::getCustomLabel($db, $botId, 'chapter_btn_label', $defaultChLabel);

            $text = "🔎 <b>پنل مدیریتی اثر: «{$media['title']}»</b>\n\n"
                  . "🎭 ژانرها: {$media['genres']}\n"
                  . "🔢 آخرین قسمت ثبت شده: <code>{$media['last_chapter']}</code>\n\n"
                  . "جهت مدیریت فایل‌های چپتر، ویرایش یا حذف کامل اثر از دکمه‌های زیر استفاده کنید:";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => "📂 مدیریت {$chLabel}", 'callback_data' => "def_manage_chapters_list_{$mediaId}_1"],
                        ['text' => '✏️ ادیت جزئیات', 'callback_data' => "def_work_edit_details_{$mediaId}"]
                    ],
                    [
                        ['text' => '📊 آمار بازدید', 'callback_data' => "def_work_stats_{$mediaId}"],
                        ['text' => '🗑️ حذف کامل کار', 'callback_data' => "def_work_delete_confirm_{$mediaId}"]
                    ],
                    [['text' => '🔙 بازگشت به کارها', 'callback_data' => 'def_manage_work_list_1']]
                ]
            ];

            $tg->sendMessage($userId, $text, $keyboard);
        }
        exit;
    }

    // د) تایید و حذف کامل یک کار از آرشیو
    elseif (strpos($callbackData, 'def_work_delete_confirm_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $mediaId = (int)str_replace('def_work_delete_confirm_', '', $callbackData);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🗑️ بله، کاملاً حذف شود', 'callback_data' => "def_work_delete_do_{$mediaId}"],
                    ['text' => '❌ لغو عملیات', 'callback_data' => "def_work_view_{$mediaId}"]
                ]
            ]
        ];

        $tg->sendMessage($userId, "⚠️ <b>آیا مطمئن هستید؟</b>\n\nبا تایید نهایی، این کار به همراه تمام چپترها، فایل‌های متصل، آمارها و لایک‌ها به صورت کامل و غیرقابل بازگشت از دیتابیس ربات حذف خواهد شد.", $keyboard);
        exit;
    }

    // حذف فیزیکی کار از دیتابیس نئون
    elseif (strpos($callbackData, 'def_work_delete_do_') === 0) {
        $mediaId = (int)str_replace('def_work_delete_do_', '', $callbackData);

        $db->beginTransaction();
        try {
            // حذف چپترها
            $stmtCh = $db->prepare("DELETE FROM chapters WHERE bot_id = :bot_id AND manhwa_id = :m_id");
            $stmtCh->execute(['bot_id' => $botId, 'm_id' => $mediaId]);

            // حذف از لایک‌ها و علاقه‌مندی‌ها
            $stmtLikes = $db->prepare("DELETE FROM media_likes WHERE bot_id = :bot_id AND media_id = :m_id");
            $stmtLikes->execute(['bot_id' => $botId, 'm_id' => $mediaId]);

            $stmtFavs = $db->prepare("DELETE FROM user_favorites WHERE bot_id = :bot_id AND media_id = :m_id");
            $stmtFavs->execute(['bot_id' => $botId, 'm_id' => $mediaId]);

            // حذف خود کار
            $stmtWork = $db->prepare("DELETE FROM manhwas WHERE bot_id = :bot_id AND id = :id");
            $stmtWork->execute(['bot_id' => $botId, 'id' => $mediaId]);

            $db->commit();
            $tg->answerCallbackQuery($callbackId, "✅ اثر و فایل‌های آن با موفقیت حذف گردید.", true);
        } catch (Exception $e) {
            $db->rollBack();
            $tg->sendMessage($userId, "❌ خطا در حذف کار از دیتابیس.");
        }

        // رفرش لیست کارها
        $callbackData = 'def_manage_work_list_1';
        require __FILE__;
        exit;
    }
}
