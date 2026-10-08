<?php

declare(strict_types=1);

namespace AmoBot\PHPStan;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\ThisType;
use PHPStan\Type\Type;
use PHPStan\Type\VoidType;

/**
 * Teaches PHPStan about the query-builder methods that Eloquent's Builder (and relations) forward
 * through __call. Without this every `->orderBy()` / `->whereIn()` / `->limit()` chain degrades to a
 * plain Query\Builder and model types are lost. Registered in phpstan.neon.
 */
final class EloquentBuilderExtension implements MethodsClassReflectionExtension
{
    private const TARGETS = [
        'Illuminate\Database\Eloquent\Builder',
        'Illuminate\Database\Eloquent\Relations\Relation',
    ];

    /** Methods that keep the chain going (return $this). */
    private const FLUENT = [
        'select', 'selectRaw', 'selectSub', 'addSelect', 'distinct', 'from', 'fromSub', 'fromRaw',
        'join', 'joinWhere', 'joinSub', 'leftJoin', 'leftJoinWhere', 'leftJoinSub', 'rightJoin', 'rightJoinSub', 'crossJoin',
        'whereRaw', 'orWhereRaw', 'whereIn', 'orWhereIn', 'whereNotIn', 'orWhereNotIn', 'whereIntegerInRaw', 'whereIntegerNotInRaw',
        'whereNull', 'orWhereNull', 'whereNotNull', 'orWhereNotNull', 'whereBetween', 'orWhereBetween', 'whereNotBetween', 'orWhereNotBetween',
        'whereBetweenColumns', 'whereNotBetweenColumns', 'whereDate', 'orWhereDate', 'whereTime', 'orWhereTime', 'whereDay', 'whereMonth', 'whereYear',
        'whereColumn', 'orWhereColumn', 'whereExists', 'orWhereExists', 'whereNotExists', 'orWhereNotExists',
        'whereJsonContains', 'whereJsonDoesntContain', 'whereJsonLength', 'whereFullText', 'orWhereFullText',
        'whereLike', 'orWhereLike', 'whereNotLike', 'orWhereNotLike', 'whereAny', 'whereAll', 'whereNone', 'whereNested',
        'groupBy', 'groupByRaw', 'having', 'orHaving', 'havingRaw', 'orHavingRaw', 'havingBetween', 'havingNull', 'havingNotNull',
        'orderBy', 'orderByDesc', 'orderByRaw', 'inRandomOrder', 'reorder',
        'limit', 'take', 'offset', 'skip', 'forPage', 'forPageBeforeId', 'forPageAfterId',
        'lock', 'lockForUpdate', 'sharedLock', 'union', 'unionAll', 'useWritePdo', 'useIndex', 'forceIndex', 'ignoreIndex',
    ];

    /** Terminal methods and what they return. */
    private const TERMINAL = [
        'count' => 'int',
        'sum' => 'mixed',
        'avg' => 'mixed',
        'average' => 'mixed',
        'min' => 'mixed',
        'max' => 'mixed',
        'aggregate' => 'mixed',
        'exists' => 'bool',
        'doesntExist' => 'bool',
        'existsOr' => 'mixed',
        'doesntExistOr' => 'mixed',
        'insert' => 'bool',
        'insertOrIgnore' => 'int',
        'insertGetId' => 'int',
        'insertUsing' => 'int',
        'update' => 'int',
        'updateFrom' => 'int',
        'increment' => 'int',
        'decrement' => 'int',
        'incrementEach' => 'int',
        'decrementEach' => 'int',
        'delete' => 'mixed',
        'truncate' => 'void',
        'value' => 'mixed',
        'rawValue' => 'mixed',
        'soleValue' => 'mixed',
        'pluck' => 'collection',
        'implode' => 'string',
        'toSql' => 'string',
        'toRawSql' => 'string',
        'getBindings' => 'array',
        'getRawBindings' => 'array',
        'dump' => 'mixed',
        'dd' => 'void',
    ];

    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        if (!in_array($methodName, self::FLUENT, true) && !isset(self::TERMINAL[$methodName])) {
            return false;
        }

        foreach (self::TARGETS as $target) {
            if ($classReflection->getName() === $target || $classReflection->isSubclassOf($target)) {
                return true;
            }
        }

        return false;
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        $returnType = in_array($methodName, self::FLUENT, true)
            ? new ThisType($classReflection)
            : self::terminalType(self::TERMINAL[$methodName]);

        return new ForwardedMethodReflection($classReflection, $methodName, $returnType);
    }

    private static function terminalType(string $kind): Type
    {
        return match ($kind) {
            'int' => new IntegerType(),
            'bool' => new BooleanType(),
            'string' => new StringType(),
            'void' => new VoidType(),
            'array' => new ArrayType(new MixedType(), new MixedType()),
            'collection' => new ObjectType('Illuminate\Support\Collection'),
            default => new MixedType(),
        };
    }
}
