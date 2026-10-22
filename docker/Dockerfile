FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    curl \
    unzip \
    && rm -rf /var/lib/apt/lists/*

RUN pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install pcntl \
    && docker-php-ext-install sockets

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Bitwarden CLI. Without BW_VERSION the latest version is downloaded.
# To pin it set the build arguments BW_VERSION (e.g. 2025.1.0) and, to verify the download, BW_SHA256 (sha256 of bw-linux-<version>.zip)
ARG BW_VERSION=
ARG BW_SHA256=
RUN if [ -n "$BW_VERSION" ]; then \
        url="https://github.com/bitwarden/clients/releases/download/cli-v${BW_VERSION}/bw-linux-${BW_VERSION}.zip"; \
    else \
        url="https://vault.bitwarden.com/download/?app=cli&platform=linux"; \
    fi \
    && curl -fL "$url" -o /tmp/bw.zip \
    && if [ -n "$BW_SHA256" ]; then echo "${BW_SHA256}  /tmp/bw.zip" | sha256sum -c -; fi \
    && unzip /tmp/bw.zip -d /usr/local/bin \
    && chmod +x /usr/local/bin/bw \
    && rm /tmp/bw.zip

WORKDIR /var/www/BitwardenOrgSync

COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --prefer-dist

COPY . .

# healthy while a synchronization cycle without errors has finished recently (run.php writes the time and the allowed age)
HEALTHCHECK --interval=60s --timeout=10s --start-period=300s --retries=3 \
    CMD php -r '$d = explode(" ", (string) @file_get_contents(sys_get_temp_dir() . "/bwsync-health")); exit(count($d) == 2 && time() - (int) $d[0] < (int) $d[1] ? 0 : 1);'

CMD ["php", "/var/www/BitwardenOrgSync/run.php"]
