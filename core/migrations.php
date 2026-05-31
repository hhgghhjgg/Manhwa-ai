<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: core/migrations.php
 * Role: Database Schema Migration Runner (CLI and Web-ready)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// تعیین نوع محیط اجرا (خط فرمان سرور یا درخواست وب)
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    
    // بررسی هویت جهت جلوگیری از سوءاستفاده‌های امنیتی و اجرای مکرر کوئری‌ها
    // مقدار پارامتر secret در آدرس باید دقیقاً با OWNER_ID تعریف شده در رندر همخوانی داشته باشد.
    // مثال فراخوانی وب: https://yourdomain.com/core/migrations.php?secret=12345678
    $secretInput = $_GET['secret'] ?? '';
    
    if (empty($secretInput) || (int)$secretInput !== OWNER_ID) {
        http_response_code(403);
        echo "<div style='direction: rtl; text-align: center; margin-top: 10%; font-family: Tahoma, sans-serif; padding: 20px; border: 1px solid #ffcccc; background-color: #fff0f0; max-width: 500px; margin-left: auto; margin-right: auto; border-radius: 8px;'>";
        echo "<h2 style='color: #cc0000;'>⛔ عدم دسترسی امنیتی</h2>";
        echo "<p>برای اجرای هماهنگ‌سازی دیتابیس از طریق وب‌سایت، باید شناسه مدیریت خود را به انتهای آدرس اضافه کنید.</p>";
        echo "<code style='background: #e0e0e0; padding: 5px; border-radius: 4px; display: block; margin: 10px 0; font-family: monospace;'>?secret=OWNER_ID</code>";
        echo "</div>";
        exit;
    }
}

try {
    if ($isCli) {
        echo "Connecting to Neon PostgreSQL database...\n";
    }
    
    // برقراری اتصال بهینه
    $db = DB::connect();

    if ($isCli) {
        echo "Database connection successful. Running migrations...\n";
    }

    // اجرای فرآیند مهاجرت دیتابیس و ایجاد جدول‌ها/ستون‌های جدید
    $result = DB::runMigrations($db);

    if ($result) {
        if ($isCli) {
            echo "SUCCESS: Database migrations executed successfully!\n";
            echo "All 22-way permissions and group rules tables are fully synchronized.\n";
        } else {
            echo "<div style='direction: rtl; text-align: center; margin-top: 10%; font-family: Tahoma, sans-serif; padding: 20px; border: 1px solid #ccffcc; background-color: #f0fff0; max-width: 500px; margin-left: auto; margin-right: auto; border-radius: 8px;'>";
            echo "<h2 style='color: green;'>✅ مهاجرت دیتابیس با موفقیت انجام شد</h2>";
            echo "<p>تمام فیلدهای دسترسی ۲۲گانه ادمین، جدول جدید قوانین تعاملی گروه و فیلدهای مالی به دیتابیس نئون متصل شدند.</p>";
            echo "<span style='color: gray; font-size: 12px;'>فرآیند همگام‌سازی ساختار دیتابیس با موفقیت به پایان رسید.</span>";
            echo "</div>";
        }
    } else {
        throw new Exception("Migration execution returned false inside DB::runMigrations.");
    }

} catch (Exception $e) {
    if ($isCli) {
        echo "FATAL ERROR during database migration: " . $e->getMessage() . "\n";
        echo "File: " . $e->getFile() . " on line " . $e->getLine() . "\n";
    } else {
        echo "<div style='direction: rtl; text-align: center; margin-top: 10%; font-family: Tahoma, sans-serif; padding: 20px; border: 1px solid #ffcccc; background-color: #fff0f0; max-width: 500px; margin-left: auto; margin-right: auto; border-radius: 8px;'>";
        echo "<h2 style='color: #cc0000;'>❌ خطای بحرانی در هماهنگ‌سازی دیتابیس</h2>";
        echo "<p style='text-align: left; background: #fff; padding: 10px; border-radius: 4px; border: 1px solid #e0e0e0; font-family: monospace; font-size: 13px;'>" . htmlspecialchars($e->getMessage()) . "</p>";
        echo "</div>";
    }
    
    error_log("Critical Database Migration Exception: " . $e->getMessage());
    exit(1);
}
