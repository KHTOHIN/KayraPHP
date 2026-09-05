<?php

declare(strict_types=1);

namespace Kayra\View;

/**
 * Compiles Kayra templates into plain PHP.
 *
 * The output is ordinary PHP written to a cache file, so OPcache treats a
 * compiled template exactly like hand-written code — there is no template
 * interpreter in the request path at all.
 *
 * Supported syntax (directive names are written without their leading `@` here,
 * because a docblock cannot contain one without being read as a PHPDoc tag):
 *
 *   {{ $x }}            escaped echo
 *   {!! $x !!}          raw echo
 *   {{-- note --}}      comment, stripped
 *
 *   if elseif else endif unless endunless isset endisset empty endempty
 *   foreach endforeach forelse endforelse for endfor while endwhile
 *   break continue switch case default endswitch
 *   extends section endsection show yield parent
 *   include includeIf includeWhen
 *   php endphp verbatim endverbatim
 *   csrf method json class style checked selected disabled
 */
final class Compiler
{
    /** Placeholders for blocks that must survive untouched. */
    private const RAW_PLACEHOLDER = '@__kayra_raw_%d__@';

    /** @var list<string> */
    private array $rawBlocks = [];

    public function compile(string $template): string
    {
        $this->rawBlocks = [];

        $result = $this->storeRawBlocks($template);
        $result = $this->compileComments($result);
        $result = $this->compileEchoes($result);
        $result = $this->compileDirectives($result);
        $result = $this->restoreRawBlocks($result);

        return $this->prependHeader($result);
    }

    /* --------------------------------------------------------------------
     | Raw blocks
     * -------------------------------------------------------------------- */

    private function storeRawBlocks(string $value): string
    {
        // @verbatim ... @endverbatim is emitted literally.
        $value = preg_replace_callback(
            '/(?<!@)@verbatim\s*(.*?)\s*@endverbatim/s',
            fn (array $m): string => $this->storeRaw($m[1]),
            $value,
        ) ?? $value;

        // @php ... @endphp becomes a real PHP block, protected from echo parsing.
        return preg_replace_callback(
            '/(?<!@)@php\s*(.*?)\s*@endphp/s',
            fn (array $m): string => $this->storeRaw('<?php ' . $m[1] . ' ?>'),
            $value,
        ) ?? $value;
    }

    private function storeRaw(string $content): string
    {
        $this->rawBlocks[] = $content;

        return sprintf(self::RAW_PLACEHOLDER, count($this->rawBlocks) - 1);
    }

    private function restoreRawBlocks(string $value): string
    {
        foreach ($this->rawBlocks as $index => $content) {
            $value = str_replace(sprintf(self::RAW_PLACEHOLDER, $index), $content, $value);
        }

        return $value;
    }

    /* --------------------------------------------------------------------
     | Comments and echoes
     * -------------------------------------------------------------------- */

    private function compileComments(string $value): string
    {
        return preg_replace('/\{\{--(.*?)--\}\}/s', '', $value) ?? $value;
    }

    private function compileEchoes(string $value): string
    {
        // An empty pair such as {{ }} is left alone rather than compiled into an
        // echo with no expression, which would be a parse error in the output.
        // Documentation pages that show the syntax itself depend on this.

        // Raw first, so {!! !!} is not mistaken for {{ }}.
        $value = preg_replace_callback(
            '/\{!!\s*(.+?)\s*!!\}/s',
            static fn (array $m): string => trim($m[1]) === ''
                ? $m[0]
                : '<?= ' . trim($m[1]) . ' ?>',
            $value,
        ) ?? $value;

        // {{{ $x }}} is not supported; {{ $x }} is always escaped.
        return preg_replace_callback(
            '/\{\{\s*(.+?)\s*\}\}/s',
            static fn (array $m): string => trim($m[1]) === ''
                ? $m[0]
                : '<?= \Kayra\View\Compiler::e(' . trim($m[1]) . ') ?>',
            $value,
        ) ?? $value;
    }

    /**
     * Escape a value for HTML output.
     *
     * ENT_QUOTES escapes both quote styles; ENT_SUBSTITUTE replaces invalid
     * UTF-8 rather than returning an empty string, which would silently blank
     * out content.
     */
    public static function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \Stringable || is_scalar($value)) {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if (is_array($value) || is_object($value)) {
            return htmlspecialchars(
                json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '',
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            );
        }

        return '';
    }

    /* --------------------------------------------------------------------
     | Directives
     * -------------------------------------------------------------------- */

    private function compileDirectives(string $value): string
    {
        // The (?3) recursion matches balanced parentheses, so expressions
        // containing their own parens — @if(count($x) > 0) — parse correctly.
        $pattern = '/\B@(@?\w+)([ \t]*)(\((?:[^()]++|(?3))*\))?/x';

        return preg_replace_callback(
            $pattern,
            function (array $m): string {
                $directive = $m[1];
                $whitespace = $m[2] ?? '';
                $expression = isset($m[3]) ? substr($m[3], 1, -1) : '';

                // @@if escapes to a literal @if.
                if (str_starts_with($directive, '@')) {
                    return '@' . substr($directive, 1) . $whitespace . ($m[3] ?? '');
                }

                $compiled = $this->compileDirective($directive, $expression, isset($m[3]));

                return $compiled ?? $m[0];
            },
            $value,
        ) ?? $value;
    }

    private function compileDirective(string $name, string $expression, bool $hasArgs): ?string
    {
        return match ($name) {
            // Conditionals
            'if'        => "<?php if ({$expression}): ?>",
            'elseif'    => "<?php elseif ({$expression}): ?>",
            'else'      => '<?php else: ?>',
            'endif'     => '<?php endif; ?>',
            'unless'    => "<?php if (! ({$expression})): ?>",
            'endunless' => '<?php endif; ?>',
            'isset'     => "<?php if (isset({$expression})): ?>",
            'endisset'  => '<?php endif; ?>',
            'empty'     => $hasArgs ? "<?php if (empty({$expression})): ?>" : '<?php endforeach; $__loop = $__env->popLoop(); if ($__loop->count === 0): ?>',
            'endempty'  => '<?php endif; ?>',

            // Loops
            'foreach'    => "<?php \$__env->pushLoop({$this->loopSubject($expression)}); foreach ({$expression}): \$loop = \$__env->incrementLoop(); ?>",
            'endforeach' => '<?php endforeach; $__env->popLoop(); ?>',
            'forelse'    => "<?php \$__env->pushLoop({$this->loopSubject($expression)}); foreach ({$expression}): \$loop = \$__env->incrementLoop(); ?>",
            'endforelse' => '<?php endif; ?>',
            'for'        => "<?php for ({$expression}): ?>",
            'endfor'     => '<?php endfor; ?>',
            'while'      => "<?php while ({$expression}): ?>",
            'endwhile'   => '<?php endwhile; ?>',
            'break'      => $expression === '' ? '<?php break; ?>' : "<?php if ({$expression}) break; ?>",
            'continue'   => $expression === '' ? '<?php continue; ?>' : "<?php if ({$expression}) continue; ?>",

            // Switch
            'switch'    => "<?php switch ({$expression}): case '__kayra_never__': ?>",
            'case'      => "<?php break; case {$expression}: ?>",
            'default'   => '<?php break; default: ?>',
            'endswitch' => '<?php endswitch; ?>',

            // Layout
            'extends'    => "<?php \$__env->extend({$expression}); ?>",
            'section'    => str_contains($expression, ',')
                ? "<?php \$__env->setSection({$expression}); ?>"
                : "<?php \$__env->startSection({$expression}); ?>",
            'endsection' => '<?php $__env->endSection(); ?>',
            'show'       => '<?php echo $__env->endSection(true); ?>',
            'parent'     => '<?php echo $__env->parentPlaceholder(); ?>',
            'yield'      => "<?php echo \$__env->yieldSection({$expression}); ?>",

            // Includes
            'include'     => "<?php echo \$__env->include({$expression}, get_defined_vars()); ?>",
            'includeIf'   => "<?php echo \$__env->includeIf({$expression}, get_defined_vars()); ?>",
            'includeWhen' => "<?php echo \$__env->includeWhen({$expression}, get_defined_vars()); ?>",

            // Authorization
            'can'       => '<?php if ($__env->can(' . $expression . ')): ?>',
            'elsecan'   => '<?php elseif ($__env->can(' . $expression . ')): ?>',
            'cannot'    => '<?php if (! $__env->can(' . $expression . ')): ?>',
            'endcan'    => '<?php endif; ?>',
            'endcannot' => '<?php endif; ?>',

            // Helpers
            'csrf'     => '<?php echo $__env->csrfField(); ?>',
            'method'   => "<?php echo \$__env->methodField({$expression}); ?>",
            'json'     => "<?php echo json_encode({$expression}, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>",
            'class'    => "<?php echo \Kayra\View\Compiler::attributeList('class', {$expression}); ?>",
            'style'    => "<?php echo \Kayra\View\Compiler::attributeList('style', {$expression}); ?>",
            'checked'  => "<?php if ({$expression}) echo ' checked'; ?>",
            'selected' => "<?php if ({$expression}) echo ' selected'; ?>",
            'disabled' => "<?php if ({$expression}) echo ' disabled'; ?>",
            'required' => "<?php if ({$expression}) echo ' required'; ?>",

            default => null,
        };
    }

    /**
     * Extract the iterable half of a `foreach` expression, for the $loop object.
     *
     * "$users as $user" becomes "$users".
     */
    private function loopSubject(string $expression): string
    {
        $position = strripos($expression, ' as ');

        return $position === false ? $expression : trim(substr($expression, 0, $position));
    }

    /**
     * Build a conditional class/style attribute from an array.
     *
     * @param array<array-key, mixed> $values
     */
    public static function attributeList(string $attribute, array $values): string
    {
        $parts = [];

        foreach ($values as $key => $value) {
            if (is_int($key)) {
                $parts[] = (string) $value;
            } elseif ($value) {
                $parts[] = $key;
            }
        }

        if ($parts === []) {
            return '';
        }

        $separator = $attribute === 'style' ? '; ' : ' ';

        return sprintf(
            ' %s="%s"',
            $attribute,
            htmlspecialchars(implode($separator, $parts), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    private function prependHeader(string $compiled): string
    {
        return "<?php /* Compiled by KayraPHP. Do not edit. */ ?>\n" . $compiled;
    }
}
