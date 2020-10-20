all: csfix static-analysis test
	@echo "Done."

vendor: composer.json
	composer update
	touch vendor

.PHONY: csfix
csfix: vendor
	vendor/bin/php-cs-fixer fix --verbose

.PHONY: static-analysis
static-analysis: vendor
	vendor/bin/phpstan analyse

.PHONY: test
test: vendor
	php -d zend.assertions=1 vendor/bin/phpunit

.PHONY: mysql-start
mysql-start:
	docker run --publish 3306:3306 --rm --name php-sql-legacy-testing --env MYSQL_ROOT_PASSWORD=root_password --env MYSQL_DATABASE=sql_legacy --detach mysql:5.7

.PHONY: mysql-stop
mysql-stop:
	docker stop php-sql-legacy-testing
