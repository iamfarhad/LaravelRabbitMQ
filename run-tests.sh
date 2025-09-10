#!/bin/bash

# Simple test runner using PHPUnit directly to avoid Pest configuration issues
echo "Running LaravelRabbitMQ Tests..."

# Set environment variables
export APP_ENV=testing
export BCRYPT_ROUNDS=4
export CACHE_DRIVER=array
export DB_CONNECTION=testing
export MAIL_MAILER=array
export QUEUE_CONNECTION=sync
export SESSION_DRIVER=array
export TELESCOPE_ENABLED=false
export RABBITMQ_HOST=rabbitmq
export RABBITMQ_PORT=5672
export RABBITMQ_USER=laravel
export RABBITMQ_PASSWORD=secret
export RABBITMQ_VHOST=b2b-field

echo "Checking AMQP extension..."
if php -m | grep -q amqp; then
    echo "AMQP extension is available"
else
    echo "AMQP extension is not available - this is expected in some environments"
fi

# Clear any cache files that might cause issues
rm -rf .phpunit.cache
rm -rf .pest.cache
find . -name "*.cache" -type f -delete 2>/dev/null || true

# Run tests with PHPUnit directly
echo "Running Unit Tests..."
if ./vendor/bin/phpunit --testsuite Unit --no-coverage --colors=never; then
    echo "Unit tests passed, running Feature tests..."
    if ./vendor/bin/phpunit --testsuite Feature --no-coverage --colors=never; then
        echo "All tests passed!"
        exit 0
    else
        echo "Feature tests failed"
        exit 1
    fi
else
    echo "Unit tests failed"
    exit 1
fi
