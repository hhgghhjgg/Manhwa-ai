<?php
/**
 * Project: Arvan Create Bot Maker Platform (Super Uploader Engine)
 * File: child_uploader/layout_renderer.php
 * Role: Generic UI Helper Utility for Plugins (Zero Business Logic)
 */

// جلوگیری از دسترسی مستقیم به این فایل بدون بارگذاری هسته اصلی ربات
if (!defined('MASTER_BOT_TOKEN')) {
    exit;
}

class LayoutRenderer {

    /**
     * چیدن خودکار یک آرایه مسطح از دکمه‌ها در یک گرید ستونی منظم (Dynamic Grid Maker)
     * 
     * @param array $flatButtons لیست دکمه‌ها به صورت یک‌بعدی
     * @param int $columns تعداد ستون‌های مورد نظر در هر ردیف
     * @return array آرایه دو بعدی مناسب برای inline_keyboard تلگرام
     */
    public static function makeGrid($flatButtons, $columns = 2) {
        $grid = [];
        $row = [];
        
        foreach ($flatButtons as $button) {
            $row[] = $button;
            if (count($row) === $columns) {
                $grid[] = $row;
                $row = [];
            }
        }
        
        if (!empty($row)) {
            $grid[] = $row;
        }
        
        return $grid;
    }

    /**
     * ساخت خودکار ردیف دکمه‌های ناوبری صفحات (Pagination Row Builder)
     * 
     * @param int $currentPage صفحه فعلی
     * @param int $totalPages کل صفحات
     * @param string $callbackPrefix پیشوند کالبک افزونه (مثلاً 'def_chapters_list_12')
     * @return array ردیف دکمه‌های قبلی و بعدی متناسب با موقعیت صفحه
     */
    public static function makePaginationRow($currentPage, $totalPages, $callbackPrefix) {
        $navRow = [];
        
        if ($currentPage > 1) {
            $navRow[] = [
                'text' => '◀️ قبلی', 
                'callback_data' => $callbackPrefix . "_" . ($currentPage - 1)
            ];
        }
        
        if ($currentPage < $totalPages) {
            $navRow[] = [
                'text' => 'بعدی ▶️', 
                'callback_data' => $callbackPrefix . "_" . ($currentPage + 1)
            ];
        }
        
        return $navRow;
    }

    /**
     * متد عمومی جهت خواندن نوع ربات برای روت‌های کلی ادمین
     */
    public static function getBotContentType($db, $botId) {
        try {
            $stmt = $db->prepare("SELECT bot_content_type FROM bots WHERE id = :bot_id LIMIT 1");
            $stmt->execute(['bot_id' => $botId]);
            $row = $stmt->fetch();
            return $row ? ($row['bot_content_type'] ?? 'manhwa') : 'manhwa';
        } catch (PDOException $e) {
            error_log("Error in LayoutRenderer::getBotContentType: " . $e->getMessage());
            return 'manhwa';
        }
    }
}
