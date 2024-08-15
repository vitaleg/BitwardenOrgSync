FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    curl \
    gnupg \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

RUN pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install pcntl \
    && docker-php-ext-install sockets

RUN git clone https://github.com/vitaleg/BitwardenOrgSync /var/www/BitwardenOrgSync

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN cd /var/www/BitwardenOrgSync \
    && composer require mikehaertl/php-shellcommand

RUN curl -L "https://vault.bitwarden.com/download/?app=cli&platform=linux" -o /tmp/bw.zip \
    && unzip /tmp/bw.zip -d /usr/local/bin \
    && chmod +x /usr/local/bin/bw \
    && rm /tmp/bw.zip
