<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use PHPUnit\Framework\TestCase;

/**
 * Contract: the update notes after 2.12.1 (docs/updating.md) say what
 * changed for source installations, which pull their base images now, and
 * for the deploy role, which ran the repository's development stack, in the
 * words the code uses. (OperatorDocsContractTest checks their links.)
 *
 * Regressions found in review: the first version of the notes said the
 * development stack exposed only mailhog and swagger, on 127.0.0.1, where it
 * published MariaDB, Redis without a password and an Adminer that logs in
 * by itself on every address up to 2.4.6; and it told operators to run git
 * in an install directory whose .git/ playbooks could change, which runs
 * planted hooks and settings on the host.
 */
class DeployRoleUpdateDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    public const UPDATE_SECTION = '## After updating from 2.12.1 or older: base images, the deploy role, nginx 1.30';

    private const SECTION_ANCHOR = 'after-updating-from-2121-or-older-base-images-the-deploy-role-nginx-130';

    private const UPDATING = 'docs/updating.md';

    public function testTheSectionIsTheNewest(): void
    {
        preg_match_all('/^## After updating from .*$/m', $this->read(self::UPDATING), $headings);

        $this->assertSame(self::UPDATE_SECTION, $headings[0][0] ?? null, 'The newest section comes first.');
        $this->assertSame(InventoryApprovalDocsContractTest::UPDATE_SECTION, $headings[0][1] ?? null, 'The section of 2.12.0 follows.');
        $this->assertSame(self::SECTION_ANCHOR, self::anchor(self::UPDATE_SECTION), 'The anchor GitHub gives the heading.');
        $this->assertMentions('(#' . self::SECTION_ANCHOR . ')', $this->section(self::UPDATING, '## Manual update — source (git)'), 'the manual source update');
    }

    /**
     * The section names the settings the role uses, and the role uses them.
     */
    public function testTheSectionNamesWhatTheRoleDoesNow(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);
        $stack = $this->read('deploy/roles/ansilume/tasks/stack.yaml');
        $env = $this->read('deploy/roles/ansilume/templates/env');

        foreach (['files', 'remove_orphans'] as $setting) {
            $this->assertMentions("(`{$setting}`)", $section, 'the update notes');
            $this->assertMatchesRegularExpression("/^    {$setting}:/m", $stack);
        }
        foreach (['COMPOSE_FILE', 'COMPOSE_PROJECT_NAME'] as $variable) {
            $this->assertMentions("`{$variable}`", $section, 'the update notes');
            $this->assertMatchesRegularExpression("/^{$variable}=/m", $env);
        }
        $this->assertMentions('`community.docker` collection 3.7.0 or later', $section, 'the update notes');
        $this->assertMentions('`community.docker` collection 3.7.0 or later', $this->read('docs/deployment.md'), 'deployment.md');
        $this->assertMentions('`ansilume_image_tag`', $section, 'the update notes');
    }

    public function testTheSourceUpdatePullsTheBaseImages(): void
    {
        $update = $this->flat(self::UPDATING, '## Manual update — source (git)');

        $this->assertMentions('docker compose pull --ignore-buildable', $update, 'the manual source update');
        $this->assertMentions('docker compose up -d --build --force-recreate', $update, 'the manual source update');
        $this->assertMentions('Every build pulls its base image (`pull: true` in `docker-compose.yml`)', $update, 'the manual source update');
        $this->assertMentions('`docker login` lifts the limit', $update, 'the manual source update');
        $this->assertMentions('docker compose exec app php -v', $this->flat(self::UPDATING, self::UPDATE_SECTION), 'the update notes');
    }

    /**
     * What the development stack published, by version, and how to look
     * at the old install directory without running what playbooks may
     * have planted there.
     */
    public function testRegressionTheSectionSaysWhatWasExposedAndHowToCheckSafely(): void
    {
        $section = $this->flat(self::UPDATING, self::UPDATE_SECTION);

        foreach ([
            'published MariaDB (3306), Redis without a password (6379, which holds the sessions and the queue), Adminer, which logs in to the database by itself (8081), mailhog (8025) and swagger-ui (8088) on every address of the host up to 2.4.6, and on 127.0.0.1 from 2.5.0 on',
            'do not run git or the role in the old install directory',
            'Copy nothing back from it. To see whether someone ran code, look through it without running git in it.',
            'change the values in your inventory or vault, not in `.env`, which the role writes on every run',
            'change them in MariaDB first (`ALTER USER`)',
            'If others could reach those ports (step 1), or anyone but an admin could launch jobs on the bundled runners',
            'Have every user with two-factor authentication turn it off before step 4 and set it up again after',
            'the bundled runners got new tokens when they registered again during the update',
        ] as $statement) {
            $this->assertMentions($statement, $section, 'the update notes');
        }
        $raw = $this->section(self::UPDATING, self::UPDATE_SECTION);
        $this->assertStringContainsString("docker ps --format '{{.Names}}  {{.Ports}}'", $raw);
        $this->assertStringContainsString('mv /opt/ansilume /opt/ansilume.old', $raw);
        $this->assertStringNotContainsString('git -C', $raw, 'No git command runs in the old install directory.');
    }

    /**
     * The anchor GitHub gives a heading.
     */
    private static function anchor(string $heading): string
    {
        return str_replace(' ', '-', (string)preg_replace('/[^a-z0-9 -]/', '', strtolower(ltrim($heading, '# '))));
    }
}
