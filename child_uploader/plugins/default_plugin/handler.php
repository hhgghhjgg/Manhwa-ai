<?php
/**
 * Project: Arvan Create Bot Maker Platform (Super Uploader Engine)
 * File: child_uploader/plugins/default_plugin/handler.php
 * Role: Core Default Plugin User Dispatcher & Dynamic UI Handler
 */

// جلوگیری از لود مستقل بدون کانتکست و متغیرهای تعریف شده در هسته ربات
if (!isset($db, $tg, $botId, $userId)) {
    exit;
}

$userStep     = $user['step'] ?? 'idle';
$pluginAction = $pluginAction ?? 'callback_query'; // رفتار پیش‌فرض کالبک‌کوئری است
$callbackData = $callbackData ?? ($callbackQuery['data'] ?? '');
$messageId    = $messageId ?? ($callbackQuery['message']['message_id'] ?? null);
$chatId       = $chatId ?? ($callbackQuery['message']['chat']['id'] ?? $userId);

// تفکیک متدهای کمکی جهت واکشی اطلاعات فارسی نوع فعالیت
if (!function_exists('getContentTypeFarsiName')) {
    function getContentTypeFarsiName($type) {
        $map = [
            'manhwa'      => '📚 مانهوا، مانگا و یائویی',
            'movie'       => '🎬 فیلم و سریال',
            'anime'       => ' انیمه',
            'book'        => '📖 کتاب',
            'music'       => '🎵 موزیک',
            'educational' => '🎓 آموزشی',
            'app'         => '📱 برنامه',
            'source_code' => '💻 سورس کد',
            'graphic'     => '🎨 فایل گرافیک',
            'game'        => '🎮 بازی',
            'wallpaper'   => '🖼 والپیپر',
            'porn'        => '🔞 پورن',
            'hentai'      => ' هنتای'
        ];
        return $map[$type] ?? $type;
    }
}

// ==========================================
// فاز ۱: رندر کردن منوی اصلی و استارت ربات (Action: render_start_menu)
// ==========================================
if ($pluginAction === 'render_start_menu') {
    // ۱. رندر اطلاعات خوش‌آمدگویی و منو از رندرر پویا
    $mainLayout = LayoutRenderer::renderMainMenu($db, $botId);
    
    // ۲. استخراج لیست‌های شیشه‌ای فعال ساخته شده توسط ادمین در ابزار «لیست‌ساز»
    $extraButtons = [];
    try {
        $stmtLists = $db->prepare("
            SELECT id, title 
            FROM curated_lists 
            WHERE bot_id = :bot_id 
            ORDER BY id ASC LIMIT 5
        ");
        $stmtLists->execute(['bot_id' => $botId]);
        $customLists = $stmtLists->fetchAll();

        foreach ($customLists as $lst) {
            $extraButtons[] = ['text' => "🔥 " . $lst['title'], 'callback_data' => "def_list_view_{$lst['id']}_1"];
        }
    } catch (PDOException $e) {
        error_log("Lists seeding query bypassed: " . $e->getMessage());
    }

    // ادغام لیست‌های ادمین با کیبورد اصلی ربات به صورت منظم
    if (!empty($extraButtons)) {
        $gridExtra = LayoutRenderer::makeGrid($extraButtons, 2);
        foreach ($gridExtra as $row) {
            $mainLayout['keyboard']['inline_keyboard'][] = $row;
        }
    }

    // اضافه کردن بقیه منوهای فعال بازارچه (مانند تیکت یا عضویت) در ردیف آخر
    $otherButtons = [];
    if (PluginLoader::isPluginActive($db, $botId, 'ticket_system')) {
        $otherButtons[] = ['text' => '✉️ ثبت تیکت پشتیبانی', 'callback_data' => 'ticket_system_init'];
    }
    if (PluginLoader::isPluginActive($db, $botId, 'practice_exams')) {
        $otherButtons[] = ['text' => '🏆 آزمون‌های تمرینی', 'callback_data' => 'practice_exams_init'];
    }
    
    if (!empty($otherButtons)) {
        $mainLayout['keyboard']['inline_keyboard'][] = $otherButtons;
    }

    $tg->sendMessage($userId, $mainLayout['text'], $mainLayout['keyboard']);
    exit;
}

// ==========================================
// فاز ۲: موتور جستجوی ساده و پیشرفته دیتابیس (Action: search_query)
// ==========================================
elseif ($pluginAction === 'search_query') {
    $searchKey = trim($text);

    if (empty($searchKey) || mb_strlen($searchKey) < 2) {
        $tg->sendMessage($userId, "❌ لطفاً حداقل ۲ کاراکتر برای جستجو وارد کنید.");
        exit;
    }

    // واکشی کارهای منطبق بر سرچ از جدول manhwas (آرشیو آثار)
    $stmt = $db->prepare("
        SELECT id, title 
        FROM manhwas 
        WHERE bot_id = :bot_id 
          AND (title ILIKE :q OR summary ILIKE :q OR genres ILIKE :q) 
        ORDER BY id DESC LIMIT 10
    ");
    $stmt->execute(['bot_id' => $botId, 'q' => "%{$searchKey}%"]);
    $results = $stmt->fetchAll();

    if (empty($results)) {
        $tg->sendMessage($userId, "🔍 اثری با عنوان یا کلمه <b>«{$searchKey}»</b> در آرشیو ربات یافت نشد.", [
            'inline_keyboard' => [[['text' => '🔙 بازگشت به خانه', 'callback_data' => 'def_home']]]
        ]);
        exit;
    }

    $buttons = [];
    foreach ($results as $res) {
        $buttons[] = ['text' => "📚 " . $res['title'], 'callback_data' => "def_view_media_{$res['id']}"];
    }

    $keyboard = LayoutRenderer::makeGrid($buttons, 1);
    $keyboard[] = [['text' => '🔙 بازگشت به خانه', 'callback_data' => 'def_home']];

    $tg->sendMessage($userId, "🔍 <b>نتایج جستجو برای کلمه «{$searchKey}»:</b>\n\nبرای مشاهده شناسنامه اثر، روی گزینه مورد نظر بزنید:", [
        'inline_keyboard' => $keyboard
    ]);
    exit;
}

// ==========================================
// فاز ۳: پردازش کالبک‌کوئری‌های کاربر عادی (Action: callback_query)
// ==========================================
elseif ($pluginAction === 'callback_query') {

    // الف) کالبک دکمه شروع جستجوی معمولی
    if ($callbackData === 'def_search_init') {
        $tg->answerCallbackQuery($callbackId);
        FSM::setStep($botId, $userId, 'def_searching');

        // چک کردن متون شخصی‌سازی‌شده ادمین
        $defaultPrompt = "✍️ <b>لطفاً نام اثر، نام نویسنده یا ژانر مورد نظر خود را بنویسید و بفرستید:</b>";
        $searchPrompt  = LayoutRenderer::getCustomLabel($db, $botId, 'btn_search_prompt_text', $defaultPrompt);

        $tg->sendMessage($userId, $searchPrompt, [
            'inline_keyboard' => [[['text' => '❌ انصراف و بازگشت', 'callback_data' => 'def_home']]]
        ]);
        exit;
    }

    // ب) کالبک دکمه بازگشت به خانه
    elseif ($callbackData === 'def_home') {
        $tg->answerCallbackQuery($callbackId);
        FSM::clearStep($botId, $userId);

        $mainLayout = LayoutRenderer::renderMainMenu($db, $botId);
        
        // واکشی لیست‌های فعال
        $extraButtons = [];
        try {
            $stmtLists = $db->prepare("SELECT id, title FROM curated_lists WHERE bot_id = :bot_id ORDER BY id ASC LIMIT 5");
            $stmtLists->execute(['bot_id' => $botId]);
            $customLists = $stmtLists->fetchAll();
            foreach ($customLists as $lst) {
                $extraButtons[] = ['text' => "🔥 " . $lst['title'], 'callback_data' => "def_list_view_{$lst['id']}_1"];
            }
        } catch (PDOException $e) {}

        if (!empty($extraButtons)) {
            $gridExtra = LayoutRenderer::makeGrid($extraButtons, 2);
            foreach ($gridExtra as $row) {
                $mainLayout['keyboard']['inline_keyboard'][] = $row;
            }
        }

        $tg->editMessageText($chatId, $messageId, $mainLayout['text'], $mainLayout['keyboard']);
        exit;
    }

    // ج) کالبک مشاهده شناسنامه اثر و جزئیات (Details view)
    elseif (strpos($callbackData, 'def_view_media_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $mediaId = (int)str_replace('def_view_media_', '', $callbackData);

        // واکشی مشخصات مانهوا/فیلم
        $stmt = $db->prepare("SELECT * FROM manhwas WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $mediaId]);
        $media = $stmt->fetch();

        if ($media) {
            $detailsLayout = LayoutRenderer::renderDetailsPage($db, $botId, $media);

            // در صورت وجود عکس کاور به شکل sendPhoto فرستاده می‌شود، در غیر این صورت پیام متنی
            if (!empty($detailsLayout['image'])) {
                $tg->deleteMessage($chatId, $messageId);
                $tg->sendPhoto($userId, $detailsLayout['image'], $detailsLayout['text'], $detailsLayout['keyboard']);
            } else {
                $tg->editMessageText($chatId, $messageId, $detailsLayout['text'], $detailsLayout['keyboard']);
            }
        } else {
            $tg->sendMessage($userId, "❌ اثر مورد نظر یافت نشد.");
        }
        exit;
    }

    // د) کالبک نمایش لیست چپترها/کیفیت‌ها (Chapters list paginated)
    elseif (strpos($callbackData, 'def_chapters_list_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        
        $params = str_replace('def_chapters_list_', '', $callbackData);
        $parts = explode('_', $params);
        $mediaId = (int)$parts[0];
        $page    = isset($parts[1]) ? (int)$parts[1] : 1;
        $limit   = 10;
        $offset  = ($page - 1) * $limit;

        // واکشی مشخصات مانهوا
        $stmtM = $db->prepare("SELECT title FROM manhwas WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmtM->execute(['bot_id' => $botId, 'id' => $mediaId]);
        $mTitle = $stmtM->fetchColumn() ?? 'پروژه';

        // محاسبه کل صفحات چپترها
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM chapters WHERE bot_id = :bot_id AND manhwa_id = :m_id AND status = 'approved'");
        $stmtCount->execute(['bot_id' => $botId, 'm_id' => $mediaId]);
        $totalCh = $stmtCount->fetchColumn();
        $totalPages = ceil($totalCh / $limit);

        // واکشی چپترها
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

        $text = "📖 <b>لیست قسمت‌های آماده مانهوای «{$mTitle}» (صفحه {$page} از {$totalPages}):</b>\n\nبرای دریافت مستقیم فایل روی قسمت مورد نظر کلیک کنید:";
        $buttons = [];

        foreach ($chapters as $ch) {
            $buttons[] = ['text' => "📖 چپتر " . $ch['chapter_num'], 'callback_data' => "def_get_file_{$ch['id']}"];
        }

        $keyboard = LayoutRenderer::makeGrid($buttons, 2);
        
        // ردیف ناوبری صفحات چپترها
        $navRow = LayoutRenderer::makePaginationRow($page, $totalPages, "def_chapters_list_{$mediaId}");
        if (!empty($navRow)) {
            $keyboard[] = $navRow;
        }

        $keyboard[] = [['text' => '🔙 بازگشت به شناسنامه اثر', 'callback_data' => "def_view_media_{$mediaId}"]];

        $tg->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $keyboard]);
        exit;
    }

    // ه) کالبک دریافت و آپلود مستقیم فایل نهایی (File Payout)
    elseif (strpos($callbackData, 'def_get_file_') === 0) {
        $chapterId = (int)str_replace('def_get_file_', '', $callbackData);

        // واکشی مشخصات فایل چپتر
        $stmt = $db->prepare("
            SELECT c.*, m.title 
            FROM chapters c 
            JOIN manhwas m ON c.manhwa_id = m.id 
            WHERE c.bot_id = :bot_id AND c.id = :id AND c.status = 'approved' 
            LIMIT 1
        ");
        $stmt->execute(['bot_id' => $botId, 'id' => $chapterId]);
        $chapter = $stmt->fetch();

        if ($chapter) {
            // ۱. فیلتر امنیتی پرداخت اشتراک ویژه (VIP Interceptor)
            $isVipInstalled = PluginLoader::isPluginActive($db, $botId, 'vip_subscription');
            if ($isVipInstalled) {
                // بررسی اینکه آیا کاربر اشتراک ویژه دارد یا خیر
                $stmtVip = $db->prepare("SELECT setting_value FROM bot_plugin_settings WHERE bot_id = :bot_id AND plugin_slug = 'vip_subscription' AND setting_key = 'vip_users' LIMIT 1");
                $stmtVip->execute(['bot_id' => $botId]);
                $vipData = $stmtVip->fetchColumn();
                $vipArray = $vipData ? json_decode($vipData, true) : [];

                if (!in_array($userId, $vipArray)) {
                    // ممانعت از دانلود و فرستادن پیام خرید اشتراک
                    $tg->answerCallbackQuery($callbackId);
                    $tg->sendMessage($userId, "🔒 <b>محدودیت دسترسی!</b>\n\nاین چپتر مخصوص اعضای VIP ربات است. برای مطالعه بدون محدودیت کل آثار، لطفا اشتراک تهیه فرمایید:", [
                        'inline_keyboard' => [
                            [['text' => '💎 خرید اشتراک ویژه', 'callback_data' => 'vip_buy_menu']],
                            [['text' => '🔙 بازگشت', 'callback_data' => "def_view_media_{$chapter['manhwa_id']}"]]
                        ]
                    ]);
                    exit;
                }
            }

            // ۲. ارسال مستقیم سند به چت کاربر
            $tg->answerCallbackQuery($callbackId, "📥 در حال آماده‌سازی و ارسال فایل...");
            $caption = "📥 <b>چپتر {$chapter['chapter_num']} مانهوای «{$chapter['title']}» خدمت شما.</b>\n\n🌟 مطالعه خوبی داشته باشید!";
            $tg->sendDocument($userId, $chapter['file_id'], $caption);
        } else {
            $tg->answerCallbackQuery($callbackId, "❌ فایل چپتر یافت نشد یا هنوز تایید نشده است.", true);
        }
        exit;
    }

    // و) کالبک سیستم علاقه‌مندی‌ها (Favorites Bookmarking)
    elseif (strpos($callbackData, 'def_fav_add_') === 0) {
        $mediaId = (int)str_replace('def_fav_add_', '', $callbackData);

        // ساخت خودکار جدول در دیتابیس نئون در صورت عدم وجود (تضمین کارکرد) [1]
        $db->exec("
            CREATE TABLE IF NOT EXISTS user_favorites (
                bot_id INT NOT NULL,
                user_id BIGINT NOT NULL,
                media_id INT NOT NULL,
                PRIMARY KEY (bot_id, user_id, media_id)
            );
        ");

        // بررسی اینکه آیا قبلا اد شده است یا خیر
        $stmtCheck = $db->prepare("SELECT 1 FROM user_favorites WHERE bot_id = :bot_id AND user_id = :u_id AND media_id = :m_id LIMIT 1");
        $stmtCheck->execute(['bot_id' => $botId, 'u_id' => $userId, 'm_id' => $mediaId]);
        $exists = (bool)$stmtCheck->fetch();

        if ($exists) {
            // حذف از علاقه‌مندی‌ها
            $stmtDel = $db->prepare("DELETE FROM user_favorites WHERE bot_id = :bot_id AND user_id = :u_id AND media_id = :m_id");
            $stmtDel->execute(['bot_id' => $botId, 'u_id' => $userId, 'm_id' => $mediaId]);
            $tg->answerCallbackQuery($callbackId, "❌ از کتابخانه شخصی شما حذف شد.", true);
        } else {
            // اضافه کردن به علاقه‌مندی‌ها
            $stmtIns = $db->prepare("INSERT INTO user_favorites (bot_id, user_id, media_id) VALUES (:bot_id, :u_id, :m_id) ON CONFLICT DO NOTHING");
            $stmtIns->execute(['bot_id' => $botId, 'u_id' => $userId, 'm_id' => $mediaId]);
            $tg->answerCallbackQuery($callbackId, "✅ با موفقیت به کتابخانه شما اضافه شد!", true);
        }

        // لود مجدد صفحه جزئیات جهت رفرش وضعیت دکمه نشان‌شده
        $callbackQuery['data'] = "def_view_media_{$mediaId}";
        $pluginAction = 'callback_query';
        require __FILE__;
        exit;
    }

    // ز) کالبک مشاهده کتابخانه علاقه‌مندی‌ها (Favorites list pager)
    elseif (strpos($callbackData, 'def_favorites_list_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $page = (int)str_replace('def_favorites_list_', '', $callbackData);
        $limit = 10;
        $offset = ($page - 1) * $limit;

        // استعلام مجموع علاقه‌مندی‌ها
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM user_favorites WHERE bot_id = :bot_id AND user_id = :u_id");
        $stmtCount->execute(['bot_id' => $botId, 'u_id' => $userId]);
        $totalFavs = $stmtCount->fetchColumn();
        $totalPages = ceil($totalFavs / $limit);

        if ($totalFavs == 0) {
            $textEmpty = "⚠️ <b>کتابخانه شخصی شما خالی است!</b>\n\nبرای اضافه کردن فیلم یا مانهوا به کتابخانه شخصی خود، کافیست در صفحه شناسنامه اثر دکمه «📚 افزودن به کتابخانه» را کلیک کنید.";
            $tg->editMessageText($chatId, $messageId, $textEmpty, ['inline_keyboard' => [[['text' => '🔙 بازگشت به خانه', 'callback_data' => 'def_home']]]]);
            exit;
        }

        // واکشی پروژه‌ها
        $stmtFavs = $db->prepare("
            SELECT m.id, m.title 
            FROM user_favorites uf 
            JOIN manhwas m ON uf.media_id = m.id 
            WHERE uf.bot_id = :bot_id AND uf.user_id = :u_id 
            ORDER BY m.id DESC LIMIT :limit OFFSET :offset
        ");
        $stmtFavs->bindValue(':bot_id', $botId, PDO::PARAM_INT);
        $stmtFavs->bindValue(':u_id', $userId, PDO::PARAM_INT);
        $stmtFavs->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmtFavs->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmtFavs->execute();
        $favs = $stmtFavs->fetchAll();

        $text = "📚 <b>کتابخانه شخصی و کارهای نشان‌شده شما (صفحه {$page} از {$totalPages}):</b>\n\nجهت مشاهده جزئیات روی اثر مورد نظر بزنید:";
        $buttons = [];
        foreach ($favs as $f) {
            $buttons[] = ['text' => "📚 " . $f['title'], 'callback_data' => "def_view_media_{$f['id']}"];
        }

        $keyboard = LayoutRenderer::makeGrid($buttons, 1);
        $navRow = LayoutRenderer::makePaginationRow($page, $totalPages, 'def_favorites_list');
        if (!empty($navRow)) {
            $keyboard[] = $navRow;
        }
        $keyboard[] = [['text' => '🔙 بازگشت به خانه', 'callback_data' => 'def_home']];

        $tg->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $keyboard]);
        exit;
    }

    // ح) کالبک مشاهده پروفایل کاربر نهایی
    elseif ($callbackData === 'def_profile_view') {
        $tg->answerCallbackQuery($callbackId);

        // واکشی تعداد کارهای نشان‌شده کاربر
        $stmtFavCount = $db->prepare("SELECT COUNT(*) FROM user_favorites WHERE bot_id = :bot_id AND user_id = :u_id");
        $stmtFavCount->execute(['bot_id' => $botId, 'u_id' => $userId]);
        $favCount = $stmtFavCount->fetchColumn();

        $textProfile = "👤 <b>پروفایل کاربری شما در ربات:</b>\n\n"
                     . "🆔 شناسه عددی شما: <code>{$userId}</code>\n"
                     . "⚔️ نقش شما: <b>مهمان (خواننده ربات)</b>\n"
                     . "⭐ مانهواهای نشان‌شده شما: <code>{$favCount}</code> عدد\n\n"
                     . "💡 برای مدیریت آثار نشان‌شده خود، از دکمه «کتابخانه شخصی» در منوی اصلی استفاده کنید.";

        $tg->editMessageText($chatId, $messageId, $textProfile, ['inline_keyboard' => [[['text' => '🔙 بازگشت به خانه', 'callback_data' => 'def_home']]]]);
        exit;
    }

    // ط) کالبک مشاهده لیست‌های پویای ساخته شده توسط ادمین در ابزار «لیست‌ساز»
    elseif (strpos($callbackData, 'def_list_view_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        
        $params = str_replace('def_list_view_', '', $callbackData);
        $parts = explode('_', $params);
        $listId = (int)$parts[0];
        $page   = isset($parts[1]) ? (int)$parts[1] : 1;
        $limit  = 10;
        $offset = ($page - 1) * $limit;

        // استعلام مشخصات لیست از دیتابیس
        $stmtListInfo = $db->prepare("SELECT title, category_id, time_filter FROM curated_lists WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmtListInfo->execute(['bot_id' => $botId, 'id' => $listId]);
        $listInfo = $stmtListInfo->fetch();

        if ($listInfo) {
            // ساخت کوئری فیلتردار لیست بر اساس کتگوری و زمان به صورت پویا
            $sql = "SELECT id, title FROM manhwas WHERE bot_id = :bot_id ";
            $paramsQuery = ['bot_id' => $botId];

            if ($listInfo['category_id'] !== null) {
                // اگر ادمین کتگوری خاصی مشخص کرده باشد، در custom_metadata جستجو می‌کنیم (از طریق جی‌سان) [1]
                $sql .= " AND custom_metadata->>'category_id' = :cat_id ";
                $paramsQuery['cat_id'] = (string)$listInfo['category_id'];
            }

            // فیلتر زمانی
            if ($listInfo['time_filter'] === 'week') {
                $sql .= " AND created_at >= CURRENT_TIMESTAMP - INTERVAL '7 day' ";
            } elseif ($listInfo['time_filter'] === 'month') {
                $sql .= " AND created_at >= CURRENT_TIMESTAMP - INTERVAL '30 day' ";
            }

            $sqlCount = str_replace("SELECT id, title", "SELECT COUNT(*)", $sql);
            
            // محاسبه تعداد کارها
            $stmtC = $db->prepare($sqlCount);
            $stmtC->execute($paramsQuery);
            $totalInList = $stmtC->fetchColumn();
            $totalPages = ceil($totalInList / $limit);

            $sql .= " ORDER BY id DESC LIMIT :limit OFFSET :offset ";
            $stmtData = $db->prepare($sql);
            
            foreach ($paramsQuery as $k => $v) {
                $stmtData->bindValue($k, $v);
            }
            $stmtData->bindValue('limit', $limit, PDO::PARAM_INT);
            $stmtData->bindValue('offset', $offset, PDO::PARAM_INT);
            $stmtData->execute();
            $items = $stmtData->fetchAll();

            $textTitle = "🔥 <b>لیست: «{$listInfo['title']}» (صفحه {$page} از {$totalPages}):</b>\n\nبرای دیدن شناسنامه و دانلود اثر، روی گزینه مورد نظر کلیک کنید:";
            $buttons = [];
            foreach ($items as $item) {
                $buttons[] = ['text' => "📚 " . $item['title'], 'callback_data' => "def_view_media_{$item['id']}"];
            }

            $keyboard = LayoutRenderer::makeGrid($buttons, 1);
            $navRow = LayoutRenderer::makePaginationRow($page, $totalPages, "def_list_view_{$listId}");
            if (!empty($navRow)) {
                $keyboard[] = $navRow;
            }
            $keyboard[] = [['text' => '🔙 بازگشت به خانه', 'callback_data' => 'def_home']];

            $tg->editMessageText($chatId, $messageId, $textTitle, ['inline_keyboard' => $keyboard]);
        }
        exit;
    }
}
