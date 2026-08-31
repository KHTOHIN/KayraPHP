<?php

declare(strict_types=1);

namespace Kayra\Tests\Unit;

use Kayra\View\Compiler;
use Kayra\View\Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Compiler::class)]
#[CoversClass(Factory::class)]
final class ViewTest extends TestCase
{
    private string $root;

    private string $cache;

    private Factory $views;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kayra_v_' . bin2hex(random_bytes(4));
        $this->cache = $this->root . '_cache';

        mkdir($this->root . '/layouts', 0o777, true);
        mkdir($this->cache, 0o777, true);

        $this->views = new Factory([$this->root], $this->cache);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*/*') ?: [] as $f) {
            unlink($f);
        }

        foreach (glob($this->root . '/*') ?: [] as $f) {
            is_file($f) && unlink($f);
        }

        foreach (glob($this->cache . '/*') ?: [] as $f) {
            unlink($f);
        }

        @rmdir($this->root . '/layouts');
        @rmdir($this->root);
        @rmdir($this->cache);
    }

    private function template(string $name, string $contents): void
    {
        file_put_contents($this->root . '/' . $name . '.kayra.php', $contents);
    }

    /** Collapse whitespace so template formatting does not affect assertions. */
    private function ws(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    #[Test]
    public function double_braces_escape_html(): void
    {
        $this->template('t', '{{ $x }}');

        $this->assertSame(
            '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;',
            $this->views->render('t', ['x' => '<script>alert("xss")</script>']),
        );
    }

    #[Test]
    public function bang_syntax_emits_raw_output(): void
    {
        $this->template('t', '{!! $x !!}');

        $this->assertSame('<b>bold</b>', $this->views->render('t', ['x' => '<b>bold</b>']));
    }

    #[Test]
    public function comments_are_stripped(): void
    {
        $this->template('t', 'a{{-- secret --}}b');

        $this->assertSame('ab', $this->views->render('t'));
    }

    #[Test]
    public function conditionals_compile(): void
    {
        $this->template('t', '@if($n > 5) big @elseif($n > 2) mid @else small @endif');

        $this->assertSame('big', $this->ws($this->views->render('t', ['n' => 10])));
        $this->assertSame('mid', $this->ws($this->views->render('t', ['n' => 3])));
        $this->assertSame('small', $this->ws($this->views->render('t', ['n' => 1])));
    }

    #[Test]
    public function expressions_containing_parentheses_compile(): void
    {
        $this->template('t', '@if(count($list) > 0 && ($list[0] ?? null) !== null) yes @endif');

        $this->assertSame('yes', $this->ws($this->views->render('t', ['list' => [1]])));
    }

    #[Test]
    public function an_email_address_is_not_treated_as_a_directive(): void
    {
        $this->template('t', 'mail support@example.com now');

        $this->assertSame('mail support@example.com now', $this->ws($this->views->render('t')));
    }

    #[Test]
    public function the_loop_variable_tracks_position(): void
    {
        $this->template('t', '@foreach($xs as $x)[{{ $loop->iteration }}/{{ $loop->count }}{{ $loop->first ? "F" : "" }}{{ $loop->last ? "L" : "" }}] @endforeach');

        $this->assertSame(
            '[1/3F][2/3][3/3L]',
            (string) preg_replace('/\s+/', '', $this->views->render('t', ['xs' => ['a', 'b', 'c']])),
        );
    }

    #[Test]
    public function nested_loops_report_their_depth(): void
    {
        $this->template('t', '@foreach($o as $a) @foreach($i as $b) {{ $loop->depth }} @endforeach @endforeach');

        $this->assertSame('2 2', $this->ws($this->views->render('t', ['o' => [1], 'i' => [1, 2]])));
    }

    #[Test]
    public function forelse_falls_back_when_the_collection_is_empty(): void
    {
        $this->template('t', '@forelse($xs as $x){{ $x }} @empty NONE @endforelse');

        $this->assertSame('a b', $this->ws($this->views->render('t', ['xs' => ['a', 'b']])));
        $this->assertSame('NONE', $this->ws($this->views->render('t', ['xs' => []])));
    }

    #[Test]
    public function verbatim_blocks_are_emitted_literally(): void
    {
        $this->template('t', '@verbatim {{ $vue }} @endverbatim');

        $this->assertSame('{{ $vue }}', $this->ws($this->views->render('t')));
    }

    #[Test]
    public function layouts_yield_child_sections_and_parent_pulls_in_the_default(): void
    {
        $this->template('layouts/base', 'T[@yield("title")] F[@section("foot")base @show]');
        $this->template('child', '@extends("layouts.base") @section("title")Child @endsection @section("foot")@parent +extra @endsection');

        $output = (string) preg_replace('/\s+/', '', $this->views->render('child'));

        $this->assertStringContainsString('T[Child]', $output);
        $this->assertStringContainsString('F[base+extra]', $output);
    }

    #[Test]
    public function section_state_does_not_leak_between_renders(): void
    {
        $this->template('layouts/base', '[@yield("body")]');
        $this->template('child', '@extends("layouts.base") @section("body"){{ $n }} @endsection');

        $this->assertStringContainsString('[1', $this->views->render('child', ['n' => 1]));
        $this->assertStringContainsString('[2', $this->views->render('child', ['n' => 2]));
    }

    #[Test]
    public function class_attribute_helper_filters_by_condition(): void
    {
        $this->template('t', '<div @class(["a" => true, "b" => false, "c"])></div>');

        $this->assertSame('<div class="a c"></div>', $this->ws($this->views->render('t')));
    }

    #[Test]
    public function an_empty_echo_pair_is_left_as_literal_text(): void
    {
        // Regression: a documentation page showing the syntax itself must not
        // compile to an echo with no expression, which is a PHP parse error.
        $this->template('t', 'use {{ }} to escape and {!! !!} for raw');

        $this->assertSame('use {{ }} to escape and {!! !!} for raw', $this->views->render('t'));
    }

    #[Test]
    public function double_at_escapes_a_directive_name(): void
    {
        $this->template('t', '@@if is written like this');

        $this->assertSame('@if is written like this', $this->ws($this->views->render('t')));
    }

    #[Test]
    public function an_unknown_directive_passes_through_untouched(): void
    {
        // CSS at-rules share the directive syntax and must survive intact.
        $this->template('t', '<style>@media (min-width: 40rem) { body { color: red } }</style>');

        $this->assertStringContainsString('@media (min-width: 40rem)', $this->views->render('t'));
    }

    #[Test]
    public function csrf_without_a_token_fails_loudly_instead_of_emitting_an_empty_one(): void
    {
        // An empty _token field looks like protection in review and is not.
        $this->template('t', '<form>@csrf</form>');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no session layer/');

        $this->views->render('t');
    }

    #[Test]
    public function csrf_renders_when_a_token_is_shared(): void
    {
        $this->template('t', '<form>@csrf</form>');
        $this->views->share('csrf_token', 'abc123');

        $this->assertStringContainsString('value="abc123"', $this->views->render('t'));
    }

    #[Test]
    public function path_traversal_in_a_view_name_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        $this->views->render('../../../etc/passwd');
    }

    #[Test]
    public function templates_recompile_when_the_source_changes(): void
    {
        $this->template('t', 'first');
        $this->assertSame('first', $this->views->render('t'));

        $this->template('t', 'second');
        touch($this->root . '/t.kayra.php', time() + 10);
        clearstatcache();

        $this->assertSame('second', (new Factory([$this->root], $this->cache))->render('t'));
    }

    #[Test]
    public function production_mode_trusts_the_compiled_cache(): void
    {
        $this->template('t', 'original');
        $this->views->render('t');

        $this->template('t', 'changed');
        touch($this->root . '/t.kayra.php', time() + 10);
        clearstatcache();

        $production = new Factory([$this->root], $this->cache, alwaysRecompile: false);

        $this->assertSame('original', $production->render('t'));
    }

    #[Test]
    public function compiled_templates_are_plain_php(): void
    {
        $this->template('t', '@if($a) x @endif');
        $this->views->render('t', ['a' => true]);

        $compiled = file_get_contents((string) (glob($this->cache . '/*.php') ?: [''])[0]);

        $this->assertStringContainsString('<?php if (', (string) $compiled);
    }
}
