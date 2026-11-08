<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\PhpDoc;

use Atatusoft\Ppphp\Semantic\Type\CompositeTypeParser;
use Atatusoft\Ppphp\Semantic\Type\TypeParameter;
use Atatusoft\Ppphp\Source\Span;
use PhpParser\Comment\Doc;

final readonly class PhpDocTypeImporter
{
    public function __construct(
        private PhpDocReader $reader = new PhpDocReader(),
        private CompositeTypeParser $types = new CompositeTypeParser(),
    ) {}

    public function hasTemplates(?Doc $document): bool
    {
        return $document !== null && str_contains($document->getText(), '@template')
            && $this->reader->readMetadata($document)->templates !== [];
    }

    /** @return list<TypeParameter> */
    public function importTemplates(?Doc $document, string $ownerKey, Span $span): array
    {
        return array_map(
            fn (array $template): TypeParameter => new TypeParameter(
                $template['name'],
                $template['bound'] === null
                    ? null
                    : $this->types->parse($this->normalizeType($template['bound'])),
                $ownerKey,
                $span,
            ),
            $this->reader->readMetadata($document)->templates,
        );
    }

    private function normalizeType(string $type): string
    {
        return strcasecmp($type, 'array-key') === 0 ? 'int|string' : $type;
    }
}
