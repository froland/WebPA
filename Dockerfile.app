FROM node:21 as build-js
WORKDIR /build
RUN mkdir -p /build/src/js
COPY package.json package-lock.json /build/
RUN --mount=type=cache,target=/root/.npm \
    npm install \
    && npm run build

FROM php:8.0-apache as app
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt --no-install-recommends install -y ssmtp mailutils
COPY ssmtp.conf /etc/ssmtp/ssmtp.conf
COPY php-mail.ini /usr/local/etc/php/conf.d/mail.ini
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    IPE_KEEP_SYSPKG_CACHE=1 install-php-extensions @composer mysqli zip
WORKDIR /var/www/html
RUN composer create-project --prefer-dist --no-dev webpa/webpa webpa
COPY --from=build-js /build/src/js /var/www/html/webpa/js/
COPY .env /var/www/html/webpa/.env
RUN rm -rf /var/www/html/install
