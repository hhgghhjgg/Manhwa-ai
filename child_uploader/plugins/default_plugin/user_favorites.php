<?php
/**
 * Project: Arvan Create Bot Maker Platform (Super Uploader Engine)
 * File: child_uploader/plugins/default_plugin/user_favorites.php
 * Role: Full User Favorites & Bookmarks Library Sheet Renderer (Paginated)
 */

// جلوگیری از لود مستقل بدون کانتکست و متغیرهای تعریف شده در هسته ربات
if (!isset($db, $tg, $botId, $userId)) {
    exit;
}

$callbackData = $callbackData ?? ($callbackQuery['data'] ?? '');
$messageId    = $messageId ?? ($callbackQuery['message']['message_id'] ?? null);
$chatId       = $chatId ?? ($callbackQuery['message']['chat']['id'] ?? $userId);

// ۱. پارس کردن شماره صفحه جاری از دیتای کالبک (مثال: def_favorites_list_2)
$page = (int)str_replace('def_favorites_list_', '', $callbackData);
if ($page <= 0) {
    $page = 1;
}

$limit = 10;
$offset = ($page - 1) * $limit;

try {
    // ۲. استعلام مجموع کل کارهای نشان‌شده کاربر جاری در این ربات
    $stmtCount = $db->prepare("
        SELECT COUNT(*) 
        FROM user_favorites 
        WHERE bot_id = :bot_id AND user_id = :u_id
    ");
    $stmtCount->execute(['bot_id' => $botId, 'u_id' => $userId]);
    $totalFavs = $stmtCount->fetchColumn() ?: 0;
    $totalPages = ceil($totalFavs / $limit);

    // ۳. سناریوی الف: کتابخانه کاربر کاملاً خالی است (Empty State UX)
    if ($totalFavs == 0) {
        $textEmpty = "⚠️ <b>کتابخانه شخصی شما در حال حاضر خالی است!</b>\n\n"
                   . "شما هنوز هیچ مانهوا، انیمه یا فیلمی را به لیست نشان‌شده‌های خود اضافه نکرده‌اید.\n\n"
                   . "💡 <b>راهنما:</b> برای افزودن کارها به این بخش، کافیست وارد شناسنامه اثر شده و دکمه 📚 «افزودن به کتابخانه من» را لمس کنید تا در این منو برای دسترسی سریع‌تر ذخیره شوند.";

        $keyboardEmpty = [
            'inline_keyboard' => [
                [['text' => '🔙 بازگشت به منو اصلی', 'callback_data' => 'def_home']]
            ]
        ];

        $tg->editMessageText($chatId, $messageId, $textEmpty, $keyboardEmpty);
        exit;
    }

    // ۴. سناریوی ب: واکشی داده‌های صفحه جاری و الحاق به دکمه‌های شیشه‌ای منظم
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

    $text = "📚 <b>کتابخانه شخصی و آثار نشان‌شده شما (صفحه {$page} از {$totalPages}):</b>\n\n"
          . "برای مشاهده شناسنامه، جزئیات و دانلود مستقیم هر اثر روی آن کلیک کنید:";
          
    $buttons = [];
    foreach ($favs as $f) {
        $buttons[] = ['text' => "📚 " . $f['title'], 'callback_data' => "def_view_media_{$f['id']}"];
    }

    // چیدن دکمه‌ها در یک ستون تمام‌عرض (گرید تک‌ستونه) جهت آراستگی ظاهری
    $keyboard = LayoutRenderer::makeGrid($buttons, 1);

    // ۵. ساخت ردیف ناوبری صفحات با متد عمومی رندرر هسته
    $navRow = LayoutRenderer::makePaginationRow($page, $totalPages, 'def_favorites_list');
    if (!empty($navRow)) {
        $keyboard[] = $navRow;
    }

    $keyboard[] = [['text' => '🔙 بازگشت به خانه', 'callback_data' => 'def_home']];

    // ویرایش پیام شیشه‌ای ادمین یا کاربر تلگرام
    $tg->editMessageText($chatId, $messageId, $text, ['inline_keyboard' => $keyboard]);

} catch (PDOException $e) {
    error_log("Error in user_favorites.php: " . $e->getMessage());
    $tg->sendMessage($userId, "❌ خطای سیستمی در واکشی اطلاعات کتابخانه شخصی.");
}
exit;
