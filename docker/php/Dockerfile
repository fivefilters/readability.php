# Load pre-built image of PHP (php-cli) and libxml. 
# See https://hub.docker.com/r/fivefilters/php-libxml for supported versions
# Use build.Dockerfile to compile new versions of PHP/libxml

# For reference, default package versions for Ubuntu are:
# Ubuntu 21.04 - php 7.4, libxml2 2.9.10
# Ubuntu 21.10 - php 8.0, libxml2 2.9.10
# Ubuntu 22.04 - php 8.1, libxml2 2.9.13
# Ubuntu 22.10 - php 8.1, libxml2 2.9.14
# Ubuntu 23.04 - php 8.1, libxml2 2.9.14
# Ubuntu 23.10 - php 8.2, libxml2 2.9.14
# Ubuntu 24.04 - php 8.3, libxml2 2.9.14
# Ubuntu 24.10 - php 8.3, libxml2 2.12.7

ARG PHP_VERSION
ARG LIBXML_VERSION
FROM fivefilters/php-libxml:php-${PHP_VERSION}-libxml-${LIBXML_VERSION}

RUN apt-get update