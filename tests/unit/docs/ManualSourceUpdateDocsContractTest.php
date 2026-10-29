<?php

declare(strict_types=1);

namespace app\tests\unit\docs;

use PHPUnit\Framework\TestCase;

/**
 * Contract: the manual source update of docs/updating.md names the images
 * that a build of the development image pulls from Docker Hub, as
 * docker/php/Dockerfile names them: the base of each stage and the source of
 * each COPY --from that is no stage of its own. docker-compose.yml builds
 * every PHP service from that Dockerfile, and Docker Hub limits anonymous
 * pulls, so the text tells an operator what `docker login` must reach.
 *
 * The text describes the current Dockerfile, so it follows a change of its
 * images, and this test says when it did not. The update notes of a release
 * quote the images of that release as fixed text instead, as history
 * (Php85UpdateDocsContractTest). Review finding: the update notes of PHP 8.5
 * were checked against the Dockerfiles, so that a later move to
 * composer:2.11 would have failed the history; this check was part of them.
 */
class ManualSourceUpdateDocsContractTest extends TestCase
{
    use ReadsOperatorDocs;

    /** The Dockerfile of the development image, which docker-compose.yml builds for every PHP service. */
    private const DEVELOPMENT_DOCKERFILE = 'docker/php/Dockerfile';

    private const MANUAL_UPDATE = '## Manual update — source (git)';

    public function testTheManualSourceUpdateNamesTheImagesTheBuildPulls(): void
    {
        $images = self::imagesPulledBy($this->read(self::DEVELOPMENT_DOCKERFILE));

        $this->assertNotSame([], $images, self::DEVELOPMENT_DOCKERFILE . ' pulls an image.');
        $this->assertMentions(
            'The builds need Docker Hub (`' . implode('`, `', $images) . '`)',
            $this->flat('docs/updating.md', self::MANUAL_UPDATE),
            'the manual source update'
        );
        $this->assertStringContainsString('dockerfile: ' . self::DEVELOPMENT_DOCKERFILE, $this->read('docker-compose.yml'));
    }

    /**
     * The images are the bases of the stages and the sources of COPY
     * --from, in their order and each once; a stage of the Dockerfile, a
     * COPY without --from and a comment are none.
     */
    public function testThePulledImagesAreRead(): void
    {
        $dockerfile = "FROM php:8.9-fpm AS base\n# FROM php:7.4-fpm\nfrom base AS dev\nRUN true\n"
            . "COPY --from=composer:2.11 /usr/bin/composer /usr/bin/composer\nCOPY --from=BASE /x /y\n"
            . "COPY ./src /var/www\nCOPY --from=composer:2.11 /usr/bin/composer /usr/local/bin/composer\n"
            . "COPY --from=nginx:1.30-alpine-slim /etc/nginx /etc/nginx\n";

        $this->assertSame(['php:8.9-fpm', 'composer:2.11', 'nginx:1.30-alpine-slim'], self::imagesPulledBy($dockerfile));
    }

    /**
     * The images the Dockerfile $text pulls: the bases of its stages and the
     * sources of its COPY --from that are no stage of its own, in their
     * order.
     *
     * @return list<string>
     */
    private static function imagesPulledBy(string $text): array
    {
        preg_match_all('/^FROM\s+(\S+)(?:\s+AS\s+(\S+))?/mi', $text, $from);
        preg_match_all('/^COPY\s+--from=(\S+)/mi', $text, $copies);
        $stages = array_map('strtolower', array_filter($from[2]));

        return array_values(array_unique(array_filter(
            array_merge($from[1], $copies[1]),
            static fn (string $image): bool => !in_array(strtolower($image), $stages, true)
        )));
    }
}
