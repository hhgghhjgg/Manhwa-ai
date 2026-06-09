<?php
/**
 * Project: Arvan Create Bot Maker Platform (Super Uploader Engine)
 * File: child_uploader/plugin_loader.php
 * Role: Secure Dynamic Plugin Loader & Variable Scope Injector (Fixed CallbackId Scope Injection)
 */

// جلوگیری از لود مستقل بدون کانتکست و متغیرهای تعریف شده در هسته ربات
if (!defined('MASTER_BOT_TOKEN')) {
    exit;
}

class PluginLoader {
    // کش استاتیک برای جلوگیری از ارسال کوئری‌های مکرر به دیتابیس در طول یک وب‌هوک
    private static $activePluginsCache = null;

    /**
     * نگاشت امن پیشوندهای ماشین وضعیت و دکمه‌ها به شناسه افزونه هدف
     */
    public static function getSlugByPrefix($prefix) {
        $prefixMap = [
            'def'    => 'default_plugin',     // افزونه همه‌کاره سوپر آپلودر پیش‌فرض
            'vip'    => 'vip_subscription',   // افزونه اشتراک ویژه و درگاه‌های پرداخت
            'guard'  => 'protector_shield',   // افزونه بادیگارد و ضد فیلتر هرمی
            'ai'     => 'ai_recommender',     // افزونه هوش مصنوعی و چت‌بات سخنگو
            'ticket' => 'ticket_system',       // افزونه تیکت پشتیبانی و تیکتینگ
            'exam'   => 'practice_exams'      // افزونه آزمون‌های تمرینی عمومی
        ];
        return $prefixMap[$prefix] ?? null;
    }

    /**
     * استعلام و کش کردن لیست افزونه‌های فعال و نصب شده برای ربات فرزند
     */
    public static function getActivePlugins($db, $botId) {
        if (self::$activePluginsCache === null) {
            try {
                $stmt = $db->prepare("
                    SELECT plugin_slug 
                    FROM bot_installed_plugins 
                    WHERE bot_id = :bot_id AND is_active = TRUE
                ");
                $stmt->execute(['bot_id' => $botId]);
                $plugins = $stmt->fetchAll(PDO::FETCH_COLUMN);

                // افزونه دیفالت به عنوان پایه همواره به عنوان افزونه فعال لود می‌شود
                if (!in_array('default_plugin', $plugins)) {
                    $plugins[] = 'default_plugin';
                }

                self::$activePluginsCache = $plugins;
            } catch (PDOException $e) {
                error_log("Error fetching active plugins: " . $e->getMessage());
                self::$activePluginsCache = ['default_plugin'];
            }
        }
        return self::$activePluginsCache;
    }

    /**
     * بررسی زنده وضعیت فعال بودن یک افزونه خاص روی ربات فرزند
     */
    public static function isPluginActive($db, $botId, $slug) {
        $activeList = self::getActivePlugins($db, $botId);
        return in_array($slug, $activeList);
    }

    /**
     * تزریق متغیرهای سراسری سیستم به کدهای لودشده افزونه جهت حفظ ایزوله‌سازی متغیرها (Variable Scope Isolation)
     */
    private static function injectAndRequire($filePath, $variables = []) {
        if (!file_exists($filePath)) {
            return false;
        }

        // استخراج آرایه کلید-مقدار متغیرها به لایه محلی کدهای افزونه
        extract($variables, EXTR_SKIP);

        // لود فایل افزونه در بستر امن تفکیک شده
        require $filePath;
        return true;
    }

    /**
     * توزیع داینامیک و امن کالبک‌های ورودی به افزونه‌های هدف (Callback Dispatcher)
     */
    public static function dispatchCallback($db, $tg, $botId, $userId, $callbackQuery, $botContext, $user) {
        $callbackData = $callbackQuery['data'] ?? '';
        
        // تفکیک کالبک جهت استخراج پیشوند
        $parts = explode('_', $callbackData, 2);
        $prefix = $parts[0] ?? '';
        
        $pluginSlug = self::getSlugByPrefix($prefix);

        // ۱. اعتبارسنجی امنیتی پیشوند افزونه هدف جهت لغو حملات نفوذ دایرکتوری (Directory Traversal Bypass)
        if ($pluginSlug && preg_match('/^[a-zA-Z0-9_]+$/', $pluginSlug)) {
            // ۲. بررسی نصب و فعال بودن افزونه روی این ربات
            if (self::isPluginActive($db, $botId, $pluginSlug)) {
                
                $handlerPath = __DIR__ . "/plugins/{$pluginSlug}/handler.php";
                
                // ۳. لود کردن کدهای افزونه با تزریق متغیرهای حیاتی وب‌هوک (متغیر callbackId نیز اضافه شد) [1]
                $variables = [
                    'db'            => $db,
                    'tg'            => $tg,
                    'botId'         => $botId,
                    'userId'        => $userId,
                    'user'          => $user,
                    'botContext'    => $botContext,
                    'callbackQuery' => $callbackQuery,
                    'callbackData'  => $callbackData,
                    'callbackId'    => $callbackQuery['id'] ?? null, // تزریق سراسری آیدی کالبک به اسکوپ تمام افزونه‌ها
                    'messageId'     => $callbackQuery['message']['message_id'] ?? null,
                    'chatId'        => $callbackQuery['message']['chat']['id'] ?? $userId
                ];

                return self::injectAndRequire($handlerPath, $variables);
            }
        }
        return false;
    }
}
