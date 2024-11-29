.PHONY: test-all

test-all: start test-8.1 test-8.2 test-8.3 test-8.4 stop

test-8.1:
	docker-compose exec php-8.1-libxml-2.9.13 php /app/vendor/phpunit/phpunit/phpunit --configuration /app/phpunit.xml

test-8.2:
	docker-compose exec php-8.2-libxml-2.9.14 php /app/vendor/phpunit/phpunit/phpunit --configuration /app/phpunit.xml

test-8.3:
	docker-compose exec php-8.3-libxml-2.9.14 php /app/vendor/phpunit/phpunit/phpunit --configuration /app/phpunit.xml

test-8.4:
	docker-compose exec php-8.4-libxml-2.9.14 php /app/vendor/phpunit/phpunit/phpunit --configuration /app/phpunit.xml

start:
	docker-compose up -d php-8.1-libxml-2.9.13 php-8.2-libxml-2.9.14 php-8.3-libxml-2.9.14 php-8.4-libxml-2.9.14

stop:
	docker-compose stop

test-all-versions:
	for php_version in 8.1 8.2 8.3 8.4; do \
	    for libxml_version in 2.9.14; do \
			docker-compose up -d php-$$php_version-libxml-$$libxml_version; \
			docker-compose exec php-$$php_version-libxml-$$libxml_version php /app/vendor/phpunit/phpunit/phpunit --configuration /app/phpunit.xml; \
		done \
	done
	docker-compose stop
