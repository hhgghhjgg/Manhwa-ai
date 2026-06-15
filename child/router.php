<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: child/router.php
 * Role: Webhook Router for Child Bots with Dual-Panel Interface Routing
 */

// ۱. اطمینان از صحت دسترسی به کانتکست ربات فرزند
if (!isset($botContext) || $botContext['is_master']) {
    exit;
}

$update = $botContext['update'];
$botId  = $botContext['bot_id'];
$db     = DB::connect();

// ۲. نمونه‌سازی شیء تلگرام با توکن اختصاصی ربات فرزند جاری
$tg = new Telegram($botContext['bot_token']);

// ۳. استخراج متغیرهای پایه تلگرام از آپدیت ورودی
$message       = $update['message'] ?? null;
$callbackQuery = $update['callback_query'] ?? null;

// استخراج اطلاعات کاربر فرستنده
$userId    = $message['from']['id'] ?? $callbackQuery['from']['id'] ?? null;
$username  = $message['from']['username'] ?? $callbackQuery['from']['username'] ?? null;
$firstName = $message['from']['first_name'] ?? $callbackQuery['from']['first_name'] ?? '';
$lastName  = $message['from']['last_name'] ?? $callbackQuery['from']['last_name'] ?? '';
$fullName  = trim($firstName . ' ' . $lastName);

if (!$userId) {
    exit; // دریافت پیامی بدون هویت کاربر (مانند آپدیت‌های کانال یا سیستمی خاص)
}

// ۴. ثبت اولیه یا آپدیت اطلاعات کاربر در دیتابیس برای این ربات مانهوا
$user = FSM::initUser($botId, $userId, $username, $fullName);

// ۵. تضمین دسترسی مالک اصلی ربات:
// اگر کاربر جاری مالک ثبت‌شده این ربات در جدول bots باشد، نقش او را روی owner و وضعیت را روی approved قفل می‌کنیم.
if ($userId === $botContext['owner_id']) {
    if ($user['role'] !== 'owner' || $user['status'] !== 'approved') {
        FSM::setRole($botId, $userId, 'owner');
        FSM::setStatus($botId, $userId, 'approved');
        $user = FSM::getUserData($botId, $userId); // بارگزاری مجدد اطلاعات اصلاح شده کاربر
    }
}

// ۶. تشخیص نوع محیط چت تلگرام (گروه یا چت شخصی)
$chatType = $message['chat']['type'] ?? $callbackQuery['message']['chat']['type'] ?? 'private';
$isGroup  = ($chatType === 'group' || $chatType === 'supergroup');

if ($isGroup) {
    // پیام در گروه یا سوپرگروه مانهوا فرستاده شده است
    require_once __DIR__ . '/group_panel.php';
    exit;
} else {
    // پیام در پی‌وی (چت شخصی) ربات فرستاده شده است
    $isAdmin = ($user['role'] === 'owner' || $user['role'] === 'admin');

    // ==========================================
    // بررسی خاموش بودن ربات برای کاربران عادی
    // ==========================================
    if (!$isAdmin) {
        $stmtStatus = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = 'bot_active_status' LIMIT 1");
        $stmtStatus->execute(['bot_id' => $botId]);
        $rowStatus = $stmtStatus->fetch();
        $botActive = $rowStatus ? $rowStatus['value'] : 'on';

        if ($botActive === 'off') {
            if ($callbackQuery) {
                $tg->answerCallbackQuery($callbackQuery['id'], "❌ ربات موقتاً توسط مدیریت خاموش شده است.", true);
            } else {
                $tg->sendMessage($userId, "❌ <b>ربات به دلیل به‌روزرسانی یا تعمیرات موقتاً توسط مدیریت خاموش شده است.</b>\n\nلطفاً بعداً مراجعه کنید.");
            }
            exit;
        }
    }

    if ($isAdmin) {
        // بررسی چندشغله بودن ادمین (آیا ادمین همزمان نقش فنی مترجم، کلینر یا تایپیست هم دارد؟)
        $rolesList = explode(',', $user['role']);
        $isTechnicalStaff = false;
        foreach ($rolesList as $rolePart) {
            $rolePart = trim($rolePart);
            if (in_array($rolePart, ['translator', 'cleaner', 'typesetter'])) {
                $isTechnicalStaff = true;
                break;
            }
        }

        // بررسی Preference ذخیره شده برای حالت نمایش پی‌وی ادمین
        $pvModeStmt = $db->prepare("SELECT value FROM settings WHERE bot_id = :bot_id AND key = :key LIMIT 1");
        $pvModeStmt->execute(['bot_id' => $botId, 'key' => "admin_pv_mode_" . $userId]);
        $pvModeRow = $pvModeStmt->fetch();
        $pvMode = $pvModeRow ? $pvModeRow['value'] : 'admin'; // به صورت پیش‌فرض پنل ادمین باز می‌شود

        // الف) پردازش مستقیم سوئیچ کردن بین پنل عادی و پنل ادمین
        if ($callbackQuery && ($callbackQuery['data'] === 'admin_sys_mode_normal' || $callbackQuery['data'] === 'admin_sys_mode_admin')) {
            $tg->answerCallbackQuery($callbackQuery['id']);
            $selectedMode = ($callbackQuery['data'] === 'admin_sys_mode_normal') ? 'normal' : 'admin';
            
            $stmtUpMode = $db->prepare("INSERT INTO settings (bot_id, key, value) VALUES (:bot_id, :key, :value) ON CONFLICT (bot_id, key) DO UPDATE SET value = EXCLUDED.value");
            $stmtUpMode->execute([
                'bot_id' => $botId,
                'key'    => "admin_pv_mode_" . $userId,
                'value'  => $selectedMode
            ]);

            FSM::clearStep($botId, $userId);
            
            if ($selectedMode === 'normal') {
                $user['step'] = 'idle'; // بروزرسانی متغیر محلی قبل از فراخوانی فایل
                require_once __DIR__ . '/user_panel.php';
            } else {
                $user['step'] = 'idle'; // بروزرسانی متغیر محلی قبل از فراخوانی فایل
                require_once __DIR__ . '/admin_panel.php';
            }
            exit;
        }

        // ب) نمایش دوراهی انتخاب پنل در زمان ارسال دستور استارت برای کاربران چندشغله
        if ($isTechnicalStaff && $message && isset($message['text']) && $message['text'] === '/start') {
            FSM::clearStep($botId, $userId);
            
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👤 ورود به پنل کاربری عادی', 'callback_data' => 'admin_sys_mode_normal'],
                        ['text' => '🛡️ ورود به پنل مدیریت تیم', 'callback_data' => 'admin_sys_mode_admin']
                    ]
                ]
            ];

            $welcomeText = "⚙️ <b>انتخاب محیط کاربری ربات مانهوا:</b>\n\n"
                         . "سلام مدیر گرامی <b>{$fullName}</b>.\n"
                         . "شما همزمان دارای سمت ادمین و عضویت فنی در تیم کاری مانهوا هستید.\n\n"
                         . "👇 لطفاً پنل مورد نظر خود را جهت ورود انتخاب کنید:";
            
            $tg->sendMessage($userId, $welcomeText, $keyboard);
            exit;
        }

        // ج) هدایت ادمین به پنل کاربری عادی در صورت فعال بودن Preference
        if ($isTechnicalStaff && $pvMode === 'normal') {
            require_once __DIR__ . '/user_panel.php';
            exit;
        }

        // د) روتینگ داینامیک و دقیق ادمین بین دو فایل admin_panel.php و admin_management.php
        $routeToManagement = false;

        if ($callbackQuery) {
            $data = $callbackQuery['data'];
            
            // لیست پیشوندهای اختصاصی فایل دوم (Management)
            $mngPrefixes = [
                'admin_recruit', 'admin_view_tests', 'admin_check_test_', 'admin_msg_', 
                'admin_reject_test_', 'admin_accept_test_', 'admin_manual_recruit_menu', 
                'admin_manual_search', 'admin_manual_table', 'admin_set_man_role_', 
                'admin_mng_invite_', 'admin_mng_view_retests', 'admin_mng_accept_retest_', 
                'admin_mng_reject_retest_', 'admin_mng_raw_', 'admin_team_list_', 
                'admin_user_search_init', 'admin_user_v_', 'admin_mng_notes_', 
                'admin_mng_note_', 'admin_mng_ratings_', 'admin_mng_rating_', 
                'admin_mng_gift_', 'admin_projects_page_', 'admin_project_search_init', 
                'admin_view_manhwa_', 'admin_mng_status_', 'admin_dismiss_list_', 
                'admin_dismiss_', 'admin_assign_', 'admin_usr_ban_', 'admin_usr_confirmban_',
                'admin_usr_warn_', 'admin_usr_dm_', 'admin_most_active', 'admin_team_info',
                'admin_manage_exams_page_', 'admin_ex_del_', 'admin_ex_edit_', 
                'admin_add_practice_exam', 'admin_select_exam_role_'
            ];

            foreach ($mngPrefixes as $pref) {
                if (strpos($data, $pref) === 0) {
                    $routeToManagement = true;
                    break;
                }
            }
        } 
        
        elseif ($user['step'] !== 'idle') {
            $step = $user['step'];
            
            // لیست پیشوندهای استپ FSM اختصاصی فایل دوم (Management)
            $mngFsmPrefixes = [
                'admin_waiting_project_search', 'admin_waiting_user_search', 
                'admin_waiting_manual_', 'admin_waiting_assign_', 'admin_waiting_m_rate_', 
                'admin_waiting_warn_reason_', 'admin_waiting_dm_text_', 'admin_waiting_mng_note_', 
                'admin_waiting_mng_rating_', 'admin_waiting_mng_gift_', 'admin_waiting_mng_raw_upload_', 
                'admin_waiting_mng_invite_', 'admin_waiting_exam_file', 'admin_waiting_exam_title_'
            ];

            foreach ($mngFsmPrefixes as $fsmPref) {
                if (strpos($step, $fsmPref) === 0) {
                    $routeToManagement = true;
                    break;
                }
            }
        }

        // فراخوانی نهایی فایل تفکیک‌شده متناظر
        if ($routeToManagement) {
            require_once __DIR__ . '/admin_management.php';
        } else {
            require_once __DIR__ . '/admin_panel.php';
        }
        exit;

    } else {
        // ۲. اعضای عادی تیم و داوطلبان جدید به پنل کاربر هدایت می‌شوند
        require_once __DIR__ . '/user_panel.php';
        exit;
    }
}
