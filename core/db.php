<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: core/db.php
 * Role: Optimized Neon PostgreSQL (PDO) Database Connection Handler with Isolated Migrations
 */

require_once __DIR__ . '/config.php';

class DB {
    // نگه‌داری نمونه اتصال فعال (الگوی Singleton) جهت بهینه‌سازی منابع دیتابیس
    private static $instance = null;

    /**
     * برقراری اتصال امن و پرسرعت به دیتابیس PostgreSQL نئون
     * بدون اجرای کدهای ساختاری سنگین در مسیر اتصالات زنده ربات
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

            } catch (PDOException $e) {
                // ثبت دقیق دلیل عدم اتصال به دیتابیس در بخش مانیتورینگ سرور رندر
                error_log("PostgreSQL Database Connection Failure: " . $e->getMessage());
                throw new Exception("Connection to Neon database failed: " . $e->getMessage());
            }
        }

        return self::$instance;
    }

    /**
     * همگام‌سازی و اعمال خودکار تغییرات دیتابیس (Migrations)
     * این متد مجزا شده است تا فقط در مواقع نیاز فراخوانی شود و سربار اتصالات زنده را صفر کند.
     * 
     * @param PDO $db نمونه اتصال فعال به دیتابیس
     * @return bool
     */
    public static function runMigrations($db) {
        try {
            // ۱. فیلدهای محاسبات مالی و هشدارهای انضباطی کاربر
            $db->exec("
                ALTER TABLE chapters ADD COLUMN IF NOT EXISTS translator_pay NUMERIC(15, 2) DEFAULT 0;
                ALTER TABLE chapters ADD COLUMN IF NOT EXISTS cleaner_pay NUMERIC(15, 2) DEFAULT 0;
                ALTER TABLE chapters ADD COLUMN IF NOT EXISTS typesetter_pay NUMERIC(15, 2) DEFAULT 0;
                
                ALTER TABLE manhwas ADD COLUMN IF NOT EXISTS rate_translator NUMERIC(15, 2) DEFAULT NULL;
                ALTER TABLE manhwas ADD COLUMN IF NOT EXISTS rate_cleaner NUMERIC(15, 2) DEFAULT NULL;
                ALTER TABLE manhwas ADD COLUMN IF NOT EXISTS rate_typesetter NUMERIC(15, 2) DEFAULT NULL;
                
                ALTER TABLE users ADD COLUMN IF NOT EXISTS warnings INT DEFAULT 0;
                ALTER TABLE tickets ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'open';
            ");

            // ۲. ساختار جدید و تفکیکی جدول قوانین گروه‌های کاری به همراه عنوان و توضیحات
            $db->exec("
                CREATE TABLE IF NOT EXISTS group_rules_list (
                    id SERIAL PRIMARY KEY,
                    bot_id INT NOT NULL,
                    group_id BIGINT NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    description TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
            ");

            // ۳. تضمین وجود جدول پایه ثبت دسترسی‌های ادمین‌ها
            $db->exec("
                CREATE TABLE IF NOT EXISTS admin_permissions (
                    bot_id INT NOT NULL,
                    user_id BIGINT NOT NULL,
                    PRIMARY KEY (bot_id, user_id)
                );
            ");

            // ۴. پیاده‌سازی کامل ساختار دسترسی‌های ۲۲گانه جدید در جدول سطوح دسترسی ادمین
            $db->exec("
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_rec_translator BOOLEAN DEFAULT FALSE;
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_rec_cleaner BOOLEAN DEFAULT FALSE;
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_rec_typesetter BOOLEAN DEFAULT FALSE;
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_rec_rules BOOLEAN DEFAULT FALSE;
                
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_proj_add BOOLEAN DEFAULT FALSE;
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_proj_edit BOOLEAN DEFAULT FALSE;
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_proj_delete BOOLEAN DEFAULT FALSE;
                
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_team_assign BOOLEAN DEFAULT FALSE;
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_team_dismiss BOOLEAN DEFAULT FALSE;
                
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_sal_chapter_approve BOOLEAN DEFAULT FALSE;
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_sal_chapter_reject BOOLEAN DEFAULT FALSE;
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_sal_rates_global BOOLEAN DEFAULT FALSE;
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_sal_rates_specific BOOLEAN DEFAULT FALSE;
                
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_broadcast_groups BOOLEAN DEFAULT FALSE;
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_broadcast_users BOOLEAN DEFAULT FALSE;
                
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_admin_add BOOLEAN DEFAULT FALSE;
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_admin_perms BOOLEAN DEFAULT FALSE;
                
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_tickets_view BOOLEAN DEFAULT FALSE;
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_tickets_reply BOOLEAN DEFAULT FALSE;
                
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_exams_manage BOOLEAN DEFAULT FALSE;
                
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_warn_user BOOLEAN DEFAULT FALSE;
                ALTER TABLE admin_permissions ADD COLUMN IF NOT EXISTS perm_user_ban BOOLEAN DEFAULT FALSE;
            ");

            return true;
        } catch (PDOException $e) {
            error_log("Database Migration Failure: " . $e->getMessage());
            return false;
        }
    }

    /**
     * متد بستن و آزادسازی دستی اتصال دیتابیس
     */
    public static function close() {
        self::$instance = null;
    }
}
