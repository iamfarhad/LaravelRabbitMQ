#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Assert that CHANGELOG.md contains exactly one dated section for a version.
 *
 * Exists because changelog verification used to run on `release: [released]`,
 * i.e. after the tag and GitHub release already existed, so it could report a
 * missing section but never prevent publishing one (issue #36). This script is
 * the gate: the release workflow runs it *before* creating the tag.
 *
 * Usage:
 *   php scripts/validate-changelog.php <version> [path/to/CHANGELOG.md]
 *
 * The version may carry a leading "v"; it is normalised away.
 *
 * Exit codes: 0 valid, 1 invalid, 2 usage error.
 */
const EXIT_VALID = 0;
const EXIT_INVALID = 1;
const EXIT_USAGE = 2;

/**
 * @param  list<string>  $argv
 * @return array{version: string, path: string}
 */
function parseArguments(array $argv): array
{
    if (! isset($argv[1]) || trim($argv[1]) === '') {
        fwrite(STDERR, "Usage: validate-changelog.php <version> [changelog-path]\n");
        exit(EXIT_USAGE);
    }

    $version = ltrim(trim($argv[1]), 'vV');
    $path = $argv[2] ?? dirname(__DIR__).'/CHANGELOG.md';

    if (! preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.\-]+)?$/', $version)) {
        fwrite(STDERR, sprintf("error: [%s] is not a semantic version.\n", $version));
        exit(EXIT_USAGE);
    }

    if (! is_file($path)) {
        fwrite(STDERR, sprintf("error: changelog not found at [%s].\n", $path));
        exit(EXIT_USAGE);
    }

    return ['version' => $version, 'path' => $path];
}

/**
 * @return list<string>
 */
function validate(string $changelog, string $version): array
{
    $errors = [];
    $quoted = preg_quote($version, '/');

    // Every heading for this version, dated or not.
    preg_match_all(
        '/^##\s+\[v?'.$quoted.'\](.*)$/mi',
        $changelog,
        $headings,
        PREG_SET_ORDER
    );

    if ($headings === []) {
        $errors[] = sprintf(
            'CHANGELOG.md has no "## [%s]" section. Move the release notes out of [Unreleased] before publishing.',
            $version
        );

        return $errors;
    }

    if (count($headings) > 1) {
        $errors[] = sprintf(
            'CHANGELOG.md has %d "## [%s]" sections; exactly one is required.',
            count($headings),
            $version
        );
    }

    foreach ($headings as $heading) {
        if (! preg_match('/^\s*-\s*\d{4}-\d{2}-\d{2}\s*$/', $heading[1])) {
            $errors[] = sprintf(
                'The "## [%s]" heading must carry a release date as "## [%s] - YYYY-MM-DD"; found "##%s".',
                $version,
                $version,
                rtrim($heading[1]) === '' ? ' ['.$version.']' : ' ['.$version.']'.$heading[1]
            );
        }
    }

    // A heading with no content under it means the notes are still elsewhere,
    // almost always still under [Unreleased].
    if (preg_match(
        '/^##\s+\[v?'.$quoted.'\][^\n]*\n(.*?)(?=^##\s|\z)/msi',
        $changelog,
        $body
    ) && trim($body[1]) === '') {
        $errors[] = sprintf('The "## [%s]" section is empty.', $version);
    }

    return $errors;
}

$arguments = parseArguments($argv);
$contents = (string) file_get_contents($arguments['path']);
$errors = validate($contents, $arguments['version']);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'error: '.$error."\n");
    }

    exit(EXIT_INVALID);
}

fwrite(STDOUT, sprintf("CHANGELOG.md has a valid dated section for %s.\n", $arguments['version']));
exit(EXIT_VALID);
