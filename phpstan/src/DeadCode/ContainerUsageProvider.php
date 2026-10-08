<?php

declare(strict_types=1);

namespace AmoBot\PHPStan\DeadCode;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InArrowFunctionNode;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Node\InClosureNode;
use PHPStan\Reflection\ParameterReflection;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use ShipMonk\PHPStan\DeadCode\Provider\MemberUsageProvider;

/**
 * The constructors PHP-DI calls: the container builds a service by its constructor (autowiring), so the detector, which
 * sees only `new`, is told where a class is asked of it — named (`X::class`: a container's get, a definition, a route's
 * or a task's or a handler's registration, an Eloquent model a relation reads) or injected (a constructor's parameter
 * types; a top-level closure's — bootstrap/container.php's factories, routes/bot.php's labels). Each usage starts where
 * it is written, so a class only a dead service injects is dead as well.
 */
final class ContainerUsageProvider implements MemberUsageProvider
{
    public function getUsages(Node $node, Scope $scope): array
    {
        if ($node instanceof ClassConstFetch && $node->class instanceof Name && $node->name instanceof Identifier
            && $node->name->toLowerString() === 'class') {
            return [$this->constructorOf($scope->resolveName($node->class), $node, $scope)];
        }

        if ($node instanceof InClassMethodNode && $node->getMethodReflection()->getName() === '__construct') {
            return $this->injected($node->getMethodReflection()->getOnlyVariant()->getParameters(), $node, $scope);
        }

        if (($node instanceof InClosureNode || $node instanceof InArrowFunctionNode) && !$scope->isInClass()) {
            return $this->injected($node->getClosureType()->getParameters(), $node, $scope);
        }

        return [];
    }

    /**
     * @param list<ParameterReflection> $parameters
     * @return list<ClassMethodUsage>
     */
    private function injected(array $parameters, Node $node, Scope $scope): array
    {
        $usages = [];
        foreach ($parameters as $parameter) {
            foreach ($parameter->getType()->getObjectClassNames() as $class) {
                $usages[] = $this->constructorOf($class, $node, $scope);
            }
        }

        return $usages;
    }

    private function constructorOf(string $class, Node $node, Scope $scope): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createRegular($node, $scope),
            new ClassMethodRef($class, '__construct', possibleDescendant: false),
        );
    }
}
