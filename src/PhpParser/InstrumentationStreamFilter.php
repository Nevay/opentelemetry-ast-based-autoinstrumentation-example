<?php declare(strict_types=1);
namespace OpenTelemetry\Instrumentation\PhpParser;

use php_user_filter;
use function spl_object_id;
use function sprintf;
use function stream_bucket_append;
use function stream_bucket_make_writeable;
use function stream_bucket_new;
use function stream_filter_register;
use function strlen;
use const PSFS_FEED_ME;
use const PSFS_PASS_ON;

final class InstrumentationStreamFilter extends php_user_filter {

    /** @var array<string, Instrumentation> */
    private static array $registered = [];

    private readonly Instrumentation $instrumentation;
    private string $data;

    public static function register(Instrumentation $instrumentation): ?string {
        static $registered;
        if (!$registered ??= stream_filter_register('otel-instrumentation.*', self::class)) {
            return null;
        }

        $protocol = sprintf('otel-instrumentation.%d', spl_object_id($instrumentation));
        self::$registered[$protocol] = $instrumentation;

        return $protocol;
    }

    public static function unregister(string $protocol): void {
        unset(self::$registered[$protocol]);
    }

    public function filter($in, $out, &$consumed, bool $closing): int {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $this->data = $bucket->data;
        }

        if (!$closing) {
            return PSFS_FEED_ME;
        }

        $code = $this->data;
        $this->data = '';

        $consumed += strlen($code);
        $code = $this->instrumentation->hook($code);

        $bucket = stream_bucket_new($this->stream, $code);
        stream_bucket_append($out, $bucket);

        return PSFS_PASS_ON;
    }

    public function onCreate(): bool {
        if (!isset(self::$registered[$this->filtername])) {
            return false;
        }

        $this->instrumentation = self::$registered[$this->filtername];

        return true;
    }
}
