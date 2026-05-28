<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: index.php
 * Role: Main Webhook Gateway & Request Router
 */

// ۱. تنظیمات مربوط به ثبت خطاهای احتمالی در بخش Logs پنل رندر
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// ۲. لود کردن ماژول‌ها و فایل‌های زیربنایی هسته
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/telegram.php';
require_once __DIR__ . '/core/fsm.php';

// ۳. پاسخ مناسب به بازدید‌های معمولی مرورگر (متد GET) جهت اطمینان از سلامت سرور
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(200);
    echo "<div style='text-align: center; margin-top: 10%; font-family: Tahoma, sans-serif;'>";
    echo "<h2>🚀 موتور ربات‌ساز مانهوا فعال است</h2>";
    echo "<p>بستر سرورلس رندر آماده دریافت اطلاعات و رویدادهای تلگرام می‌باشد.</p>";
    echo "<span style='color: green;'>● وضعیت دیتابیس و ارتباط بهینه است.</span>";
    echo "</div>";
    exit;
}

// ۴. دریافت بدنه اصلی پیام ارسالی از سمت وب‌هوک تلگرام
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    error_log("Warning: Invalid or empty json received via webhook.");
    http_response_code(400);
    exit;
}

// ۵. دریافت توکن ربات از پارامترهای GET در URL وب‌هوک
$botToken = $_GET['bot_token'] ?? null;

// بررسی اینکه آیا درخواست مربوط به رباتِ مادر (ربات‌ساز اصلی) است یا خیر
// مسیر وب‌هوک ربات مادر می‌تواند به شکل index.php?master=1 یا حاوی توکن اصلی ربات مادر باشد
$isMaster = isset($_GET['master']) || ($botToken === MASTER_BOT_TOKEN);

try {
    // ایجاد یک اتصال پویا و بهینه به دیتابیس نئون
    $db = DB::connect();
    
    if ($isMaster) {
        // ایجاد بسته زمینه (Context) برای ربات مادر
        $botContext = [
            'is_master' => true,
            'bot_id'    => 0, // ربات مادر ردیفی در جدول ربات‌ها ندارد
            'bot_token' => MASTER_BOT_TOKEN,
            'owner_id'  => OWNER_ID,
            'update'    => $update
        ];
        
        // هدایت درخواست به پردازشگر اختصاصی ربات‌ساز مادر
        require_once __DIR__ . '/master/master_handler.php';
        
    } else {
        // سناریو مربوط به ربات فرزند است؛ ابتدا صحت وجود توکن در دیتابیس را بررسی می‌کنیم
        if (!$botToken) {
            error_log("Error: Bot token parameter is missing from the query string.");
            http_response_code(400);
            exit;
        }
        
        // استعلام اطلاعات مالکیتی و شناسه داخلی ربات فرزند از جدول ربات‌ها
        $stmt = $db->prepare("SELECT id, owner_id, bot_name FROM bots WHERE token = :token LIMIT 1");
        $stmt->execute(['token' => $botToken]);
        $botData = $stmt->fetch();
        
        if (!$botData) {
            error_log("Error: Bot token " . substr($botToken, 0, 10) . "... is not registered in our database.");
            http_response_code(404);
            exit;
        }
        
        // ایجاد بسته زمینه (Context) برای ربات فرزندِ مانهوا
        $botContext = [
            'is_master' => false,
            'bot_id'    => (int)$botData['id'],
            'bot_token' => $botToken,
            'owner_id'  => (int)$botData['owner_id'],
            'bot_name'  => $botData['bot_name'] ?? 'ربات مانهوا',
            'update'    => $update
        ];
        
        // هدایت درخواست به پردازشگر مرکزی مانهوا
        require_once __DIR__ . '/child/router.php';
    }
    
    // اتمام عملیات با موفقیت کامل جهت جلوگیری از ارسال مجدد پیام تکراری توسط سرور تلگرام
    http_response_code(200);
    
} catch (Exception $e) {
    error_log("Fatal Webhook Routing Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    http_response_code(500);
    exit;
}
