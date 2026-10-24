FROM node:26.12.0-bookworm-slim@sha256:298992629d69bbd5b2b03ad57094d8e4fcb3a8eeb7c4e25d29de656519568913 AS frontend
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY scripts/build-frontend.mjs scripts/check-frontend-build.mjs scripts/
COPY public/javascript/ public/javascript/
COPY public/stylesheets/ public/stylesheets/
COPY public/images/ public/images/
COPY Tests/node/ Tests/node/
COPY Tests/fixtures/editor-map-response.json Tests/fixtures/
COPY views/main.html views/help.html views/
RUN npm run build && npm test && npm run check:build

FROM debian:trixie-slim@sha256:62bd423aeee912639ca8ee681c2b762cb3e25137e0a92b20be455898a9f034ad

ARG DEBIAN_SNAPSHOT=20261021T134156Z
RUN printf 'deb [check-valid-until=no] http://snapshot.debian.org/archive/debian/%s/ trixie main\ndeb [check-valid-until=no] http://snapshot.debian.org/archive/debian-security/%s/ trixie-security main\n' "$DEBIAN_SNAPSHOT" "$DEBIAN_SNAPSHOT" > /etc/apt/sources.list \
    && rm /etc/apt/sources.list.d/debian.sources \
    && apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends ca-certificates \
    && sed -i 's|http://snapshot.debian.org|https://snapshot.debian.org|g' /etc/apt/sources.list \
    && rm -rf /var/lib/apt/lists/* \
    && apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        apache2 libapache2-mod-php8.4 php8.4-cli php-mapscript-ng \
        php8.4-gd php8.4-mysql php8.4-curl php8.4-mbstring php8.4-xml php8.4-zip \
        gdal-bin gettext ca-certificates fonts-dejavu-core curl composer \
    && php -r 'if (PHP_VERSION_ID < 80427) { fwrite(STDERR, "PHP 8.4.27 or newer is required for CVE-2026-109618 and CVE-2026-109619.\n"); exit(1); }' \
    && dpkg-query -W -f='${Package}\t${Version}\n' > /usr/local/share/simplemappr-packages.tsv \
    && rm -rf /var/lib/apt/lists/* \
    && a2dismod mpm_event \
    && a2enmod mpm_prefork php8.4 rewrite headers

WORKDIR /srv/simplemappr
COPY composer.json composer.lock /srv/simplemappr/
RUN composer install --no-interaction --prefer-dist --no-progress
COPY . /srv/simplemappr/
COPY --from=frontend /build/public/build/ /srv/simplemappr/public/build/
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /etc/php/8.4/mods-available/simplemappr.ini
RUN phpenmod simplemappr \
    && mkdir -p /var/lib/simplemappr/checks /var/cache/simplemappr-relief public/tmp public/uploads \
    && chown -R www-data:www-data public/tmp public/uploads \
    && chown -R www-data:www-data /var/lib/simplemappr /var/cache/simplemappr-relief \
    && chmod +x scripts/* docker/entrypoint

EXPOSE 80
ENTRYPOINT ["/srv/simplemappr/docker/entrypoint"]
CMD ["apache2ctl", "-D", "FOREGROUND"]
