<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

use Atatusoft\Ppphp\Config\PhpTarget;
use Atatusoft\Ppphp\Interop\Php\Signature\PhpSignaturePackageLoader;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;

/** Prepares only the installed compiler and its default platform declarations. */
final class WorkflowPlatformPreparation
{
    public function prepare(PhpSignaturePackageLoader $signatures, WorkflowProtocol $protocol, SemanticAnalyzer $semanticAnalyzer): void
    {
        $result = $signatures->load(PhpTarget::DEFAULT, []);

        if (!$result->isSuccessful) {
            throw new \RuntimeException('The default PHP platform could not be prepared.');
        }

        $semanticAnalyzer->preparePlatformDeclarations($result);
        $protocol->prepareInstallationIdentity();
        $signatures->retainImmutableVerification(PhpTarget::DEFAULT);
    }
}
