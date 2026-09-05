<?php

/*
|--------------------------------------------------------------------------
| Preflight
|--------------------------------------------------------------------------
|
| Runs before the Composer autoloader, and therefore before anything else.
|
| Composer's own platform check fires at autoload time and reports the problem
| as an uncaught RuntimeException with a stack trace through vendor/ — which is
| accurate and completely unhelpful. This file catches the same conditions
| first and says what to do about them.
|
| IMPORTANT: this file must parse on any PHP version a user might have on their
| PATH, including very old ones. A parse error here would be reported instead of
| the message it exists to print. So: no typed properties, no arrow functions,
| no match, no null-coalescing, no named arguments, nothing after PHP 5.4.
|
*/

if (!defined('KAYRA_MINIMUM_PHP')) {
    define('KAYRA_MINIMUM_PHP', '8.5.0');
}

/**
 * Print a fatal preflight failure and stop.
 *
 * @param string $title
 * @param array  $lines
 * @return void
 */
function kayra_preflight_fail($title, $lines)
{
    $cli = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');
    $out = '';

    if ($cli) {
        $out .= "\n  \033[41;97m " . $title . " \033[0m\n\n";

        foreach ($lines as $line) {
            $out .= '  ' . $line . "\n";
        }

        $out .= "\n";
        fwrite(STDERR, $out);
        exit(1);
    }

    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8', true, 500);
    }

    echo $title . "\n\n";

    foreach ($lines as $line) {
        // Strip the ANSI codes that only make sense in a terminal.
        echo preg_replace('/\033\[[0-9;]*m/', '', $line) . "\n";
    }

    exit(1);
}

/**
 * Look for a PHP binary on this machine new enough to run the framework.
 *
 * Most people hitting this are on Windows with several PHP versions installed
 * side by side (WAMP, Laragon, XAMPP) and simply have an older one on PATH.
 * Naming the exact working binary turns a dead end into a one-line fix.
 *
 * @param string $minimum
 * @return string  Absolute path, or '' when nothing suitable was found.
 */
function kayra_find_php($minimum)
{
    $candidates = array();

    // Siblings of the running binary: C:\wamp64\bin\php\php8.2.29\php.exe
    // means C:\wamp64\bin\php\*\php.exe are the other installs.
    $binary = defined('PHP_BINARY') ? PHP_BINARY : '';

    if ($binary !== '') {
        $parent = dirname(dirname($binary));
        $pattern = $parent . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR
            . (DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php');

        $found = glob($pattern);

        if (is_array($found)) {
            $candidates = array_merge($candidates, $found);
        }
    }

    // Common versioned names on Unix-like systems.
    if (DIRECTORY_SEPARATOR === '/') {
        foreach (array('/usr/bin', '/usr/local/bin', '/opt/homebrew/bin') as $dir) {
            $found = glob($dir . '/php8.[0-9]');

            if (is_array($found)) {
                $candidates = array_merge($candidates, $found);
            }
        }
    }

    $best = '';
    $bestVersion = $minimum;

    foreach (array_unique($candidates) as $candidate) {
        if ($candidate === $binary || !is_file($candidate)) {
            continue;
        }

        // Read the version from the path first — cheap, and enough to skip
        // almost every candidate without launching a process.
        if (preg_match('/php[\/\\\\]?v?(\d+)\.(\d+)(?:\.(\d+))?/i', $candidate, $m)) {
            $guess = $m[1] . '.' . $m[2] . '.' . (isset($m[3]) ? $m[3] : '0');

            if (version_compare($guess, $bestVersion, '<')) {
                continue;
            }
        }

        // Confirm by asking the binary itself; a path can lie.
        //
        // stderr is deliberately not merged in, and the version is searched for
        // rather than anchored to the start: a PHP install with a broken
        // zend_extension line prints a warning before any output, which would
        // otherwise make a perfectly good binary look unusable.
        $null = (DIRECTORY_SEPARATOR === '\\') ? 'NUL' : '/dev/null';
        $reported = @shell_exec(escapeshellarg($candidate) . ' -r "echo PHP_VERSION;" 2>' . $null);

        if (!is_string($reported) || !preg_match('/(\d+\.\d+\.\d+)/', $reported, $found)) {
            continue;
        }

        if (version_compare($found[1], $bestVersion, '>=')) {
            $best = $candidate;
            $bestVersion = $found[1];
        }
    }

    return $best;
}

/*
|--------------------------------------------------------------------------
| 1. PHP version
|--------------------------------------------------------------------------
*/

if (version_compare(PHP_VERSION, KAYRA_MINIMUM_PHP, '<')) {
    $lines = array(
        'KayraPHP needs PHP ' . KAYRA_MINIMUM_PHP . ' or newer.',
        '',
        "\033[90mrunning\033[0m  " . PHP_VERSION,
        "\033[90mbinary \033[0m  " . (defined('PHP_BINARY') ? PHP_BINARY : 'unknown'),
        '',
    );

    $better = kayra_find_php(KAYRA_MINIMUM_PHP);

    if ($better !== '') {
        $script = isset($_SERVER['argv'][0]) ? $_SERVER['argv'][0] : 'kayra';
        $args = '';

        if (isset($_SERVER['argv']) && count($_SERVER['argv']) > 1) {
            $rest = $_SERVER['argv'];
            array_shift($rest);
            $args = ' ' . implode(' ', $rest);
        }

        $lines[] = 'A suitable PHP is already installed on this machine:';
        $lines[] = '';
        $lines[] = "  \033[92m" . $better . "\033[0m";
        $lines[] = '';
        $lines[] = 'Run this instead:';
        $lines[] = '';
        $lines[] = "  \033[92m\"" . $better . '" ' . $script . $args . "\033[0m";
        $lines[] = '';
        $lines[] = 'To make it permanent, put that directory ahead of the current';
        $lines[] = 'one on your PATH. On WAMP you can also left-click the tray icon';
        $lines[] = '-> PHP -> Version, which switches the CLI as well as Apache.';
    } else {
        $lines[] = 'No newer PHP was found on this machine. Install PHP '
            . KAYRA_MINIMUM_PHP . '+ and put it on your PATH.';
    }

    kayra_preflight_fail('PHP VERSION TOO OLD', $lines);
}

/*
|--------------------------------------------------------------------------
| 2. Required extensions
|--------------------------------------------------------------------------
| Checked here rather than left to Composer so the message names all of the
| missing extensions at once, instead of the first one it happens to hit.
*/

$missing = array();

foreach (array('uri', 'mbstring', 'json', 'openssl') as $extension) {
    if (!extension_loaded($extension)) {
        $missing[] = $extension;
    }
}

if ($missing !== array()) {
    $lines = array(
        'KayraPHP needs these PHP extensions, which are not loaded:',
        '',
    );

    foreach ($missing as $extension) {
        $note = ($extension === 'uri')
            ? '  (bundled with PHP 8.5 — check it was not disabled at build time)'
            : '';
        $lines[] = "  \033[91m" . $extension . "\033[0m" . $note;
    }

    $lines[] = '';
    $lines[] = 'Enable them in: ' . (php_ini_loaded_file() ? php_ini_loaded_file() : 'your php.ini');

    kayra_preflight_fail('MISSING PHP EXTENSIONS', $lines);
}

/*
|--------------------------------------------------------------------------
| 3. Dependencies
|--------------------------------------------------------------------------
*/

if (!is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
    kayra_preflight_fail('DEPENDENCIES NOT INSTALLED', array(
        'The Composer autoloader is missing.',
        '',
        'Run:',
        '',
        "  \033[92mcomposer install\033[0m",
    ));
}
