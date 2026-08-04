<?php

declare(strict_types=1);

namespace iamfarhad\LaravelRabbitMQ\Tests\Unit;

use iamfarhad\LaravelRabbitMQ\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The changelog gate for issue #36. Verification used to run on
 * `release: [released]` — after the tag existed — so it could report a bad
 * changelog but never block publication. This script is what the release
 * workflow runs *before* tagging, so its behaviour is pinned here.
 */
class ValidateChangelogScriptTest extends UnitTestCase
{
    private string $script;

    private ?string $fixture = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->script = dirname(__DIR__, 2).'/scripts/validate-changelog.php';
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null && is_file($this->fixture)) {
            unlink($this->fixture);
        }

        $this->fixture = null;

        parent::tearDown();
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function validate(string $version, string $changelog): array
    {
        $this->fixture = tempnam(sys_get_temp_dir(), 'changelog').'.md';
        file_put_contents($this->fixture, $changelog);

        $command = sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->script),
            escapeshellarg($version),
            escapeshellarg($this->fixture)
        );

        $output = (string) shell_exec($command.'; echo "__STATUS__$?"');
        [$body, $status] = explode('__STATUS__', $output);

        return [(int) trim($status), trim($body)];
    }

    private const VALID = <<<'MD'
        # Changelog

        ## [Unreleased]

        ## [1.4.1] - 2026-08-04

        ### Fixed

        - Something real.

        ## [1.4.0] - 2026-08-01

        - Older release.
        MD;

    /**
     * @return array<string, array{string}>
     */
    public static function acceptedTagFormats(): array
    {
        return ['bare version' => ['1.4.1'], 'v-prefixed tag' => ['v1.4.1']];
    }

    #[DataProvider('acceptedTagFormats')]
    public function testAcceptsADatedSectionForEitherTagFormat(string $version): void
    {
        [$status, $output] = $this->validate($version, self::VALID);

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('valid dated section for 1.4.1', $output);
    }

    public function testRejectsAVersionWhoseNotesAreStillOnlyUnderUnreleased(): void
    {
        $changelog = <<<'MD'
            # Changelog

            ## [Unreleased]

            ### Fixed

            - Not yet moved into a version section.

            ## [1.4.0] - 2026-08-01

            - Older release.
            MD;

        [$status, $output] = $this->validate('1.5.0', $changelog);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('has no "## [1.5.0]" section', $output);
        $this->assertStringContainsString('[Unreleased]', $output);
    }

    public function testRejectsAnUndatedHeading(): void
    {
        $changelog = "# Changelog\n\n## [1.4.1]\n\n- Undated.\n";

        [$status, $output] = $this->validate('1.4.1', $changelog);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('must carry a release date', $output);
    }

    public function testRejectsAMalformedDate(): void
    {
        $changelog = "# Changelog\n\n## [1.4.1] - Aug 4 2026\n\n- Wrong date format.\n";

        [$status, $output] = $this->validate('1.4.1', $changelog);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('must carry a release date', $output);
    }

    public function testRejectsDuplicateSections(): void
    {
        $changelog = <<<'MD'
            # Changelog

            ## [1.4.1] - 2026-08-04

            - First.

            ## [1.4.1] - 2026-08-05

            - Duplicate.
            MD;

        [$status, $output] = $this->validate('1.4.1', $changelog);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('exactly one is required', $output);
    }

    public function testRejectsAnEmptySection(): void
    {
        $changelog = "# Changelog\n\n## [1.4.1] - 2026-08-04\n\n## [1.4.0] - 2026-08-01\n\n- Older.\n";

        [$status, $output] = $this->validate('1.4.1', $changelog);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('is empty', $output);
    }

    public function testRejectsANonSemanticVersion(): void
    {
        [$status, $output] = $this->validate('not-a-version', self::VALID);

        $this->assertSame(2, $status);
        $this->assertStringContainsString('is not a semantic version', $output);
    }

    public function testAcceptsAPreReleaseVersion(): void
    {
        $changelog = "# Changelog\n\n## [1.5.0-beta.1] - 2026-08-04\n\n- Beta.\n";

        [$status, $output] = $this->validate('1.5.0-beta.1', $changelog);

        $this->assertSame(0, $status, $output);
    }

    /**
     * The repository's own changelog must always be releasable for the version
     * it most recently published.
     */
    public function testTheRepositoryChangelogPassesForItsLatestReleasedVersion(): void
    {
        $changelog = (string) file_get_contents(dirname(__DIR__, 2).'/CHANGELOG.md');

        preg_match('/^##\s+\[(\d+\.\d+\.\d+)\]\s+-\s+\d{4}-\d{2}-\d{2}/m', $changelog, $match);

        $this->assertNotEmpty($match, 'CHANGELOG.md must contain at least one dated release section.');

        [$status, $output] = $this->validate($match[1], $changelog);

        $this->assertSame(0, $status, $output);
    }
}
