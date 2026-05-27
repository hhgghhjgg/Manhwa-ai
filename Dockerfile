FROM php:8.2-apache

# به روزرسانی مخازن سیستم‌عامل و نصب درایورهای لازم برای اتصال به دیتابیس Neon PostgreSQL
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# فعال‌سازی ماژول rewrite در آپاچی برای مدیریت بهتر آدرس‌ها
RUN a2enmod rewrite

# کپی کردن تمام فایل‌های پوشه جاری به پوشه اصلی سرور وب
COPY . /var/www/html/

# تعیین دسترسی‌های لازم برای اجرای فایل‌ها در آپاچی (بدون کاراکتر اضافه -y)
RUN chown -R www-data:www-data /var/www/html

# باز کردن پورت ۸۰ برای هدایت ترافیک وب به سرور رندر
EXPOSE 80
