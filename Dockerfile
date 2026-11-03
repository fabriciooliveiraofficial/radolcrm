FROM php:8.2-apache

# mbstring e curl já vêm compilados na imagem oficial do PHP; só pdo_mysql é extra.
# AllowOverride All é necessário para o Apache respeitar o .htaccess (bloqueio de app/, config/, database/, storage/...).
# cron roda as rotinas agendadas (financeira, câmbio, WhatsApp) dentro do próprio container.
RUN apt-get update \
    && apt-get install -y --no-install-recommends cron \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers \
    && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

COPY . /var/www/html/

RUN install -m 755 /var/www/html/docker/entrypoint.sh /usr/local/bin/nexo-entrypoint \
    && install -m 755 /var/www/html/docker/nexo-cron.sh /usr/local/bin/nexo-cron \
    && install -m 644 /var/www/html/docker/crontab /etc/cron.d/nexo \
    && install -D -m 644 /var/www/html/docker/cron-secret.php /usr/local/lib/nexo/cron-secret.php \
    && sed -i 's/\r$//' /usr/local/bin/nexo-entrypoint /usr/local/bin/nexo-cron /etc/cron.d/nexo \
    && rm -rf /var/www/html/docker

RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \; \
    && chmod -R 775 /var/www/html/config /var/www/html/storage

EXPOSE 80

CMD ["nexo-entrypoint"]
