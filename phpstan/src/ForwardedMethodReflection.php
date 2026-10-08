<?php

declare(strict_types=1);

namespace AmoBot\PHPStan;

use PHPStan\PhpDoc\ResolvedPhpDocBlock;
use PHPStan\Reflection\Annotations\AnnotationsMethodParameterReflection;
use PHPStan\Reflection\Assertions;
use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedFunctionVariant;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ExtendedParametersAcceptor;
use PHPStan\Reflection\PassedByReference;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;

/**
 * A public, non-static method `name(mixed ...$arguments): <returnType>` on the given class.
 */
final class ForwardedMethodReflection implements ExtendedMethodReflection
{
    public function __construct(
        private readonly ClassReflection $declaringClass,
        private readonly string $name,
        private readonly Type $returnType,
    ) {}

    public function getDeclaringClass(): ClassReflection
    {
        return $this->declaringClass;
    }

    public function isStatic(): bool
    {
        return false;
    }

    public function isPrivate(): bool
    {
        return false;
    }

    public function isPublic(): bool
    {
        return true;
    }

    public function getDocComment(): ?string
    {
        return null;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPrototype(): ClassMemberReflection
    {
        return $this;
    }

    /** @return list<ExtendedFunctionVariant> */
    public function getVariants(): array
    {
        return [$this->getOnlyVariant()];
    }

    public function getOnlyVariant(): ExtendedParametersAcceptor
    {
        $arguments = new AnnotationsMethodParameterReflection(
            'arguments',
            new MixedType(),
            PassedByReference::createNo(),
            true,
            true,
            null,
        );

        return new ExtendedFunctionVariant(
            TemplateTypeMap::createEmpty(),
            null,
            [$arguments],
            true,
            $this->returnType,
            $this->returnType,
            new MixedType(),
        );
    }

    public function getNamedArgumentsVariants(): ?array
    {
        return null;
    }

    public function acceptsNamedArguments(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function isDeprecated(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function getDeprecatedDescription(): ?string
    {
        return null;
    }

    public function isFinal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function isFinalByKeyword(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function isInternal(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function isBuiltin(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function isAbstract(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function getThrowType(): ?Type
    {
        return null;
    }

    public function hasSideEffects(): TrinaryLogic
    {
        return TrinaryLogic::createMaybe();
    }

    public function getAsserts(): Assertions
    {
        return Assertions::createEmpty();
    }

    public function getSelfOutType(): ?Type
    {
        return null;
    }

    public function returnsByReference(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function isPure(): TrinaryLogic
    {
        return TrinaryLogic::createMaybe();
    }

    public function getPureUnlessCallableIsImpureParameters(): array
    {
        return [];
    }

    public function getAttributes(): array
    {
        return [];
    }

    public function mustUseReturnValue(): TrinaryLogic
    {
        return TrinaryLogic::createNo();
    }

    public function getResolvedPhpDoc(): ?ResolvedPhpDocBlock
    {
        return null;
    }
}
