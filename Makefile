.EXPORT_ALL_VARIABLES:
.PHONY: all init start stop clean rebuild install shell-php shell-node test test-db test-coverage ci-test update ci-update ci-update-commit deploy

UID!=id -u
GID!=id -g
COMPOSE=UID=${UID} GID=${GID} docker-compose -f docker/docker-compose.yml
COMPOSE-RUN=${COMPOSE} run --rm -u ${UID}:${GID}
PHP-DB-RUN=${COMPOSE-RUN} php
PHP-RUN=${COMPOSE-RUN} --no-deps php
NODE-RUN=${COMPOSE-RUN} --no-deps encore
MARIADB-RUN=${COMPOSE-RUN} --no-deps mariadb

all: install

init: start
	${PHP-DB-RUN} bin/console cache:warmup
	${PHP-DB-RUN} bin/console doctrine:database:create
	${PHP-DB-RUN} bin/console doctrine:schema:create
	${PHP-DB-RUN} bin/console app:config:update-countries
	${PHP-DB-RUN} bin/console app:update:mirrors
	${PHP-DB-RUN} bin/console app:update:news
	${PHP-DB-RUN} bin/console app:update:releases
	${PHP-DB-RUN} bin/console app:update:repositories
	${PHP-DB-RUN} bin/console app:update:packages

start:
	${COMPOSE} up -d
	${MARIADB-RUN} mysqladmin -uroot --wait=10 ping

stop:
	${COMPOSE} stop

clean:
	${COMPOSE} down -v
	git clean -fdqx -e .idea

rebuild: clean
	${COMPOSE} build --pull
	${MAKE} install
	${MAKE} init

install:
	${PHP-RUN} composer --no-interaction install
	${NODE-RUN} yarn install

shell-php:
	${PHP-DB-RUN} bash

shell-node:
	${NODE-RUN} bash

test:
	${PHP-RUN} vendor/bin/phpcs
	${NODE-RUN} node_modules/.bin/standard 'assets/js/**/*.js' '*.js'
	${NODE-RUN} node_modules/.bin/stylelint 'assets/css/**/*.scss' 'assets/css/**/*.css'
	${PHP-RUN} bin/console lint:yaml config
	${PHP-RUN} bin/console lint:twig templates
	${PHP-RUN} vendor/bin/phpunit

test-db: start
	${PHP-DB-RUN} vendor/bin/phpunit -c phpunit-db.xml

test-coverage:
	${PHP-RUN} phpdbg -qrr -d memory_limit=-1 vendor/bin/phpunit --coverage-html var/coverage

ci-test: install
	${MAKE} test
	${PHP-RUN} bin/console security:check
	${NODE-RUN} node_modules/.bin/encore production
	${MAKE} test-db

update:
	${PHP-RUN} composer --no-interaction update
	${MAKE} test
	${MAKE} test-db
	${NODE-RUN} yarn upgrade --latest
	${NODE-RUN} node_modules/.bin/encore production

ci-update-commit:
	git config --local user.name "$${GH_NAME}"
	git config --local user.email "$${GH_EMAIL}"
	git add -A
	git commit -m"Update dependencies"
	git remote add origin-push https://$${GH_TOKEN}@github.com/archlinux-de/www.archlinux.de.git
	git push --set-upstream origin-push "$${TRAVIS_BRANCH}"

ci-update:
	${MAKE} update
	git diff-index --quiet HEAD || ${MAKE} ci-update-commit

deploy:
	chmod o-x .
	composer --no-interaction install --prefer-dist --no-dev --optimize-autoloader
	yarn install
	bin/console cache:clear --no-debug --no-warmup
	yarn run encore production
	bin/console cache:warmup
	bin/console app:config:update-countries
	chmod o+x .
