<?php
// فعال‌سازی سشن برای حفظ حافظه و چت متوالی هوش مصنوعی
session_start();

// دریافت متغیرهای محیطی (تنظیم شده در پنل Render)
$geminiApiKey = getenv('GEMINI_API_KEY') ?: '';
$dbUrl = getenv('DATABASE_URL') ?: getenv('NEON_DATABASE_URL') ?: '';

$db = null;
$dbError = null;

// اتصال پایدار به دیتابیس Neon PostgreSQL و ساخت خودکار جدول‌ها در صورت عدم وجود
if (!empty($dbUrl)) {
    try {
        $parsedUrl = parse_url($dbUrl);
        if ($parsedUrl) {
            $host = $parsedUrl['host'] ?? '';
            $port = $parsedUrl['port'] ?? '5432';
            $user = $parsedUrl['user'] ?? '';
            $pass = $parsedUrl['pass'] ?? '';
            $dbname = ltrim($parsedUrl['path'] ?? '', '/');
            
            $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";
            $db = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            
            // ساخت جدول چپترها و سگمنت‌ها به صورت خودکار (بدون فیلد ذخیره تصویر جهت سبک ماندن دیتابیس)
            $db->exec("
                CREATE TABLE IF NOT EXISTS chapters (
                    id INT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL
                );
                CREATE TABLE IF NOT EXISTS segments (
                    id SERIAL PRIMARY KEY,
                    chapter_id INT NOT NULL,
                    absolute_index INT NOT NULL,
                    prompt TEXT,
                    negative_prompt TEXT,
                    ai_version VARCHAR(50),
                    translations TEXT, 
                    current_version_index INT DEFAULT -1,
                    FOREIGN KEY (chapter_id) REFERENCES chapters(id) ON DELETE CASCADE
                );
            ");
        }
    } catch (Exception $e) {
        $dbError = $e->getMessage();
    }
}

// تابع ارسال درخواست به API جمینی (پشتیبانی از مولتی‌مدیا و متن)
function callGeminiMultimodal($promptText, $base64Image, $mimeType, $apiKey, $chatHistory = []) {
    if (empty($apiKey)) {
        return null;
    }
    
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey;
    
    $contents = [];
    
    // اضافه کردن تاریخچه چت متنی برای مدل متوالی
    foreach ($chatHistory as $chat) {
        $contents[] = [
            "role" => $chat['role'] === 'user' ? 'user' : 'model',
            "parts" => [["text" => $chat['content']]]
        ];
    }
    
    // اضافه کردن پیام جاری به همراه تصویر موقت و متن دستورالعمل
    $currentParts = [];
    if (!empty($base64Image)) {
        $currentParts[] = [
            "inlineData" => [
                "mimeType" => $mimeType,
                "data" => $base64Image
            ]
        ];
    }
    $currentParts[] = [
        "text" => $promptText
    ];
    
    $contents[] = [
        "role" => "user",
        "parts" => $currentParts
    ];
    
    $data = [
        "contents" => $contents
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        curl_close($ch);
        return null;
    }
    curl_close($ch);
    
    $result = json_decode($response, true);
    return $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
}

// مسیرهای پاسخ‌دهی بک‌اند (AJAX Backend Router)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // ذخیره کل وضعیت چپترها و سگمنت‌ها در Neon
    if ($action === 'save_state') {
        $input = json_decode(file_get_contents('php://input'), true);
        $chaptersInput = $input['chapters'] ?? [];
        
        if ($db) {
            try {
                $db->beginTransaction();
                
                // پاک‌سازی موقت برای ثبت ساختار جدید آپدیت شده
                $db->exec("DELETE FROM segments");
                $db->exec("DELETE FROM chapters");
                
                foreach ($chaptersInput as $chap) {
                    $stmt = $db->prepare("INSERT INTO chapters (id, name) VALUES (:id, :name)");
                    $stmt->execute([
                        ':id' => $chap['id'],
                        ':name' => $chap['name']
                    ]);
                    
                    $segmentsInput = $chap['segments'] ?? [];
                    foreach ($segmentsInput as $index => $seg) {
                        $absoluteIndex = $index + 1;
                        $stmtSeg = $db->prepare("
                            INSERT INTO segments (chapter_id, absolute_index, prompt, negative_prompt, ai_version, translations, current_version_index)
                            VALUES (:chapter_id, :absolute_index, :prompt, :negative_prompt, :ai_version, :translations, :current_version_index)
                        ");
                        $stmtSeg->execute([
                            ':chapter_id' => $chap['id'],
                            ':absolute_index' => $absoluteIndex,
                            ':prompt' => $seg['prompt'] ?? '',
                            ':negative_prompt' => $seg['negativePrompt'] ?? '',
                            ':ai_version' => $seg['aiVersion'] ?? 'gpt-4o',
                            ':translations' => json_encode($seg['translations'] ?? []),
                            ':current_version_index' => $seg['currentVersionIndex'] ?? -1
                        ]);
                    }
                }
                
                $db->commit();
                echo json_encode(['success' => true, 'message' => 'اطلاعات با موفقیت در نئون ذخیره شد']);
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                echo json_encode(['success' => false, 'message' => 'خطا در عملیات تراکنش دیتابیس: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'دیتابیس متصل نیست. داده‌ها به طور موقت در مرورگر حفظ می‌شوند.']);
        }
        exit;
    }

    // بارگذاری کل وضعیت چپترها و سگمنت‌ها از Neon
    if ($action === 'load_state') {
        if ($db) {
            try {
                $chapters = [];
                $stmt = $db->query("SELECT * FROM chapters ORDER BY id ASC");
                $dbChapters = $stmt->fetchAll();
                
                foreach ($dbChapters as $dbChap) {
                    $stmtSeg = $db->prepare("SELECT * FROM segments WHERE chapter_id = :chapter_id ORDER BY absolute_index ASC");
                    $stmtSeg->execute([':chapter_id' => $dbChap['id']]);
                    $dbSegments = $stmtSeg->fetchAll();
                    
                    $segments = [];
                    foreach ($dbSegments as $dbSeg) {
                        $segments[] = [
                            'id' => (int)$dbSeg['id'],
                            'prompt' => $dbSeg['prompt'] ?? '',
                            'negativePrompt' => $dbSeg['negative_prompt'] ?? '',
                            'aiVersion' => $dbSeg['ai_version'] ?? 'gpt-4o',
                            'translations' => json_decode($dbSeg['translations'] ?? '[]', true),
                            'currentVersionIndex' => (int)$dbSeg['current_version_index'],
                            'image' => '' // تصویر به صورت موقت در کلاینت می‌ماند و در دیتابیس دخیره نمی‌شود
                        ];
                    }
                    
                    $chapters[] = [
                        'id' => (int)$dbChap['id'],
                        'name' => $dbChap['name'],
                        'segments' => $segments
                      ];
                }
                
                echo json_encode(['success' => true, 'chapters' => $chapters]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'خطا در بارگذاری اطلاعات: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'اتصال دیتابیس برقرار نیست']);
        }
        exit;
    }

    // فرآیند هوشمند ترجمه با پشتیبانی از حافظه متوالی و ارسال مستقیم تصویر موقت
    if ($action === 'translate') {
        $input = json_decode(file_get_contents('php://input'), true);
        $segmentId = $input['segmentId'] ?? '';
        $prompt = $input['prompt'] ?? '';
        $negativePrompt = $input['negativePrompt'] ?? '';
        $aiModel = $input['aiModel'] ?? 'gpt-4o';
        $memoryMode = $input['memoryMode'] ?? 'continuous';
        $groupId = $input['groupId'] ?? 'default';
        $rawImage = $input['image'] ?? ''; // تصویر موقت ارسالی از کلاینت

        // فیلتر کردن هدر تصویر Base64 برای استخراج محتوای خام تصویر
        $base64Image = '';
        $mimeType = 'image/jpeg';
        if (!empty($rawImage) && strpos($rawImage, ';base64,') !== false) {
            list($type, $data) = explode(';', $rawImage);
            list(, $data) = explode(',', $data);
            $base64Image = $data;
            if (preg_match('/data:(.*)/', $type, $matches)) {
                $mimeType = $matches[1];
            }
        }

        $instruction = "شما مترجم مانهوا و وب‌تون هستید. متن داخل پانل مانهوا ضمیمه شده را به فارسی روان، بدون تکلف و کاملاً عامیانه ترجمه کنید.\n"
                     . "دستورالعمل ویژه: $prompt\n"
                     . "قوانین منفی (نبایدها): $negativePrompt\n"
                     . "فقط و فقط متن نهایی ترجمه را برگردانید و از ارائه توضیحات اضافه خودداری کنید.";

        $chatHistory = [];
        $sessionKey = 'ai_chat_history_' . $groupId;

        if ($memoryMode === 'continuous') {
            if (!isset($_SESSION[$sessionKey])) {
                $_SESSION[$sessionKey] = [];
            }
            $chatHistory = $_SESSION[$sessionKey];
        }

        // فراخوانی مستقیم API جمینی در صورت وجود کلید متغیر محیطی
        $apiResult = callGeminiMultimodal($instruction, $base64Image, $mimeType, $geminiApiKey, $chatHistory);

        if ($apiResult !== null) {
            $translation = trim($apiResult);
        } else {
            // شبیه‌ساز در صورت عدم وجود کلید API معتبر روی سرور
            $simulated = [
                "ترجمه روان مانهوایی هماهنگ با سبک کار مانهوا [سگمنت $segmentId]",
                "نسخه بازنویسی شده دیالوگ مانهوا با رعایت قوانین منفی [سگمنت $segmentId]",
                "جمله بومی‌سازی شده متناسب با بافت مانهوای آسیایی [سگمنت $segmentId]"
            ];
            $translation = $simulated[array_rand($simulated)] . " (شبیه‌ساز جمینی - بدون کلید)";
        }

        // ذخیره سازی تاریخچه متنی گفتگو در سشن
        if ($memoryMode === 'continuous') {
            $_SESSION[$sessionKey][] = ["role" => "user", "content" => "سگمنت $segmentId: $instruction"];
            $_SESSION[$sessionKey][] = ["role" => "assistant", "content" => $translation];
        }

        echo json_encode([
            'success' => true,
            'translation' => $translation
        ]);
        exit;
    }

    // بخش چت دستیار هوشمند
    if ($action === 'chat_assistant') {
        $input = json_decode(file_get_contents('php://input'), true);
        $message = $input['message'] ?? '';

        if (!isset($_SESSION['assistant_chat'])) {
            $_SESSION['assistant_chat'] = [];
        }

        $promptText = "شما دستیار ترجمه مانهوا هستید. پاسخ سوال کاربر در مورد اصطلاحات، معانی کلمات یا ساختار جملات مانهوا را دقیق و خلاصه بدهید:\n" . $message;
        
        $apiResult = callGeminiMultimodal($promptText, '', '', $geminiApiKey, []);

        if ($apiResult !== null) {
            $reply = trim($apiResult);
        } else {
            $reply = "من به عنوان دستیار ترجمه مانهوای شما آماده‌ام. برای پاسخ دقیق لطفا کلید API جمینی را در متغیرهای محیطی Render تنظیم کنید. در حال حاضر سوال شما را دریافت کردم: «" . htmlspecialchars($message) . "»";
        }

        $_SESSION['assistant_chat'][] = ["role" => "user", "content" => $message];
        $_SESSION['assistant_chat'][] = ["role" => "assistant", "content" => $reply];

        echo json_encode([
            'success' => true,
            'reply' => $reply
        ]);
        exit;
    }

    // خروجی نهایی ورد متناسب با انتخاب‌های کلاینت
    if ($action === 'export_docx') {
        $input = json_decode(file_get_contents('php://input'), true);
        $chaptersData = $input['chapters'] ?? [];
        
        header('Content-Type: application/vnd.ms-word');
        header('Content-Disposition: attachment; filename="manhwa_translation_export.doc"');
        
        echo "<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'>";
        echo "<head><meta charset='utf-8'><title>خروجی ترجمه</title></head>";
        echo "<body style='direction:rtl; font-family:Arial, sans-serif; text-align:right;'>";
        echo "<h2>گزارش جامع ترجمه چپترهای مانهوا</h2><hr/>";
        
        foreach ($chaptersData as $chap) {
            echo "<h3 style='color:#8b5cf6; border-bottom:1px solid #ddd; padding-bottom:4px;'>" . htmlspecialchars($chap['name']) . "</h3>";
            foreach ($chap['segments'] as $index => $seg) {
                $num = $index + 1;
                $text = !empty($seg['translations']) ? $seg['translations'][$seg['currentVersionIndex']] : 'ترجمه‌ای ثبت نشده است';
                echo "<div style='margin-bottom:15px; padding:10px; border-left:4px solid #8b5cf6; background:#f9fafb;'>";
                echo "<strong>سگمنت شماره $num:</strong>";
                echo "<p style='margin:5px 0 0 0; color:#4b5563;'>$text</p>";
                echo "</div>";
            }
        }
        echo "</body></html>";
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مترجم هوشمند مانهوا | نسخه ابری</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;600;800&display=swap');
        
        :root {
            --bg-primary: #090d16;
            --text-primary: #f8fafc;
            --glass-panel-bg: rgba(255, 255, 255, 0.02);
            --glass-panel-border: rgba(255, 255, 255, 0.06);
            --glass-card-bg: rgba(255, 255, 255, 0.03);
            --glass-card-border: rgba(255, 255, 255, 0.07);
            --glass-card-hover: rgba(255, 255, 255, 0.07);
            --glass-input-bg: rgba(255, 255, 255, 0.04);
            --glass-input-border: rgba(255, 255, 255, 0.08);
            --text-muted: #94a3b8;
            --accent-primary: #a855f7;
            --segment-box-bg: rgba(0, 0, 0, 0.35);
        }

        [data-theme="light"] {
            --bg-primary: #f1f5f9;
            --text-primary: #0f172a;
            --glass-panel-bg: rgba(15, 23, 42, 0.03);
            --glass-panel-border: rgba(15, 23, 42, 0.08);
            --glass-card-bg: rgba(255, 255, 255, 0.7);
            --glass-card-border: rgba(15, 23, 42, 0.08);
            --glass-card-hover: rgba(255, 255, 255, 0.9);
            --glass-input-bg: rgba(255, 255, 255, 0.85);
            --glass-input-border: rgba(15, 23, 42, 0.1);
            --text-muted: #64748b;
            --accent-primary: #7c3aed;
            --segment-box-bg: rgba(255, 255, 255, 0.95);
        }

        body {
            font-family: 'Vazirmatn', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            transition: background 0.4s cubic-bezier(0.4, 0, 0.2, 1), color 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            overflow-x: hidden;
        }

        .glass-panel {
            background: var(--glass-panel-bg);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border: 1px solid var(--glass-panel-border);
        }

        .glass-card {
            background: var(--glass-card-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--glass-card-border);
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }

        .glass-card:hover {
            background: var(--glass-card-hover);
            transform: translateY(-2px);
        }

        .glass-input {
            background: var(--glass-input-bg);
            border: 1px solid var(--glass-input-border);
            color: var(--text-primary);
            transition: all 0.2s ease;
        }

        .glass-input:focus {
            border-color: var(--accent-primary);
            box-shadow: 0 0 10px rgba(168, 85, 247, 0.15);
            outline: none;
        }

        .nav-orb {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            transition: all 0.45s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .nav-orb.expanded {
            width: 320px;
            height: 70px;
            border-radius: 35px;
        }

        .animate-spin-slow {
            animation: spin 10s linear infinite;
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        ::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }
        ::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.2);
        }
    </style>
</head>
<body class="min-h-screen relative pb-28">

    <!-- لایه‌های نوری پس‌زمینه رنگی مخصوص طراحی شیشه‌ای آیفون -->
    <div class="fixed top-[-25%] left-[-20%] w-[65%] h-[65%] bg-purple-600/10 rounded-full blur-[180px] pointer-events-none z-0"></div>
    <div class="fixed bottom-[-25%] right-[-20%] w-[65%] h-[65%] bg-pink-600/10 rounded-full blur-[180px] pointer-events-none z-0"></div>

    <div class="container mx-auto px-4 py-6 max-w-5xl relative z-10">
        <!-- هدر اصلی برنامه -->
        <header class="flex justify-between items-center mb-8 p-4 glass-panel rounded-2xl shadow-xl">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-gradient-to-tr from-purple-600 to-pink-500 rounded-2xl flex items-center justify-center shadow-lg">
                    <i class="fa-solid fa-wand-magic-sparkles text-xl text-white"></i>
                </div>
                <div>
                    <h1 class="text-base font-extrabold tracking-wide">محیط یکپارچه مانهوا</h1>
                    <p class="text-[9px] text-[var(--text-muted)] flex items-center gap-1.5">
                        <span id="neon-status" class="w-2 h-2 rounded-full bg-yellow-500 animate-pulse"></span>
                        در حال بررسی اتصال دیتابیس ابری...
                    </p>
                </div>
            </div>
            
            <!-- نمایش وضعیت ذخیره‌سازی ابری پویای آیفون -->
            <div class="flex items-center gap-3">
                <div id="save-status-pill" class="px-3 py-1.5 rounded-full text-[10px] font-semibold bg-white/5 border border-white/5 flex items-center gap-1.5 transition-all duration-300">
                    <i id="save-status-icon" class="fa-solid fa-cloud"></i>
                    <span id="save-status-text">همگام‌سازی فعال</span>
                </div>
                <button onclick="openGlobalSettings()" class="px-3 py-2 text-xs bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl flex items-center gap-2 transition-all">
                    <i class="fa-solid fa-sliders text-purple-400"></i>تنظیمات کلی
                </button>
                <button onclick="openGlobalStartModal()" class="px-4 py-2 text-xs bg-gradient-to-r from-purple-600 to-pink-600 hover:opacity-95 text-white rounded-xl flex items-center gap-2 transition-all shadow-md font-bold">
                    <i class="fa-solid fa-play"></i>شروع کلی
                </button>
            </div>
        </header>

        <!-- نمایش هشدارهای احتمالی دیتابیس در بالای صفحه -->
        <?php if ($dbError): ?>
            <div class="mb-6 p-4 bg-rose-500/10 border border-rose-500/20 text-rose-300 rounded-2xl text-xs text-right">
                <i class="fa-solid fa-triangle-exclamation ml-1.5"></i>
                خطا در اتصال به دیتابیس Neon: <?= htmlspecialchars($dbError) ?>. برنامه به طور خودکار در حالت حافظه آفلاین اجرا خواهد شد.
            </div>
        <?php endif; ?>

        <!-- بخش سگمنت‌ها (Segments) -->
        <main id="tab-segments" class="tab-content space-y-6">
            <!-- نوار کپسولی چپترها (با اسکرول صفحه خارج می‌شود) -->
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-[var(--text-muted)] flex items-center gap-1.5">
                        <i class="fa-solid fa-book-bookmark text-purple-400"></i>فهرست چپترهای فعال پروژه
                    </span>
                </div>
                <div class="flex gap-2 items-center overflow-x-auto pb-2" id="chapters-bar-container">
                    <!-- کپسول‌ها به صورت داینامیک رندر می‌شوند -->
                </div>
            </div>

            <!-- هدر مدیریت سگمنت‌ها -->
            <div class="flex justify-between items-center pt-2 border-t border-white/5">
                <h2 class="text-xs font-bold text-[var(--text-muted)] flex items-center gap-2">
                    <i class="fa-solid fa-layer-group text-purple-400"></i>سگمنت‌های چپتر فعال
                </h2>
                <button onclick="addSegment()" class="px-4 py-2 bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 border border-emerald-500/10 rounded-xl text-xs font-bold transition-all flex items-center gap-1">
                    <i class="fa-solid fa-plus"></i>سگمنت جدید
                </button>
            </div>

            <!-- محفظه رندر سگمنت‌ها -->
            <div id="segments-container" class="space-y-4">
                <!-- المان‌ها با ساختار شیشه‌ای رندر می‌شوند -->
            </div>
        </main>

        <!-- بخش چت اختصاصی دستیار هوش مصنوعی (Assistant Chat) -->
        <main id="tab-chat" class="tab-content hidden space-y-4">
            <div class="glass-panel p-5 rounded-3xl flex flex-col h-[600px] shadow-2xl">
                <div class="flex items-center gap-3 border-b border-white/5 pb-4 mb-4 text-right">
                    <div class="w-10 h-10 bg-purple-600/10 rounded-2xl flex items-center justify-center border border-purple-500/10">
                        <i class="fa-solid fa-comments text-purple-400 text-base"></i>
                    </div>
                    <div>
                        <h3 class="text-xs font-bold">دستیار تخصصی ترجمه مانهوا</h3>
                        <p class="text-[9px] text-[var(--text-muted)]">معنی اصطلاحات فانتزی، عامیانه و ساختارهای دشوار را در لحظه بپرسید</p>
                    </div>
                </div>

                <div id="chat-messages-container" class="flex-1 overflow-y-auto space-y-3 pr-2 text-right">
                    <div class="bg-white/5 border border-white/5 rounded-2xl p-3 max-w-[85%] ml-auto text-xs leading-relaxed">
                        سلام! من دستیار ترجمه مانهوای شما هستم. هر سوالی در رابطه با اصطلاحات مانهواهای فانتزی، لحن گفتار کاراکترها یا عبارات بومی دارید بپرسید تا با هم بررسی کنیم.
                    </div>
                </div>

                <div class="pt-4 border-t border-white/5 flex gap-2">
                    <input type="text" id="chat-input-field" onkeypress="handleChatKeyPress(event)" placeholder="سوال خود را بنویسید..." class="flex-1 px-4 py-3 text-xs glass-input rounded-xl text-right">
                    <button onclick="sendMessageToAssistant()" class="px-5 py-3 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-xs font-bold transition-all flex items-center gap-1">
                        <i class="fa-solid fa-paper-plane"></i>ارسال
                    </button>
                </div>
            </div>
        </main>

        <!-- بخش تنظیمات پیشرفته و گروه‌بندی -->
        <main id="tab-settings" class="tab-content hidden space-y-6">
            <div class="glass-panel p-6 rounded-3xl max-w-2xl mx-auto space-y-6 shadow-xl">
                <h2 class="text-xs font-bold flex items-center gap-2 pb-4 border-b border-white/5">
                    <i class="fa-solid fa-sliders text-blue-400"></i>تنظیمات تخصصی محیط کاربری
                </h2>

                <!-- تم روشن و تیره -->
                <div class="flex justify-between items-center pb-5 border-b border-white/5">
                    <div>
                        <p class="font-bold text-xs">تم رنگی سیستم (روشن / تیره)</p>
                        <p class="text-[10px] text-[var(--text-muted)]">بهینه‌سازی شده برای استایل شیشه‌ای (Frosted Glass) آیفون</p>
                    </div>
                    <button onclick="toggleSystemTheme()" id="theme-toggle-btn" class="px-4 py-2.5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all text-xs flex items-center gap-2 font-bold">
                        <i class="fa-solid fa-moon"></i>حالت شب فعال است
                    </button>
                </div>

                <!-- انتخاب مدل حافظه متوالی -->
                <div class="pb-5 border-b border-white/5">
                    <p class="font-bold text-xs mb-1">مکانیزم حافظه یکپارچه هوش مصنوعی (AI Memory)</p>
                    <p class="text-[10px] text-[var(--text-muted)] mb-4">آیا دیالوگ‌های قبلی برای همگام‌سازی لحن در سگمنت جدید فرستاده شوند؟</p>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="p-3.5 glass-card rounded-xl cursor-pointer flex items-center gap-3">
                            <input type="radio" name="ai_memory" value="continuous" checked class="accent-purple-500">
                            <div>
                                <span class="block text-xs font-bold">حافظه متوالی (Continuous)</span>
                                <span class="block text-[9px] text-[var(--text-muted)]">ارسال دیالوگ‌های قبلی به هوش مصنوعی</span>
                            </div>
                        </label>
                        <label class="p-3.5 glass-card rounded-xl cursor-pointer flex items-center gap-3">
                            <input type="radio" name="ai_memory" value="isolated" class="accent-purple-500">
                            <div>
                                <span class="block text-xs font-bold">چت مستقل (Isolated)</span>
                                <span class="block text-[9px] text-[var(--text-muted)]">ترجمه هر سگمنت به صورت تکی</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- مدیریت پویای بازه چت‌های تفکیک‌شده -->
                <div>
                    <div class="flex justify-between items-center mb-2">
                        <div>
                            <p class="font-bold text-xs">گروه‌بندی سگمنت‌ها برای چت تفکیک‌شده</p>
                            <p class="text-[10px] text-[var(--text-muted)]">تعیین کنید کدام محدوده‌ها در چت متوالی یک تاریخچه متنی مشترک را استفاده کنند.</p>
                        </div>
                        <button onclick="addNewSegmentGroup()" class="px-3 py-1.5 bg-purple-500/10 hover:bg-purple-500/20 text-purple-400 border border-purple-500/10 rounded-lg text-[10px] font-bold flex items-center gap-1 transition-all">
                            <i class="fa-solid fa-plus"></i>گروه جدید
                        </button>
                    </div>

                    <div id="segment-groups-list" class="space-y-2 mt-3">
                        <!-- رندر داینامیک لیست گروه‌ها -->
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- ناوبری شناور گرد سبک آیفون (Circular Navigation) -->
    <div class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50">
        <div id="nav-container" class="nav-orb glass-panel flex items-center justify-center overflow-hidden shadow-2xl relative">
            <button id="nav-trigger" onclick="toggleNavigationMenu()" class="absolute inset-0 flex items-center justify-center text-white focus:outline-none z-10">
                <i class="fa-solid fa-compass text-2xl animate-spin-slow"></i>
            </button>
            <div id="nav-menu-items" class="hidden flex items-center justify-around w-full px-4 h-full">
                <button onclick="switchSystemTab('segments')" class="nav-link text-[10px] flex flex-col items-center gap-1 text-purple-400 font-bold transition-all">
                    <i class="fa-solid fa-layer-group text-base"></i>سگمنت‌ها
                </button>
                <button onclick="switchSystemTab('chat')" class="nav-link text-[10px] flex flex-col items-center gap-1 text-slate-400 hover:text-white transition-all">
                    <i class="fa-solid fa-comments text-base"></i>دستیار چت
                </button>
                <button onclick="switchSystemTab('settings')" class="nav-link text-[10px] flex flex-col items-center gap-1 text-slate-400 hover:text-white transition-all">
                    <i class="fa-solid fa-gears text-base"></i>تنظیمات
                </button>
                <button onclick="toggleNavigationMenu()" class="text-[10px] flex flex-col items-center gap-1 text-rose-400 hover:text-rose-300 transition-all">
                    <i class="fa-solid fa-circle-xmark text-base"></i>بستن
                </button>
            </div>
        </div>
    </div>

    <!-- مودال تنظیمات دسته‌ای و کلی چپتر -->
    <div id="global-settings-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="glass-panel max-w-xl w-full rounded-3xl p-6 shadow-2xl space-y-4">
            <div class="flex justify-between items-center border-b border-white/10 pb-3">
                <h3 class="text-xs font-bold flex items-center gap-2">
                    <i class="fa-solid fa-sliders text-purple-400"></i>تنظیمات دسته‌ای و همگانی چپتر
                </h3>
                <button onclick="closeGlobalSettings()" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <div class="space-y-3 text-right">
                <div>
                    <label class="block text-[10px] text-slate-300 mb-1">پرامپت پیش‌فرض ترجمه برای این گروه:</label>
                    <textarea id="global-prompt" class="w-full h-16 p-2.5 text-xs glass-input rounded-xl text-right" placeholder="مثال: لحن فانتزی و مانهوایی ترجمه شود..."></textarea>
                </div>
                <div>
                    <label class="block text-[10px] text-slate-300 mb-1">پرامپت ضد کلی (Negative Rules):</label>
                    <textarea id="global-negative-prompt" class="w-full h-16 p-2.5 text-xs glass-input rounded-xl text-right" placeholder="مثال: از کلمات نامانوس استفاده نشود..."></textarea>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[10px] text-slate-300 mb-1">مدل هوش مصنوعی:</label>
                        <select id="global-ai-version" class="w-full p-2.5 text-xs bg-slate-800 border border-white/10 rounded-xl focus:outline-none focus:border-purple-500 text-white">
                            <option value="gpt-4o">GPT-4o (پیشنهادی)</option>
                            <option value="gpt-4-turbo">GPT-4 Turbo</option>
                            <option value="claude-3-opus">Claude 3 Opus</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] text-slate-300 mb-1">بارگذاری دسته‌ای تصاویر مانهوا:</label>
                        <input type="file" id="bulk-images-input" multiple accept="image/*" class="hidden" onchange="handleBulkImagesUpload(event)">
                        <button onclick="document.getElementById('bulk-images-input').click()" class="w-full py-2.5 bg-blue-600/20 hover:bg-blue-600/30 border border-blue-500/20 rounded-xl text-xs text-blue-300 transition-all font-bold">
                            <i class="fa-solid fa-images ml-1"></i>انتخاب گروهی تصاویر
                        </button>
                    </div>
                </div>

                <div class="pt-3 border-t border-white/10">
                    <label class="block text-[10px] text-slate-300 mb-2">محدوده اعمال تنظیمات بر روی سگمنت‌های چپتر جاری:</label>
                    <div class="flex gap-2 items-center">
                        <span class="text-[10px]">از شماره:</span>
                        <input type="number" id="apply-range-start" placeholder="1" class="w-14 p-1.5 bg-white/5 border border-white/10 rounded-md text-center text-xs">
                        <span class="text-[10px]">تا شماره:</span>
                        <input type="number" id="apply-range-end" placeholder="آخر" class="w-14 p-1.5 bg-white/5 border border-white/10 rounded-md text-center text-xs">
                        <button onclick="applyGlobalSettingsToActiveSegments()" class="mr-auto px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-xs rounded-xl font-bold transition-all">
                            اعمال تنظیمات
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- مودال ترجمه خودکار پیاپی -->
    <div id="global-start-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="glass-panel max-w-xl w-full rounded-3xl p-6 shadow-2xl space-y-4">
            <div class="flex justify-between items-center border-b border-white/10 pb-3">
                <h3 class="text-xs font-bold flex items-center gap-2">
                    <i class="fa-solid fa-bolt text-pink-400"></i>ترجمه دسته‌جمعی و پیاپی سگمنت‌ها
                </h3>
                <button onclick="closeGlobalStartModal()" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <div class="space-y-4 text-right">
                <div class="bg-white/5 p-4 rounded-xl border border-white/10 space-y-3">
                    <p class="text-[10px] text-slate-300 leading-relaxed">سیستم سگمنت‌های انتخابی را به نوبت با تصاویرشان به API ارسال کرده و ترجمه را ثبت می‌کند. در صورت خالی بودن بازه، تمامی سگمنت‌های فعال چپتر ترجمه خواهند شد.</p>
                    <div class="flex gap-3 items-center">
                        <span class="text-xs">از سگمنت:</span>
                        <input type="number" id="run-range-start" class="w-20 p-1.5 bg-slate-950 border border-white/10 rounded-md text-center text-xs text-white">
                        <span class="text-xs">تا سگمنت:</span>
                        <input type="number" id="run-range-end" class="w-20 p-1.5 bg-slate-950 border border-white/10 rounded-md text-center text-xs text-white">
                    </div>
                    <button id="batch-process-btn" onclick="startBatchTranslationProcess()" class="w-full py-2.5 bg-gradient-to-r from-purple-600 to-pink-600 hover:opacity-95 text-white rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-2">
                        <i class="fa-solid fa-play"></i>شروع ترجمه خودکار
                    </button>
                </div>

                <!-- نوار پیشرفت -->
                <div id="batch-progress" class="hidden space-y-2">
                    <div class="flex justify-between text-xs font-bold">
                        <span id="batch-progress-text">در حال ارتباط با API...</span>
                        <span id="batch-progress-percent">0%</span>
                    </div>
                    <div class="w-full bg-slate-800 h-2 rounded-full overflow-hidden">
                        <div id="batch-progress-bar" class="bg-gradient-to-r from-purple-500 to-pink-500 h-full w-[0%] transition-all duration-300"></div>
                    </div>
                </div>

                <!-- پنل دانلود نهایی -->
                <div id="batch-success-actions" class="hidden space-y-2 pt-4 border-t border-white/10">
                    <p class="text-xs text-emerald-400 font-bold flex items-center gap-1.5">
                        <i class="fa-solid fa-circle-check"></i>ترجمه دسته‌ای پایان یافت.
                    </p>
                    <div class="grid grid-cols-2 gap-2">
                        <button onclick="copyAllCurrentTranslations()" class="py-2.5 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl text-xs flex items-center justify-center gap-1.5 transition-all font-bold">
                            <i class="fa-solid fa-copy text-purple-400"></i>کپی نسخه‌های فعال
                        </button>
                        <button onclick="downloadWordDocumentExport()" class="py-2.5 bg-blue-600 hover:bg-blue-700 rounded-xl text-xs text-white font-bold flex items-center justify-center gap-1.5 transition-all shadow-md">
                            <i class="fa-solid fa-file-word"></i>دانلود فایل ورد
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- لایت باکس تصاویر مانهوا -->
    <div id="lightbox" class="fixed inset-0 bg-black/95 backdrop-blur-md z-50 hidden flex items-center justify-center p-4" onclick="closeImageLightbox()">
        <button class="absolute top-6 right-6 text-white text-2xl hover:text-slate-300"><i class="fa-solid fa-xmark"></i></button>
        <div class="relative max-w-2xl w-full flex items-center justify-center" onclick="event.stopPropagation()">
            <button onclick="navigateLightboxImage('prev')" class="absolute left-[-40px] md:left-[-70px] text-white text-3xl hover:text-purple-400 transition-all"><i class="fa-solid fa-chevron-left"></i></button>
            <img id="lightbox-img" src="" alt="بزرگنمایی" class="max-h-[85vh] max-w-full rounded-xl shadow-2xl border border-white/10 object-contain">
            <button onclick="navigateLightboxImage('next')" class="absolute right-[-40px] md:right-[-70px] text-white text-3xl hover:text-purple-400 transition-all"><i class="fa-solid fa-chevron-right"></i></button>
        </div>
    </div>

    <!-- موتور جاوا اسکریپت هماهنگ‌ساز ابری -->
    <script>
        let chapters = [
            { id: 1, name: "چپتر ۱", segments: [] }
        ];
        let activeChapterId = 1;
        let activeLightboxIndex = 0;
        let autoSaveTimeout;

        let segmentGroups = [
            { id: 'group_1', name: "گروه اول (سگمنت ۱ تا ۱۰)", start: 1, end: 10 }
        ];

        // لود اولیه و اتصال ابری
        window.addEventListener('DOMContentLoaded', async () => {
            // بررسی اتصال دیتابیس Neon
            const dbConnected = <?= $db ? 'true' : 'false' ?>;
            const statusIndicator = document.getElementById('neon-status');
            const statusText = document.getElementById('neon-status').parentNode;
            
            if (dbConnected) {
                statusIndicator.className = "w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse";
                statusText.innerHTML = '<span id="neon-status" class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> دیتابیس ابری Neon متصل است';
                // بارگذاری داده‌ها از Neon
                await loadDataFromCloud();
            } else {
                statusIndicator.className = "w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse";
                statusText.innerHTML = '<span id="neon-status" class="w-2.5 h-2.5 rounded-full bg-amber-500"></span> دیتابیس متصل نیست (حافظه موقت مرورگر)';
                bootstrapDefaultState();
            }
            
            renderChaptersTab();
            renderSegmentGroupsInSettings();
            renderActiveSegments();
        });

        function bootstrapDefaultState() {
            chapters = [{ id: 1, name: "چپتر ۱", segments: [] }];
            addNewSegmentToActiveChapter();
        }

        // بارگذاری داده‌ها از Neon
        async function loadDataFromCloud() {
            try {
                showSaveStatus('syncing');
                const response = await fetch('?action=load_state', { method: 'POST' });
                const res = await response.json();
                if (res.success && res.chapters && res.chapters.length > 0) {
                    chapters = res.chapters;
                    showSaveStatus('synced');
                } else {
                    bootstrapDefaultState();
                }
            } catch (err) {
                console.error("خطا در همگام‌سازی اولیه:", err);
                showSaveStatus('error');
                bootstrapDefaultState();
            }
        }

        // مکانیزم ذخیره‌سازی خودکار هوشمند ابری (با ایجاد تاخیر برای تجمیع تغییرات)
        function triggerCloudAutoSave() {
            showSaveStatus('saving');
            clearTimeout(autoSaveTimeout);
            autoSaveTimeout = setTimeout(async () => {
                try {
                    const response = await fetch('?action=save_state', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ chapters: chapters })
                    });
                    const res = await response.json();
                    if (res.success) {
                        showSaveStatus('saved');
                    } else {
                        showSaveStatus('error');
                    }
                } catch (err) {
                    showSaveStatus('error');
                }
            }, 1500); // 1.5 ثانیه تاخیر پس از آخرین ویرایش
        }

        // مدیریت نمایش وضعیت ذخیره‌سازی
        function showSaveStatus(status) {
            const pill = document.getElementById('save-status-pill');
            const icon = document.getElementById('save-status-icon');
            const text = document.getElementById('save-status-text');

            if (status === 'syncing') {
                pill.className = "px-3 py-1.5 rounded-full text-[10px] font-semibold bg-blue-500/10 border border-blue-500/20 text-blue-400 flex items-center gap-1.5";
                icon.className = "fa-solid fa-spinner animate-spin";
                text.innerText = "در حال بارگذاری...";
            } else if (status === 'saving') {
                pill.className = "px-3 py-1.5 rounded-full text-[10px] font-semibold bg-yellow-500/10 border border-yellow-500/20 text-yellow-400 flex items-center gap-1.5";
                icon.className = "fa-solid fa-rotate animate-spin";
                text.innerText = "در حال ذخیره‌سازی...";
            } else if (status === 'saved' || status === 'synced') {
                pill.className = "px-3 py-1.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center gap-1.5";
                icon.className = "fa-solid fa-circle-check";
                text.innerText = "ذخیره در ابر Neon";
            } else {
                pill.className = "px-3 py-1.5 rounded-full text-[10px] font-semibold bg-rose-500/10 border border-rose-500/20 text-rose-400 flex items-center gap-1.5";
                icon.className = "fa-solid fa-cloud-showers-water";
                text.innerText = "عدم اتصال ابری";
            }
        }

        function getChapterById(id) {
            return chapters.find(c => c.id === id);
        }

        function addNewChapter() {
            const nextId = chapters.length > 0 ? Math.max(...chapters.map(c => c.id)) + 1 : 1;
            chapters.push({
                id: nextId,
                name: `چپتر ${nextId}`,
                segments: [{
                    id: 1,
                    image: '',
                    prompt: document.getElementById('global-prompt').value || '',
                    negativePrompt: document.getElementById('global-negative-prompt').value || '',
                    aiVersion: document.getElementById('global-ai-version').value || 'gpt-4o',
                    translations: [],
                    currentVersionIndex: -1
                }]
            });
            activeChapterId = nextId;
            renderChaptersTab();
            renderActiveSegments();
            triggerCloudAutoSave();
        }

        function deleteChapter(id, event) {
            event.stopPropagation();
            if (chapters.length <= 1) {
                alert("پروژه باید حداقل شامل یک چپتر فعال باشد.");
                return;
            }
            if (!confirm("آیا مایل به حذف کامل این چپتر هستید؟")) return;
            chapters = chapters.filter(c => c.id !== id);
            if (activeChapterId === id) {
                activeChapterId = chapters[0].id;
            }
            renderChaptersTab();
            renderActiveSegments();
            triggerCloudAutoSave();
        }

        function selectActiveChapter(id) {
            activeChapterId = id;
            renderChaptersTab();
            renderActiveSegments();
        }

        function renderChaptersTab() {
            const container = document.getElementById('chapters-bar-container');
            container.innerHTML = '';

            chapters.forEach(chap => {
                const isActive = chap.id === activeChapterId;
                const activeClasses = isActive 
                    ? "bg-gradient-to-r from-purple-600 to-pink-600 text-white font-bold shadow-md" 
                    : "bg-white/5 text-[var(--text-muted)] hover:bg-white/10 border border-white/5";

                const btnHtml = `
                    <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs shrink-0 cursor-pointer ${activeClasses}" onclick="selectActiveChapter(${chap.id})">
                        <span>${chap.name}</span>
                        <button onclick="deleteChapter(${chap.id}, event)" class="w-4 h-4 rounded-full bg-black/20 hover:bg-rose-600/60 flex items-center justify-center text-[8px] text-white">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                `;
                container.insertAdjacentHTML('beforeend', btnHtml);
            });

            const addBtnHtml = `
                <button onclick="addNewChapter()" class="px-3 py-1.5 rounded-full text-xs bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 border border-emerald-500/10 shrink-0 transition-all flex items-center gap-1 font-bold">
                    <i class="fa-solid fa-plus"></i>چپتر جدید
                </button>
            `;
            container.insertAdjacentHTML('beforeend', addBtnHtml);
        }

        function addSegment() {
            addNewSegmentToActiveChapter();
            renderActiveSegments();
            triggerCloudAutoSave();
        }

        function addNewSegmentToActiveChapter() {
            const activeChap = getChapterById(activeChapterId);
            const newId = activeChap.segments.length > 0 ? Math.max(...activeChap.segments.map(s => s.id)) + 1 : 1;
            activeChap.segments.push({
                id: newId,
                image: '',
                prompt: document.getElementById('global-prompt').value || '',
                negativePrompt: document.getElementById('global-negative-prompt').value || '',
                aiVersion: document.getElementById('global-ai-version').value || 'gpt-4o',
                translations: [],
                currentVersionIndex: -1
            });
        }

        function removeSegment(id) {
            const activeChap = getChapterById(activeChapterId);
            if (activeChap.segments.length <= 1) {
                alert("چپتر شما باید حداقل دارای یک سگمنت باشد.");
                return;
            }
            activeChap.segments = activeChap.segments.filter(s => s.id !== id);
            renderActiveSegments();
            triggerCloudAutoSave();
        }

        function duplicateSegment(id) {
            const activeChap = getChapterById(activeChapterId);
            const original = activeChap.segments.find(s => s.id === id);
            if (!original) return;
            const newId = Math.max(...activeChap.segments.map(s => s.id)) + 1;
            const duplicate = {
                ...JSON.parse(JSON.stringify(original)),
                id: newId,
                image: '' // تصویر کپی نمی‌شود جهت سبکی
            };
            activeChap.segments.push(duplicate);
            renderActiveSegments();
            triggerCloudAutoSave();
        }

        function renderActiveSegments() {
            const container = document.getElementById('segments-container');
            container.innerHTML = '';

            const activeChap = getChapterById(activeChapterId);
            activeChap.segments.forEach((seg, index) => {
                const currentTranslation = seg.translations.length > 0 ? seg.translations[seg.currentVersionIndex] : 'هنوز ترجمه‌ای دریافت نشده است.';
                const hasHistory = seg.translations.length > 1;

                const cardHtml = `
                    <div class="glass-card rounded-2xl p-5 flex flex-col md:flex-row gap-5 items-stretch relative" data-id="${seg.id}">
                        <div class="absolute top-4 left-4 flex gap-1.5 z-10">
                            <button onclick="duplicateSegment(${seg.id})" class="w-7 h-7 bg-white/5 hover:bg-white/10 rounded-lg text-slate-300 flex items-center justify-center text-xs transition-all" title="تکثیر این سگمنت">
                                <i class="fa-solid fa-clone"></i>
                            </button>
                            <button onclick="removeSegment(${seg.id})" class="w-7 h-7 bg-rose-500/10 hover:bg-rose-500/20 rounded-lg text-rose-400 flex items-center justify-center text-xs transition-all" title="حذف">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>

                        <!-- تصویر موقت سگمنت -->
                        <div class="w-full md:w-52 flex flex-col gap-2 justify-center items-center bg-black/25 rounded-xl p-3 border border-white/5 min-h-[170px] relative">
                            ${seg.image ? `
                                <img src="${seg.image}" onclick="openImageLightbox(${index})" class="max-h-40 w-full object-cover rounded-lg cursor-pointer hover:opacity-90 transition-all shadow-md">
                                <button onclick="clearSegmentImage(${seg.id})" class="absolute bottom-4 right-4 bg-black/70 hover:bg-black/90 px-2 py-1 rounded text-[10px] text-rose-300"><i class="fa-solid fa-trash-can"></i> حذف عکس</button>
                            ` : `
                                <i class="fa-solid fa-file-image text-3xl text-slate-500 mb-2"></i>
                                <span class="text-[10px] text-[var(--text-muted)] text-center">عکسی وارد نشده است</span>
                                <input type="file" accept="image/*" onchange="uploadSegmentImage(event, ${seg.id})" class="absolute inset-0 opacity-0 cursor-pointer">
                                <button class="mt-2 px-3 py-1 bg-white/5 border border-white/10 rounded-lg text-[10px] text-slate-300 font-bold">انتخاب تصویر</button>
                            `}
                        </div>

                        <!-- متون و تنظیمات هوش مصنوعی -->
                        <div class="flex-1 flex flex-col gap-3 justify-between">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-right">
                                <div>
                                    <label class="block text-[10px] text-[var(--text-muted)] mb-1">دستور ترجمه هوشمند (Prompt):</label>
                                    <input type="text" value="${seg.prompt}" oninput="updateSegmentField(${seg.id}, 'prompt', this.value); triggerCloudAutoSave();" class="w-full px-3 py-2 text-xs glass-input rounded-xl text-right">
                                </div>
                                <div>
                                    <label class="block text-[10px] text-[var(--text-muted)] mb-1">پرامپت ضد (Negative Rules):</label>
                                    <input type="text" value="${seg.negativePrompt}" oninput="updateSegmentField(${seg.id}, 'negativePrompt', this.value); triggerCloudAutoSave();" class="w-full px-3 py-2 text-xs glass-input rounded-xl text-right">
                                </div>
                            </div>

                            <!-- بخش خروجی ترجمه با قابلیت ادیت مستقیم -->
                            <div class="relative bg-[var(--segment-box-bg)] border border-white/5 rounded-xl p-3 text-right min-h-[90px] flex flex-col justify-between">
                                <div class="text-[9px] text-[var(--text-muted)] mb-1 font-bold flex items-center gap-1">
                                    <i class="fa-solid fa-pen-to-square"></i>نتیجه نهایی ترجمه (قابل ویرایش مستقیم):
                                </div>
                                <div contenteditable="true" onblur="updateSegmentTranslationContent(${seg.id}, this.innerText); triggerCloudAutoSave();" class="text-xs text-[var(--text-primary)] leading-relaxed mb-4 outline-none focus:ring-1 focus:ring-purple-500 rounded p-1 transition-all">
                                    ${currentTranslation}
                                </div>
                                
                                <div class="flex justify-between items-center pt-2 border-t border-white/5">
                                    <div class="flex items-center gap-1.5 text-[10px] text-[var(--text-muted)]">
                                        <span>نسخه مدل:</span>
                                        <select onchange="updateSegmentField(${seg.id}, 'aiVersion', this.value); triggerCloudAutoSave();" class="bg-slate-850 text-white border border-white/10 rounded px-1.5 py-0.5 text-[10px]">
                                            <option value="gpt-4o" ${seg.aiVersion === 'gpt-4o' ? 'selected' : ''}>GPT-4o</option>
                                            <option value="gpt-4-turbo" ${seg.aiVersion === 'gpt-4-turbo' ? 'selected' : ''}>GPT-4 Turbo</option>
                                            <option value="claude-3-opus" ${seg.aiVersion === 'claude-3-opus' ? 'selected' : ''}>Claude 3 Opus</option>
                                        </select>
                                    </div>

                                    <!-- نسخه‌های ترجمه ثبت شده -->
                                    <div class="flex items-center gap-2">
                                        ${hasHistory ? `
                                            <button onclick="changeSegmentTranslationVersion(${seg.id}, -1)" class="w-5 h-5 bg-white/5 hover:bg-white/10 border border-white/10 rounded flex items-center justify-center text-[10px] text-slate-300"><i class="fa-solid fa-arrow-right"></i></button>
                                            <span class="text-[9px] text-[var(--text-muted)]">${seg.currentVersionIndex + 1} از ${seg.translations.length}</span>
                                            <button onclick="changeSegmentTranslationVersion(${seg.id}, 1)" class="w-5 h-5 bg-white/5 hover:bg-white/10 border border-white/10 rounded flex items-center justify-center text-[10px] text-slate-300"><i class="fa-solid fa-arrow-left"></i></button>
                                        ` : ''}
                                        <button onclick="translateSingleSegment(${seg.id})" class="px-3 py-1 bg-purple-600/30 hover:bg-purple-600/50 border border-purple-500/30 rounded-lg text-[10px] text-purple-200 transition-all flex items-center gap-1 font-bold">
                                            <i class="fa-solid fa-wand-magic-sparkles"></i>ترجمه نسخه جدید
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                container.insertAdjacentHTML('beforeend', cardHtml);
            });
        }

        function updateSegmentField(id, field, value) {
            const activeChap = getChapterById(activeChapterId);
            const seg = activeChap.segments.find(s => s.id === id);
            if (seg) seg[field] = value;
        }

        function updateSegmentTranslationContent(id, newText) {
            const activeChap = getChapterById(activeChapterId);
            const seg = activeChap.segments.find(s => s.id === id);
            if (seg) {
                if (seg.translations.length === 0) {
                    seg.translations.push(newText);
                    seg.currentVersionIndex = 0;
                } else {
                    seg.translations[seg.currentVersionIndex] = newText;
                }
            }
        }

        function uploadSegmentImage(event, id) {
            const file = event.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = function(e) {
                const activeChap = getChapterById(activeChapterId);
                const seg = activeChap.segments.find(s => s.id === id);
                if (seg) {
                    seg.image = e.target.result;
                    renderActiveSegments();
                }
            }
            reader.readAsDataURL(file);
        }

        function clearSegmentImage(id) {
            const activeChap = getChapterById(activeChapterId);
            const seg = activeChap.segments.find(s => s.id === id);
            if (seg) {
                seg.image = '';
                renderActiveSegments();
            }
        }

        function changeSegmentTranslationVersion(id, direction) {
            const activeChap = getChapterById(activeChapterId);
            const seg = activeChap.segments.find(s => s.id === id);
            if (!seg) return;
            const newIndex = seg.currentVersionIndex + direction;
            if (newIndex >= 0 && newIndex < seg.translations.length) {
                seg.currentVersionIndex = newIndex;
                renderActiveSegments();
                triggerCloudAutoSave();
            }
        }

        async function translateSingleSegment(id) {
            const activeChap = getChapterById(activeChapterId);
            const seg = activeChap.segments.find(s => s.id === id);
            if (!seg) return;

            // تشخیص پویای گروه سگمنت فعلی بر اساس ایندکس در آرایه
            const absoluteIndex = activeChap.segments.findIndex(s => s.id === id) + 1;
            const targetGroup = segmentGroups.find(g => absoluteIndex >= g.start && absoluteIndex <= g.end);
            const groupId = targetGroup ? targetGroup.id : 'default';

            const selectedMemory = document.querySelector('input[name="ai_memory"]:checked').value;

            try {
                const response = await fetch('?action=translate', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        segmentId: absoluteIndex,
                        prompt: seg.prompt,
                        negativePrompt: seg.negativePrompt,
                        aiModel: seg.aiVersion,
                        memoryMode: selectedMemory,
                        groupId: groupId,
                        image: seg.image // فرستادن مستقیم عکس کلاینت به API بک‌اند
                    })
                });
                const res = await response.json();
                if (res.success) {
                    seg.translations.push(res.translation);
                    seg.currentVersionIndex = seg.translations.length - 1;
                    renderActiveSegments();
                    triggerCloudAutoSave();
                }
            } catch (err) {
                console.error("خطا در ترجمه:", err);
            }
        }

        function toggleNavigationMenu() {
            const container = document.getElementById('nav-container');
            const trigger = document.getElementById('nav-trigger');
            const items = document.getElementById('nav-menu-items');

            if (container.classList.contains('expanded')) {
                container.classList.remove('expanded');
                items.classList.add('hidden');
                trigger.classList.remove('hidden');
            } else {
                container.classList.add('expanded');
                trigger.classList.add('hidden');
                items.classList.remove('hidden');
            }
        }

        function switchSystemTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.add('hidden'));
            document.getElementById('tab-' + tabName).classList.remove('hidden');
            toggleNavigationMenu();
        }

        function openGlobalSettings() { document.getElementById('global-settings-modal').classList.remove('hidden'); }
        function closeGlobalSettings() { document.getElementById('global-settings-modal').classList.add('hidden'); }
        function openGlobalStartModal() { document.getElementById('global-start-modal').classList.remove('hidden'); }
        
        function closeGlobalStartModal() { 
            document.getElementById('global-start-modal').classList.add('hidden'); 
            document.getElementById('batch-progress').classList.add('hidden');
            document.getElementById('batch-success-actions').classList.add('hidden');
        }

        function handleBulkImagesUpload(event) {
            const files = Array.from(event.target.files);
            if (files.length === 0) return;
            
            files.sort((a, b) => a.name.localeCompare(b.name, undefined, {numeric: true, sensitivity: 'base'}));

            const activeChap = getChapterById(activeChapterId);
            if (activeChap.segments.length === 1 && activeChap.segments[0].image === '' && activeChap.segments[0].translations.length === 0) {
                activeChap.segments = [];
            }

            let loaded = 0;
            files.forEach(file => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const newId = activeChap.segments.length > 0 ? Math.max(...activeChap.segments.map(s => s.id)) + 1 : 1;
                    activeChap.segments.push({
                        id: newId,
                        image: e.target.result,
                        prompt: document.getElementById('global-prompt').value || '',
                        negativePrompt: document.getElementById('global-negative-prompt').value || '',
                        aiVersion: document.getElementById('global-ai-version').value || 'gpt-4o',
                        translations: [],
                        currentVersionIndex: -1
                    });
                    loaded++;
                    if (loaded === files.length) {
                        renderActiveSegments();
                        triggerCloudAutoSave();
                        alert(`تعداد ${files.length} سگمنت تصویری ایجاد شد.`);
                        closeGlobalSettings();
                    }
                }
                reader.readAsDataURL(file);
            });
        }

        function applyGlobalSettingsToActiveSegments() {
            const startRange = parseInt(document.getElementById('apply-range-start').value) || 1;
            const activeChap = getChapterById(activeChapterId);
            const endRange = parseInt(document.getElementById('apply-range-end').value) || activeChap.segments.length;

            const gPrompt = document.getElementById('global-prompt').value;
            const gNegPrompt = document.getElementById('global-negative-prompt').value;
            const gAiVer = document.getElementById('global-ai-version').value;

            activeChap.segments.forEach((seg, index) => {
                const absoluteIndex = index + 1;
                if (absoluteIndex >= startRange && absoluteIndex <= endRange) {
                    if (gPrompt) seg.prompt = gPrompt;
                    if (gNegPrompt) seg.negativePrompt = gNegPrompt;
                    if (gAiVer) seg.aiVersion = gAiVer;
                }
            });

            renderActiveSegments();
            triggerCloudAutoSave();
            alert("تنظیمات چپتر با موفقیت اعمال و در Neon همگام شد.");
            closeGlobalSettings();
        }

        async function startBatchTranslationProcess() {
            const activeChap = getChapterById(activeChapterId);
            const runStart = parseInt(document.getElementById('run-range-start').value) || 1;
            const runEnd = parseInt(document.getElementById('run-range-end').value) || activeChap.segments.length;

            const runSegments = activeChap.segments.filter((seg, index) => {
                const num = index + 1;
                return num >= runStart && num <= runEnd;
            });

            if (runSegments.length === 0) {
                alert("سگمنتی برای ترجمه در این بازه یافت نشد.");
                return;
            }

            document.getElementById('batch-progress').classList.remove('hidden');
            document.getElementById('batch-success-actions').classList.add('hidden');

            const total = runSegments.length;
            let completed = 0;

            for (let seg of runSegments) {
                document.getElementById('batch-progress-text').innerText = `در حال پردازش سگمنت شماره ${seg.id}...`;
                await translateSingleSegment(seg.id);
                completed++;
                let percent = Math.round((completed / total) * 100);
                document.getElementById('batch-progress-bar').style.width = percent + '%';
                document.getElementById('batch-progress-percent').innerText = percent + '%';
            }

            document.getElementById('batch-progress-text').innerText = `عملیات پایان یافت.`;
            document.getElementById('batch-success-actions').classList.remove('hidden');
        }

        // کپی هوشمند دیالوگ‌های نسخه‌های انتخابی
        function copyAllCurrentTranslations() {
            let compiledText = "";
            const activeChap = getChapterById(activeChapterId);
            activeChap.segments.forEach((seg, index) => {
                const text = seg.translations.length > 0 ? seg.translations[seg.currentVersionIndex] : 'بدون ترجمه';
                compiledText += `سگمنت شماره ${index + 1}:\n${text}\n\n`;
            });
            
            navigator.clipboard.writeText(compiledText).then(() => {
                alert("تمامی متون انتخاب شده کپی شدند.");
            });
        }

        // خروجی نهایی ورد با ارجاع دقیق به نسخه‌های فعال چپترها
        function downloadWordDocumentExport() {
            const cleanChapters = chapters.map(chap => ({
                name: chap.name,
                segments: chap.segments.map(seg => ({
                    translations: seg.translations,
                    currentVersionIndex: seg.currentVersionIndex
                }))
            }));

            const xhr = new XMLHttpRequest();
            xhr.open("POST", "?action=export_docx", true);
            xhr.setRequestHeader("Content-Type", "application/json");
            xhr.responseType = "blob";
            xhr.onload = function() {
                if (this.status === 200) {
                    const blob = this.response;
                    const link = document.createElement('a');
                    link.href = window.URL.createObjectURL(blob);
                    link.download = "manhwa_translation_selected.doc";
                    link.click();
                }
            };
            xhr.send(JSON.stringify({ chapters: cleanChapters }));
        }

        async function sendMessageToAssistant() {
            const inputField = document.getElementById('chat-input-field');
            const message = inputField.value.trim();
            if (!message) return;

            appendChatMessage('user', message);
            inputField.value = '';

            try {
                const response = await fetch('?action=chat_assistant', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ message: message })
                });
                const res = await response.json();
                if (res.success) {
                    appendChatMessage('assistant', res.reply);
                }
            } catch (err) {
                console.error("خطا در دریافت پاسخ دستیار:", err);
            }
        }

        function handleChatKeyPress(event) {
            if (event.key === 'Enter') {
                sendMessageToAssistant();
            }
        }

        function appendChatMessage(role, text) {
            const container = document.getElementById('chat-messages-container');
            const alignment = role === 'user' ? 'mr-auto bg-purple-600/30 text-purple-100' : 'ml-auto bg-white/5 text-[var(--text-primary)]';
            
            const messageHtml = `
                <div class="border border-white/5 rounded-2xl p-3 max-w-[85%] text-xs leading-relaxed ${alignment}">
                    ${text}
                </div>
            `;
            container.insertAdjacentHTML('beforeend', messageHtml);
            container.scrollTop = container.scrollHeight;
        }

        function renderSegmentGroupsInSettings() {
            const container = document.getElementById('segment-groups-list');
            container.innerHTML = '';

            segmentGroups.forEach((group, index) => {
                const groupHtml = `
                    <div class="flex gap-2 items-center bg-white/5 p-2 rounded-xl border border-white/5">
                        <span class="text-[10px] text-[var(--text-muted)] shrink-0">بازه ${index + 1}:</span>
                        <input type="number" value="${group.start}" onchange="updateGroupRange(${index}, 'start', this.value)" class="w-14 p-1 text-xs bg-black/30 border border-white/10 rounded-md text-center text-white" placeholder="شروع">
                        <span class="text-[10px] text-[var(--text-muted)]">تا</span>
                        <input type="number" value="${group.end}" onchange="updateGroupRange(${index}, 'end', this.value)" class="w-14 p-1 text-xs bg-black/30 border border-white/10 rounded-md text-center text-white" placeholder="پایان">
                        <button onclick="removeSegmentGroup(${index})" class="mr-auto p-1.5 bg-rose-500/20 hover:bg-rose-500/30 text-rose-300 rounded-md text-[10px] transition-all">
                            <i class="fa-solid fa-trash-can"></i>
                        </button>
                    </div>
                `;
                container.insertAdjacentHTML('beforeend', groupHtml);
            });
        }

        function addNewSegmentGroup() {
            const nextStart = segmentGroups.length > 0 ? Math.max(...segmentGroups.map(g => g.end)) + 1 : 1;
            const nextEnd = nextStart + 9;
            segmentGroups.push({
                id: 'group_' + (segmentGroups.length + 1),
                name: `گروه سگمنت ${nextStart} تا ${nextEnd}`,
                start: nextStart,
                end: nextEnd
            });
            renderSegmentGroupsInSettings();
        }

        function removeSegmentGroup(index) {
            segmentGroups.splice(index, 1);
            renderSegmentGroupsInSettings();
        }

        function updateGroupRange(index, field, value) {
            segmentGroups[index][field] = parseInt(value) || 0;
        }

        function toggleSystemTheme() {
            const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
            const targetTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            document.documentElement.setAttribute('data-theme', targetTheme);
            
            const btn = document.getElementById('theme-toggle-btn');
            if (targetTheme === 'light') {
                btn.innerHTML = `<i class="fa-solid fa-sun text-amber-500"></i> حالت روز فعال است`;
            } else {
                btn.innerHTML = `<i class="fa-solid fa-moon"></i> حالت شب فعال است`;
            }
        }

        function openImageLightbox(index) {
            activeLightboxIndex = index;
            const activeChap = getChapterById(activeChapterId);
            const seg = activeChap.segments[index];
            if (seg && seg.image) {
                document.getElementById('lightbox-img').src = seg.image;
                document.getElementById('lightbox').classList.remove('hidden');
            }
        }

        function closeImageLightbox() {
            document.getElementById('lightbox').classList.add('hidden');
        }

        function navigateLightboxImage(direction) {
            const activeChap = getChapterById(activeChapterId);
            const len = activeChap.segments.length;

            if (direction === 'next') {
                activeLightboxIndex = (activeLightboxIndex + 1) % len;
            } else {
                activeLightboxIndex = (activeLightboxIndex - 1 + len) % len;
            }

            const seg = activeChap.segments[activeLightboxIndex];
            if (seg && seg.image) {
                document.getElementById('lightbox-img').src = seg.image;
            } else {
                navigateLightboxImage(direction);
            }
        }
    </script>
</body>
</html>
