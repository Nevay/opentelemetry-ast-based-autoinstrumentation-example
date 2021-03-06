### Setup / Usage

```php
$classLoader = require __DIR__ . '/vendor/autoload.php';
spl_autoload_register((new Autoloader($classLoader, 'resolveHooks'))->load(...), prepend: true);

function resolveHooks(?string $class, string $function): ?array {
    return [
        function(object|string|null $target, array $params, ?string $class, string $function, ?string $filename, ?int $lineno) {
            echo "Entering $class::$function", PHP_EOL;
        },
        function(object|string|null $target, array $params, mixed $returnValue, ?Throwable $exception, ?string $class, string $function, ?string $filename, ?int $lineno) {
            echo "Leaving $class::$function", PHP_EOL;
        },
    ];
}
```


### Generated code

```php
# original
public function send(RequestInterface $request, array $options = []): ResponseInterface
{
    $options[RequestOptions::SYNCHRONOUS] = true;
    return $this->sendAsync($request, $options)->wait();
}

# instrumented
public function send(RequestInterface $request, array $options = []) : ResponseInterface
{
    static $__otel_hooks;
    if ($__otel_hooks === null) {
        $__otel_hooks = \resolveHooks(__CLASS__, __FUNCTION__) ?? array();
    }
    if (isset($__otel_hooks[0]) && ($__otel_args = $__otel_hooks[0]($this, \func_get_args(), __CLASS__, __FUNCTION__, __FILE__, 120))) {
        foreach ($__otel_args as $__otel_key => $__otel_value) {
            match ($__otel_key) {
                default => \trigger_error(\sprintf('Unexpected argument "%s"', $__otel_key)),
                0, 'request' => $request = $__otel_value,
                1, 'options' => $options = $__otel_value,
            };
        }
    }
    unset($__otel_args, $__otel_key, $__otel_value);
    try {
        $options[RequestOptions::SYNCHRONOUS] = true;
        return $__otel_return = $this->sendAsync($request, $options)->wait();
    } catch (\Throwable $__otel_exception) {
        throw $__otel_exception;
    } finally {
        if (isset($__otel_hooks[1]) && (list($__otel_result) = $__otel_hooks[1]($this, \func_get_args(), $__otel_return ?? null, $__otel_exception ?? null, __CLASS__, __FUNCTION__, __FILE__, 120))) {
            return $__otel_result;
        }
    }
}
```