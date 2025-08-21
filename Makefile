SERVICE_POSTFIX=_monadungeon
DOCKER_COMPOSE=docker compose
# Frontend commands
fe-install:
	docker-compose exec frontend npm install

fe-dev:
	docker-compose exec frontend npm run dev

fe-build:
	docker-compose exec frontend npm run build

fe-lint:
	docker-compose exec frontend npm run lint

fe-bash:
	docker-compose exec frontend /bin/sh

# One-time setup command to install packages if node_modules doesn't exist
fe-setup:
	docker-compose up -d frontend
	docker-compose exec frontend npm install

# Use this to run any npm command directly
# Example: make fe-npm CMD="install axios --save"
fe-npm:
	docker-compose exec frontend npm $(CMD)
.PHONY: build run restart stop php phpx piy piyx all psalm psalm-cache rector rectorfix \
        composer-normalize doctrine-validate csfix fix setup rebuild_db_test init \
        composer-require-checker composer-unused composerfix

build:
	$(DOCKER_COMPOSE) build

run:
	$(DOCKER_COMPOSE) up -d

restart:
	$(DOCKER_COMPOSE) restart

stop:
	$(DOCKER_COMPOSE) stop

php:
	docker exec -it php$(SERVICE_POSTFIX) sh

phpx:
	docker exec -it phpx$(SERVICE_POSTFIX) sh

piy:
	docker exec -it php$(SERVICE_POSTFIX) php vendor/bin/phpunit --group piy --stop-on-failure

piyx:
	docker exec -it phpx$(SERVICE_POSTFIX) php vendor/bin/phpunit --group piy --stop-on-failure

all:
	docker exec -it php$(SERVICE_POSTFIX) php vendor/bin/phpunit --stop-on-failure

psalm:
	docker exec -it php$(SERVICE_POSTFIX) php vendor-bin/psalm/vendor/bin/psalm

psalm-cache:
	docker exec -it php$(SERVICE_POSTFIX) php vendor-bin/psalm/vendor/bin/psalm --clear-cache

rector:
	docker exec -it php$(SERVICE_POSTFIX) php vendor/bin/rector process src -n

rectorfix:
	docker exec -it php$(SERVICE_POSTFIX) php vendor/bin/rector process src

composer-normalize:
	docker exec -it php$(SERVICE_POSTFIX) composer normalize --diff

doctrine-validate:
	docker exec -it php$(SERVICE_POSTFIX) php bin/console doctrine:schema:validate

csfix:
	docker exec -it php$(SERVICE_POSTFIX) php vendor/bin/php-cs-fixer fix src

fix: rectorfix composer-normalize csfix psalm

setup:
	docker exec -it php$(SERVICE_POSTFIX) composer install
	docker exec -it php$(SERVICE_POSTFIX) php bin/console doctrine:database:create --if-not-exists
	docker exec -it php$(SERVICE_POSTFIX) php bin/console doctrine:migrations:migrate -n

rebuild_db_test:
	docker exec -it php$(SERVICE_POSTFIX) php bin/console doctrine:database:drop --if-exists --force --env=test
	docker exec -it php$(SERVICE_POSTFIX) php bin/console doctrine:database:create --env=test
	docker exec -it php$(SERVICE_POSTFIX) php bin/console doctrine:migrations:migrate -n --env=test

init: run setup rebuild_db_test all
	docker exec -it php$(SERVICE_POSTFIX) php bin/console game:run 100

composer-require-checker:
	docker exec -it php$(SERVICE_POSTFIX) php vendor/bin/composer-require-checker check

composer-unused:
	docker exec -it php$(SERVICE_POSTFIX) php vendor/bin/composer-unused

composerfix: composer-normalize composer-require-checker composer-unused
