<?php declare(strict_types=1);
namespace OpenTelemetry\Instrumentation\PhpParser;

use Closure;
use Composer\Autoload\ClassLoader;
use PhpParser\Error;
use PhpParser\Lexer;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use function Composer\Autoload\includeFile;
use function file_get_contents;
use function file_put_contents;
use function fopen;
use function fseek;
use function fwrite;
use function realpath;
use function sha1;
use function str_starts_with;

final class Autoloader {

    private readonly ClassLoader $classLoader;
    private readonly string $resolveFunction;
    private readonly ?Closure $filter;
    private readonly ?string $directory;

    private readonly Lexer $lexer;
    private readonly Parser $parser;

    public function __construct(
        ClassLoader $classLoader,
        string $resolveFunction,
        ?Closure $filter = null,
        ?string $directory = null,
    ) {
        $this->classLoader = $classLoader;
        $this->resolveFunction = $resolveFunction;
        $this->filter = $filter;
        $this->directory = $directory;

        $this->lexer = new Lexer([
            'usedAttributes' => [
                'comments',
                'startLine', 'endLine',
                'startTokenPos', 'endTokenPos',
            ],
        ]);
        $this->parser = (new ParserFactory())->create(ParserFactory::ONLY_PHP7, $this->lexer);
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
        if (!$file = realpath($file)) {
            return;
        }

        $code = $this->parse(file_get_contents($file));
        if ($code === null) {
            return;
        }

        AutoloaderStream::$loaders[$file] = $this->save($code, $file);
        try {
            includeFile('otel-loader://' . $file);
        } finally {
            unset(AutoloaderStream::$loaders[$file]);
        }
    }

    private function save(string $code, ?string $file = null) {
        if ($this->directory) {
            $instrumentedFile = $this->directory . '/generated-' . sha1($file ?? $code) . '.php';
            file_put_contents($instrumentedFile, $code);
        }

        $instrumented = fopen('php://temp', 'r+');
        fwrite($instrumented, $code);
        fseek($instrumented, 0);

        return $instrumented;
    }

    private function parse(string $content): ?string {
        try {
            $ast = $this->parser->parse($content);
        } catch (Error) {
            return null;
        }

        $tokens = $this->lexer->getTokens();

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new CloningVisitor());
        $traverser->addVisitor(new NameResolver());
        $stmts = $traverser->traverse($ast);

        $visitor = new InstrumentationNodeVisitor($this->resolveFunction, $this->filter);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $stmts = $traverser->traverse($stmts);

        if (!$visitor->hooked) {
            return null;
        }

        return (new Standard())->printFormatPreserving($stmts, $ast, $tokens);
    }
}
