<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use app\services\ldap\LdapException;
use app\services\ldap\PhpLdapClient;
use PHPUnit\Framework\TestCase;

/**
 * What the docs say about the images that support LDAP is what the images
 * install and how they are deployed: docs/ldap.md (Container images, the
 * checklist), installation.md, the fixes and the rollback of the section of
 * docs/updating.md for the update from 2.10.1, and the troubleshooting
 * entry, in the words of PhpLdapClient::EXTENSION_NOT_LOADED. The prebuilt
 * app image is built from docker/php/Dockerfile.prod (release.yml), a git
 * checkout and the deploy role's build mode from docker/php/Dockerfile, the
 * runner image from docker/runner/Dockerfile, and the quickstart runs
 * docker-compose.prebuilt.yml in both of its modes. PhpExtensionParityTest
 * keeps the extensions of the two app images alike.
 *
 * Regression (wave 5g of 2.11.0): the prebuilt app image had no PHP ldap
 * extension, so LDAP failed with every prebuilt deployment, and the docs
 * said nothing of it. Each test fails on the docs from before.
 */
class LdapImageDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;
    use ReadsTheUpdateSection;

    /** The section of ldap.md. */
    private const IMAGES = '## Container images';

    /** The troubleshooting entry. */
    private const ENTRY = '## LDAP fails: "PHP ldap extension is not loaded."';

    /** How the console stops on the error, without YII_DEBUG. */
    private const CONSOLE_ERROR = 'Error: ' . PhpLdapClient::EXTENSION_NOT_LOADED;

    /** The check of a running installation. */
    private const CHECK = '`docker compose exec app php -m | grep -x ldap`';

    /**
     * regression: ldap.md named no image. It says which images have the
     * extension and since when, and how to check one.
     */
    public function testRegressionLdapMdSaysWhichImagesSupportLdap(): void
    {
        $this->assertSame('PHP ldap extension is not loaded.', PhpLdapClient::EXTENSION_NOT_LOADED);
        $images = $this->flat('docs/ldap.md', self::IMAGES);
        $expected = [
            'LDAP needs PHP\'s `ldap` extension in the container that signs users in and runs `php yii ldap/*`: the app container, whose image the queue-worker and the schedule-runner share.',
            '- The prebuilt app image `ghcr.io/ansilume/ansilume` has it from 2.11.0 on.',
            'Up to 2.10.1 it had none: with `LDAP_ENABLED=true` and a host and base DN set, the sign-in of LDAP users showed the error page, and `ldap/test-connection`, `ldap/test-user` and `ldap/sync` stopped with `'
                . self::CONSOLE_ERROR . '` (see [troubleshooting.md](troubleshooting.md#ldap-fails-php-ldap-extension-is-not-loaded)).',
            'This concerns `docker-compose.prebuilt.yml`, both modes of the quickstart, which both run `docker-compose.prebuilt.yml`, and the Ansible deploy role with `ansilume_use_prebuilt_images: true`. Use 2.11.0 or later with LDAP.',
            '- A git checkout (`docker-compose.yml`, built from `docker/php/Dockerfile`) and the deploy role\'s default build mode have had it since 2.1.11, the release that brought LDAP.',
            '- The runner image `ghcr.io/ansilume/ansilume-runner` has no `ldap` extension and needs none: runners never contact the directory.',
            'Check a running installation with ' . self::CHECK . ', which prints `ldap`.',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $images, 'The container images of ldap.md');
        }
        $checklist = $this->flat('docs/ldap.md', '## Operational checklist');
        $this->assertMentions('- [ ] ' . self::CHECK . ' prints `ldap` (prebuilt images: 2.11.0 or later).', $checklist, 'The checklist of ldap.md');
        $this->assertMentions('- [ ] `php yii ldap/test-connection` exits with `0`, and its `Service bind:` line reads `OK`.', $checklist, 'The checklist of ldap.md');
        $this->assertStringNotContainsString('service_bind: OK', $checklist, 'ldap/test-connection prints no such line.');
        $this->assertStringContainsString("'Service bind' => \$diag['service_bind'] ? 'OK' : 'FAILED',", $this->source('commands/LdapController.php'), 'The line ldap/test-connection prints.');
        $this->assertMentions(
            '| `LDAP_ENABLED` | `false` by default. Set to `true` to enable — see [ldap.md](ldap.md) for the full variable set. Prebuilt images: 2.11.0 or later (see [ldap.md](ldap.md#container-images)). |',
            $this->flat('docs/installation.md'),
            'installation.md'
        );
    }

    /**
     * What ldap.md says of each image is what builds it: release.yml builds
     * the app image from Dockerfile.prod, which installs the extension in its
     * final stage, as docker/php/Dockerfile does; the runner image installs
     * none; the quickstart runs the prebuilt images in both modes, and the
     * deploy role builds docker/php/Dockerfile unless told to use them.
     */
    public function testWhatLdapMdSaysOfEachImageIsWhatBuildsIt(): void
    {
        $release = $this->source('.github/workflows/release.yml');
        $this->assertSame(1, preg_match('#- image: ansilume/ansilume\s*\n(?:\s+\w+: .*\n)*?\s+dockerfile: docker/php/Dockerfile\.prod#', $release), 'release.yml builds the app image from Dockerfile.prod.');
        $this->assertSame(1, preg_match('#- image: ansilume/ansilume-runner\s*\n(?:\s+\w+: .*\n)*?\s+dockerfile: docker/runner/Dockerfile#', $release), 'release.yml builds the runner image from docker/runner/Dockerfile.');
        foreach (['docker/php/Dockerfile.prod', 'docker/php/Dockerfile'] as $dockerfile) {
            $stage = $this->finalStage($dockerfile);
            $this->assertStringContainsString("    libldap2-dev \\\n", $stage, "{$dockerfile} installs the LDAP library.");
            $this->assertSame(1, preg_match('/RUN docker-php-ext-install \\\\\n(?:\s+[a-z_]+ \\\\\n)*\s+ldap \\\\\n/', $stage), "{$dockerfile} installs the ldap extension.");
        }
        $this->assertStringNotContainsString('ldap', $this->source('docker/runner/Dockerfile'), 'The runner image has no ldap extension.');
        $this->assertSame(1, preg_match('/if \[ "\$SOURCE_MODE" = "true" \]; then\n(?:(?!\nelse\n).)*COMPOSE_FILE="docker-compose\.prebuilt\.yml"\nelse\n/s', $this->source('bin/quickstart')), 'The quickstart\'s mode that clones the repository runs the prebuilt images.');
        $this->assertStringContainsString('ansilume_use_prebuilt_images: false', $this->source('deploy/roles/ansilume/defaults/main.yaml'), 'The deploy role builds its images by default.');
        $this->assertSame(1, preg_match('/\{% else %\}\n    build:\n      context: \.\n      dockerfile: docker\/php\/Dockerfile\n/', $this->source('deploy/roles/ansilume/templates/docker-compose.yaml')), 'The deploy role builds the app from docker/php/Dockerfile.');
        $this->assertStringContainsString('throw new LdapException(self::EXTENSION_NOT_LOADED);', $this->source('services/ldap/PhpLdapClient.php'), 'The client refuses to start without the extension.');
    }

    /**
     * regression: the update notes and the troubleshooting guide said
     * nothing of the missing extension. The fixes, the rollback and the
     * entry quote PhpLdapClient::EXTENSION_NOT_LOADED and say what to do.
     */
    public function testRegressionTheUpdateNotesAndTroubleshootingNameTheMissingExtension(): void
    {
        $section = $this->flat('docs/updating.md', WorkflowStepsUpdateDocsContractTest::UPDATE_SECTION);
        $this->assertMentions('LDAP works with the prebuilt images for the first time: their app image has PHP\'s `ldap` extension now (see **Fixes that change behaviour**).', $section, 'The intro of updating.md');
        $fixes = $this->between($section, '**Fixes that change behaviour:**', '**API contract change:**');
        $fix = [
            '- The prebuilt app image `ghcr.io/ansilume/ansilume` had no PHP `ldap` extension in any release since LDAP came in 2.1.11.',
            'With `LDAP_ENABLED=true` and a host and base DN set, the sign-in of an LDAP user, or of a username that is no local account, showed the error page (`500`), `php yii ldap/test-connection`, `ldap/test-user` and `ldap/sync` stopped with `'
                . self::CONSOLE_ERROR . '`, and `/api/v1/admin/ldap/test` answered `500`; local accounts signed in as before.',
            'The image has the extension now (' . self::CHECK . ' prints `ldap`), so LDAP works for the first time with `docker-compose.prebuilt.yml`, the quickstart and the deploy role\'s prebuilt mode.',
            'A git checkout and the deploy role\'s default build mode had it already (see [ldap.md](ldap.md#container-images)).',
        ];
        foreach ($fix as $phrase) {
            $this->assertMentions($phrase, $fixes, 'The fixes of updating.md');
        }
        $this->assertMentions(
            'This takes the `ldap` extension away again: the sign-in of LDAP users shows the error page, and `php yii ldap/*` stops with `' . self::CONSOLE_ERROR . '`',
            $this->item('**Database and deployment.**'),
            'The rollback of updating.md'
        );

        $entry = $this->flat('docs/troubleshooting.md', self::ENTRY);
        $expected = [
            '**Symptom:** With `LDAP_ENABLED=true` and a host and base DN set, `php yii ldap/test-connection`, `php yii ldap/test-user <username>` and `php yii ldap/sync` (at its first LDAP account) stop with `'
                . self::CONSOLE_ERROR . '` and exit with `1`.',
            'The application log has an `' . LdapException::class . '` with `' . PhpLdapClient::EXTENSION_NOT_LOADED . '`',
            '**Cause:** The PHP of the container has no `ldap` extension. The prebuilt app image `ghcr.io/ansilume/ansilume` had none up to 2.10.1',
            '**Fix:** Update to 2.11.0 or later (`docker compose pull && docker compose up -d`, or quickstart `--update`). Then ' . self::CHECK . ' prints `ldap`',
            'An image of your own built on `ghcr.io/ansilume/ansilume` 2.10.1 or older needs `libldap2-dev` and `docker-php-ext-install ldap`, as `docker/php/Dockerfile.prod` has them from 2.11.0 on. See [ldap.md](ldap.md#container-images).',
        ];
        foreach ($expected as $phrase) {
            $this->assertMentions($phrase, $entry, 'troubleshooting.md, on the missing extension');
        }
    }

    /**
     * The final stage of $dockerfile: from its last FROM on.
     */
    private function finalStage(string $dockerfile): string
    {
        $text = $this->source($dockerfile);
        $this->assertGreaterThan(0, (int)preg_match_all('/^FROM /m', $text, $froms, PREG_OFFSET_CAPTURE), "{$dockerfile} has a FROM.");
        $last = end($froms[0]);
        $this->assertIsArray($last);

        return substr($text, (int)$last[1]);
    }

    private function source(string $file): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $file);
    }
}
