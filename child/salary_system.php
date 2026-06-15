<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: child/salary_system.php
 * Role: Financial calculations, Payout Processor, Lazy Monthly Reset & Activity Monitor
 */

// اطمینان از صحت کانتکست لود شده
if (!isset($botContext, $tg, $db)) {
    exit;
}

$botId = $botContext['bot_id'];

// استخراج اطلاعات کالبک‌کوئری جهت اینترسپت مستقیم دکمه‌های تایید و رد چپتر
$callbackQuery = $botContext['update']['callback_query'] ?? null;

// ==========================================
// ۱. سیستم ریست ماهانه هوشمند و تنبل (Lazy Monthly Reset)
// ==========================================
if (!function_exists('lazyMonthlyReset')) {
    /**
     * بررسی خودکار تاریخ و صفر کردن آمارهای ماهانه اعضا در صورت ورود به ماه جدید
     */
    function lazyMonthlyReset($db, $botId) {
        $currentMonth = date('Y-m'); // به صورت فرمت YYYY-MM

        // استعلام تاریخ آخرین ریست ثبت شده در تنظیمات
        $stmt = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = 'last_monthly_reset_date' LIMIT 1");
        $stmt->execute(['bot_id' => $botId]);
        $row = $stmt->fetch();
        $lastResetMonth = $row ? $row['value'] : '';

        // در صورت عدم تطابق تاریخ، فرآیند صفر کردن آمارهای ماه جاری اجرا می‌شود
        if ($currentMonth !== $lastResetMonth) {
            $db->beginTransaction();
            try {
                // صفر کردن فیلد آمارهای ماه جاری برای تمامی کاربران تایید شده این ربات
                $stmtReset = $db->prepare("UPDATE users SET monthly_chapters = 0 WHERE bot_id = :bot_id");
                $stmtReset->execute(['bot_id' => $botId]);

                // ذخیره تاریخ جدید به عنوان آخرین ریست موفق
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

// ==========================================
// ۲. سیستم پایش هوشمند مانهواهای راکد (Lazy Activity Monitoring)
// ==========================================
if (!function_exists('checkInactiveManhwas')) {
    /**
     * پایش مانهواهای ثبت شده و ارسال هشدار خودکار به گروه‌ها در صورت راکد ماندن پروژه
     */
    function checkInactiveManhwas($db, $tg, $botId) {
        // واکشی مانهواهایی که از آخرین فعالیت کاربری آن‌ها بیش از روزهای مجاز گذشته است
        // تغییر فیلتر جدید: اگر وضعیت مانهوا روی درآپ (dropped) یا پایان فصل (season_end) باشد، از این چرخه پایش خارج است
        $stmtInactive = $db->prepare("
            SELECT m.id, m.title, m.group_id, m.last_active_at,
                   EXTRACT(DAY FROM (CURRENT_TIMESTAMP - m.last_active_at)) as inactive_days
            FROM manhwas m
            WHERE m.bot_id = :bot_id 
              AND m.group_id IS NOT NULL
              AND (m.status IS NULL OR m.status NOT IN ('dropped', 'season_end'))
              AND m.last_active_at < CURRENT_TIMESTAMP - (COALESCE(
                  (SELECT value FROM settings WHERE bot_id = :bot_id AND key = 'inactivity_warning_days' LIMIT 1), '7'
              )::integer * INTERVAL '1 day')
              AND (m.last_warning_sent_at IS NULL OR m.last_warning_sent_at < CURRENT_TIMESTAMP - INTERVAL '1 day')
        ");
        $stmtInactive->execute(['bot_id' => $botId]);
        $inactiveManhwas = $stmtInactive->fetchAll();

        if (empty($inactiveManhwas)) {
            return;
        }

        // واکشی لیست ادمین‌های مسئول هشدارهای انضباطی (perm_warn_user) و مالک جهت منشن
        $stmtAdmins = $db->prepare("
            SELECT u.tg_id, u.full_name 
            FROM users u
            LEFT JOIN admin_permissions ap ON u.bot_id = ap.bot_id AND u.tg_id = ap.user_id
            WHERE u.bot_id = :bot_id 
              AND u.status = 'approved'
              AND (
                  u.role = 'owner' 
                  OR (u.role = 'admin' AND ap.perm_warn_user = TRUE)
              )
        ");
        $stmtAdmins->execute(['bot_id' => $botId]);
        $admins = $stmtAdmins->fetchAll();

        // ایجاد منشن‌های HTML با استفاده از آیدی عددی
        $mentions = "";
        foreach ($admins as $ad) {
            $mentions .= " <a href='tg://user?id={$ad['tg_id']}'>{$ad['full_name']}</a>";
        }

        foreach ($inactiveManhwas as $manhwa) {
            $warningText = "🚨 <b>هشدار راکد ماندن پروژه مانهوا!</b>\n\n"
                         . "📚 مانهوا: <b>«{$manhwa['title']}»</b>\n"
                         . "⏳ مدت زمان بدون ثبت چپتر جدید: <code>" . (int)$manhwa['inactive_days'] . "</code> روز\n\n"
                         . "⚠️ تیم متصل به این مانهوا در این مدت هیچ پیشرفتی در گروه ثبت نکرده‌اند!\n\n"
                         . "📢 توجه مدیران مانهوا:{$mentions}\nلطفاً وضعیت پیشرفت کار را پیگیری فرمایید.";

            // ارسال پیام اخطار به گروه اختصاصی مانهوا
            $tg->sendMessage($manhwa['group_id'], $warningText);

            // به‌روزرسانی فیلد تاریخ آخرین هشدار
            $stmtUpdateWarning = $db->prepare("UPDATE manhwas SET last_warning_sent_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmtUpdateWarning->execute(['id' => $manhwa['id']]);
        }
    }
}

// ==========================================
// بهینه‌سازی سرعت: کنترل دفعات اجرای پایش مانهواهای راکد و ریست ماهانه (محدود به هر ۱۲ ساعت یک‌بار)
// ==========================================
$now = time();
$stmtCheckTime = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = 'last_inactivity_check' LIMIT 1");
$stmtCheckTime->execute(['bot_id' => $botId]);
$timeRow = $stmtCheckTime->fetch();

if (!$timeRow || ($now - (int)$timeRow['value']) > 43200) {
    // اجرای مکانیزم ریست ماهانه و پایش مانهواهای راکد
    lazyMonthlyReset($db, $botId);
    checkInactiveManhwas($db, $tg, $botId);

    // به‌روزرسانی زمان آخرین بررسی در پایگاه‌داده
    $stmtUpdateCheckTime = $db->prepare("
        INSERT INTO settings (bot_id, key, value) 
        VALUES (:bot_id, 'last_inactivity_check', :value)
        ON CONFLICT (bot_id, key) DO UPDATE SET value = EXCLUDED.value
    ");
    $stmtUpdateCheckTime->execute(['bot_id' => $botId, 'value' => (string)$now]);
}

// ==========================================
// ۳. توابع تایید یا رد چپترهای ارسالی و پرداخت حقوق
// ==========================================

if (!function_exists('processChapterApproval')) {
    /**
     * تایید نهایی چپتر ارسالی، پرداخت حقوق به سهم اعضا و به‌روزرسانی رکوردهای فعالیت مانهوا
     */
    function processChapterApproval($db, $tg, $botId, $chapterId, $adminId) {
        // واکشی مشخصات چپتر در انتظار تایید
        $stmtCh = $db->prepare("SELECT * FROM chapters WHERE bot_id = :bot_id AND id = :id AND status = 'pending' LIMIT 1");
        $stmtCh->execute(['bot_id' => $botId, 'id' => $chapterId]);
        $chapter = $stmtCh->fetch();

        if (!$chapter) {
            return "⚠️ این چپتر قبلاً توسط ادمین‌های دیگر تایید یا رد شده است.";
        }

        // واکشی مشخصات مانهوای مربوطه
        $stmtM = $db->prepare("SELECT title, group_id FROM manhwas WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmtM->execute(['bot_id' => $botId, 'id' => $chapter['manhwa_id']]);
        $manhwa = $stmtM->fetch();
        $manhwaTitle = $manhwa ? $manhwa['title'] : 'پروژه نامشخص';
        $groupId     = $manhwa ? $manhwa['group_id'] : null;

        $db->beginTransaction();
        try {
            // ۱. تغییر وضعیت چپتر جاری به تایید شده
            $stmtUpdateStatus = $db->prepare("UPDATE chapters SET status = 'approved' WHERE bot_id = :bot_id AND id = :id");
            $stmtUpdateStatus->execute(['bot_id' => $botId, 'id' => $chapterId]);

            // ۲. به‌روزرسانی فیلد آخرین چپتر مانهوا و صفر کردن مجدد شمارنده‌ی راکد ماندن مانهوا
            $stmtUpdateManhwa = $db->prepare("
                UPDATE manhwas 
                SET last_chapter = GREATEST(last_chapter, :chapter_num),
                    last_active_at = CURRENT_TIMESTAMP,
                    last_warning_sent_at = NULL
                WHERE bot_id = :bot_id AND id = :id
            ");
            $stmtUpdateManhwa->execute([
                'chapter_num' => $chapter['chapter_num'],
                'bot_id'      => $botId,
                'id'          => $chapter['manhwa_id']
            ]);

            // ۳. تسویه حساب مالی تجمعی برای اعضایی که ممکن است چندشغله باشند
            $rawPayouts = [
                'translator' => ['id' => $chapter['translator_id'], 'pay' => (float)$chapter['translator_pay'], 'role_text' => 'مترجم'],
                'cleaner'    => ['id' => $chapter['cleaner_id'],    'pay' => (float)$chapter['cleaner_pay'],    'role_text' => 'کلینر'],
                'typesetter' => ['id' => $chapter['typesetter_id'], 'pay' => (float)$chapter['typesetter_pay'], 'role_text' => 'تایپیست']
            ];

            // تجمیع مبالغ و نقش‌ها بر اساس آیدی عددی منحصر به فرد کاربران
            $userPayouts = [];
            foreach ($rawPayouts as $p) {
                if (!empty($p['id'])) {
                    $uid = $p['id'];
                    if (!isset($userPayouts[$uid])) {
                        $userPayouts[$uid] = [
                            'pay' => 0.0,
                            'roles' => [],
                            'chapters_increment' => 1 // فقط ۱ چپتر به آمار کل اضافه می‌شود
                        ];
                    }
                    $userPayouts[$uid]['pay'] += $p['pay'];
                    $userPayouts[$uid]['roles'][] = $p['role_text'];
                }
            }

            // اعمال تراکنش نهایی برای هر کاربر
            foreach ($userPayouts as $uid => $data) {
                $stmtUserUpdate = $db->prepare("
                    UPDATE users 
                    SET total_chapters = total_chapters + :ch_inc, 
                        monthly_chapters = monthly_chapters + :ch_inc, 
                        total_earned = total_earned + :pay 
                    WHERE bot_id = :bot_id AND tg_id = :tg_id
                ");
                $stmtUserUpdate->execute([
                    'ch_inc' => $data['chapters_increment'],
                    'pay'    => $data['pay'],
                    'bot_id' => $botId,
                    'tg_id'  => $uid
                ]);

                // تولید پیام و فیش حقوقی دیجیتالی برای کاربر با ذکر نقش‌های چندگانه
                $rolesJoined = implode(' و ', $data['roles']);
                $notifyText = "🎉 <b>حقوق چپتر جدید به موجودی شما اضافه شد!</b>\n\n"
                            . "📚 مانهوا: <b>«{$manhwaTitle}»</b>\n"
                            . "🔢 شماره چپتر کار شده: <code>{$chapter['chapter_num']}</code>\n"
                            . "⚔️ سمت‌های شما در این چپتر: <b>{$rolesJoined}</b>\n"
                            . "💰 مجموع دستمزد واریز شده: <code>" . number_format($data['pay']) . "</code> تومان\n\n"
                            . "💡 کیف پول شما در ربات مانهوا به‌روزرسانی شد. خسته نباشید اعضا! 💖";
                $tg->sendMessage($uid, $notifyText);
            }

            $db->commit();

            // ۴. ارسال پیام اتمام کار در گروه رسمی تلگرامی مانهوا
            if (!empty($groupId)) {
                $groupText = "🔔 <b>اطلاعیه تایید چپتر جدید مانهوا!</b>\n\n"
                           . "🎉 چپتر <code>{$chapter['chapter_num']}</code> مانهوای <b>«{$manhwaTitle}»</b> با موفقیت تایید نهایی گردید.\n\n"
                           . "💸 حقوق اعضای تیم محاسبه و به حساب کاربری آنها در ربات واریز شد.\n"
                           . "با تشکر فراوان از مترجم، کلینر و تایپیست این اثر! ✨";
                $tg->sendMessage($groupId, $groupText);
            }

            return true;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Error in processChapterApproval: " . $e->getMessage());
            return "❌ خطای سیستمی در ثبت تراکنش دیتابیس نئون.";
        }
    }
}

if (!function_exists('processChapterRejection')) {
    /**
     * رد کردن چپتر ارسالی به دلیل عدم تایید کیفیت کار شده توسط ادمین
     */
    function processChapterRejection($db, $tg, $botId, $chapterId, $adminId) {
        $stmtCh = $db->prepare("SELECT * FROM chapters WHERE bot_id = :bot_id AND id = :id AND status = 'pending' LIMIT 1");
        $stmtCh->execute(['bot_id' => $botId, 'id' => $chapterId]);
        $chapter = $stmtCh->fetch();

        if (!$chapter) {
            return "⚠️ این چپتر قبلاً تایید یا رد شده است.";
        }

        $stmtM = $db->prepare("SELECT title FROM manhwas WHERE bot_id = :bot_id AND id = :id LIMIT 1");
        $stmtM->execute(['bot_id' => $botId, 'id' => $chapter['manhwa_id']]);
        $manhwa = $stmtM->fetch();
        $manhwaTitle = $manhwa ? $manhwa['title'] : 'پروژه نامشخص';

        // آپدیت وضعیت چپتر جاری به حالت رد شده
        $stmtUpdate = $db->prepare("UPDATE chapters SET status = 'rejected' WHERE bot_id = :bot_id AND id = :id");
        $stmtUpdate->execute(['bot_id' => $botId, 'id' => $chapterId]);

        // ارسال فیش خطای عدم کیفیت کار به پی‌وی تایپیست مانهوا جهت انجام ویرایش
        if (!empty($chapter['typesetter_id'])) {
            $rejectText = "❌ <b>چپتر کار شده شما تایید نشد!</b>\n\n"
                        . "📚 مانهوا: <b>«{$manhwaTitle}»</b>\n"
                        . "🔢 شماره چپتر: <code>{$chapter['chapter_num']}</code>\n\n"
                        . "⚠️ فایل کار شده شما متاسفانه مورد تایید ادمین‌های مانهوا قرار نگرفت. لطفاً اصلاحات لازم را انجام داده و کار اصلاح‌شده را مجدداً ارسال فرمایید.";
            $tg->sendMessage($chapter['typesetter_id'], $rejectText);
        }

        return true;
    }
}

// ==========================================
// ۴. اینترسپت مستقیم دکمه‌های کالبک تایید یا رد چپتر توسط ادمین با کنترل دسترسی‌ها
// ==========================================
if ($callbackQuery) {
    $callbackData = $callbackQuery['data'];
    $callbackId   = $callbackQuery['id'];
    $messageId    = $callbackQuery['message']['message_id'];
    $adminChatId  = $callbackQuery['message']['chat']['id'];

    // الف) تایید چپتر و پرداخت حقوق
    if (strpos($callbackData, 'admin_approve_ch_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        
        // اعتبارسنجی تایید تراکنش و حقوق (دسترسی perm_sal_chapter_approve در سیستم ۲۲گانه)
        if (!hasPermission($db, $botId, $adminChatId, 'sal_chapter_approve')) {
            $tg->sendMessage($adminChatId, "⚠️ شما سطح دسترسی برای تایید چپترها و پرداخت حقوق را ندارید.");
            exit;
        }

        $chapterId = (int)str_replace('admin_approve_ch_', '', $callbackData);
        $result = processChapterApproval($db, $tg, $botId, $chapterId, $adminChatId);

        if ($result === true) {
            $tg->editMessageText($adminChatId, $messageId, "✅ <b>چپتر تایید شد!</b>\n\nدستمزدها و کیف پول اعضا آپدیت گردید و فیش‌های گزارش کار برای اعضای مرتبط ارسال شد.");
        } else {
            $tg->sendMessage($adminChatId, $result);
        }
        exit;
    }

    // ب) رد چپتر
    elseif (strpos($callbackData, 'admin_reject_ch_') === 0) {
        $tg->answerCallbackQuery($callbackId);

        // اعتبارسنجی لغو تراکنش و حقوق (دسترسی perm_sal_chapter_reject در سیستم ۲۲گانه)
        if (!hasPermission($db, $botId, $adminChatId, 'sal_chapter_reject')) {
            $tg->sendMessage($adminChatId, "⚠️ شما سطح دسترسی برای رد چپترها را ندارید.");
            exit;
        }

        $chapterId = (int)str_replace('admin_reject_ch_', '', $callbackData);
        $result = processChapterRejection($db, $tg, $botId, $chapterId, $adminChatId);

        if ($result === true) {
            $tg->editMessageText($adminChatId, $messageId, "❌ <b>چپتر ارسالی رد شد.</b>\n\nفایل اعلان خطا و ویرایش کار برای کاربر فرستاده شد.");
        } else {
            $tg->sendMessage($adminChatId, $result);
        }
        exit;
    }
}
