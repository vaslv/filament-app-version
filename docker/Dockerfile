ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli-alpine

# The official php:*-cli-alpine images already carry dom, xml, xmlwriter,
# mbstring, tokenizer, simplexml, pdo_sqlite and sqlite3, so PHPUnit and
# Testbench start without docker-php-ext-install. Verified by running on 8.4.24
# and 8.5.9 (2026-08-13).
#
# What they do NOT have and what has to be added, both of them found by running
# it rather than by assumption:
#
#   ext-intl is a hard requirement of filament/support.
#   ext-zip  is a hard requirement of openspout/openspout, a transitive
#            dependency of filament/actions. The unzip binary is NOT enough
#            here: it covers Composer unpacking archives, but not the package's
#            platform requirement, and composer refuses to resolve dependencies.
#
# icu-dev and libzip-dev are needed only at build time; icu-libs and libzip stay
# in the runtime.
RUN apk add --no-cache git unzip icu-libs libzip \
    && apk add --no-cache --virtual .build-deps icu-dev libzip-dev \
    && docker-php-ext-install intl zip \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

WORKDIR /pkg
