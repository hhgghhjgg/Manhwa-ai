<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: core/db.php
 * Role: Optimized Neon PostgreSQL (PDO) Database Connection Handler with Self-Healing Migrations
 */

require_once __DIR__ . '/config.php';

class DB {
    // نگه‌داری نمونه اتصال فعال (الگوی Singleton) جهت بهینه‌سازی منابع دیتابیس
    private static $instance = null;

    /**
     * برقراری اتصال امن به دیتابیس PostgreSQL نئون
     * 
     * @return PDO
     * @throws Exception
     */
    public static function connect() {
        if (self::$instance === null) {
            // اطمینان از تعریف شدن آدرس اتصال دیتابیس در فایل تنظیمات اصلی
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

                // ساخت رشته‌ی DSN با ملزم کردن اتصال امن SSL (مورد نیاز Neon Tech)
                $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";

                // برقراری اتصال بهینه به دیتابیس نئون
                self::$instance = new PDO($dsn, $user, $pass, [
                    // فعال کردن نمایش دقیق تمام خطاهای دیتابیس جهت مانیتورینگ بهتر در پنل رندر
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    
                    // تنظیم نحوه بازگشت داده‌ها به شکل آرایه‌های انجمنی برای دسترسی ساده به ستون‌ها
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    
                    // شبیه‌سازی کلاینتی آماده‌سازی دستورات جهت دور زدن کش سروری PgBouncer دیتابیس نئون
                    // این خط به طور کامل مشکل خطای "cached plan must not change result type" را برطرف می‌کند
                    PDO::ATTR_EMULATE_PREPARES   => true, 
                    
                    // غیرفعال کردن اتصالات پایا؛ در پلتفرم‌های سرورلس اتصالات باید فوراً پس از پایان اسکریپت بسته شوند
                    PDO::ATTR_PERSISTENT         => false
                ]);

                // اجرای خودکار هماهنگ‌سازی و ثبت ستون‌ها و جدول‌های جدید (Database Migration)
                // این بخش برای جلوگیری از ارورهای مربوط به نبود ستون‌های مالی و تیکت‌ها به صورت هوشمند عمل می‌کند
                self::$instance->exec("
                    ALTER TABLE chapters ADD COLUMN IF NOT EXISTS translator_pay NUMERIC(15, 2) DEFAULT 0;
                    ALTER TABLE chapters ADD COLUMN IF NOT EXISTS cleaner_pay NUMERIC(15, 2) DEFAULT 0;
                    ALTER TABLE chapters ADD COLUMN IF NOT EXISTS typesetter_pay NUMERIC(15, 2) DEFAULT 0;
                    ALTER TABLE manhwas ADD COLUMN IF NOT EXISTS rate_translator NUMERIC(15, 2) DEFAULT NULL;
                    ALTER TABLE manhwas ADD COLUMN IF NOT EXISTS rate_cleaner NUMERIC(15, 2) DEFAULT NULL;
                    ALTER TABLE manhwas ADD COLUMN IF NOT EXISTS rate_typesetter NUMERIC(15, 2) DEFAULT NULL;
                    ALTER TABLE users ADD COLUMN IF NOT EXISTS warnings INT DEFAULT 0;
                    ALTER TABLE tickets ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'open';
                    CREATE TABLE IF NOT EXISTS group_rules (
                        bot_id INT NOT NULL,
                        group_id BIGINT NOT NULL,
                        rules TEXT,
                        PRIMARY KEY (bot_id, group_id)
                    );
                ");

            } catch (PDOException $e) {
                // ثبت دقیق دلیل عدم اتصال به دیتابیس در بخش مانیتورینگ سرور رندر
                error_log("PostgreSQL Database Connection Failure: " . $e->getMessage());
                throw new Exception("Connection to Neon database failed: " . $e->getMessage());
            }
        }

        return self::$instance;
    }

    /**
     * متد بستن و آزادسازی دستی اتصال دیتابیس
     */
    public static function close() {
        self::$instance = null;
    }
}
