<?php

declare(strict_types=1);

namespace Rector\Core\PhpParser;

use Nette\Utils\FileSystem;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\Php\PhpPropertyReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\TypeWithClassName;
use Rector\Core\PhpParser\Node\BetterNodeFinder;
use Rector\Core\Reflection\ReflectionResolver;
use Rector\Core\ValueObject\Application\File;
use Rector\Core\ValueObject\MethodName;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\NodeTypeResolver\NodeScopeAndMetadataDecorator;
use Rector\NodeTypeResolver\NodeTypeResolver;
use Rector\PhpDocParser\PhpParser\SmartPhpParser;

/**
 * The nodes provided by this resolver is for read-only analysis only!
 * They are not part of node tree processed by Rector, so any changes will not make effect in final printed file.
 */
final class AstResolver
{
    /**
     * Parsing files is very heavy performance, so this will help to leverage it
     * The value can be also null, as the method might not exist in the class.
     *
     * @var array<class-string, array<string, ClassMethod|null>>
     */
    private array $classMethodsByClassAndMethod = [];

    /**
     * Parsing files is very heavy performance, so this will help to leverage it
     * The value can be also null, as the method might not exist in the class.
     *
     * @var array<string, Function_|null>>
     */
    private array $functionsByName = [];

    public function __construct(
        private readonly SmartPhpParser $smartPhpParser,
        private readonly NodeScopeAndMetadataDecorator $nodeScopeAndMetadataDecorator,
        private readonly BetterNodeFinder $betterNodeFinder,
        private readonly NodeNameResolver $nodeNameResolver,
        private readonly ReflectionProvider $reflectionProvider,
        private readonly ReflectionResolver $reflectionResolver,
        private readonly NodeTypeResolver $nodeTypeResolver,
        private readonly ClassLikeAstResolver $classLikeAstResolver
    ) {
    }

    public function resolveClassFromName(string $className): Class_ | Trait_ | Interface_ | Enum_ | null
    {
        if (! $this->reflectionProvider->hasClass($className)) {
            return null;
        }

        $classReflection = $this->reflectionProvider->getClass($className);
        return $this->resolveClassFromClassReflection($classReflection);
    }

    public function resolveClassFromObjectType(
        TypeWithClassName $typeWithClassName
    ): Class_ | Trait_ | Interface_ | Enum_ | null {
        return $this->resolveClassFromName($typeWithClassName->getClassName());
    }

    public function resolveClassMethodFromMethodReflection(MethodReflection $methodReflection): ?ClassMethod
    {
        $classReflection = $methodReflection->getDeclaringClass();

        $classLikeName = $classReflection->getName();
        $methodName = $methodReflection->getName();

        if (isset($this->classMethodsByClassAndMethod[$classLikeName][$methodName])) {
            return $this->classMethodsByClassAndMethod[$classLikeName][$methodName];
        }

        // saved as null data
        if (array_key_exists($classLikeName, $this->classMethodsByClassAndMethod)
            && array_key_exists($methodName, $this->classMethodsByClassAndMethod[$classLikeName])) {
            return null;
        }

        $fileName = $classReflection->getFileName();

        // probably native PHP method → un-parseable
        if ($fileName === null) {
            return null;
        }

        $nodes = $this->parseFileNameToDecoratedNodes($fileName);
        if ($nodes === null) {
            return null;
        }

        /** @var ClassLike[] $classLikes */
        $classLikes = $this->betterNodeFinder->findInstanceOf($nodes, ClassLike::class);

        foreach ($classLikes as $classLike) {
            if (! $this->nodeNameResolver->isName($classLike, $classLikeName)) {
                continue;
            }

            $classMethod = $classLike->getMethod($methodName);
            if (! $classMethod instanceof ClassMethod) {
                continue;
            }

            $this->classMethodsByClassAndMethod[$classLikeName][$methodName] = $classMethod;
            return $classMethod;
        }

        // avoids looking for a class in a file where is not present
        $this->classMethodsByClassAndMethod[$classLikeName][$methodName] = null;
        return null;
    }

    public function resolveClassMethodOrFunctionFromCall(
        FuncCall | StaticCall | MethodCall $call,
        Scope $scope
    ): ClassMethod | Function_ | null {
        if ($call instanceof FuncCall) {
            return $this->resolveFunctionFromFuncCall($call, $scope);
        }

        return $this->resolveClassMethodFromCall($call);
    }

    public function resolveFunctionFromFunctionReflection(FunctionReflection $functionReflection): ?Function_
    {
        $functionName = $functionReflection->getName();

        if (isset($this->functionsByName[$functionName])) {
            return $this->functionsByName[$functionName];
        }

        // saved as null data
        if (array_key_exists($functionName, $this->functionsByName)) {
            return null;
        }

        $fileName = $functionReflection->getFileName();
        if ($fileName === null) {
            return null;
        }

        $nodes = $this->parseFileNameToDecoratedNodes($fileName);
        if ($nodes === null) {
            return null;
        }

        /** @var Function_[] $functions */
        $functions = $this->betterNodeFinder->findInstanceOf($nodes, Function_::class);
        foreach ($functions as $function) {
            if (! $this->nodeNameResolver->isName($function, $functionName)) {
                continue;
            }

            // to avoid parsing missing function again
            $this->functionsByName[$functionName] = $function;

            return $function;
        }

        // to avoid parsing missing function again
        $this->functionsByName[$functionName] = null;

        return null;
    }

    /**
     * @param class-string $className
     */
    public function resolveClassMethod(string $className, string $methodName): ?ClassMethod
    {
        $methodReflection = $this->reflectionResolver->resolveMethodReflection($className, $methodName, null);
        if (! $methodReflection instanceof MethodReflection) {
            return null;
        }

        $classMethod = $this->resolveClassMethodFromMethodReflection($methodReflection);

        if (! $classMethod instanceof ClassMethod) {
            return $this->locateClassMethodInTrait($methodName, $methodReflection);
        }

        return $classMethod;
    }

    public function resolveClassMethodFromCall(MethodCall | StaticCall $call): ?ClassMethod
    {
        if ($call instanceof MethodCall) {
            $callerStaticType = $this->nodeTypeResolver->getType($call->var);
        } else {
            $callerStaticType = $this->nodeTypeResolver->getType($call->class);
        }

        if (! $callerStaticType instanceof TypeWithClassName) {
            return null;
        }

        $methodName = $this->nodeNameResolver->getName($call->name);
        if ($methodName === null) {
            return null;
        }

        return $this->resolveClassMethod($callerStaticType->getClassName(), $methodName);
    }

    public function resolveClassFromClassReflection(
        ClassReflection $classReflection
    ): Trait_ | Class_ | Interface_ | Enum_ | null {
        return $this->classLikeAstResolver->resolveClassFromClassReflection($classReflection);
    }

    /**
     * @return Trait_[]
     */
    public function parseClassReflectionTraits(ClassReflection $classReflection): array
    {
        /** @var ClassReflection[] $classLikes */
        $classLikes = $classReflection->getTraits(true);
        $traits = [];
        foreach ($classLikes as $classLike) {
            $fileName = $classLike->getFileName();
            if ($fileName === null) {
                continue;
            }

            $nodes = $this->parseFileNameToDecoratedNodes($fileName);
            if ($nodes === null) {
                continue;
            }

            /** @var Trait_|null $trait */
            $trait = $this->betterNodeFinder->findFirst(
                $nodes,
                fn (Node $node): bool => $node instanceof Trait_ && $this->nodeNameResolver->isName(
                    $node,
                    $classLike->getName()
                )
            );

            if (! $trait instanceof Trait_) {
                continue;
            }

            $traits[] = $trait;
        }

        return $traits;
    }

    public function resolvePropertyFromPropertyReflection(
        PhpPropertyReflection $phpPropertyReflection
    ): Property | Param | null {
        $classReflection = $phpPropertyReflection->getDeclaringClass();

        $fileName = $classReflection->getFileName();
        if ($fileName === null) {
            return null;
        }

        $nodes = $this->parseFileNameToDecoratedNodes($fileName);
        if ($nodes === null) {
            return null;
        }

        $nativeReflectionProperty = $phpPropertyReflection->getNativeReflection();
        $desiredPropertyName = $nativeReflectionProperty->getName();

        /** @var Property[] $properties */
        $properties = $this->betterNodeFinder->findInstanceOf($nodes, Property::class);
        foreach ($properties as $property) {
            if ($this->nodeNameResolver->isName($property, $desiredPropertyName)) {
                return $property;
            }
        }

        // promoted property
        return $this->findPromotedPropertyByName($nodes, $desiredPropertyName);
    }

    private function locateClassMethodInTrait(string $methodName, MethodReflection $methodReflection): ?ClassMethod
    {
        $classReflection = $methodReflection->getDeclaringClass();
        $traits = $this->parseClassReflectionTraits($classReflection);

        /** @var ClassMethod|null $classMethod */
        $classMethod = $this->betterNodeFinder->findFirst(
            $traits,
            fn (Node $node): bool => $node instanceof ClassMethod && $this->nodeNameResolver->isName($node, $methodName)
        );

        $this->classMethodsByClassAndMethod[$classReflection->getName()][$methodName] = $classMethod;
        if ($classMethod instanceof ClassMethod) {
            return $classMethod;
        }

        return null;
    }

    /**
     * @return Stmt[]|null
     */
    private function parseFileNameToDecoratedNodes(string $fileName): ?array
    {
        $stmts = $this->smartPhpParser->parseFile($fileName);
        if ($stmts === []) {
            return null;
        }

        $file = new File($fileName, FileSystem::read($fileName));
        return $this->nodeScopeAndMetadataDecorator->decorateNodesFromFile($file, $stmts);
    }

    /**
     * @param Stmt[] $stmts
     */
    private function findPromotedPropertyByName(array $stmts, string $desiredPropertyName): ?Param
    {
        $class = $this->betterNodeFinder->findFirstInstanceOf($stmts, Class_::class);
        if (! $class instanceof Class_) {
            return null;
        }

        $constructClassMethod = $class->getMethod(MethodName::CONSTRUCT);
        if (! $constructClassMethod instanceof ClassMethod) {
            return null;
        }

        foreach ($constructClassMethod->getParams() as $param) {
            if ($param->flags === 0) {
                continue;
            }

            if ($this->nodeNameResolver->isName($param, $desiredPropertyName)) {
                return $param;
            }
        }

        return null;
    }

    private function resolveFunctionFromFuncCall(FuncCall $funcCall, Scope $scope): ?Function_
    {
        if ($funcCall->name instanceof Expr) {
            return null;
        }

        if (! $this->reflectionProvider->hasFunction($funcCall->name, $scope)) {
            return null;
        }

        $functionReflection = $this->reflectionProvider->getFunction($funcCall->name, $scope);
        return $this->resolveFunctionFromFunctionReflection($functionReflection);
    }
}
