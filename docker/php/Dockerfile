# PHP >= 8.4 bundles the Lexbor-based HTML parser in ext-dom,
# so the official CLI images are all we need (no libxml pinning).
ARG PHP_VERSION
FROM php:${PHP_VERSION}-cli

RUN apt-get update
