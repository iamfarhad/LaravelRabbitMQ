# Installation guide

This package requires the native PHP `ext-amqp` extension.

The goal of this guide is to make the production install path predictable across common PHP runtimes.

## Composer

```bash
composer require iamfarhad/laravel-rabbitmq
```

If Composer reports that `ext-amqp` is missing, install and enable the extension first.

## Debian or Ubuntu

```bash
sudo apt-get update
sudo apt-get install -y librabbitmq-dev libssh-dev php-dev make gcc
sudo pecl install amqp
```

Enable the extension if PECL does not do it automatically:

```bash
echo "extension=amqp.so" | sudo tee /etc/php/$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')/mods-available/amqp.ini
sudo phpenmod amqp
php -m | grep amqp
```

Restart PHP-FPM or your worker process after enabling the extension.

## Docker, Debian-based PHP images

```dockerfile
RUN apt-get update \
    && apt-get install -y librabbitmq-dev libssh-dev \
    && pecl install amqp \
    && docker-php-ext-enable amqp \
    && rm -rf /var/lib/apt/lists/*
```

Add `pcntl` only when you use `rabbitmq:consume --num-processes` greater than `1`:

```dockerfile
RUN docker-php-ext-install pcntl
```

## Docker, Alpine-based PHP images

```dockerfile
RUN apk add --no-cache rabbitmq-c-dev autoconf make g++ \
    && pecl install amqp \
    && docker-php-ext-enable amqp
```

If your image separates build and runtime dependencies, keep the runtime RabbitMQ C library installed in the final image.

## Laravel Sail

Publish Sail's Dockerfile, then add the Debian-based Docker snippet above.

Rebuild the container:

```bash
./vendor/bin/sail build --no-cache
./vendor/bin/sail up -d
./vendor/bin/sail php -m | grep amqp
```

## GitHub Actions

```yaml
- name: Install ext-amqp
  run: |
    sudo apt-get update
    sudo apt-get install -y librabbitmq-dev libssh-dev
    sudo pecl install amqp
    echo "extension=amqp.so" | sudo tee /etc/php/$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')/cli/conf.d/20-amqp.ini
    php -m | grep amqp
```

## Verify installation

```bash
php -m | grep amqp
php --ri amqp
composer check-platform-reqs
```

## Common problems

### Composer says ext-amqp is missing

The extension is not enabled for the PHP binary running Composer. Check:

```bash
which php
php --ini
php -m | grep amqp
```

### Web requests work, but queue workers fail

Your CLI PHP and PHP-FPM may use different `php.ini` files. Enable `amqp` for the CLI runtime used by workers.

### Extension installed, but workers still fail

Restart the process manager:

```bash
sudo systemctl restart php8.3-fpm
sudo supervisorctl restart all
```
