

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
version+=0.6.0-beta1


all: dev-setup lint build-js-production composer

# Dev env management
dev-setup: clean clean-dev npm-init composer

npm-init:
	npm install

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
clean:
	rm -rf $(build_dir)
	rm -fr js/
	mkdir js/

clean-dev:
	rm -rf node_modules

composer:
	composer install --prefer-dist --no-dev
	composer upgrade --prefer-dist --no-dev

composer-dev:
	composer install --prefer-dist --dev
	composer upgrade --prefer-dist --dev

release: appstore

# creating .tar.gz + signature
appstore: dev-setup lint build-js-production composer
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
	--exclude=/tests \
	--exclude=.git \
	--exclude=/.github \
	--exclude=/.babelrc.js \
	--exclude=/.drone.yml \
	--exclude=/.eslintrc.js \
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

