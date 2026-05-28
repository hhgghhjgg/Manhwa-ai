# Dockerfile
# استفاده از تصویر رسمی پایدار پی‌اچ‌پی نسخه 8.2 به همراه وب‌سرور آپاچی
FROM php:8.2-apache

# ۱. به‌روزرسانی مخازن لینوکس و نصب لایبرری‌های سیستمی مورد نیاز برای PostgreSQL
# سپس کامپایل و نصب افزونه‌های pdo و pdo_pgsql در PHP کانتینر
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    # پاک‌سازی فایل‌های اضافی کش لینوکس جهت سبک نگه داشتن حجم نهایی ایمیج داکر
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# ۲. فعال‌سازی ماژول کاربردی mod_rewrite در آپاچی جهت خوانش فایل‌های htaccess و روتینگ تمیز
RUN a2enmod rewrite

# ۳. کپی کردن کل فایل‌های پروژه گیت‌هاب به پوشه ریشه سرور آپاچی در لینوکس کانتینر
COPY . /var/www/html/

# ۴. اعطای مالکیت و دسترسی‌های امنیتی کامل پوشه وب‌سرور به یوزر پیش‌فرض آپاچی (www-data)
RUN chown -R www-data:www-data /var/www/html/

# ۵. باز کردن پورت پیش‌فرض ۸۰ جهت هدایت ترافیک اینترنت و وب‌هوک‌های تلگرام توسط رندر به وب‌سرور
EXPOSE 80

# ۶. استارت وب‌سرور آپاچی در لایه پیش‌زمینه (Foreground) برای آماده‌به‌کار ماندن دائمی کانتینر
CMD ["apache2-foreground"]
