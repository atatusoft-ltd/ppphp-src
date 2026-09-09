#!/usr/bin/env python3
"""Prepare a pinned, disposable PHP-WASM build. Never changes installed packages."""
from __future__ import annotations

import argparse
import hashlib
import json
import shutil
import subprocess
from pathlib import Path

UPSTREAM = 'a6ed3872674399baa47c2f55fe1e660633fc8051'
PHP_COMMIT = '52cee85adfeeb6f017f2ac796ab7973353702c20'
PHP_VERSION = '8.4.23'
DOCKER_BLOB = '81f85293110155e19e190f7fc27522c6c4851c84'
FIBERS_BLOB = 'd571a622e476ba2a7f889e1111ee3c309ac71099'
BASE_BLOB = '893f51c1b543661fbca89d8e9d58c24361ed9ac8'


def blob_sha(data: bytes) -> str:
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def replace_exact(source: str, old: str, new: str, count: int = 1) -> str:
    found = source.count(old)
    if found != count:
        raise ValueError(f'Patch context changed: expected {count}, found {found}: {old[:100]!r}')
    return source.replace(old, new)


def patch_fibers(source: str) -> str:
    """Experimental Asyncify backend; all normal/native paths remain conditional."""
    if blob_sha(source.encode()) != FIBERS_BLOB:
        raise ValueError('Unexpected Zend Fiber source; refuse an unreviewed PHP revision')
    source = replace_exact(source, '#include "zend_fibers_arginfo.h"', '''#include "zend_fibers_arginfo.h"

/* Experimental Emscripten backend. The downstream builder selects Asyncify. */
#if defined(__EMSCRIPTEN__)
# if !defined(ZEND_FIBER_UCONTEXT)
#  error "The Emscripten Fiber build requires --disable-fiber-asm"
# endif
# if defined(__SANITIZE_ADDRESS__)
#  error "The experimental Emscripten Fiber backend is not ASan-qualified"
# endif
# define ZEND_FIBER_EMSCRIPTEN 1
# undef ZEND_FIBER_UCONTEXT
# include <emscripten/fiber.h>
#endif''')
    source = replace_exact(source, '''#ifdef ZEND_FIBER_UCONTEXT
	/* Embedded ucontext to avoid unnecessary memory allocations. */''', '''#ifdef ZEND_FIBER_EMSCRIPTEN
	/* The continuation stack is allocated immediately after this structure. */
	emscripten_fiber_t emscripten_context;
#elif defined(ZEND_FIBER_UCONTEXT)
	/* Embedded ucontext to avoid unnecessary memory allocations. */''')
    source = replace_exact(source, '#ifdef ZEND_FIBER_UCONTEXT\nZEND_TLS zend_fiber_transfer *transfer_data;', '#if defined(ZEND_FIBER_UCONTEXT) || defined(ZEND_FIBER_EMSCRIPTEN)\nZEND_TLS zend_fiber_transfer *transfer_data;')
    source = replace_exact(source, '\tzend_fiber_stack *stack = emalloc(sizeof(zend_fiber_stack));', '''#ifdef ZEND_FIBER_EMSCRIPTEN
	zend_fiber_stack *stack = safe_emalloc(1, stack_size, sizeof(zend_fiber_stack));
#else
	zend_fiber_stack *stack = emalloc(sizeof(zend_fiber_stack));
#endif''')
    source = replace_exact(source, '#if !defined(ZEND_FIBER_UCONTEXT) && BOOST_CONTEXT_SHADOW_STACK', '#if !defined(ZEND_FIBER_UCONTEXT) && !defined(ZEND_FIBER_EMSCRIPTEN) && BOOST_CONTEXT_SHADOW_STACK', 2)
    source = replace_exact(source, '''#ifdef ZEND_FIBER_UCONTEXT
static ZEND_NORETURN void zend_fiber_trampoline(void)''', '''#ifdef ZEND_FIBER_EMSCRIPTEN
static ZEND_NORETURN void zend_fiber_trampoline(void *unused)
#elif defined(ZEND_FIBER_UCONTEXT)
static ZEND_NORETURN void zend_fiber_trampoline(void)''')
    source = replace_exact(source, '#ifdef ZEND_FIBER_UCONTEXT\n\tzend_fiber_transfer transfer = *transfer_data;', '#if defined(ZEND_FIBER_UCONTEXT) || defined(ZEND_FIBER_EMSCRIPTEN)\n\tzend_fiber_transfer transfer = *transfer_data;')
    source = replace_exact(source, '#ifndef ZEND_FIBER_UCONTEXT', '#if !defined(ZEND_FIBER_UCONTEXT) && !defined(ZEND_FIBER_EMSCRIPTEN)', 2)
    source = replace_exact(source, '''#ifdef ZEND_FIBER_UCONTEXT
	ucontext_t *handle = &context->stack->ucontext;''', '''#ifdef ZEND_FIBER_EMSCRIPTEN
	emscripten_fiber_t *handle = &context->stack->emscripten_context;
	emscripten_fiber_init(handle, zend_fiber_trampoline, NULL,
		context->stack->pointer, context->stack->size,
		(void *) (context->stack + 1), context->stack->size);
	context->handle = handle;
#elif defined(ZEND_FIBER_UCONTEXT)
	ucontext_t *handle = &context->stack->ucontext;''')
    source = replace_exact(source, '''#ifdef ZEND_FIBER_UCONTEXT
	transfer_data = transfer;

	swapcontext(from->handle, to->handle);''', '''#if defined(ZEND_FIBER_UCONTEXT) || defined(ZEND_FIBER_EMSCRIPTEN)
	transfer_data = transfer;

# ifdef ZEND_FIBER_EMSCRIPTEN
	emscripten_fiber_swap(from->handle, to->handle);
# else
	swapcontext(from->handle, to->handle);
# endif''')
    source = replace_exact(source, '#if defined(__SANITIZE_ADDRESS__) || defined(ZEND_FIBER_UCONTEXT)', '#if defined(__SANITIZE_ADDRESS__) || defined(ZEND_FIBER_UCONTEXT) || defined(ZEND_FIBER_EMSCRIPTEN)', 2)
    source = replace_exact(source, '''	// Main fiber stack is only needed if ASan or ucontext is enabled.
	context->stack = emalloc(sizeof(zend_fiber_stack));

#ifdef ZEND_FIBER_UCONTEXT
	context->handle = &context->stack->ucontext;''', '''	// Allocate main context metadata without owning the original C stack.
# ifdef ZEND_FIBER_EMSCRIPTEN
	context->stack = safe_emalloc(1, ZEND_FIBER_DEFAULT_C_STACK_SIZE, sizeof(zend_fiber_stack));
	context->handle = &context->stack->emscripten_context;
	emscripten_fiber_init_from_current_context(context->handle,
		(void *) (context->stack + 1), ZEND_FIBER_DEFAULT_C_STACK_SIZE);
	context->stack->pointer = context->stack->emscripten_context.stack_limit;
	context->stack->size = (uintptr_t) context->stack->emscripten_context.stack_base
		- (uintptr_t) context->stack->emscripten_context.stack_limit;
# else
	context->stack = emalloc(sizeof(zend_fiber_stack));
# endif

#ifdef ZEND_FIBER_UCONTEXT
	context->handle = &context->stack->ucontext;''')
    return source


def prepare_dockerfile(source: str, *, candidate: bool, jobs: int) -> str:
    if jobs not in range(1, 5):
        raise ValueError('Build parallelism must be between one and four')
    if blob_sha(source.encode()) != DOCKER_BLOB:
        raise ValueError('Unexpected upstream Dockerfile')
    source = replace_exact(source, 'FROM playground-php-wasm:base', f'FROM playground-php-wasm:base\nENV BINARYEN_CORES={jobs}')
    # Verify the cloned tag before any upstream/downstream PHP source changes.
    marker = '# Work around memory leak due to PHP using Emscripten\'s incomplete mmap/munmap support'
    check = f'RUN test "$(git -C /root/php-src rev-parse HEAD)" = "{PHP_COMMIT}"\n'
    if candidate:
        check += 'COPY ./compile/ppphp-prepare.py /root/ppphp-prepare.py\nRUN python3 /root/ppphp-prepare.py --patch-fibers /root/php-src/Zend/zend_fibers.c\n'
    source = replace_exact(source, marker, check + '\n' + marker)
    source = replace_exact(source, 'emmake make -j14', f'emmake make -j{jobs}')
    # g2 preserves function names at the existing O3 optimization level. Not DWARF.
    source = replace_exact(source, '-s ASSERTIONS=0 \\\n', '-g2 -s ASSERTIONS=1 \\\n')
    if candidate:
        # Correctness first: instrument indirect paths instead of a partial allowlist.
        source = replace_exact(source, '-s ASYNCIFY_IGNORE_INDIRECT=1', '-s ASYNCIFY_IGNORE_INDIRECT=0')
        source = replace_exact(source, '$(cat /root/.emcc-php-asyncify-flags) ";', '$(cat /root/.emcc-php-asyncify-flags) -s ASYNCIFY_ONLY=[] ";')
    return source


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--patch-fibers', type=Path)
    parser.add_argument('--upstream', type=Path)
    parser.add_argument('--candidate', action='store_true')
    parser.add_argument('--jobs', type=int, default=2)
    args = parser.parse_args()
    if args.patch_fibers:
        if args.upstream:
            parser.error('Choose source patch or build preparation, not both')
        path = args.patch_fibers
        path.write_text(patch_fibers(path.read_text()), encoding='utf-8')
        return
    if not args.upstream:
        parser.error('--upstream is required')
    root = args.upstream.resolve(strict=True)
    head = subprocess.check_output(['git', '-C', str(root), 'rev-parse', 'HEAD'], text=True, timeout=10).strip()
    if head != UPSTREAM:
        raise ValueError('Upstream checkout does not match the pinned commit')
    compile_root = root / 'packages/php-wasm/compile'
    base = compile_root / 'base-image/Dockerfile'
    if blob_sha(base.read_bytes()) != BASE_BLOB:
        raise ValueError('Unexpected base image recipe')
    path = compile_root / 'php/Dockerfile'
    # Read from the frozen tree, never layer transformations onto a prior output.
    original = subprocess.check_output(['git', '-C', str(root), 'show', f'{UPSTREAM}:packages/php-wasm/compile/php/Dockerfile'], timeout=10).decode()
    effective = prepare_dockerfile(original, candidate=args.candidate, jobs=args.jobs)
    path.write_text(effective, encoding='utf-8')
    shutil.copyfile(Path(__file__), compile_root / 'ppphp-prepare.py')
    print(json.dumps({'upstream': head, 'phpCommit': PHP_COMMIT, 'phpVersion': PHP_VERSION,
                      'emscripten': '4.0.19', 'profile': 'experimental-fibers' if args.candidate else 'symbol-baseline',
                      'sourceDockerfileBlob': DOCKER_BLOB,
                      'effectiveDockerfileSha256': hashlib.sha256(effective.encode()).hexdigest(),
                      'jobs': args.jobs, 'mode': 'asyncify', 'debug': 'function names; assertions; O3; no DWARF',
                      'productionReady': False}, indent=2))


if __name__ == '__main__':
    main()
