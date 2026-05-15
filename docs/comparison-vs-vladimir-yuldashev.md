# Comparison vs vladimir-yuldashev/laravel-rabbitmq

| Feature                        | iamfarhad/LaravelRabbitMQ          | vladimir-yuldashev/laravel-rabbitmq |
|--------------------------------|------------------------------------|-------------------------------------|
| Native ext-amqp                | Yes (high performance)             | PHP-AMQP / fallback                 |
| Connection / Channel Pooling   | Advanced                           | Basic                               |
| Laravel Horizon integration    | Full                               | Partial                             |
| Octane support                 | Yes                                | No                                  |
| Production multi-host support  | Yes                                | Limited                             |
| Quorum + Priority queues       | Yes                                | Limited                             |