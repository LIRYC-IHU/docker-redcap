FROM php:8.1-apache

# Install required PHP extensions
RUN apt-get update && apt-get install -y \
    libmysqlnd-dev \
    default-libmysqlclient-dev \
    && docker-php-ext-install mysqli pdo pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy REDCap from the local redcap15.9.3/redcap folder to /web in the container
COPY redcap15.9.3/redcap /var/www/html

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

# Expose port 80
EXPOSE 80

CMD ["apache2-foreground"]
