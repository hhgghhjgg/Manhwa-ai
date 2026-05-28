<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: core/fsm.php
 * Role: State Machine Manager for Multi-Tenant bots (State & Flow tracking)
 */

require_once __DIR__ . '/db.php';

class FSM {

    /**
     * ثبت اولیه یا به‌روزرسانی مشخصات کاربر تلگرام به محض تعامل با ربات.
     * این متد در یک دورِ اتصالِ دیتابیس (Round-trip) اطلاعات کاربر را Upsert کرده و کل ردیف کاربر را برمی‌گرداند.
     * 
     * @param int $botId شناسه اختصاصی ربات مانهوا
     * @param int $tgId آیدی عددی کاربر تلگرام
     * @param string|null $username یوزرنیم تلگرام کاربر
     * @param string|null $fullName نام نمایشی کاربر
     * @return array اطلاعات کامل کاربر از دیتابیس
     */
    public static function initUser($botId, $tgId, $username, $fullName) {
        $db = DB::connect();
        $stmt = $db->prepare("
            INSERT INTO users (bot_id, tg_id, username, full_name)
            VALUES (:bot_id, :tg_id, :username, :full_name)
            ON CONFLICT (bot_id, tg_id) DO UPDATE 
            SET username = EXCLUDED.username, full_name = EXCLUDED.full_name
            RETURNING *
        ");
        $stmt->execute([
            'bot_id'   => $botId,
            'tg_id'    => $tgId,
            'username' => $username,
            'full_name' => $fullName
        ]);
        return $stmt->fetch();
    }

    /**
     * دریافت وضعیت فعلی کاربر
     * 
     * @param int $botId
     * @param int $tgId
     * @return string وضعیت کاربر (در صورت عدم وجود، مقدار پیش‌فرض idle برمی‌گردد)
     */
    public static function getStep($botId, $tgId) {
        $db = DB::connect();
        $stmt = $db->prepare("SELECT step FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id LIMIT 1");
        $stmt->execute([
            'bot_id' => $botId,
            'tg_id'  => $tgId
        ]);
        $row = $stmt->fetch();
        return $row ? $row['step'] : 'idle';
    }

    /**
     * تغییر وضعیت فعلی کاربر
     * 
     * @param int $botId
     * @param int $tgId
     * @param string $step وضعیت جدید
     * @return bool
     */
    public static function setStep($botId, $tgId, $step) {
        $db = DB::connect();
        $stmt = $db->prepare("UPDATE users SET step = :step WHERE bot_id = :bot_id AND tg_id = :tg_id");
        return $stmt->execute([
            'step'   => $step,
            'bot_id' => $botId,
            'tg_id'  => $tgId
        ]);
    }

    /**
     * ریست کردن وضعیت کاربر به حالت پیش‌فرض (idle)
     */
    public static function clearStep($botId, $tgId) {
        return self::setStep($botId, $tgId, 'idle');
    }

    /**
     * تغییر نقش کاربر در تیم (None, Cleaner, Typesetter, Translator, Admin, Owner)
     */
    public static function setRole($botId, $tgId, $role) {
        $db = DB::connect();
        $stmt = $db->prepare("UPDATE users SET role = :role WHERE bot_id = :bot_id AND tg_id = :tg_id");
        return $stmt->execute([
            'role'   => $role,
            'bot_id' => $botId,
            'tg_id'  => $tgId
        ]);
    }

    /**
     * تغییر وضعیت عضویت کاربر (guest, pending_test, approved, rejected)
     */
    public static function setStatus($botId, $tgId, $status) {
        $db = DB::connect();
        $stmt = $db->prepare("UPDATE users SET status = :status WHERE bot_id = :bot_id AND tg_id = :tg_id");
        return $stmt->execute([
            'status' => $status,
            'bot_id' => $botId,
            'tg_id'  => $tgId
        ]);
    }

    /**
     * دریافت کل مشخصات کاربری یک عضو به صورت یکجا
     * 
     * @param int $botId
     * @param int $tgId
     * @return array|bool آرایه اطلاعات کاربر یا false در صورت عدم وجود
     */
    public static function getUserData($botId, $tgId) {
        $db = DB::connect();
        $stmt = $db->prepare("SELECT * FROM users WHERE bot_id = :bot_id AND tg_id = :tg_id LIMIT 1");
        $stmt->execute([
            'bot_id' => $botId,
            'tg_id'  => $tgId
        ]);
        return $stmt->fetch();
    }
}
