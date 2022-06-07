<?php declare(strict_types=1);
namespace OpenTelemetry\Instrumentation\PhpParser;

use Closure;
use PhpParser\ErrorHandler;
use PhpParser\Lexer;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

final class Instrumentation {

    private readonly Lexer $lexer;
    private readonly Parser $parser;
    private readonly Standard $printer;

    public readonly string $hookResolver;
    public readonly ?Closure $filter;

    public function __construct(
        string $hookResolver,
        ?Closure $filter = null,
    ) {
        $this->hookResolver = $hookResolver;
        $this->filter = $filter;

        $this->lexer = new Lexer([
            'usedAttributes' => [
                'comments',
                'startLine', 'endLine',
                'startTokenPos', 'endTokenPos',
            ],
        ]);
        $this->parser = (new ParserFactory())->create(ParserFactory::ONLY_PHP7, $this->lexer);
        $this->printer = new Standard();
    }

    public function hook(string $code): string {
        $errorHandler = new ErrorHandler\Collecting();

        $ast = $this->parser->parse($code, $errorHandler);
        $tokens = $this->lexer->getTokens();
        self::releaseState($this->lexer);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new CloningVisitor());
        $traverser->addVisitor(new NameResolver());
        $stmts = $traverser->traverse($ast);

        $visitor = new InstrumentationNodeVisitor($this->hookResolver, $this->filter);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $stmts = $traverser->traverse($stmts);

        if (!$visitor->hooked) {
            return $code;
        }

        return $this->printer->printFormatPreserving($stmts, $ast, $tokens);
    }

    private static function releaseState(Lexer $lexer): void {
        static $release;
        $release ??= (static function($lexer): void {
            $lexer->code = null;
            $lexer->tokens = null;
        })->bindTo(null, Lexer::class);

        $release($lexer);
    }
}
