# Use this file to build a Docker image using the versions of PHP and Libxml specified.
#
# We have pre-built images at https://hub.docker.com/r/fivefilters/php-libxml which are 
# faster to load than building from this file.
#
# To build using this file, type the following command from the root project folder 
# (replace version of PHP/Libxml with the ones you want to use):
#
# docker build --build-arg PHP_VERSION=7.4 --build-arg LIBXML_VERSION=2.9.12 -t php-libxml -f ./docker/php/build.Dockerfile .

# To upload the image to Docker Hub, the tag (-t) value should be something like org/repo:tag, e.g. for us, fivefilters/php-libxml:php-8-libxml-2.9.12
# The tag can be applied afterwards too, e.g. docker tag php-libxml org/repo:tag

ARG PHP_VERSION=8.1
FROM php:${PHP_VERSION}-cli

# Install sqlite and libonig-dev (required for building PHP 7.4), libreadline-dev for php 8.1
RUN apt-get update && apt-get install -y libsqlite3-dev libonig-dev libreadline-dev
# Install libsodium (package doesn't work for some reason)
RUN curl https://download.libsodium.org/libsodium/releases/LATEST.tar.gz -o /tmp/libsodium.tar.gz && \
	cd /tmp && \
	tar -xzf libsodium.tar.gz && \
	cd libsodium-stable/ && \
	./configure && \
	make && make check && \
	make install
# Install custom version of libxml2
RUN apt-get install -y automake libtool unzip libssl-dev
# Remove current version
RUN apt-get remove -y libxml2
# Download new version, configure and compile
ARG LIBXML_VERSION=2.9.14
RUN curl https://gitlab.gnome.org/GNOME/libxml2/-/archive/v$LIBXML_VERSION/libxml2-v$LIBXML_VERSION.zip -o /tmp/libxml.zip && \
	cd /tmp && \
	unzip libxml.zip && \
	cd libxml2-v$LIBXML_VERSION && \
	./autogen.sh --libdir=/usr/lib/x86_64-linux-gnu && \
	make && \
	make install
# Recompile PHP with the new libxml2 library
RUN docker-php-source extract && \
	cd /usr/src/php && \
	./configure \
		--with-readline \
		--with-libxml \
		--enable-mbstring \
		--with-openssl \
		--with-config-file-path=/usr/local/etc/php \
		--with-config-file-scan-dir=/usr/local/etc/php/conf.d && \
	make && make install && \
	docker-php-source delete

RUN apt-get update

#RUN pecl install libsodium

# Check if there's a pinned version of Xdebug for compatibility reasons
ARG XDEBUG_VERSION
RUN pecl install xdebug$(if [ ! ${XDEBUG_VERSION} = '' ]; then echo -${XDEBUG_VERSION} ; fi) && docker-php-ext-enable xdebug

# Required by coveralls
RUN apt-get install git -y
