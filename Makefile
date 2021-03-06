COMPOSER_FILES = files/usr/share/grase/symfony4/composer.json files/usr/share/grase/symfony4/composer.lock files/usr/share/grase/symfony4/vendor/
JS_FILES = files/usr/share/grase/symfony4/public/build/
VERSION = $(shell sed -n '/grase-www-portal/s/[^ ]* (//;s/).*//p;q' debian/changelog)

all: js composer prod_env version
#files/usr/share/grase/src/includes/constants.php: debian/changelog
	chmod 0440 sudo/grase-www-portal

# JS doesn't end up in ext-libs due to webpack compiling it all into our own thing
js:
	cd files/usr/share/grase/symfony4; bin/console bazinga:js-translation:dump --format=js --merge-domains assets/js/bazinga
	cd files/usr/share/grase/symfony4; yarn; yarn encore prod
	rm -fr files/usr/share/grase/symfony4/node_modules

composer: prod_env
	cd files/usr/share/grase/symfony4; composer install --no-dev
	mkdir -p ext-libs/composer
	cp -r $(COMPOSER_FILES) ext-libs/composer/
	rm -fr files/usr/share/grase/symfony4/vendor

prod_env:
	sed -i '/APP_ENV=/d' files/usr/share/grase/symfony4/.env
	echo 'APP_ENV=prod' >> files/usr/share/grase/symfony4/.env

version:
	echo $(VERSION) | sed 's/~/-/g' | sed 's/\./-/3g' > files/usr/share/grase/symfony4/VERSION
	cat files/usr/share/grase/symfony4/VERSION

clean:
	rm -fr ext-libs
	rm -fr files/usr/share/grase/symfony4/.env.local
