<?php

declare(strict_types=1);

namespace AmoBot\PHPStan\DeadCode;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use ShipMonk\PHPStan\DeadCode\Provider\MemberUsageProvider;

/**
 * The actions Slim calls: a route's handler is `[Controller::class, 'action']` or an invokable `Controller::class`
 * (routes/*.php), resolved through the container when the route matches.
 */
final class SlimRouteUsageProvider implements MemberUsageProvider
{
    private const ROUTES = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'map'];

    public function getUsages(Node $node, Scope $scope): array
    {
        if (!$node instanceof MethodCall || !$node->name instanceof Identifier
            || !in_array($node->name->toLowerString(), self::ROUTES, true)) {
            return [];
        }
        $collector = new ObjectType('Slim\Interfaces\RouteCollectorProxyInterface');
        if (!$collector->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }
        $args = $node->getArgs();
        if ($args === []) {
            return [];
        }
        $handler = $scope->getType($args[count($args) - 1]->value);

        $usages = [];
        foreach ($handler->getConstantArrays() as $callable) {
            $parts = $callable->getValueTypes();
            if (count($parts) !== 2) {
                continue;
            }
            foreach ($parts[0]->getConstantStrings() as $class) {
                foreach ($parts[1]->getConstantStrings() as $action) {
                    $usages[] = $this->usage($class->getValue(), $action->getValue(), $node, $scope);
                }
            }
        }
        foreach ($handler->getConstantStrings() as $class) {
            $usages[] = $this->usage($class->getValue(), '__invoke', $node, $scope);
        }

        return $usages;
    }

    private function usage(string $class, string $method, Node $node, Scope $scope): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createRegular($node, $scope),
            new ClassMethodRef($class, $method, possibleDescendant: false),
        );
    }
}
