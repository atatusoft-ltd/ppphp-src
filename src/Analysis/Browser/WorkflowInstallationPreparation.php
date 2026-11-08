<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Analysis\Browser;

use Atatusoft\Ppphp\Config\PhpTarget;
use Atatusoft\Ppphp\Interop\Php\Signature\PhpSignaturePackageLoader;

/** Verifies the immutable installation before any project bytes are admitted. */
final class WorkflowInstallationPreparation
{
    public function prepare(PhpSignaturePackageLoader $signatures, WorkflowProtocol $protocol): void
    {
        $signatures->verifyPackage(PhpTarget::DEFAULT);
        $signatures->retainImmutableVerification(PhpTarget::DEFAULT);
        $protocol->prepareInstallationIdentity();
    }
}
