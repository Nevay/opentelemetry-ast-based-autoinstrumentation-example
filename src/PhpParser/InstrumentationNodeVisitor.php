<?php declare(strict_types=1);
namespace OpenTelemetry\Instrumentation\PhpParser;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use function array_pop;
use function end;
use function sprintf;

final class InstrumentationNodeVisitor extends NodeVisitorAbstract {

    private readonly string $resolveFunction;
    private readonly ?string $filename;

    public function __construct(
        string $resolveFunction,
        ?string $filename = null,
    ) {
        $this->resolveFunction = $resolveFunction;
        $this->filename = $filename;
    }

    /**
     * @var list<Node\FunctionLike>
     */
    private array $functionLike = [];

    public function enterNode(Node $node): Node\Stmt|int|null {
        if ($node instanceof Node\Stmt\Interface_) {
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }
        if ($node instanceof Node\FunctionLike) {
            $this->functionLike[] = $node;
        }

        return null;
    }

    public function leaveNode(Node $node): ?Node\Stmt {
        if ($node instanceof Node\FunctionLike) {
            array_pop($this->functionLike);
        }

        if ($node instanceof Node\Stmt\ClassMethod && !$node->isAbstract()) {
            $node->stmts = $this->hook($node);

            return $node;
        }

        if ($node instanceof Node\Stmt\Return_ && $node->expr && end($this->functionLike) instanceof Node\Stmt\ClassMethod) {
            if (!end($this->functionLike)->returnsByRef()) {
                return new Node\Stmt\Return_(new Node\Expr\Assign(new Node\Expr\Variable($this->variable('return')), $node->expr));
            }

            return new Node\Stmt\If_(
                new Node\Expr\ConstFetch(new Node\Name('true')),
                [
                    'stmts' => [
                        new Node\Stmt\Expression(new Node\Expr\AssignRef(new Node\Expr\Variable($this->variable('return')), $node->expr)),
                        new Node\Stmt\Return_(new Node\Expr\Variable($this->variable('return'))),
                    ],
                ]
            );
        }

        return null;
    }

    public function hook(Node\Stmt\ClassMethod $method): array {
        $target = $method->isStatic() ? new Node\Expr\ClassConstFetch(new Node\Name('static'), 'class') : new Node\Expr\Variable('this');
        $function = new Node\Scalar\String_($method->name->toString());
        $params = new Node\Expr\FuncCall(new Node\Name\FullyQualified('func_get_args'));
        $class = new Node\Scalar\MagicConst\Class_();
        $filename = new Node\Scalar\String_($this->filename);
        $lineno = new Node\Scalar\LNumber($method->getStartLine());

        $hooksVariable = new Node\Expr\Variable($this->variable('hooks'));
        $hooks = new Node\Stmt\Static_([new Node\Stmt\StaticVar($hooksVariable)]);
        $resolveHooks = new Node\Stmt\If_(new Node\Expr\BinaryOp\Identical($hooksVariable, new Node\Expr\ConstFetch(new Node\Name('null'))), [
            'stmts' => [
                new Node\Stmt\Expression(new Node\Expr\Assign($hooksVariable, new Node\Expr\BinaryOp\Coalesce(new Node\Expr\FuncCall(new Node\Name\FullyQualified($this->resolveFunction), [
                    new Node\Arg(new Node\Scalar\MagicConst\Class_()),
                    new Node\Arg($function),
                ]), new Node\Expr\Array_()))),
            ],
        ]);

        $preHookCall = new Node\Expr\FuncCall(new Node\Expr\ArrayDimFetch($hooksVariable, new Node\Scalar\LNumber(0)), [
            new Node\Arg($target),
            new Node\Arg($function),
            new Node\Arg($params),
            new Node\Arg($class),
            new Node\Arg($filename),
            new Node\Arg($lineno),
        ]);
        $postHookCall = new Node\Expr\FuncCall(new Node\Expr\ArrayDimFetch($hooksVariable, new Node\Scalar\LNumber(1)), [
            new Node\Arg($target),
            new Node\Arg($function),
            new Node\Arg($params),
            new Node\Arg(new Node\Expr\BinaryOp\Coalesce(new Node\Expr\Variable($this->variable('return')), new Node\Expr\ConstFetch(new Node\Name('null')))),
            new Node\Arg(new Node\Expr\BinaryOp\Coalesce(new Node\Expr\Variable($this->variable('exception')), new Node\Expr\ConstFetch(new Node\Name('null')))),
            new Node\Arg($class),
            new Node\Arg($filename),
            new Node\Arg($lineno),
        ]);

        $preHookCall = new Node\Expr\Assign(new Node\Expr\Variable($this->variable('args')), $preHookCall);
        if ($method->returnType != 'void') {
            $postHookCall = new Node\Expr\Assign(new Node\Expr\List_([new Node\Expr\ArrayItem(new Node\Expr\Variable($this->variable('result')))]), $postHookCall);
        }

        $preHook = new Node\Stmt\If_(new Node\Expr\BinaryOp\BooleanAnd(new Node\Expr\Isset_([new Node\Expr\ArrayDimFetch($hooksVariable, new Node\Scalar\LNumber(0))]), $preHookCall), [
            'stmts' => [
                new Node\Stmt\Foreach_(
                    new Node\Expr\Variable($this->variable('args')),
                    new Node\Expr\Variable($this->variable('value')),
                    [
                        'keyVar' => new Node\Expr\Variable($this->variable('key')),
                        'stmts' => [new Node\Stmt\Expression($this->generateParameterOverrideExpression($method, $this->variable('key'), $this->variable('value')))],
                    ],
                )
            ],
        ]);
        $preHookCleanup = new Node\Stmt\Unset_([
            new Node\Expr\Variable($this->variable('args')),
            new Node\Expr\Variable($this->variable('key')),
            new Node\Expr\Variable($this->variable('value')),
        ]);

        $try = new Node\Stmt\TryCatch(
            $method->stmts,
            [
                new Node\Stmt\Catch_(
                    [new Node\Name\FullyQualified('Throwable')],
                    new Node\Expr\Variable($this->variable('exception')),
                    [
                        new Node\Stmt\Throw_(new Node\Expr\Variable($this->variable('exception'))),
                    ],
                ),
            ],
            new Node\Stmt\Finally_([
                new Node\Stmt\If_(new Node\Expr\BinaryOp\BooleanAnd(new Node\Expr\Isset_([new Node\Expr\ArrayDimFetch($hooksVariable, new Node\Scalar\LNumber(1))]), $postHookCall), [
                    'stmts' => [
                        $method->returnType == 'void'
                            ? new Node\Stmt\Return_()
                            : new Node\Stmt\Return_(new Node\Expr\Variable($this->variable('result'))),
                    ],
                ]),
            ]),
        );

        return [$hooks, $resolveHooks, $preHook, $preHookCleanup, $try];
    }

    private function generateParameterOverrideExpression(Node\FunctionLike $function, string $key, string $value): Node\Expr {
        $match = new Node\Expr\Match_(new Node\Expr\Variable($key));
        $match->arms[] = new Node\MatchArm(
            [],
            new Node\Expr\FuncCall(new Node\Name\FullyQualified('trigger_error'), [
                new Node\Arg(new Node\Expr\FuncCall(new Node\Name\FullyQualified('sprintf'), [
                    new Node\Arg(new Node\Scalar\String_('Unexpected argument "%s"')),
                    new Node\Arg(new Node\Expr\Variable($key)),
                ])),
            ]),
        );
        foreach ($function->getParams() as $index => $param) {
            $match->arms[] = new Node\MatchArm(
                [
                    new Node\Scalar\LNumber($index),
                    new Node\Scalar\String_($param->var->name),
                ],
                new Node\Expr\Assign($param->var, new Node\Expr\Variable($value)),
            );
        }

        return $match;
    }

    private function variable(string $name): string {
        return sprintf('__otel_%s', $name);
    }
}
