<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: core/config.php
 * Role: System Configuration & Environment Variables Loader
 */

// ۱. تنظیم منطقه زمانی پیش‌فرض روی ایران برای هماهنگی لاگ‌ها و سیستم حضور غیاب/آمارهای ماهانه
date_default_timezone_set('Asia/Tehran');

// ۲. خواندن متغیرهای محیطی از تنظیمات سرور رندر (Render Environment Variables)
$masterBotToken = getenv('MASTER_BOT_TOKEN') ?: getenv('BOT_TOKEN');
$databaseUrl    = getenv('DATABASE_URL');
$ownerId        = getenv('OWNER_ID');

// ۳. اعتبارسنجی اولیه متغیرها و ثبت خطا در بخش لاگ رندر در صورت خالی بودن هرکدام
if (!$masterBotToken) {
    error_log("CRITICAL CONFIG WARNING: 'MASTER_BOT_TOKEN' is empty. Please set it in Render environment settings.");
}

if (!$databaseUrl) {
    error_log("CRITICAL CONFIG WARNING: 'DATABASE_URL' is empty. Please set it in Render environment settings.");
}

if (!$ownerId) {
    error_log("CRITICAL CONFIG WARNING: 'OWNER_ID' is empty. Please set it in Render environment settings.");
}

// ۴. تعریف ثابت‌های سراسری برنامه بر اساس مقادیر دریافتی
define('MASTER_BOT_TOKEN', $masterBotToken);
define('DB_DSN_URL', $databaseUrl);
define('OWNER_ID', (int)$ownerId);

// ۵. تعاریف و مقادیر فرعی سیستم (مانند زبان پایه و تنظیمات عملکردی)
define('DEFAULT_LOCALE', 'fa');
define('MAX_PENDING_TESTS_SHOWN', 10); // تعداد تست‌های ارسالی قابل نمایش در هر صفحه پنل ادمین
