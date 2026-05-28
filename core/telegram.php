<?php
/**
 * Project: Manhwa Team Telegram Bot Maker (Multi-Tenant Engine)
 * File: core/telegram.php
 * Role: Object-Oriented Telegram Bot API Helper Class
 */

class Telegram {
    private $token;
    private $apiUrl;

    /**
     * سازنده کلاس - مقداردهی اولیه با توکن ربات جاری
     * 
     * @param string $token توکن ربات تلگرام
     */
    public function __construct($token) {
        $this->token = $token;
        $this->apiUrl = "https://api.telegram.org/bot" . $token . "/";
    }

    /**
     * متد پایه و همه‌منظوره جهت ارسال درخواست به تلگرام با cURL به صورت JSON
     * 
     * @param string $method متد مورد نظر تلگرام (مانند sendMessage)
     * @param array $parameters آرایه پارامترهای ارسالی
     * @return array|bool پاسخ برگشتی تلگرام به صورت آرایه یا false در صورت بروز خطا
     */
    public function apiRequest($method, $parameters = []) {
        $url = $this->apiUrl . $method;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json"
        ]);
        
        $result = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($error) {
            error_log("cURL Error in method '{$method}': " . $error);
            return false;
        }
        
        $response = json_decode($result, true);
        if ($httpCode !== 200 || !isset($response['ok']) || !$response['ok']) {
            error_log("Telegram API Error in method '{$method}': " . $result);
            return $response;
        }
        
        return $response;
    }

    /**
     * ارسال پیام متنی
     */
    public function sendMessage($chatId, $text, $keyboard = null, $parseMode = 'HTML') {
        $data = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => $parseMode
        ];
        if ($keyboard) {
            $data['reply_markup'] = $keyboard;
        }
        return $this->apiRequest('sendMessage', $data);
    }

    /**
     * ارسال تصویر (کاور مانهوا و غیره) با پشتیبانی از file_id تلگرام
     */
    public function sendPhoto($chatId, $photo, $caption = '', $keyboard = null, $parseMode = 'HTML') {
        $data = [
            'chat_id'    => $chatId,
            'photo'      => $photo,
            'caption'    => $caption,
            'parse_mode' => $parseMode
        ];
        if ($keyboard) {
            $data['reply_markup'] = $keyboard;
        }
        return $this->apiRequest('sendPhoto', $data);
    }

    /**
     * ارسال فایل داکیومنت (فایل تست خام یا تست حل شده) با پشتیبانی از file_id
     */
    public function sendDocument($chatId, $document, $caption = '', $keyboard = null, $parseMode = 'HTML') {
        $data = [
            'chat_id'    => $chatId,
            'document'   => $document,
            'caption'    => $caption,
            'parse_mode' => $parseMode
        ];
        if ($keyboard) {
            $data['reply_markup'] = $keyboard;
        }
        return $this->apiRequest('sendDocument', $data);
    }

    /**
     * پاسخ به رویداد دکمه‌های شیشه‌ای (Callback Query) جهت برطرف کردن حالت لودینگ دکمه
     */
    public function answerCallbackQuery($callbackQueryId, $text = '', $showAlert = false) {
        $data = [
            'callback_query_id' => $callbackQueryId,
            'show_alert'        => $showAlert
        ];
        if (!empty($text)) {
            $data['text'] = $text;
        }
        return $this->apiRequest('answerCallbackQuery', $data);
    }

    /**
     * ویرایش متن یک پیام ارسال شده (برای ساخت منوهای داینامیک شیشه‌ای)
     */
    public function editMessageText($chatId, $messageId, $text, $keyboard = null, $parseMode = 'HTML') {
        $data = [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => $parseMode
        ];
        if ($keyboard) {
            $data['reply_markup'] = $keyboard;
        }
        return $this->apiRequest('editMessageText', $data);
    }

    /**
     * حذف یک پیام
     */
    public function deleteMessage($chatId, $messageId) {
        return $this->apiRequest('deleteMessage', [
            'chat_id'    => $chatId,
            'message_id' => $messageId
        ]);
    }

    /**
     * تولید لینک دعوت یک‌بار مصرف برای گروه‌ها/کانال‌های مانهوا
     */
    public function createChatInviteLink($chatId, $expireSeconds = 86400) {
        $data = [
            'chat_id'      => $chatId,
            'member_limit' => 1,                  // محدود کردن ظرفیت عضویت به دقیقاً ۱ نفر
            'expire_date'  => time() + $expireSeconds // زمان انقضای پیش‌فرض ۲۴ ساعت آینده
        ];
        $response = $this->apiRequest('createChatInviteLink', $data);
        return $response['result']['invite_link'] ?? null;
    }

    /**
     * بررسی معتبر بودن توکن (استفاده در ربات مادر جهت تست سالم بودن توکن‌های ورودی کاربران)
     */
    public function getMe() {
        return $this->apiRequest('getMe');
    }

    /**
     * تنظیم وب‌هوک برای ربات‌های جدیدی که کاربران می‌سازند
     */
    public function setWebhook($url) {
        return $this->apiRequest('setWebhook', [
            'url' => $url
        ]);
    }
}
