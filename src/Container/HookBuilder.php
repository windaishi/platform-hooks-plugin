<?php declare(strict_types=1);

namespace Shyim\Hooks\Container;

use PhpParser\BuilderFactory;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter\Standard;
use Shyim\Hooks\Event\AfterHook;
use Shyim\Hooks\Event\BeforeHook;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class HookBuilder
{
    private string $cacheDir;

    private Filesystem $filesystem;

    public function __construct(string $cacheDir)
    {
        $this->filesystem = new Filesystem();
        $this->cacheDir = $cacheDir;
    }

    public function build(array $classes): bool
    {
        $parser = (new ParserFactory())->createForVersion(PhpVersion::getHostVersion());
        $nodeFinder = new NodeFinder();
        $builder = new BuilderFactory();
        $printer = new Standard();

        $hookFile = $this->cacheDir . '/hook.php';

        $code = '';

        foreach ($classes as $className => $filePath) {
            $stmts = $parser->parse(file_get_contents($filePath));

            $parts = explode('\\', $className);

            foreach ($stmts as $key => $stmt) {
                if ($stmt instanceof Declare_) {
                    unset($stmts[$key]);
                }
            }

            $stmts = array_values($stmts);

            /** @var Class_ $class */

            $class = $nodeFinder->findFirstInstanceOf($stmts, Class_::class);

            $class->name = new Identifier($parts[count($parts) - 1] . 'HookProxy');

            $class->extends = new \PhpParser\Node\Name('\\' . $className);

            array_unshift($class->stmts, $builder->property('__internal_eventDispatcher')->getNode());
            array_unshift($class->stmts, $builder->property('service_id')->getNode());

            $propertyFetch = $builder->propertyFetch($builder->var('this'), '__internal_eventDispatcher');
            $propertyFetch2 = $builder->propertyFetch($builder->var('this'), 'service_id');

            $methods = $nodeFinder->findInstanceOf($stmts, ClassMethod::class);
            $didAddedConstructor = false;

            /** @var ClassMethod $method */
            foreach ($methods as $method) {
                $name = (string) $method->name;
                $canHaveReturn = $method->returnType instanceof Identifier && $method->returnType->name === 'void';

                if ($name === '__construct' && !$didAddedConstructor) {
                    $param = $builder->param('__internal_eventDispatcher')->getNode();
                    $param->type = new Identifier('\\' . EventDispatcherInterface::class);
                    $method->params[] = $param;
                    $param = $builder->param('service_id')->getNode();
                    $param->type = new Identifier('string');
                    $method->params[] = $param;
                    $method->stmts[] = new Expression(new Assign($propertyFetch, $builder->var('__internal_eventDispatcher')));
                    $method->stmts[] = new Expression(new Assign($propertyFetch2, $builder->var('service_id')));

                    $didAddedConstructor = true;
                } else {
                    // After Event
                    $returnStmts = $nodeFinder->findInstanceOf($method->stmts, Return_::class);

                    /** @var Return_ $returnStmt */
                    foreach ($returnStmts as $returnStmt) {
                        $originalValue = $returnStmt->expr;

                        $arg = $builder->new('\\' . AfterHook::class, [$builder->funcCall('func_get_args'), $builder->var('this'), $originalValue]);
                        $eventName = '::' . (string) $method->name . '::after';

                        $returnStmt->expr = $builder->methodCall($builder->methodCall(
                            $propertyFetch,
                            'dispatch',
                            [$arg, new Concat($builder->propertyFetch($builder->var('this'), 'service_id'), new String_($eventName))]
                        ), 'getReturn');
                    }

                    // Before Event
                    $arg = $builder->new('\\' . BeforeHook::class, [$builder->funcCall('func_get_args'), $builder->var('this')]);
                    $eventName = '::' . (string) $method->name . '::before';

                    $event = $builder->var('__internal_eventDispatcher');
                    $newStmt = new Expression(new Assign($event, $builder->methodCall($propertyFetch, 'dispatch', [
                        $arg,
                        new Concat($builder->propertyFetch($builder->var('this'), 'service_id'), new String_($eventName))
                    ])));

                    $ret = new Return_($builder->methodCall($event, 'getReturn'));

                    if ($canHaveReturn) {
                        $ret = new Return_();
                    }

                    $condition = new If_($builder->methodCall($event, 'hasReturn'));
                    $condition->stmts = [$ret];

                    foreach ($method->params as $i => $param) {
                        $paramCondition = new If_($builder->methodCall($event, 'hasArgument', [$i]));
                        $paramCondition->stmts = [
                            new Expression(new Assign($param->var, $builder->methodCall($event, 'getArgument', [$i]))),
                        ];

                        array_unshift($method->stmts, $paramCondition);
                    }

                    array_unshift($method->stmts, $condition);
                    array_unshift($method->stmts, $newStmt);
                }
            }

            $code .= mb_substr($printer->prettyPrintFile($stmts), 5);
        }

        $this->filesystem->dumpFile($hookFile, '<?php ' . $code);

        require_once $hookFile;

        return false;
    }
}
