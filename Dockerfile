FROM composer

COPY . .

RUN composer install

CMD php bot.php
