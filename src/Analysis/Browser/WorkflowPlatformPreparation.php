<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

use Atatusoft\Ppphp\Analysis\Experimental\Enumerations\ScriptPlatformScope;
use Atatusoft\Ppphp\Config\PhpTarget;
use Atatusoft\Ppphp\Interop\Php\Signature\PhpSignaturePackageLoader;
use Atatusoft\Ppphp\Semantic\SemanticAnalyzer;

/** Prepares only the verified default platform declarations. */
final class WorkflowPlatformPreparation
{
    public function prepare(PhpSignaturePackageLoader $signatures, SemanticAnalyzer $semanticAnalyzer): void
    {
        $result = $signatures->load(PhpTarget::DEFAULT, []);

        if (!$result->isSuccessful) {
            throw new \RuntimeException('The default PHP platform could not be prepared.');
        }

        $semanticAnalyzer->preparePlatformDeclarations($result);
    }

    public function prepareProofDeclarations(PhpSignaturePackageLoader $signatures, SemanticAnalyzer $semanticAnalyzer): void
    {
        foreach (ScriptPlatformScope::cases() as $scope) {
            $class = $scope->selectPlatformClass();
            if ($class === null) {
                continue;
            }

            $result = $signatures->loadClassDocument(PhpTarget::DEFAULT, $class);
            if ($result !== null) {
                $semanticAnalyzer->preparePlatformDeclarations($result);
            }
        }
    }
}
