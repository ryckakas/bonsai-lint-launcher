<?php

declare(strict_types=1);

namespace BonsaiLint\Composer\Tests;

function composer_json_names_the_package_and_its_bin(): void
{
    $package = json_decode((string) file_get_contents(path(Fixture::root(), 'composer.json')), true);

    same('bonsai-lint/bonsai-lint', $package['name']);
    same(['composer/bin/bonsai-lint'], $package['bin']);
    same(['php' => '>=7.4'], $package['require']);
    same('MIT', $package['license']);
    same(true, in_array('static analysis', $package['keywords'], true));
    same(true, in_array('dev', $package['keywords'], true));
}

// Each would reach users: a version overrides the tags, autoload enters their classmap, and an
// extension requirement is checked against the PHP that runs Composer, not the launcher's.
function composer_json_has_no_version_autoload_or_extension_requirement(): void
{
    $package = json_decode((string) file_get_contents(path(Fixture::root(), 'composer.json')), true);

    foreach (['version', 'autoload', 'autoload-dev', 'require-dev', 'scripts'] as $key) {
        same(false, array_key_exists($key, $package), $key);
    }
    same([], preg_grep('/^ext-/', array_keys($package['require'])));
}

function the_bin_gets_composers_php_proxy(): void
{
    $head = (string) file_get_contents(Fixture::bin(), false, null, 0, 500);

    same(1, preg_match('{^(#!.*\r?\n)?[\r\n\t ]*<\?php}', $head));
}

function the_cache_directory_ignores_everything_but_its_gitignore(): void
{
    same("*\n!.gitignore\n", file_get_contents(path(Fixture::root(), 'composer', 'cache', '.gitignore')));
}

function the_launcher_never_writes_to_stdout(): void
{
    foreach ((array) glob(path(Fixture::root(), 'composer', 'src', '*.php')) as $file) {
        $source = str_replace('[STDIN, STDOUT, STDERR]', '', (string) file_get_contents($file));

        same(0, preg_match('/\b(echo|print|printf|var_dump|print_r)\b|STDOUT|php:\/\/output/', $source), basename($file));
    }
}

function the_composer_dist_holds_only_the_launcher(): void
{
    $root = Fixture::root();
    if (!is_dir(path($root, '.git')) || trim((string) shell_exec('git --version')) === '') {
        skip('needs git and the repository');
    }
    $files = explode("\n", trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' ls-files --cached --others --exclude-standard')));
    $input = path(Fixture::directory(), 'files');
    file_put_contents($input, implode("\n", $files) . "\n");
    $attributes = explode("\n", trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' check-attr --stdin export-ignore < ' . escapeshellarg($input))));
    $shipped = [];
    foreach ($attributes as $line) {
        if (substr($line, -strlen(': export-ignore: set')) !== ': export-ignore: set') {
            $shipped[] = substr($line, 0, (int) strrpos($line, ': export-ignore: '));
        }
    }
    sort($shipped);

    $expected = ['LAUNCHERS.md', 'LICENSE', 'README.md', 'composer.json', 'composer/bin/bonsai-lint', 'composer/cache/.gitignore'];
    foreach ((array) glob(path($root, 'composer', 'src', '*.php')) as $file) {
        $expected[] = 'composer/src/' . basename($file);
    }
    sort($expected);
    same($expected, $shipped);
}
