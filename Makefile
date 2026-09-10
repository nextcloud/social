# SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

app_name=social

project_dir=$(CURDIR)
build_dir=$(CURDIR)/build/artifacts
appstore_dir=$(build_dir)/appstore
source_dir=$(build_dir)/source
sign_dir=$(build_dir)/sign
package_name=$(app_name)
cert_dir=$(HOME)/.nextcloud/certificates
github_account=nextcloud
branch=master
version+=0.10.1


all: dev-setup lint build-js-production composer

# Dev env management
dev-setup: clean clean-dev npm-init composer

# Release env management. `npm ci` and `composer install` install exactly what
# the lock files pin; `npm install` and `composer upgrade` do not, so a release
# built with them shipped dependency versions no CI job had ever run.
release-setup: clean clean-dev npm-ci composer

npm-init:
	npm install

npm-ci:
	npm ci

npm-update:
	npm update

# Building
build-js:
	npm run dev

build-js-production:
	npm run build

watch-js:
	npm run watch

# Testing
test:
	npm run test

test-watch:
	npm run test:watch

test-coverage:
	npm run test:coverage

# Linting
lint:
	npm run lint

lint-fix:
	npm run lint:fix

# Cleaning
# js/ is not purely build output: js/.htaccess and the hand-written
# js/social-adminSettings.js -- which lib/Settings/AdminSettings.php loads --
# are committed sources, and no webpack entry point can regenerate them. Wiping
# the directory here is why the released tarball had a broken admin settings
# page. Webpack clears stale bundles out of js/ on every build and keeps those
# two files (see output.clean in webpack.common.js), so nothing is needed here.
clean:
	rm -rf $(build_dir)

clean-dev:
	rm -rf node_modules

composer:
	composer install --prefer-dist --no-dev

composer-dev:
	composer install --prefer-dist --dev

# Deliberately rewrites composer.lock. Never part of a build; run it, run the
# tests, and commit the lock file.
composer-update:
	composer upgrade --prefer-dist

release: appstore

# creating .tar.gz + signature
appstore: release-setup lint build-js-production composer
	@test -f js/social-adminSettings.js || { \
		echo "js/social-adminSettings.js is missing. It is a committed file, not webpack output, and lib/Settings/AdminSettings.php loads it; restore it before packaging."; \
		exit 1; }
	@test -f js/.htaccess || { \
		echo "js/.htaccess is missing. It is a committed file, not webpack output; restore it before packaging."; \
		exit 1; }
	mkdir -p $(sign_dir)
	rsync -a \
	--exclude=/build \
	--exclude=/babel.config.js \
	--exclude=/cypress.json \
	--exclude=/.php-cs-fixer.cache \
	--exclude=/.nextcloudignore \
	--exclude=/.php-cs-fixer.dist.php \
	--exclude=/psalm.xml \
	--exclude=/cypress.json \
	--exclude=/cypress \
	--exclude=/docs \
	--exclude=/translationfiles \
	--exclude=/.tx \
	--exclude=/.idea \
	--exclude=/tests \
	--exclude=.git \
	--exclude=/.github \
	--exclude=/.babelrc.js \
	--exclude=/.drone.yml \
	--exclude=/.eslintrc.js \
	--exclude=/cypress.config.js \
	--exclude=/stylelint.config.js \
	--exclude=/composer.json \
	--exclude=/composer.lock \
	--exclude=/src \
	--exclude=/node_modules \
	--exclude=/webpack.*.js \
	--exclude=/package.json \
	--exclude=/package-lock.json \
	--exclude=/l10n/l10n.pl \
	--exclude=/CONTRIBUTING.md \
	--exclude=/issue_template.md \
	--exclude=/krankerl.toml \
	--exclude=/README.md \
	--exclude=/.gitattributes \
	--exclude=/.gitignore \
	--exclude=/.scrutinizer.yml \
	--exclude=/.travis.yml \
	--exclude=/Makefile \
	$(project_dir)/ $(sign_dir)/$(app_name)
	tar -czf $(build_dir)/$(app_name).tar.gz \
		-C $(sign_dir) $(app_name)

