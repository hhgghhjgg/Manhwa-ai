<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: child/salary_system.php
 * Role: Financial calculations, Payout Processor & Lazy Monthly Reset
 */

// اطمینان از صحت کانتکست لود شده در index.php و child/router.php
if (!isset($botContext) || !isset($tg) || !isset($db)) {
    exit;
}

$botId = $botContext['bot_id'];

// برای پردازش کالبک‌کوئری‌های حقوقی، اطلاعات شناسه و کالبک را استخراج می‌کنیم
$callbackQuery = $botContext['update']['callback_query'] ?? null;

// ==========================================
// فاز ۱: سیستم ریست ماهانه هوشمند و تنبل (Lazy Monthly Reset)
// این سیستم تضمین می‌کند بدون نیاز به کرون‌جاب، در ابتدای هر ماه آمار ماهانه اعضا صفر شود.
// ==========================================
if (!function_exists('lazyMonthlyReset')) {
    function lazyMonthlyReset($db, $botId) {
        $currentMonth = date('Y-m'); // خروجی به شکل: 2026-05

        // دریافت تاریخ آخرین ریست ماهانه از جدول تنظیمات ربات جاری
        $stmt = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = 'last_monthly_reset_date' LIMIT 1");
        $stmt->execute(['bot_id' => $botId]);
        $row = $stmt->fetch();
        $lastResetMonth = $row ? $row['value'] : '';

        // اگر ماه جاری با ماه آخرین ریست متفاوت باشد، یعنی وارد ماه جدید شده‌ایم
        if ($currentMonth !== $lastResetMonth) {
            $db->beginTransaction();
            try {
                // ۱. صفر کردن چپترهای ماهانه تمام اعضا در این ربات
                $stmtReset = $db->prepare("UPDATE users SET monthly_chapters = 0 WHERE bot_id = :bot_id");
                $stmtReset = $stmtReset->execute(['bot_id' => $botId]);

                // ۲. به‌روزرسانی تاریخ آخرین ریست به ماه جاری در دیتابیس نئون
                $stmtUpdateSetting = $db->prepare("
                    INSERT INTO settings (bot_id, key, value) 
                    VALUES (:bot_id, 'last_monthly_reset_date', :value)
                    ON CONFLICT (bot_id, key) DO UPDATE SET value = EXCLUDED.value
                ");
                $stmtUpdateSetting->execute([
                    'bot_id' => $botId,
                    'value'  => $currentMonth
                ]);

                $db->commit();
                error_log("Lazy Monthly Reset successfully executed for Bot ID: {$botId}");
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Error in Lazy Monthly Reset: " . $e->getMessage());
            }
        }
    }
}

// اجرای ریست ماهانه هوشمند و تنبل با لود شدن هاب مالی
lazyMonthlyReset($db, $botId);

// ==========================================
// فاز ۲: توابع تایید یا رد چپترهای ارسالی و پرداخت حقوق
// ==========================================

if (!function_exists('processChapterApproval')) {
    /**
     * تایید نهایی چپتر ارسالی، آپدیت آخرین چپتر مانهوا و واریز دستمزد به کیف پول اعضای متصل به پروژه
     */
    function processChapterApproval($db, $tg, $botId, $chapterId, $adminId) {
        // ۱. واکشی اطلاعات چپتر در انتظار تایید
        $stmtCh = $db->prepare("SELECT * FROM chapters WHERE bot_id = :bot_id AND id = :id AND status = 'pending' LIMIT 1");
        $stmtCh->execute(['bot_id' => $botId, 'id' => $chapterId]);
        $chapter = $stmtCh->fetch();

        if (!$chapter) {
            return "⚠️ این چپتر قبلاً توسط ادمین‌های دیگر رد یا تایید شده است.";
        }

        // ۲. واکشی عنوان و گروه تلگرامی متصل به مانهوای این چپتر
        $stmtM = $db->prepare("SELECT title, group_id FROM manhwas WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmtM->execute(['bot_id' => $botId, 'id' => $chapter['manhwa_id']]);
        $manhwa = $stmtM->fetch();
        $manhwaTitle = $manhwa ? $manhwa['title'] : 'پروژه نامشخص';
        $groupId     = $manhwa ? $manhwa['group_id'] : null;

        $db->beginTransaction();
        try {
            // ۳. آپدیت وضعیت چپتر به تایید شده
            $stmtUpdateStatus = $db->prepare("UPDATE chapters SET status = 'approved' WHERE bot_id = :bot_id AND id = :id");
            $stmtUpdateStatus->execute(['bot_id' => $botId, 'id' => $chapterId]);

            // ۴. به‌روزرسانی شماره آخرین چپتر ثبت شده مانهوا
            $stmtUpdateManhwa = $db->prepare("
                UPDATE manhwas 
                SET last_chapter = GREATEST(last_chapter, :chapter_num) 
                WHERE bot_id = :bot_id AND id = :id
            ");
            $stmtUpdateManhwa->execute([
                'chapter_num' => $chapter['chapter_num'],
                'bot_id'      => $botId,
                'id'          => $chapter['manhwa_id']
            ]);

            // ۵. پرداخت سهم دستمزد اعضای تیم و ارتقای آمارهای کاری آنها در دیتابیس نئون
            $payouts = [
                'translator' => ['id' => $chapter['translator_id'], 'pay' => (float)$chapter['translator_pay'], 'role_text' => 'مترجم'],
                'cleaner'    => ['id' => $chapter['cleaner_id'],    'pay' => (float)$chapter['cleaner_pay'],    'role_text' => 'کلینر'],
                'typesetter' => ['id' => $chapter['typesetter_id'], 'pay' => (float)$chapter['typesetter_pay'], 'role_text' => 'تایپیست']
            ];

            foreach ($payouts as $p) {
                if (!empty($p['id'])) {
                    // واریز حقوق و اعمال چپتر انجام شده به کل آمارها و آمارهای ماه جاری
                    $stmtUserUpdate = $db->prepare("
                        UPDATE users 
                        SET total_chapters = total_chapters + 1, 
                            monthly_chapters = monthly_chapters + 1, 
                            total_earned = total_earned + :pay 
                        WHERE bot_id = :bot_id AND tg_id = :tg_id
                    ");
                    $stmtUserUpdate->execute([
                        'pay'    => $p['pay'],
                        'bot_id' => $botId,
                        'tg_id'  => $p['id']
                    ]);

                    // ارسال اعلان فیش حقوقی به پی‌وی هرکدام از اعضای تیم به طور اختصاصی
                    $notifyText = "🎉 <b>حقوق چپتر جدید واریز شد!</b>\n\n"
                                . "📚 مانهوا: <b>«{$manhwaTitle}»</b>\n"
                                . "🔢 شماره چپتر کار شده: <code>{$chapter['chapter_num']}</code>\n"
                                . "⚔️ سمت شما: {$p['role_text']}\n"
                                . "💰 سهم دستمزد واریز شده: <code>" . number_format($p['pay']) . "</code> تومان\n\n"
                                . "💡 موجودی کیف پول و آمار پروژه‌های شما در ربات به‌روزرسانی شد. خسته نباشید اعضا! 💖";
                    $tg->sendMessage($p['id'], $notifyText);
                }
            }

            $db->commit();

            // ۶. ارسال پیام تبریک تایید چپتر و خسته نباشید به گروه تلگرامی رسمی مانهوا
            if (!empty($groupId)) {
                $groupText = "🔔 <b>اطلاعیه تایید چپتر جدید!</b>\n\n"
                           . "🎉 چپتر <code>{$chapter['chapter_num']}</code> مانهوای <b>«{$manhwaTitle}»</b> توسط مدیریت کل بررسی و تایید نهایی شد و فایل کار شده ثبت گردید.\n\n"
                           . "💸 حقوق اعضای تیم متصل به پروژه محاسبه و واریز شد. خسته نباشید به همه اعضای زحمت‌کش تیم! ✨";
                $tg->sendMessage($groupId, $groupText);
            }

            return true;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Error in processChapterApproval: " . $e->getMessage());
            return "❌ خطای سیستمی در انجام تراکنش دیتابیس نئون.";
        }
    }
}

if (!function_exists('processChapterRejection')) {
    /**
     * رد کردن چپتر ارسالی به دلیل عدم کیفیت یا نیاز به اصلاح
     */
    function processChapterRejection($db, $tg, $botId, $chapterId, $adminId) {
        $stmtCh = $db->prepare("SELECT * FROM chapters WHERE bot_id = :bot_id AND id = :id AND status = 'pending' LIMIT 1");
        $stmtCh->execute(['bot_id' => $botId, 'id' => $chapterId]);
        $chapter = $stmtCh->fetch();

        if (!$chapter) {
            return "⚠️ این چپتر قبلاً رد یا تایید شده است.";
        }

        // واکشی نام مانهوا جهت اطلاع‌رسانی
        $stmtM = $db->prepare("SELECT title FROM manhwas WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmtM->execute(['bot_id' => $botId, 'id' => $chapter['manhwa_id']]);
        $manhwa = $stmtM->fetch();
        $manhwaTitle = $manhwa ? $manhwa['title'] : 'پروژه نامشخص';

        // آپدیت وضعیت چپتر در دیتابیس به رد شده
        $stmtUpdate = $db->prepare("UPDATE chapters SET status = 'rejected' WHERE bot_id = :bot_id AND id = :id");
        $stmtUpdate->execute(['bot_id' => $botId, 'id' => $chapterId]);

        // اطلاع‌رسانی به تایپیست (فرستنده چپتر) جهت بازنگری و ویرایش کار
        if (!empty($chapter['typesetter_id'])) {
            $rejectText = "❌ <b>چپتر شما مورد تایید قرار نگرفت!</b>\n\n"
                        . "📚 مانهوا: <b>«{$manhwaTitle}»</b>\n"
                        . "🔢 شماره چپتر: <code>{$chapter['chapter_num']}</code>\n\n"
                        . "⚠️ فایل چپتر فرستاده شده توسط ادمین‌های مانهوا رد شد. لطفاً اصلاحات و ویرایش‌های لازم را انجام داده و کار اصلاح‌شده را مجدداً از داخل گروه ارسال فرمایید.";
            $tg->sendMessage($chapter['typesetter_id'], $rejectText);
        }

        return true;
    }
}

// ==========================================
// فاز ۳: پردازش و اینترسپت مستقیم کالبک‌کوئری‌های حقوقی
// از آنجا که ادمین دکمه تایید یا رد را در پی‌وی خود فشار می‌دهد، این بخش بلافاصله کدهای کلیک را اجرا می‌کند.
// ==========================================
if ($callbackQuery) {
    $callbackData = $callbackQuery['data'];
    $callbackId   = $callbackQuery['id'];
    $messageId    = $callbackQuery['message']['message_id'];
    $adminChatId  = $callbackQuery['message']['chat']['id'];

    // الف) کلیک ادمین روی دکمه تایید چپتر و پرداخت دستمزد
    if (strpos($callbackData, 'admin_approve_ch_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $chapterId = (int)str_replace('admin_approve_ch_', '', $callbackData);

        $result = processChapterApproval($db, $tg, $botId, $chapterId, $adminChatId);

        if ($result === true) {
            $tg->editMessageText($adminChatId, $messageId, "✅ <b>چپتر با موفقیت تایید شد!</b>\n\nمبالغ دستمزد به کیف پول اعضا واریز گردید و آمار مانهوا به‌روزرسانی شد.");
        } else {
            $tg->sendMessage($adminChatId, $result);
        }
        exit;
    }

    // ب) کلیک ادمین روی دکمه رد چپتر
    elseif (strpos($callbackData, 'admin_reject_ch_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $chapterId = (int)str_replace('admin_reject_ch_', '', $callbackData);

        $result = processChapterRejection($db, $tg, $botId, $chapterId, $adminChatId);

        if ($result === true) {
            $tg->editMessageText($adminChatId, $messageId, "❌ <b>چپتر توسط شما رد شد.</b>\n\nکاربر فرستنده چپتر با فیش اعلان مطلع شد تا اصلاحات لازم را انجام دهد.");
        } else {
            $tg->sendMessage($adminChatId, $result);
        }
        exit;
    }
}
