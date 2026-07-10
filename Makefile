.PHONY: test-all test-8.4 test-8.5 start stop

test-all: start test-8.4 test-8.5 stop

test-8.4:
	docker-compose exec php-8.4 php /app/vendor/phpunit/phpunit/phpunit --configuration /app/phpunit.xml

test-8.5:
	docker-compose exec php-8.5 php /app/vendor/phpunit/phpunit/phpunit --configuration /app/phpunit.xml

start:
	docker-compose up -d php-8.4 php-8.5

stop:
	docker-compose stop
