<?php
/**
 * Project: Arvan Create Bot Maker Platform (Super Uploader Engine)
 * File: child_uploader/admin_panel.php
 * Role: Main Admin Panel Shell with Dynamic Plugin Dispatching & Paginated Marketplace
 */

// ۱. اطمینان از صحت دسترسی به کانتکست و متغیرهای تعریف شده در روتر
if (!isset($botContext, $tg, $user, $db)) {
    exit;
}

$userId    = $user['tg_id'];
$fullName  = $user['full_name'];
$step      = $user['step'];
$botId     = $botContext['bot_id'];

$message       = $botContext['update']['message'] ?? null;
$callbackQuery = $botContext['update']['callback_query'] ?? null;

// ==========================================
// بخش کمکی: دیتابیس استاتیک محصولات بازارچه (Global Marketplace Catalog)
// ==========================================
if (!function_exists('getMarketplaceCatalog')) {
    /**
     * کاتالوگ مرجع کل افزونه‌ها و قالب‌های پلتفرم آروان کریت
     */
    function getMarketplaceCatalog() {
        return [
            'default_plugin' => [
                'title' => '📦 افزونه دیفالت همه‌کاره (Super Default)',
                'type' => 'plugin',
                'desc' => 'موتور اصلی کارهای کاربر عادی شامل جستجوی پیشرفته، نمایش شیک شناسنامه آثار، چپترها/لینک‌های دانلود، کتابخانه شخصی و بخش پروفایل کاربری.',
                'image' => null
            ],
            'vip_subscription' => [
                'title' => '💎 افزونه اشتراک ویژه و درگاه پرداخت (VIP Panel)',
                'type' => 'plugin',
                'desc' => 'امکان تعریف قفل پرداخت روی کیفیت‌های بالا، چپترهای جدید مانهوا یا فایل‌های خاص با قابلیت اتصال مستقیم به درگاه‌های ریالی و کریپتو.',
                'image' => null
            ],
            'protector_shield' => [
                'title' => '🛡️ افزونه بادیگارد و ضد فیلتر (Guard Bots Shield)',
                'type' => 'plugin',
                'desc' => 'حفاظت هرمی از آرشیو فایل‌ها با اتصال ربات‌های جانبی موقت جهت دور زدن فیلترینگ تلگرام و ایمن نگه‌داشتن دیتابیس اصلی.',
                'image' => null
            ],
            'ai_recommender' => [
                'title' => '🧠 افزونه چت و پیشنهاد با هوش مصنوعی (AI Assistant)',
                'type' => 'plugin',
                'desc' => 'اضافه کردن چت‌بات اختصاصی هوش مصنوعی جهت راهنمایی مخاطبان و پیشنهاد کارهای مرتبط بر اساس سلیقه و ژانرهای آرشیو.',
                'image' => null
            ],
            'ticket_system' => [
                'title' => '✉️ افزونه تیکت پشتیبانی و تیکتینگ (Support Tickets)',
                'type' => 'plugin',
                'desc' => 'سیستم تیکتینگ دوطرفه برای کاربران جهت ارسال سوالات و مشکلات خود به ادمین‌های مشخص‌شده بدون نیاز به خروج از ربات.',
                'image' => null
            ],
            'practice_exams' => [
                'title' => '🏆 افزونه آزمون‌های تمرینی عمومی (Practice Exams)',
                'type' => 'plugin',
                'desc' => 'امکان آپلود آزمون‌های تمرینی جهت ارزیابی و بالا بردن مهارت‌های پرسنل یا کاربران با قابلیت ثبت فایل‌های حل‌شده.',
                'image' => null
            ],
            'cinema_template' => [
                'title' => '🎬 قالب سینمایی فیلم و سریال (Cinema Theme)',
                'type' => 'template',
                'desc' => 'پیکربندی تخصصی دکمه‌ها و فیلدهای نمایش فیلم بر اساس فصل‌ها، قسمت‌ها، تریلر، بازیگران، سال ساخت و تفکیک کیفیت‌های دانلود.',
                'image' => null
            ],
            'manga_template' => [
                'title' => '📚 قالب گرید مانهوا و مانگا (Manga Theme)',
                'type' => 'template',
                'desc' => 'بهینه‌سازی نمایش چپترهای کتاب به صورت دکمه‌های شیشه‌ای ترازشده چند ستونه به همراه شمارشگرهای لایک، دیس‌لایک و کتابخانه.',
                'image' => null
            ],
            'music_template' => [
                'title' => '🎵 قالب آلبوم موسیقی و موزیک (Music Theme)',
                'type' => 'template',
                'desc' => 'چیدمان اختصاصی برای نمایش فایل‌های صوتی، تفکیک کیفیت‌های ۱۲۸/۳۲۰، متن ترانه و دسته‌بندی داینامیک بر اساس آلبوم یا خواننده.',
                'image' => null
            ],
            'e_learning_template' => [
                'title' => '🎓 قالب دوره‌های آموزشی (E-Learning Theme)',
                'type' => 'template',
                'desc' => 'سازماندهی جلسات دوره، نمایش زمان آموزش، فایل‌های ضمیمه جلسات و سیستم گام‌به‌گام تایید اتمام دوره‌ها برای دانشجویان.',
                'image' => null
            ],
            'app_download_template' => [
                'title' => '🎮 قالب دانلود برنامه و بازی (App Theme)',
                'type' => 'template',
                'desc' => 'تفکیک پلتفرم‌ها (اندروید، ویندوز، مک)، نمایش مشخصات فایل، حجم، راهنمای نصب و مانیتور آپدیت‌های جدید برنامه‌ها.',
                'image' => null
            ]
        ];
    }
}

// ==========================================
// فاز ۲: پردازش دستورات متنی FSM ادمین
// ==========================================
if ($message && isset($message['text'])) {
    $text = trim($message['text']);

    // دکمه لغو کلی عملیات
    if ($text === '/cancel' || $text === 'لغو' || $text === '/start') {
        FSM::clearStep(0, $userId); // آیدی ربات مادر در لایه FSM برابر 0 است
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '⚙️ مدیریت‌ها', 'callback_data' => 'admin_managements']],
                [
                    ['text' => '📂 صفحات و آپشن', 'callback_data' => 'admin_pages_options'],
                    ['text' => '🔧 تنظیمات عمومی', 'callback_data' => 'admin_global_settings']
                ]
            ]
        ];

        $welcomeText = "👋 <b>سلام مدیر گرامی، به کنترل پنل شیشه‌ای سوپر آپلودر خوش آمدید.</b>\n\n"
                     . "سیستم شما در حال حاضر از ساختار ماژولار بازارچه برای مدیریت ابزارها و قالب‌ها استفاده می‌کند.\n\n"
                     . "👇 لطفاً یکی از گزینه‌های پنل مدیریت زیر را انتخاب کنید:";

        $tg->sendMessage($userId, $welcomeText, $keyboard);
        exit;
    }
}

// ==========================================
// فاز ۳: پردازش دکمه‌های شیشه‌ای ادمین (Callback Queries)
// ==========================================
if ($callbackQuery) {
    $callbackData = $callbackQuery['data'];
    $callbackId   = $callbackQuery['id'];
    $messageId    = $callbackQuery['message']['message_id'];
    $adminChatId  = $callbackQuery['message']['chat']['id'];

    // بازگشت کلی به منوی اصلی ادمین
    if ($callbackData === 'admin_back_to_menu' || $callbackData === 'admin_cancel') {
        $tg->answerCallbackQuery($callbackId);
        FSM::clearStep(0, $userId);

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '⚙️ مدیریت‌ها', 'callback_data' => 'admin_managements']],
                [
                    ['text' => '📂 صفحات و آپشن', 'callback_data' => 'admin_pages_options'],
                    ['text' => '🔧 تنظیمات عمومی', 'callback_data' => 'admin_global_settings']
                ]
            ]
        ];

        $welcomeText = "👋 <b>سلام مدیر گرامی، به کنترل پنل شیشه‌ای سوپر آپلودر خوش آمدید.</b>\n\n"
                     . "سیستم شما در حال حاضر از ساختار ماژولار بازارچه برای مدیریت ابزارها و قالب‌ها استفاده می‌کند.\n\n"
                     . "👇 لطفاً یکی از گزینه‌های پنل مدیریت زیر را انتخاب کنید:";

        $tg->editMessageText($adminChatId, $messageId, $welcomeText, $keyboard);
        exit;
    }

    // ۱. هندل کالبک کلیک روی دکمه «⚙️ مدیریت‌ها» با شرط انتقال هوشمند
    elseif ($callbackData === 'admin_managements') {
        $tg->answerCallbackQuery($callbackId);

        // واکشی کل افزونه‌های نصب‌شده و فعال این ربات
        $stmt = $db->prepare("SELECT plugin_slug FROM bot_installed_plugins WHERE bot_id = :bot_id AND is_active = TRUE");
        $stmt->execute(['bot_id' => $botId]);
        $activePlugins = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // افزونه دیفالت همواره به صورت پیش‌فرض فعال در نظر گرفته می‌شود
        if (empty($activePlugins)) {
            $activePlugins = ['default_plugin'];
        }

        $pluginCount = count($activePlugins);

        if ($pluginCount <= 1) {
            // سناریوی الف: انتقال مستقیم به صفحه مدیریت افزونه دیفالت (جهت جلوگیری از منوی واسط بیهوده)
            $pluginAction = 'render_main_menu';
            $defaultPluginPath = __DIR__ . "/plugins/default_plugin/admin_menu.php";
            if (file_exists($defaultPluginPath)) {
                require_once $defaultPluginPath;
            } else {
                $tg->sendMessage($userId, "❌ خطای سیستم: افزونه دیفالت همه‌کاره روی سرور یافت نشد.");
            }
        } else {
            // سناریوی ب: نمایش منوی شیشه‌ای لیست افزونه‌های نصب‌شده جهت مدیریت
            $buttons = [];
            $catalog = getMarketplaceCatalog();

            foreach ($activePlugins as $slug) {
                $title = $catalog[$slug]['title'] ?? $slug;
                $buttons[] = [['text' => $title, 'callback_data' => "manage_plugin_{$slug}"]];
            }
            $buttons[] = [['text' => '🔙 بازگشت به منو اصلی', 'callback_data' => 'admin_back_to_menu']];

            $text = "⚙️ <b>بخش مدیریت ابزارهای فعال ربات:</b>\n\n"
                  . "چندین ابزار یا قالب بر روی ربات شما فعال است. ابزار مورد نظر جهت پیکربندی تنظیمات را انتخاب کنید:";

            $tg->editMessageText($adminChatId, $messageId, $text, ['inline_keyboard' => $buttons]);
        }
        exit;
    }

    // ورود مستقیم به تنظیمات یک افزونه نصب شده خاص
    elseif (strpos($callbackData, 'manage_plugin_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $pluginSlug = str_replace('manage_plugin_', '', $callbackData);

        if (preg_match('/^[a-zA-Z0-9_]+$/', $pluginSlug)) {
            $pluginAdminPath = __DIR__ . "/plugins/{$pluginSlug}/admin_menu.php";
            if (file_exists($pluginAdminPath)) {
                $pluginAction = 'render_main_menu';
                require_once $pluginAdminPath;
            } else {
                $tg->sendMessage($userId, "❌ خطای لودر: فایل پیکربندی این افزونه یافت نشد.");
            }
        }
        exit;
    }

    // ۲. هندل دکمه «📂 صفحات و آپشن»
    elseif ($callbackData === 'admin_pages_options') {
        $tg->answerCallbackQuery($callbackId);

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🏪 بازارچه قالب‌ها و افزونه‌ها', 'callback_data' => 'admin_market_page_1']],
                [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'admin_back_to_menu']]
            ]
        ];

        $text = "📂 <b>بخش صفحات و آپشن‌های ربات سوپر آپلودر:</b>\n\n"
              . "در این بخش می‌توانید ساختار ظاهری (قالب‌ها) یا قابلیت‌های فنی ربات خود را مدیریت کنید.\n\n"
              . "برای گشت‌وگذار و فعال‌سازی ابزارهای جدید وارد بازارچه شوید:";

        $tg->editMessageText($adminChatId, $messageId, $text, $keyboard);
        exit;
    }

    // ۳. سیستم داینامیک بازارچه با صفحه‌بندی ۱۰ تایی (Marketplace Pagination)
    elseif (strpos($callbackData, 'admin_market_page_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $page = (int)str_replace('admin_market_page_', '', $callbackData);
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $catalog = getMarketplaceCatalog();
        $totalItems = count($catalog);
        $totalPages = ceil($totalItems / $limit);

        // واکشی برش ۱۰ تایی از کاتالوگ استاتیک
        $slicedCatalog = array_slice($catalog, $offset, $limit, true);

        $buttons = [];
        foreach ($slicedCatalog as $slug => $item) {
            $typeLabel = $item['type'] === 'plugin' ? '🔌' : '🎨';
            $buttons[] = [['text' => "{$typeLabel} {$item['title']}", 'callback_data' => "market_view_{$slug}"]];
        }

        // ردیف دکمه‌های ناوبری (قبلی / بعدی)
        $navRow = [];
        if ($page > 1) {
            $navRow[] = ['text' => '◀️ قبلی', 'callback_data' => 'admin_market_page_' . ($page - 1)];
        }
        if ($page < $totalPages) {
            $navRow[] = ['text' => 'بعدی ▶️', 'callback_data' => 'admin_market_page_' . ($page + 1)];
        }
        if (!empty($navRow)) {
            $buttons[] = $navRow;
        }

        $buttons[] = [['text' => '🔙 بازگشت', 'callback_data' => 'admin_pages_options']];

        $text = "🏪 <b>بازارچه قالب‌ها و ابزارهای آروان کریت (صفحه {$page} از {$totalPages}):</b>\n\n"
              . "🔌 = افزونه عملکردی (Option)\n"
              . "🎨 = قالب گرافیکی (Template)\n\n"
              . "جهت مشاهده مستندات، پیش‌نویس و نصب روی گزینه مورد نظر کلیک کنید:";

        $tg->editMessageText($adminChatId, $messageId, $text, ['inline_keyboard' => $buttons]);
        exit;
    }

    // مشاهده جزئیات و تایید نصب یک محصول در بازارچه
    elseif (strpos($callbackData, 'market_view_') === 0) {
        $tg->answerCallbackQuery($callbackId);
        $slug = str_replace('market_view_', '', $callbackData);

        $catalog = getMarketplaceCatalog();
        if (!isset($catalog[$slug])) {
            $tg->sendMessage($userId, "❌ محصول مورد نظر در کاتالوگ یافت نشد.");
            exit;
        }

        $item = $catalog[$slug];

        // بررسی اینکه آیا این ربات جاری این محصول را نصب دارد یا خیر
        $stmtCheck = $db->prepare("SELECT 1 FROM bot_installed_plugins WHERE bot_id = :bot_id AND plugin_slug = :slug LIMIT 1");
        $stmtCheck->execute(['bot_id' => $botId, 'slug' => $slug]);
        $isInstalled = (bool)$stmtCheck->fetch();

        $statusText = $isInstalled ? "✅ نصب و فعال شده" : "❌ نصب نشده (غیرفعال)";
        $typeFarsi  = $item['type'] === 'plugin' ? 'افزونه عملکردی (Option)' : 'قالب گرافیکی (Template)';

        $text = "🔎 <b>جزئیات محصول بازارچه:</b>\n\n"
              . "📦 نام: <b>{$item['title']}</b>\n"
              . "🏷️ نوع: <i>{$typeFarsi}</i>\n"
              . "📌 وضعیت: <b>{$statusText}</b>\n\n"
              . "📝 <b>توضیحات:</b>\n<i>{$item['desc']}</i>";

        $buttons = [];
        if ($slug === 'default_plugin') {
            // افزونه اصلی و حیاتی است و غیرقابل حذف می‌باشد
            $buttons[] = [['text' => '🛡️ این ابزار پیش‌فرض و سیستمی است', 'callback_data' => 'dummy']];
        } else {
            if ($isInstalled) {
                $buttons[] = [['text' => '❌ غیرفعال‌سازی و حذف آنی', 'callback_data' => "market_uninstall_{$slug}"]];
            } else {
                $buttons[] = [['text' => '✅ نصب و فعال‌سازی آنی', 'callback_data' => "market_install_{$slug}"]];
            }
        }
        $buttons[] = [['text' => '🔙 بازگشت به بازارچه', 'callback_data' => 'admin_market_page_1']];

        $tg->editMessageText($adminChatId, $messageId, $text, ['inline_keyboard' => $buttons]);
        exit;
    }

    // فعال‌سازی و نصب خودکار افزونه/قالب
    elseif (strpos($callbackData, 'market_install_') === 0) {
        $slug = str_replace('market_install_', '', $callbackData);

        $db->beginTransaction();
        try {
            $stmtIns = $db->prepare("
                INSERT INTO bot_installed_plugins (bot_id, plugin_slug, is_active)
                VALUES (:bot_id, :slug, TRUE)
                ON CONFLICT (bot_id, plugin_slug) DO UPDATE SET is_active = TRUE
            ");
            $stmtIns->execute(['bot_id' => $botId, 'slug' => $slug]);
            $db->commit();

            $tg->apiRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text'              => "🎉 نصب و فعال‌سازی با موفقیت انجام شد!",
                'show_alert'        => true
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            $tg->sendMessage($userId, "❌ خطا در ثبت اطلاعات افزونه در پایگاه‌داده.");
        }

        // رفرش صفحه جزئیات برای نمایش وضعیت جدید
        $callbackQuery['data'] = "market_view_{$slug}";
        require __FILE__;
        exit;
    }

    // غیرفعال‌سازی و حذف افزونه/قالب
    elseif (strpos($callbackData, 'market_uninstall_') === 0) {
        $slug = str_replace('market_uninstall_', '', $callbackData);

        $db->beginTransaction();
        try {
            $stmtDel = $db->prepare("DELETE FROM bot_installed_plugins WHERE bot_id = :bot_id AND plugin_slug = :slug");
            $stmtDel->execute(['bot_id' => $botId, 'slug' => $slug]);
            $db->commit();

            $tg->apiRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text'              => "❌ افزونه غیرفعال و با موفقیت حذف گردید.",
                'show_alert'        => true
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            $tg->sendMessage($userId, "❌ خطا در حذف اطلاعات افزونه از پایگاه‌داده.");
        }

        // رفرش صفحه جزئیات برای نمایش وضعیت جدید
        $callbackQuery['data'] = "market_view_{$slug}";
        require __FILE__;
        exit;
    }

    // ۴. بخش تنظیمات عمومی (Global Settings)
    elseif ($callbackData === 'admin_global_settings') {
        $tg->answerCallbackQuery($callbackId);

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔗 ثبت کانال جوین اجباری (بزودی)', 'callback_data' => 'dummy_feature']],
                [['text' => '👤 پیام خوش‌آمدگویی استارت (بزودی)', 'callback_data' => 'dummy_feature']],
                [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'admin_back_to_menu']]
            ]
        ];

        $text = "🔧 <b>بخش تنظیمات عمومی ربات سوپر آپلودر:</b>\n\n"
              . "در این بخش می‌توانید متغیرهای عمومی ربات خود را که به صورت مشترک میان افزونه‌ها استفاده می‌شوند مدیریت کنید.\n\n"
              . "⚠️ <i>این امکانات در قالب پکیج تنظیمات به زودی تکمیل خواهند شد.</i>";

        $tg->editMessageText($adminChatId, $messageId, $text, $keyboard);
        exit;
    }

    // هندل کارهای دکمه‌های نمایشی غیرفعال
    elseif ($callbackData === 'dummy_feature') {
        $tg->apiRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text'              => "⚠️ این قابلیت به زودی در قالب یک آپشن تکمیلی به بازارچه اضافه خواهد شد.",
            'show_alert'        => true
        ]);
        exit;
    }
}
