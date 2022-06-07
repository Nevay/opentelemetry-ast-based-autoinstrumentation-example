<?php declare(strict_types=1);
namespace OpenTelemetry\Instrumentation\PhpParser;

use Closure;
use Composer\Autoload\ClassLoader;
use function Composer\Autoload\includeFile;
use function sprintf;
use function str_starts_with;

final class Autoloader {

    private readonly ClassLoader $classLoader;
    private readonly string $protocol;

    public function __construct(
        ClassLoader $classLoader,
        string $hookResolver,
        ?Closure $filter = null,
    ) {
        $this->classLoader = $classLoader;
        $this->protocol = InstrumentationStreamFilter::register(new Instrumentation($hookResolver, $filter));
    }

    public function load(string $class): void {
        if (str_starts_with($class, 'PhpParser\\')) {
            return;
        }
        if (str_starts_with($class, 'OpenTelemetry\\Instrumentation\\PhpParser\\')) {
            return;
        }
        if (!$file = $this->classLoader->findFile($class)) {
            return;
        }

        includeFile(sprintf('php://filter/read=%s/resource=%s', $this->protocol, $file));
    }

    public function __destruct() {
        InstrumentationStreamFilter::unregister($this->protocol);
    }
}
