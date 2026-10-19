FROM php:8.2-apache
RUN docker-php-ext-install pdo pdo_mysql
RUN a2enmod rewrite
COPY aqua_learn_swim_center_zambia/ /var/www/html/
RUN chown -R www-data:www-data /var/www/html
EXPOSE 80
