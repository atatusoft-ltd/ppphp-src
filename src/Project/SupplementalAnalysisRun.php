<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Project;

use Atatusoft\Ppphp\Analysis\AnalysisProject;
use Atatusoft\Ppphp\Analysis\AnalysisResult;
use Atatusoft\Ppphp\Analysis\AnalysisWorkspacePreparer;
use Atatusoft\Ppphp\Diagnostics\Diagnostic;
use Atatusoft\Ppphp\Diagnostics\DiagnosticBag;
use Atatusoft\Ppphp\Diagnostics\Enumerations\DiagnosticCode;

/** Shared native/resumable analysis: source contracts first, emitted annotations second. */
final class SupplementalAnalysisRun
{
    public AnalysisProject $pendingProject;
    public ?AnalysisResult $result = null;
    private ?AnalysisResult $sourceResult = null;
    /** @var array<string, array<int, list<string>>> */
    private array $omissions = [];
    /** @var array<string, array<int, list<string>>> */
    private array $owned = [];

    public function __construct(
        private readonly SupplementalAnalysisPreparation $preparation,
        private readonly AnalysisWorkspacePreparer $workspaces = new AnalysisWorkspacePreparer(),
    ) {
        $this->pendingProject = $preparation->analysisProject ?? throw new \LogicException('Missing prepared analysis.');
        foreach ($this->pendingProject->selectedFiles as $file) {
            foreach ($file->generatedAnnotationOrigins as $origin) {
                foreach ($origin['names'] as $name) {
                    $this->owned[$file->sourceFile->path][$origin['owner']][] = $name;
                }
            }
        }
    }

    public function advance(AnalysisResult $observation): void
    {
        if ($this->result !== null) {
            throw new \LogicException('Analysis is already complete.');
        }
        if (!$observation->isSuccessful) {
            $this->fail($observation);
            return;
        }
        if ($this->sourceResult === null) {
            $this->sourceResult = $observation;
            if ($this->owned === []) {
                $this->finish();
                return;
            }
        } else {
            $changed = false;
            foreach ($observation->localAnnotationOmissions as $path => $owners) {
                foreach ($owners as $owner => $names) {
                    foreach ($names as $name) {
                        if (!in_array($name, $this->owned[$path][$owner] ?? [], true)
                            || in_array($name, $this->omissions[$path][$owner] ?? [], true)) {
                            $this->fail(new AnalysisResult(new DiagnosticBag([new Diagnostic(
                                DiagnosticCode::StaticAnalysisResultInvalid,
                                'Static analysis returned inconsistent annotation advice.',
                                help: 'Run the command again with --debug and report the failure if it persists.',
                            )])));
                            return;
                        }
                        $this->omissions[$path][$owner][] = $name;
                        $changed = true;
                    }
                }
            }
            if (!$changed) {
                $this->finish();
                return;
            }
        }
        // Re-lower all selected peers together. Fresh processes and the actual
        // candidate files also cover parser rereads from trait/method analysis.
        $candidate = $this->workspaces->prepare($this->preparation->compilerAnalysis, true, $this->omissions);
        if (!$candidate->isSuccessful || $candidate->project === null) {
            $this->fail(new AnalysisResult($candidate->diagnostics));
            return;
        }
        $this->pendingProject = $candidate->project;
    }

    private function finish(): void
    {
        $source = $this->sourceResult ?? throw new \LogicException('Missing authoritative source analysis.');
        $this->result = new AnalysisResult($source->diagnostics, $source->metadata, $this->omissions);
    }

    private function fail(AnalysisResult $observation): void
    {
        $diagnostics = new DiagnosticBag();
        if ($this->sourceResult !== null) {
            $diagnostics->addAll($this->sourceResult->diagnostics);
        }
        $diagnostics->addAll($observation->diagnostics);
        $this->result = new AnalysisResult($diagnostics, $observation->metadata);
    }
}
