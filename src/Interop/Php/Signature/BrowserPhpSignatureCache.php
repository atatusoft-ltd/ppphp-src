<?php

declare(strict_types=1);

namespace Atatusoft\Ppphp\Interop\Php\Signature;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Stmt;

/** Optional cache owned by the trusted Wasm host, outside the project mount. */
final class BrowserPhpSignatureCache
{
    public const string PATH = '/tmp/ppphp-platform-signatures.cache';
    public const int MAXIMUM_BYTES = 8 * 1024 * 1024;
    private const string FORMAT = 'ppphp-platform-signatures-v1';

    // The reviewed platform stubs use only these data classes. A new node kind
    // falls back to parsing until explicitly supported by the cache.
    private const array AST_CLASSES = [
        Comment::class,
        Comment\Doc::class,
        Node\Arg::class,
        Node\Attribute::class,
        Node\AttributeGroup::class,
        Node\Const_::class,
        Node\Expr\Array_::class,
        Node\Expr\BinaryOp\BitwiseOr::class,
        Node\Expr\BinaryOp\Mul::class,
        Node\Expr\ClassConstFetch::class,
        Node\Expr\ConstFetch::class,
        Node\Expr\New_::class,
        Node\Expr\Throw_::class,
        Node\Expr\UnaryMinus::class,
        Node\Expr\Variable::class,
        Node\Identifier::class,
        Node\IntersectionType::class,
        Node\Name::class,
        Node\Name\FullyQualified::class,
        Node\NullableType::class,
        Node\Param::class,
        Node\PropertyItem::class,
        Node\Scalar\Float_::class,
        Node\Scalar\Int_::class,
        Node\Scalar\String_::class,
        Stmt\ClassConst::class,
        Stmt\ClassMethod::class,
        Stmt\Class_::class,
        Stmt\Const_::class,
        Stmt\EnumCase::class,
        Stmt\Enum_::class,
        Stmt\Expression::class,
        Stmt\Function_::class,
        Stmt\Interface_::class,
        Stmt\Namespace_::class,
        Stmt\Nop::class,
        Stmt\Property::class,
        Stmt\Trait_::class,
        Node\UnionType::class,
        Node\VarLikeIdentifier::class,
    ];

    private ?string $key = null;
    /** @var array<string, string> */
    private array $documents = [];
    private int $documentBytes = 0;
    private bool $dirty = false;

    public function __construct(private readonly string $hostIdentity, private readonly string $path = self::PATH)
    {
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/', $hostIdentity) !== 1) {
            throw new \InvalidArgumentException('Invalid browser signature cache identity.');
        }
    }

    public function useVerifiedPackage(string $target, string $packageIdentity): void
    {
        $key = hash('sha256', self::FORMAT . "\0" . $this->hostIdentity . "\0" . $target . "\0" . $packageIdentity);
        if ($key === $this->key) {
            return;
        }

        $this->key = $key;
        $this->documents = [];
        $this->documentBytes = 0;
        $this->dirty = false;

        if (!is_file($this->path) || is_link($this->path)) {
            return;
        }
        $bytes = @file_get_contents($this->path, length: self::MAXIMUM_BYTES + 1);
        // Check framing and corruption before decoding. The bytes come from
        // the host's private cache; the checksum is not an authenticity check.
        $header = $key . "\n";
        if ($bytes === false || strlen($bytes) > self::MAXIMUM_BYTES || !str_starts_with($bytes, $header)) {
            return;
        }
        $payload = substr($bytes, strlen($header) + 65);
        if (!hash_equals(substr($bytes, strlen($header), 65), hash('sha256', $payload) . "\n")) {
            return;
        }
        $documents = @unserialize($payload, ['allowed_classes' => false, 'max_depth' => 2]);
        if (!is_array($documents)) {
            return;
        }
        foreach ($documents as $path => $ast) {
            if (!is_string($path) || !is_string($ast)) {
                return;
            }
        }
        $this->documents = $documents;
        $this->documentBytes = strlen($payload);
    }

    /** @return list<Stmt>|null */
    public function restore(string $document): ?array
    {
        $bytes = $this->documents[$document] ?? null;
        if ($bytes === null) {
            return null;
        }
        try {
            $statements = @unserialize($bytes, ['allowed_classes' => self::AST_CLASSES, 'max_depth' => 64]);
            $budget = strlen($bytes);
            if (is_array($statements) && array_is_list($statements)) {
                $restored = [];
                foreach ($statements as $statement) {
                    if (!$statement instanceof Stmt || !self::isAstData($statement, $budget)) {
                        break;
                    }
                    $restored[] = $statement;
                }
                if (count($restored) === count($statements)) {
                    return $restored;
                }
            }
        } catch (\Throwable) {
            // Invalid cached objects are disposable.
        }
        unset($this->documents[$document]);
        return null;
    }

    /** @param list<Stmt> $statements */
    public function remember(string $document, array $statements): void
    {
        $bytes = serialize($statements);
        $size = strlen($document) + strlen($bytes) + 64;
        if ($size + $this->documentBytes <= self::MAXIMUM_BYTES - 256) {
            $this->documents[$document] = $bytes;
            $this->documentBytes += $size;
            $this->dirty = true;
        }
    }

    public function save(): void
    {
        if (!$this->dirty || is_link($this->path)) {
            return;
        }
        $payload = serialize($this->documents);
        $bytes = $this->key . "\n" . hash('sha256', $payload) . "\n" . $payload;
        if (strlen($bytes) <= self::MAXIMUM_BYTES) {
            // One owned compiler guest writes this file. The host imports it
            // only after successful completion, and rejects oversized data.
            @file_put_contents($this->path, $bytes);
        }
    }

    private static function isAstData(mixed $value, int &$budget, int $depth = 0): bool
    {
        if ($depth > 64 || --$budget < 0) {
            return false;
        }
        if (is_object($value)) {
            if (!$value instanceof Node && !$value instanceof Comment) {
                return false;
            }
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                if (!self::isAstData($child, $budget, $depth + 1)) {
                    return false;
                }
            }
        }
        return true;
    }
}
