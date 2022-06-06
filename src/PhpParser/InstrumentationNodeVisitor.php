<?php declare(strict_types=1);
namespace OpenTelemetry\Instrumentation\PhpParser;

use Closure;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use function array_pop;
use function end;
use function sprintf;

final class InstrumentationNodeVisitor extends NodeVisitorAbstract {

    private readonly string $resolveFunction;
    private readonly ?Closure $filter;

    /** @var list<Node\Stmt\ClassLike> */
    private array $classes = [];
    /** @var list<Node\Stmt\ClassMethod|Node\Stmt\Function_> */
    private array $functions = [];

    public bool $hooked = false;

    public function __construct(
        string $resolveFunction,
        ?Closure $filter = null,
    ) {
        $this->resolveFunction = $resolveFunction;
        $this->filter = $filter;
    }

    public function enterNode(Node $node): int|null {
        if ($node instanceof Node\Stmt\Interface_) {
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->classes[] = $node;
        }
        if ($node instanceof Node\FunctionLike) {
            $shouldHook = match (true) {
                $node instanceof Node\Stmt\ClassMethod => !$this->filter || ($this->filter)(end($this->classes)->namespacedName->toString(), $node->name->name),
                $node instanceof Node\Stmt\Function_ => !$this->filter || ($this->filter)(null, $node->namespacedName->toString()),
                default => false,
            };

            if ($shouldHook) {
                $this->hooked = true;
                $this->functions[] = $node;
            } else {
                $this->functions[] = null;
            }
        }

        return null;
    }

    public function leaveNode(Node $node): Node\Stmt|array|null {
        if ($node instanceof Node\Stmt\Interface_) {
            return null;
        }
        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->classes);
            return null;
        }
        if ($node instanceof Node\FunctionLike) {
            if ($function = array_pop($this->functions)) {
                $function->stmts = $this->hook($function);
                return $function;
            }
        }
        if ($node instanceof Node\Stmt\Return_ && $node->expr && ($function = end($this->functions))) {
            if (!$function->returnsByRef()) {
                return new Node\Stmt\Return_(new Node\Expr\Assign(new Node\Expr\Variable($this->variable('return')), $node->expr));
            }

            return [
                new Node\Stmt\Expression(new Node\Expr\AssignRef(new Node\Expr\Variable($this->variable('return')), $node->expr)),
                new Node\Stmt\Return_(new Node\Expr\Variable($this->variable('return'))),
            ];
        }

        return null;
    }

    public function hook(Node\FunctionLike $functionLike): array {
        $null = new Node\Expr\ConstFetch(new Node\Name('null'));

        $target = $functionLike instanceof Node\Stmt\ClassMethod
            ? $functionLike->isStatic() ? new Node\Expr\ClassConstFetch(new Node\Name('static'), 'class') : new Node\Expr\Variable('this')
            : $null;
        $function = new Node\Scalar\MagicConst\Function_();
        $params = new Node\Expr\FuncCall(new Node\Name\FullyQualified('func_get_args'));
        $class = $functionLike instanceof Node\Stmt\ClassMethod
            ? new Node\Scalar\MagicConst\Class_()
            : $null;
        $filename = new Node\Scalar\MagicConst\File();
        $lineno = new Node\Scalar\LNumber($functionLike->getStartLine());

        $varArgs = new Node\Expr\Variable($this->variable('args'));
        $varKey = new Node\Expr\Variable($this->variable('key'));
        $varValue = new Node\Expr\Variable($this->variable('value'));

        $varResult = new Node\Expr\Variable($this->variable('result'));

        $varReturn = new Node\Expr\Variable($this->variable('return'));
        $varException = new Node\Expr\Variable($this->variable('exception'));

        $varHooks = new Node\Expr\Variable($this->variable('hooks'));
        $preHook = new Node\Expr\ArrayDimFetch($varHooks, new Node\Scalar\LNumber(0));
        $postHook = new Node\Expr\ArrayDimFetch($varHooks, new Node\Scalar\LNumber(1));

        $declareHooks = new Node\Stmt\Static_([new Node\Stmt\StaticVar($varHooks)]);
        $resolveHooks = new Node\Stmt\If_(new Node\Expr\BinaryOp\Identical($varHooks, $null), [
            'stmts' => [
                new Node\Stmt\Expression(new Node\Expr\Assign($varHooks, new Node\Expr\BinaryOp\Coalesce(new Node\Expr\FuncCall(new Node\Name\FullyQualified($this->resolveFunction), [
                    new Node\Arg($class),
                    new Node\Arg($function),
                ]), new Node\Expr\Array_()))),
            ],
        ]);

        $preHookCall = new Node\Expr\FuncCall($preHook, [
            new Node\Arg($target),
            new Node\Arg($params),
            new Node\Arg($class),
            new Node\Arg($function),
            new Node\Arg($filename),
            new Node\Arg($lineno),
        ]);
        $postHookCall = new Node\Expr\FuncCall($postHook, [
            new Node\Arg($target),
            new Node\Arg($params),
            new Node\Arg(new Node\Expr\BinaryOp\Coalesce($varReturn, $null)),
            new Node\Arg(new Node\Expr\BinaryOp\Coalesce($varException, $null)),
            new Node\Arg($class),
            new Node\Arg($function),
            new Node\Arg($filename),
            new Node\Arg($lineno),
        ]);

        $preHookCall = new Node\Expr\Assign($varArgs, $preHookCall);
        if ($functionLike->returnType != 'void') {
            $postHookCall = new Node\Expr\Assign(new Node\Expr\List_([new Node\Expr\ArrayItem($varResult)]), $postHookCall);
        }

        $preHook = new Node\Stmt\If_(new Node\Expr\BinaryOp\BooleanAnd(new Node\Expr\Isset_([$preHook]), $preHookCall), [
            'stmts' => [
                new Node\Stmt\Foreach_(
                    $varArgs,
                    $varValue,
                    [
                        'keyVar' => $varKey,
                        'stmts' => [new Node\Stmt\Expression($this->generateParameterOverrideExpression($functionLike, $varKey, $varValue))],
                    ],
                )
            ],
        ]);
        $preHookCleanup = new Node\Stmt\Unset_([$varArgs, $varKey, $varValue]);

        $try = new Node\Stmt\TryCatch(
            $functionLike->getStmts(),
            [
                new Node\Stmt\Catch_(
                    [new Node\Name\FullyQualified('Throwable')],
                    $varException,
                    [new Node\Stmt\Throw_($varException)],
                ),
            ],
            new Node\Stmt\Finally_([
                new Node\Stmt\If_(new Node\Expr\BinaryOp\BooleanAnd(new Node\Expr\Isset_([$postHook]), $postHookCall), [
                    'stmts' => [
                        $functionLike->getReturnType() == 'void'
                            ? new Node\Stmt\Return_()
                            : new Node\Stmt\Return_($varResult),
                    ],
                ]),
            ]),
        );

        return [$declareHooks, $resolveHooks, $preHook, $preHookCleanup, $try];
    }

    private function generateParameterOverrideExpression(Node\FunctionLike $function, Node\Expr\Variable $key, Node\Expr\Variable $value): Node\Expr {
        $match = new Node\Expr\Match_($key);
        $match->arms[] = new Node\MatchArm(
            [],
            new Node\Expr\ErrorSuppress(new Node\Expr\FuncCall(new Node\Name\FullyQualified('trigger_error'), [
                new Node\Arg(new Node\Expr\FuncCall(new Node\Name\FullyQualified('sprintf'), [
                    new Node\Arg(new Node\Scalar\String_('Unexpected argument "%s"')),
                    new Node\Arg($key),
                ])),
                new Node\Expr\ConstFetch(new Node\Name\FullyQualified('E_USER_DEPRECATED')),
            ])),
        );
        foreach ($function->getParams() as $index => $param) {
            $match->arms[] = new Node\MatchArm(
                [
                    new Node\Scalar\LNumber($index),
                    new Node\Scalar\String_($param->var->name),
                ],
                new Node\Expr\Assign($param->var, $value),
            );
        }

        return $match;
    }

    private function variable(string $name): string {
        return sprintf('__otel_%s', $name);
    }
}
