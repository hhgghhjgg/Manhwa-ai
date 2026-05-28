<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: core/db.php
 * Role: Optimized Neon PostgreSQL (PDO) Database Connection Handler
 */

require_once __DIR__ . '/config.php';

class DB {
    // نگه‌داری نمونه اتصال فعال (الگوی Singleton) جهت بهینه‌سازی منابع
    private static $instance = null;

    /**
     * اتصال به دیتابیس PostgreSQL نئون
     * 
     * @return PDO
     * @throws Exception
     */
    public static function connect() {
        if (self::$instance === null) {
            // اطمینان از تعریف شدن آدرس اتصال دیتابیس در فایل تنظیمات
            if (!defined('DB_DSN_URL') || empty(DB_DSN_URL)) {
                throw new Exception("Database URL is not defined in core/config.php.");
            }

            try {
                // پارس کردن فرمت آدرس postgresql:// که رندر در متغیر DATABASE_URL به ما می‌دهد
                $dbopts = parse_url(DB_DSN_URL);
                
                if ($dbopts === false) {
                    throw new Exception("Failed to parse database connection URL.");
                }

                $host   = $dbopts['host'] ?? '';
                $port   = $dbopts['port'] ?? '5432';
                $user   = $dbopts['user'] ?? '';
                $pass   = $dbopts['pass'] ?? '';
                $dbname = isset($dbopts['path']) ? ltrim($dbopts['path'], '/') : '';

                // ساخت رشته‌ی DSN منطبق با پی‌اچ‌پی و ملزم کردن اتصال امن SSL (مورد نیاز Neon Tech)
                $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";

                // برقراری اتصال با اعمال تنظیمات بهینه امنیتی و کارایی
                self::$instance = new PDO($dsn, $user, $pass, [
                    // فعال کردن نمایش دقیق تمام خطاهای دیتابیس جهت مانیتورینگ بهتر در رندر
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    
                    // تنظیم نحوه بازگشت داده‌ها به شکل آرایه‌های انجمنی (آسان کردن دسترسی به فیلدها)
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    
                    // غیرفعال کردن اجرای شبیه‌سازی‌شده کوئری‌ها جهت افزایش امنیت در مقابل حملات SQL Injection
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    
                    // غیرفعال کردن اتصالات پایا؛ در پلتفرم‌های سرورلس اتصالات باید فوراً باز و بسته شوند
                    PDO::ATTR_PERSISTENT         => false
                ]);

            } catch (PDOException $e) {
                // ثبت دلیل عدم اتصال به دیتابیس در بخش مانیتورینگ سرور
                error_log("PostgreSQL Database Connection Failure: " . $e->getMessage());
                throw new Exception("Connection to Neon database failed: " . $e->getMessage());
            }
        }

        return self::$instance;
    }

    /**
     * متد بستن و آزادسازی دستی اتصال دیتابیس در صورت نیاز
     */
    public static function close() {
        self::$instance = null;
    }
}
