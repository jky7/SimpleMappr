FROM debian:trixie-slim@sha256:a29215f6a35e51e22adffa17f89e9d2ef06214e64a2bad10d765c46aea49f11f

ARG DEBIAN_SNAPSHOT=20261019T000000Z
RUN printf 'deb [check-valid-until=no] http://snapshot.debian.org/archive/debian/%s/ trixie main\ndeb [check-valid-until=no] http://snapshot.debian.org/archive/debian-security/%s/ trixie-security main\n' "$DEBIAN_SNAPSHOT" "$DEBIAN_SNAPSHOT" > /etc/apt/sources.list \
    && rm /etc/apt/sources.list.d/debian.sources \
    && apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        apache2 libapache2-mod-php8.4 php8.4-cli php-mapscript-ng \
        php8.4-gd php8.4-mysql php8.4-curl php8.4-mbstring php8.4-xml php8.4-zip \
        gdal-bin gettext ca-certificates fonts-dejavu-core curl composer \
    && dpkg-query -W -f='${Package}\t${Version}\n' > /usr/local/share/simplemappr-packages.tsv \
    && rm -rf /var/lib/apt/lists/* \
    && a2dismod mpm_event \
    && a2enmod mpm_prefork php8.4 rewrite headers

WORKDIR /srv/simplemappr
COPY composer.json composer.lock /srv/simplemappr/
RUN composer install --no-interaction --prefer-dist --no-progress
COPY . /srv/simplemappr/
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /etc/php/8.4/mods-available/simplemappr.ini
RUN phpenmod simplemappr \
    && mkdir -p /var/lib/simplemappr/checks public/tmp public/uploads public/stylesheets/cache public/javascript/cache \
    && chown -R www-data:www-data public/tmp public/uploads public/stylesheets/cache public/javascript/cache \
    && chown -R www-data:www-data /var/lib/simplemappr \
    && chmod +x scripts/* docker/entrypoint

EXPOSE 80
ENTRYPOINT ["/srv/simplemappr/docker/entrypoint"]
CMD ["apache2ctl", "-D", "FOREGROUND"]
