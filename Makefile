.PHONY: test-all

test-all: start test-7.4 test-8.0 test-8.1 stop

test-7.4:
	docker-compose exec php-7.4-libxml-2.9.10 php /app/vendor/phpunit/phpunit/phpunit --configuration /app/phpunit.xml

test-8.0:
	docker-compose exec php-8.0-libxml-2.9.10 php /app/vendor/phpunit/phpunit/phpunit --configuration /app/phpunit.xml

test-8.1:
	docker-compose exec php-8.1-libxml-2.9.13 php /app/vendor/phpunit/phpunit/phpunit --configuration /app/phpunit.xml

start:
	docker-compose up -d php-7.4-libxml-2.9.10 php-8.0-libxml-2.9.10 php-8.1-libxml-2.9.13

stop:
	docker-compose stop

test-all-versions:
	for php_version in 7.4 8.0 8.1; do \
	    for libxml_version in 2.9.10 2.9.13 2.9.14; do \
			docker-compose up -d php-$$php_version-libxml-$$libxml_version; \
			docker-compose exec php-$$php_version-libxml-$$libxml_version php /app/vendor/phpunit/phpunit/phpunit --configuration /app/phpunit.xml; \
		done \
	done
	docker-compose stop
