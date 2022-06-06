<?php declare(strict_types=1);
namespace OpenTelemetry\Instrumentation\PhpParser;

use function feof;
use function fread;
use function fseek;
use function fstat;
use function ftell;
use function stream_wrapper_register;
use function substr;

final class AutoloaderStream {

    /** * @var array<string, resource> */
    public static array $loaders = [];
    /** @var resource */
    private $stream;

    public function stream_open(string $path, string $mode, int $options, string &$opened_path = null): bool {
        $opened_path = substr($path, strlen('otel-loader://'));
        $this->stream = self::$loaders[$opened_path];

        return true;
    }

    public function stream_read(int $count): string {
        return fread($this->stream, $count);
    }

    public function stream_tell(): int {
        return ftell($this->stream);
    }

    public function stream_eof(): bool {
        return feof($this->stream);
    }

    public function stream_seek(int $offset, int $whence): bool {
        return !fseek($this->stream, $offset, $whence);
    }

    public function stream_set_option(): bool {
        return false;
    }

    public function stream_stat(): array|false {
        return fstat($this->stream);
    }
}

stream_wrapper_register('otel-loader', AutoloaderStream::class);