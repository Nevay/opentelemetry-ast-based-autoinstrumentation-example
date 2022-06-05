```php
$classLoader = require __DIR__ . '/vendor/autoload.php';
spl_autoload_register((new Autoloader('resolveHooks', $classLoader, 'generated'))->load(...), prepend: true);

function resolveHooks(string $class, string $function) {
    return [
        function(object|string|null $target, string $function, array $params, ?string $class, ?string $filename, ?int $lineno) {
            echo "Entering $class::$function", PHP_EOL;
        },
        function(object|string|null $target, string $function, array $params, mixed $returnValue, ?Throwable $exception, ?string $class, ?string $filename, ?int $lineno) {
            echo "Leaving $class::$function", PHP_EOL;
        },
    ];
}
```
