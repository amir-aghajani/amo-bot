<?php

declare(strict_types=1);

namespace AmoBot\PHPStan\DeadCode;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodRef;
use ShipMonk\PHPStan\DeadCode\Graph\ClassMethodUsage;
use ShipMonk\PHPStan\DeadCode\Graph\UsageOrigin;
use ShipMonk\PHPStan\DeadCode\Provider\MemberUsageProvider;

/**
 * What Eloquent calls on a model by name: the hooks it boots a model with (`boot<Trait>` — BelongsToBot's shop scope),
 * and a relation read without its method being called — as a property (`$order->payments`) or by its name, in the
 * calls that take relation names (`with('payments.order')`, `withCount()`, `whereHas()`, `load()` …, each segment of a
 * path) or in a list a model hands such a call (Bot::statCounts(): 'customers', 'orders as sold_count'). The
 * detector's own Eloquent support counts every relation as used; a relation here exists only with a caller (CLAUDE.md,
 * Conventions), so phpstan.neon switches that support off for this.
 */
final class ModelUsageProvider implements MemberUsageProvider
{
    private const MODEL = 'Illuminate\Database\Eloquent\Model';

    /** Calls that name relations in their arguments: a name, a list of them, or a map of name => constraint. */
    private const BY_NAME = [
        'with', 'withonly', 'without', 'withwherehas', 'load', 'loadmissing', 'loadcount', 'withcount', 'withsum',
        'withavg', 'withmin', 'withmax', 'withexists', 'has', 'orhas', 'doesnthave', 'ordoesnthave', 'wherehas',
        'orwherehas', 'wheredoesnthave', 'orwheredoesnthave', 'whererelation', 'orwhererelation', 'relationloaded',
        'getrelation', 'setrelation',
    ];

    public function getUsages(Node $node, Scope $scope): array
    {
        if ($node instanceof InClassNode && $node->getClassReflection()->is(self::MODEL)) {
            return $this->hooks($node->getClassReflection(), $node, $scope);
        }

        if (($node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch) && $node->name instanceof Identifier) {
            return $this->asProperty($scope->getType($node->var), $node->name->toString(), $node, $scope);
        }

        if (($node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof StaticCall)
            && $node->name instanceof Identifier && in_array($node->name->toLowerString(), self::BY_NAME, true)) {
            $usages = [];
            foreach ($node->getArgs() as $arg) {
                foreach ($this->names($scope->getType($arg->value)) as $path) {
                    $usages = [...$usages, ...$this->path(self::MODEL, $path, $node, $scope)];
                }
            }

            return $usages;
        }

        $class = $scope->isInClass() ? $scope->getClassReflection() : null;
        if ($node instanceof Array_ && $class !== null && $class->is(self::MODEL) && $scope->getFunction() !== null) {
            $usages = [];
            foreach ($node->items as $item) {
                $types = $item->key !== null ? [$scope->getType($item->key), $scope->getType($item->value)] : [$scope->getType($item->value)];
                foreach ($types as $type) {
                    foreach ($type->getConstantStrings() as $string) {
                        $usages = [...$usages, ...$this->path($class->getName(), $string->getValue(), $node, $scope)];
                    }
                }
            }

            return $usages;
        }

        return [];
    }

    /**
     * What Eloquent calls on a model by name as it boots one: its own hooks and each trait's `boot<Trait>` /
     * `initialize<Trait>`.
     *
     * @return list<ClassMethodUsage>
     */
    private function hooks(ClassReflection $model, Node $node, Scope $scope): array
    {
        $usages = [];
        foreach (['boot', 'booted', 'casts'] as $hook) {
            $usages[] = $this->usage($model->getName(), $hook, $node, $scope);
        }
        foreach ($model->getTraits(true) as $trait) {
            foreach (['boot', 'initialize'] as $prefix) {
                $usages[] = $this->usage($model->getName(), $prefix . $trait->getNativeReflection()->getShortName(), $node, $scope);
            }
        }

        return $usages;
    }

    /**
     * A relation read as a property of a model.
     *
     * @return list<ClassMethodUsage>
     */
    private function asProperty(Type $type, string $name, Node $node, Scope $scope): array
    {
        $usages = [];
        foreach ($type->getObjectClassNames() as $class) {
            if ((new ObjectType(self::MODEL))->isSuperTypeOf(new ObjectType($class))->yes()) {
                $usages[] = $this->usage($class, $name, $node, $scope);
            }
        }

        return $usages;
    }

    /**
     * A relation path — `payments.order:id,status`, `orders as sold_count` —: its first relation is `$model`'s, the
     * ones after it some model's.
     *
     * @return list<ClassMethodUsage>
     */
    private function path(string $model, string $path, Node $node, Scope $scope): array
    {
        $usages = [];
        foreach (explode('.', $path) as $i => $segment) {
            $relation = trim(explode(' ', explode(':', trim($segment))[0])[0]);
            if ($relation !== '') {
                $usages[] = $this->usage($i === 0 ? $model : self::MODEL, $relation, $node, $scope);
            }
        }

        return $usages;
    }

    /**
     * The relation paths an argument names: a string, or a list's values and a map's string keys.
     *
     * @return list<string>
     */
    private function names(Type $type): array
    {
        $names = [];
        foreach ($type->getConstantStrings() as $string) {
            $names[] = $string->getValue();
        }
        foreach ($type->getConstantArrays() as $array) {
            foreach ([...$array->getKeyTypes(), ...$array->getValueTypes()] as $part) {
                foreach ($part->getConstantStrings() as $string) {
                    $names[] = $string->getValue();
                }
            }
        }

        return $names;
    }

    private function usage(string $class, string $method, Node $node, Scope $scope): ClassMethodUsage
    {
        return new ClassMethodUsage(
            UsageOrigin::createRegular($node, $scope),
            new ClassMethodRef($class, $method, possibleDescendant: true),
        );
    }
}
