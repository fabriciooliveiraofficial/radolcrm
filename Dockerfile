FROM php:8.2-apache

# mbstring e curl já vêm compilados na imagem oficial do PHP; só pdo_mysql é extra.
# AllowOverride All é necessário para o Apache respeitar o .htaccess (bloqueio de app/, config/, database/, storage/...).
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers \
    && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \; \
    && chmod -R 775 /var/www/html/config /var/www/html/storage

EXPOSE 80
