FROM php:8.1-apache

# Install required PHP extensions: mysql client and zip and sendmail
RUN apt-get update && apt-get install -y \
    libzip-dev \ 
    unzip \ 
    cron \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libmagickwand-dev --no-install-recommends \
    ghostscript \
    ssmtp \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install mysqli zip gd \ 
    && apt-get clean \ 
    && rm -rf /var/lib/apt/lists/*

# Use prod php.ini settings
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Add the REDCap cron job
RUN echo "* * * * * www-data /usr/local/bin/php /var/www/html/cron.php > /dev/null 2>&1" > /etc/cron.d/redcap-cron \
    && chmod 0644 /etc/cron.d/redcap-cron \
    && crontab /etc/cron.d/redcap-cron

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy REDCap files to the container
COPY redcap /var/www/html

# Copy redcap overrides php configuration file
COPY redcap-overrides.ini $PHP_INI_DIR/conf.d/

# Copy configuration script
COPY configure-db.sh /usr/local/bin/configure-db.sh
RUN chmod +x /usr/local/bin/configure-db.sh

# Copy entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Copy Girder Uploader plugin files to the container
COPY girder_uploader_v1.0.0 /var/www/html/modules/girder_uploader_v1.0.0

# Set proper permissions: all users should have read/write access to /var/user_uploads
RUN chown -R www-data:www-data /var/www/html \
    && mkdir -p /var/user_uploads \
    && chmod -R 777 /var/user_uploads

# Expose port 80
EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
