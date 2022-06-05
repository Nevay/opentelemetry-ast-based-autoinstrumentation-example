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
use function file_get_contents;
use function file_put_contents;
use function realpath;
use function sha1;
use function str_starts_with;

final class Autoloader {

    private readonly string $resolveFunction;
    private readonly ClassLoader $classLoader;
    private readonly string $directory;
    private readonly ?Closure $filter;

    private readonly Lexer $lexer;
    private readonly Parser $parser;

    public function __construct(
        string $resolveFunction,
        ClassLoader $classLoader,
        string $directory,
        ?Closure $filter = null,
    ) {
        $this->resolveFunction = $resolveFunction;
        $this->classLoader = $classLoader;
        $this->directory = $directory;
        $this->filter = $filter;

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

        $instrumentedFile = $this->directory . '/generated-' . sha1($file) . '.php';

        try {
            $ast = $this->parser->parse(file_get_contents($file));
        } catch (Error) {
            return;
        }

        $tokens = $this->lexer->getTokens();

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new CloningVisitor());
        $traverser->addVisitor(new NameResolver());
        $stmts = $traverser->traverse($ast);

        $visitor = new InstrumentationNodeVisitor($this->resolveFunction, $file, $this->filter);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $stmts = $traverser->traverse($stmts);

        if (!$visitor->hooked) {
            return;
        }

        $code = (new Standard())->printFormatPreserving($stmts, $ast, $tokens);

        file_put_contents($instrumentedFile, $code);
        require $instrumentedFile;
    }
}
