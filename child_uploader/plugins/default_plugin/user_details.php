<?php
/**
 * Project: Arvan Create Bot Maker Platform (Super Uploader Engine)
 * File: child_uploader/plugins/default_plugin/user_details.php
 * Role: Full Dynamic Details Page Renderer & Interaction Processor (Likes & Bookmarks)
 */

// جلوگیری از لود مستقل بدون کانتکست و متغیرهای تعریف شده در هسته ربات
if (!isset($db, $tg, $botId, $userId)) {
    exit;
}

$callbackData = $callbackData ?? ($callbackQuery['data'] ?? '');
$callbackId   = $callbackId ?? ($callbackQuery['id'] ?? null); // مهار لودینگ ساعت تلگرام جهت تایید رویدادها [1]
$messageId    = $messageId ?? ($callbackQuery['message']['message_id'] ?? null);
$chatId       = $chatId ?? ($callbackQuery['message']['chat']['id'] ?? $userId);

// تضمین وجود جدول‌های لایک و علاقه‌مندی در دیتابیس نئون (پایداری سیستم) [1]
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS media_likes (
            bot_id INT NOT NULL,
            user_id BIGINT NOT NULL,
            media_id INT NOT NULL,
            vote INT NOT NULL, -- 1 = Like, -1 = Dislike
            PRIMARY KEY (bot_id, user_id, media_id)
        );
        CREATE TABLE IF NOT EXISTS user_favorites (
            bot_id INT NOT NULL,
            user_id BIGINT NOT NULL,
            media_id INT NOT NULL,
            PRIMARY KEY (bot_id, user_id, media_id)
        );
    ");
} catch (PDOException $e) {
    error_log("Failed to create user details helper tables: " . $e->getMessage());
}

// ==========================================
// بخش اول: پردازش اکشن‌های تعاملی (Likes & Bookmarks Actions)
// ==========================================

// الف) کالبک ثبت لایک (Like Action)
if (strpos($callbackData, 'def_like_') === 0) {
    $mediaId = (int)str_replace('def_like_', '', $callbackData);

    $db->beginTransaction();
    try {
        // ثبت لایک یا به روز رسانی رأی قبلی به حالت لایک (vote = 1)
        $stmt = $db->prepare("
            INSERT INTO media_likes (bot_id, user_id, media_id, vote) 
            VALUES (:bot_id, :u_id, :m_id, 1)
            ON CONFLICT (bot_id, user_id, media_id) 
            DO UPDATE SET vote = CASE WHEN media_likes.vote = 1 THEN 0 ELSE 1 END
        ");
        $stmt->execute(['bot_id' => $botId, 'u_id' => $userId, 'm_id' => $mediaId]);
        $db->commit();
        
        if ($callbackId) {
            $tg->answerCallbackQuery($callbackId, "📥 رأی شما با موفقیت ثبت شد.");
        }
    } catch (Exception $e) {
        $db->rollBack();
        if ($callbackId) {
            $tg->answerCallbackQuery($callbackId, "❌ خطا در ثبت رأی.");
        }
    }

    // رفرش شناسنامه برای نمایش آمارهای جدید
    renderSingleMediaDetails($db, $tg, $botId, $userId, $mediaId, $chatId, $messageId);
    exit;
}

// ب) کالبک ثبت دیس‌لایک (Dislike Action)
elseif (strpos($callbackData, 'def_dislike_') === 0) {
    $mediaId = (int)str_replace('def_dislike_', '', $callbackData);

    $db->beginTransaction();
    try {
        // ثبت دیس‌لایک یا به روز رسانی رأی قبلی به حالت دیس‌لایک (vote = -1)
        $stmt = $db->prepare("
            INSERT INTO media_likes (bot_id, user_id, media_id, vote) 
            VALUES (:bot_id, :u_id, :m_id, -1)
            ON CONFLICT (bot_id, user_id, media_id) 
            DO UPDATE SET vote = CASE WHEN media_likes.vote = -1 THEN 0 ELSE -1 END
        ");
        $stmt->execute(['bot_id' => $botId, 'u_id' => $userId, 'm_id' => $mediaId]);
        $db->commit();
        
        if ($callbackId) {
            $tg->answerCallbackQuery($callbackId, "📥 رأی شما با موفقیت ثبت شد.");
        }
    } catch (Exception $e) {
        $db->rollBack();
        if ($callbackId) {
            $tg->answerCallbackQuery($callbackId, "❌ خطا در ثبت رأی.");
        }
    }

    renderSingleMediaDetails($db, $tg, $botId, $userId, $mediaId, $chatId, $messageId);
    exit;
}

// ------------------------------------------
// بخش دوم: متد رندر پویا شناسنامه اثر بر اساس فیلدهای داینامیک و JSONB مانهواها
// ------------------------------------------

if (!function_exists('renderSingleMediaDetails')) {
    /**
     * رندر متنی و کیبوردی شناسنامه کار بر اساس فیلدهای پویا و کاتالوگ
     */
    function renderSingleMediaDetails($db, $tg, $botId, $userId, $mediaId, $chatId, $messageId = null) {
        // ۱. واکشی اطلاعات اصلی کار از جدول manhwas
        $stmt = $db->prepare("SELECT * FROM manhwas WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmt->execute(['bot_id' => $botId, 'id' => $mediaId]);
        $media = $stmt->fetch();

        if (!$media) {
            $tg->sendMessage($userId, "❌ اثر مورد نظر یافت نشد.");
            return;
        }

        // ۲. پارس کردن داده‌های فیلدهای داینامیک ذخیره شده در ستون JSONB مانهواها [1]
        $customMetadata = !empty($media['custom_metadata']) ? json_decode($media['custom_metadata'], true) : [];

        // ۳. استعلام فیلدهای اطلاعاتی داینامیک که ادمین در بخش «تنظیم اطلاعات» ساخته است
        $stmtFields = $db->prepare("SELECT setting_value FROM bot_plugin_settings WHERE bot_id = :bot_id AND plugin_slug = 'default_plugin' AND setting_key = 'custom_fields_list' LIMIT 1");
        $stmtFields->execute(['bot_id' => $botId]);
        $fieldsData = $stmtFields->fetchColumn();
        $customFields = $fieldsData ? json_decode($fieldsData, true) : [];

        // ساخت بدنه اصلی متنی شناسنامه بر اساس فیلدهای پویا به صورت خودکار [1]
        $dynamicMetaText = "";
        foreach ($customFields as $field) {
            $fieldId = $field['id'];
            $fieldTitle = $field['title'];
            // خواندن مقدار ثبت شده برای این اثر بر اساس آیدی فیلد از ستون جی‌سان [1]
            $value = $customMetadata[$fieldId] ?? 'ثبت نشده';
            $dynamicMetaText .= "├ <b>{$fieldTitle}:</b> <code>{$value}</code>\n";
        }

        // ۴. واکشی آمار لایک‌ها و دیس‌لایک‌های اثر از جدول media_likes
        $stmtLikes = $db->prepare("SELECT COUNT(*) FROM media_likes WHERE bot_id = :bot_id AND media_id = :m_id AND vote = 1");
        $stmtLikes->execute(['bot_id' => $botId, 'm_id' => $mediaId]);
        $likesCount = $stmtLikes->fetchColumn() ?: 0;

        $stmtDislikes = $db->prepare("SELECT COUNT(*) FROM media_likes WHERE bot_id = :bot_id AND media_id = :m_id AND vote = -1");
        $stmtDislikes->execute(['bot_id' => $botId, 'm_id' => $mediaId]);
        $dislikesCount = $stmtDislikes->fetchColumn() ?: 0;

        // ۵. بررسی وضعیت نشان‌گذاری کاربر جاری (Favorites Check)
        $stmtFav = $db->prepare("SELECT 1 FROM user_favorites WHERE bot_id = :bot_id AND user_id = :u_id AND media_id = :m_id LIMIT 1");
        $stmtFav->execute(['bot_id' => $botId, 'u_id' => $userId, 'm_id' => $mediaId]);
        $isFavorited = (bool)$stmtFav->fetch();

        // ۶. تولید متن نهایی شناسنامه با تفکیک نوع مانهوا/فیلم
        $botContentType = LayoutRenderer::getBotContentType($db, $botId);
        $typeLabel = $botContentType === 'movie' ? '🎥 فیلم' : '📚 اثر';

        $caption = "<b>{$typeLabel}: «{$media['title']}»</b>\n\n"
                 . "🎭 <b>ژانرها/کتگوری:</b> {$media['genres']}\n"
                 . "🔢 آخرین قسمت کار شده: <code>{$media['last_chapter']}</code>\n"
                 . "👍 تعداد لایک: <code>{$likesCount}</code> | 👎 دیس‌لایک: <code>{$dislikesCount}</code>\n"
                 . "------------------------------------------\n"
                 . "📋 <b>مشخصات فنی و اطلاعات سیستمی:</b>\n"
                 . $dynamicMetaText
                 . "------------------------------------------\n"
                 . "📝 <b>خلاصه داستان:</b>\n<i>{$media['summary']}</i>";

        // ۷. شخصی‌سازی نام و برچسب دکمه‌های شیشه‌ای توسط ادمین در افزونه دیفالت
        $defaultBtnDl  = $botContentType === 'movie' ? '📥 دریافت کیفیت‌های دانلود' : '📖 نمایش لیست چپترها';
        $btnDownload   = LayoutRenderer::getCustomLabel($db, $botId, 'btn_download_label', $defaultBtnDl);
        
        $btnLikeText   = "👍 پسندیدم ({$likesCount})";
        $btnDislikeTxt = "👎 نپسندیدم ({$dislikesCount})";
        
        $btnFavText    = $isFavorited ? "🗑️ حذف از کتابخانه" : "📚 افزودن به کتابخانه من";

        // ۸. چیدن نهایی منوی دکمه‌های شیشه‌ای
        $flatButtons = [
            ['text' => $btnDownload, 'callback_data' => "def_chapters_list_{$mediaId}_1"],
            ['text' => $btnLikeText, 'callback_data' => "def_like_{$mediaId}"],
            ['text' => $btnDislikeTxt, 'callback_data' => "def_dislike_{$mediaId}"],
            ['text' => $btnFavText, 'callback_data' => "def_fav_add_{$mediaId}"],
            ['text' => '🔙 بازگشت به خانه', 'callback_data' => 'def_home']
        ];

        // چیدن دکمه‌ها در یک گرید ستونی منظم با متد عمومی هسته
        $keyboard = LayoutRenderer::makeGrid($flatButtons, 2);

        // ارسال پیام جدید به همراه کاور یا ویرایش پیام چت متنی قبلی
        if (!empty($media['cover_file_id'])) {
            // حذف پیام قدیمی و ارسال مجدد جهت عدم نمایش لینک نامربوط
            if ($messageId) {
                $tg->deleteMessage($chatId, $messageId);
            }
            $tg->sendPhoto($userId, $media['cover_file_id'], $caption, ['inline_keyboard' => $keyboard]);
        } else {
            if ($messageId) {
                $tg->editMessageText($chatId, $messageId, $caption, ['inline_keyboard' => $keyboard]);
            } else {
                $tg->sendMessage($userId, $caption, ['inline_keyboard' => $keyboard]);
            }
        }
    }
}

// لود نهایی شناسنامه بر اساس سناریوی تایید کالبک جزئیات
if (strpos($callbackData, 'def_view_media_') === 0) {
    $mediaId = (int)str_replace('def_view_media_', '', $callbackData);
    renderSingleMediaDetails($db, $tg, $botId, $userId, $mediaId, $chatId, $messageId);
}
