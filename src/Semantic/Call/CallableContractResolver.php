<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Semantic\Call;

use Atatusoft\Ppphp\Analysis\Declaration\DeclarationOrigin;
use Atatusoft\Ppphp\Interop\Php\Intrinsic\IntrinsicFunctionRepository;
use Atatusoft\Ppphp\Semantic\Effect\CallableErrorContract;
use Atatusoft\Ppphp\Semantic\SemanticContext;
use Atatusoft\Ppphp\Semantic\SourceNameResolver;
use Atatusoft\Ppphp\Semantic\Symbol\ClassSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\FunctionSymbol;
use Atatusoft\Ppphp\Semantic\Symbol\MethodSymbol;
use Atatusoft\Ppphp\Semantic\Type\Interfaces\Type;
use Atatusoft\Ppphp\Semantic\Type\AtomicType;
use Atatusoft\Ppphp\Semantic\Type\GenericType;
use Atatusoft\Ppphp\Semantic\Type\MemberResolutionStatus;
use Atatusoft\Ppphp\Semantic\Type\MemberTypeResolver;
use PhpParser\Node;

final readonly class CallableContractResolver
{
    private IntrinsicFunctionRepository $intrinsics;

    private MemberTypeResolver $members;

    private SourceNameResolver $sourceNames;

    public function __construct(private SemanticContext $context)
    {
        $this->intrinsics = new IntrinsicFunctionRepository();
        $this->members = new MemberTypeResolver($context->symbols);
        $this->sourceNames = new SourceNameResolver();
    }

    public function resolveFunction(Node\Name $name): ResolvedCallable
    {
        $rawName = $name->toString();
        $resolvedName = $this->context->resolvedNames->resolve($name) ?? $rawName;
        $lexicalName = $resolvedName;
        $symbol = null;

        if ($name->isUnqualified()) {
            $namespace = $this->sourceNames->resolveNamespaceAt(
                $this->context->parsedFile,
                $name->getStartFilePos(),
            );
            $namespaced = $namespace === '' ? $rawName : $namespace . '\\' . $rawName;
            $imported = strcasecmp($resolvedName, $rawName) === 0 ? null : $resolvedName;
            $symbol = $imported !== null
                ? $this->context->symbols->findFunction($imported)
                : ($this->context->symbols->findFunction($namespaced)
                    ?? $this->context->symbols->findFunction($rawName));
            $lexicalName = $imported ?? $namespaced;
        } else {
            $symbol = $this->context->symbols->findFunction($resolvedName);
        }

        $intrinsic = $this->intrinsics->find($symbol->fullyQualifiedName ?? $resolvedName);

        if ($symbol !== null) {
            if ($symbol->sourceFile->declarationOrigin === DeclarationOrigin::PhpPlatform
                && $intrinsic !== null) {
                return ResolvedCallable::found($intrinsic);
            }

            return ResolvedCallable::found($this->fromFunction($symbol));
        }

        if ($intrinsic !== null) {
            return ResolvedCallable::found($intrinsic);
        }

        if (!$name->isUnqualified() && $this->belongsToProjectNamespace($lexicalName)) {
            return new ResolvedCallable(
                CallableResolutionStatus::Missing,
                provenance: 'known-project-namespace',
            );
        }

        return new ResolvedCallable(
            CallableResolutionStatus::DeferredExternal,
            provenance: 'portable-external-function-index-unavailable',
        );
    }

    public function resolveMethod(Type $receiver, string $name): ResolvedCallable
    {
        $resolution = $this->members->resolveMethod($receiver, $name);

        if ($resolution->status === MemberResolutionStatus::DeferredExternal
            || $resolution->status === MemberResolutionStatus::UnknownReceiver) {
            return new ResolvedCallable(
                CallableResolutionStatus::DeferredExternal,
                provenance: implode(', ', $resolution->unresolvedReceivers),
            );
        }

        if (!$resolution->complete || $resolution->targets === []) {
            return new ResolvedCallable(
                CallableResolutionStatus::Missing,
                provenance: implode(', ', $resolution->unresolvedReceivers),
            );
        }

        $contracts = [];

        foreach ($resolution->targets as $target) {
            $member = $target['member'];

            if (!$member instanceof MethodSymbol) {
                continue;
            }

            $contract = $this->fromMethod($member, $target['owner'], $target['substitutions']);
            $contracts[$this->contractShape($contract)][] = $contract;
        }

        if (count($contracts) !== 1) {
            return new ResolvedCallable(
                CallableResolutionStatus::Ambiguous,
                provenance: 'receiver arms expose incompatible method contracts',
            );
        }

        return ResolvedCallable::found($this->mergeCompatibleContracts(array_values($contracts)[0]));
    }

    public function resolveConstructor(Type $receiver): ResolvedCallable
    {
        $resolved = $this->resolveMethod($receiver, '__construct');

        if ($resolved->status !== CallableResolutionStatus::Missing) {
            return $resolved;
        }

        $name = match (true) {
            $receiver instanceof GenericType => $receiver->base->name,
            $receiver instanceof AtomicType && !$receiver->isBuiltin => $receiver->name,
            default => null,
        };
        $class = $name === null ? null : $this->context->symbols->findClass($name);

        if ($class === null) {
            return $resolved;
        }

        return ResolvedCallable::found(new CallableContract(
            CallableKind::Constructor,
            $class->fullyQualifiedName . '::__construct',
            $class->fullyQualifiedName,
            $this->origin($class->sourceFile->declarationOrigin),
            [],
            null,
            null,
            [],
            \Atatusoft\Ppphp\Semantic\Effect\CallableErrorContract::createEmpty($class->declarationSpan),
            'public',
            false,
            false,
            $class->declarationSpan,
            $class->selectionSpan,
        ));
    }

    private function fromFunction(FunctionSymbol $symbol): CallableContract
    {
        return new CallableContract(
            CallableKind::Function,
            $symbol->fullyQualifiedName,
            null,
            $this->origin($symbol->sourceFile->declarationOrigin),
            $symbol->parameters,
            $symbol->effectiveReturnType,
            $symbol->genericDeclaration,
            [],
            $symbol->errorContract,
            'public',
            true,
            $symbol->hasBody,
            $symbol->declarationSpan,
            $symbol->selectionSpan,
            $symbol,
        );
    }

    /** @param array<string, Type> $substitutions */
    private function fromMethod(MethodSymbol $symbol, ClassSymbol $owner, array $substitutions): CallableContract
    {
        return new CallableContract(
            strtolower($symbol->name) === '__construct' ? CallableKind::Constructor : CallableKind::Method,
            $owner->fullyQualifiedName . '::' . $symbol->name,
            $owner->fullyQualifiedName,
            $this->origin($owner->sourceFile->declarationOrigin),
            $symbol->parameters,
            $symbol->effectiveReturnType,
            $symbol->genericDeclaration,
            $substitutions,
            $symbol->errorContract,
            $symbol->visibility,
            $symbol->static,
            $symbol->hasBody,
            $symbol->declarationSpan,
            $symbol->selectionSpan,
            $symbol,
        );
    }

    private function origin(DeclarationOrigin $origin): CallableOrigin
    {
        return match ($origin) {
            DeclarationOrigin::ProjectPpphp => CallableOrigin::ProjectPpphp,
            DeclarationOrigin::ProjectPhp => CallableOrigin::ProjectPhp,
            DeclarationOrigin::ConfiguredStub => CallableOrigin::ConfiguredStub,
            DeclarationOrigin::ComposerDependency,
            DeclarationOrigin::ConditionalComposerDependency => CallableOrigin::ComposerDependency,
            DeclarationOrigin::PhpPlatform => CallableOrigin::PhpPlatform,
            DeclarationOrigin::IntrinsicOverride => CallableOrigin::IntrinsicOverride,
        };
    }

    private function belongsToProjectNamespace(string $name): bool
    {
        return $this->context->symbols->isKnownClassNamespace($name);
    }

    private function contractShape(CallableContract $contract): string
    {
        $parameterShapes = [];

        foreach ($contract->parameters as $parameter) {
            $type = $parameter->effectiveType();
            $parameterShapes[] = ($type === null ? '?' : $type->canonical)
                . ($parameter->variadic ? '...' : '')
                . ($parameter->byReference ? '&' : '');
        }

        $returnType = $contract->returnType;

        return implode('|', [
            (string) count($contract->parameters),
            ...$parameterShapes,
            $returnType === null ? '?' : $returnType->canonical,
            $contract->static ? 'static' : 'instance',
        ]);
    }

    /** @param non-empty-list<CallableContract> $contracts */
    private function mergeCompatibleContracts(array $contracts): CallableContract
    {
        $selected = $contracts[0];
        $errors = $selected->errorContract->declaredErrors;

        foreach (array_slice($contracts, 1) as $contract) {
            $errors = $errors->combine($contract->errorContract->declaredErrors);
        }

        if (count($contracts) === 1) {
            return $selected;
        }

        return new CallableContract(
            $selected->kind,
            $selected->identity,
            $selected->owner,
            $selected->origin,
            $selected->parameters,
            $selected->returnType,
            $selected->genericDeclaration,
            $selected->receiverSubstitutions,
            new CallableErrorContract($errors, $selected->errorContract->ownerSpan),
            $selected->visibility,
            $selected->static,
            $selected->hasBody,
            $selected->declarationSpan,
            $selected->selectionSpan,
            $selected->sourceSymbol,
        );
    }
}
