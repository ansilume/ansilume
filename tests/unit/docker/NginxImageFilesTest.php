<?php

declare(strict_types=1);

namespace app\tests\unit\docker;

use PHPUnit\Framework\TestCase;

/**
 * Regression: docker/nginx/default.conf serves /openapi.yaml as a static
 * file (since 1.6.0), but the prebuilt nginx image (docker/nginx/Dockerfile.prod)
 * never copied web/openapi.yaml. Installations from the prebuilt images
 * answered 404 for the API spec, and Swagger UI could not load it; the dev
 * checkout bind-mounts web/ and served it.
 *
 * Every file the config serves through an exact location must be in web/
 * and copied into the image.
 */
class NginxImageFilesTest extends TestCase
{
    public function testEveryStaticFileTheConfigServesIsCopiedIntoTheImage(): void
    {
        $files = $this->staticFilesServed();
        $this->assertContains('openapi.yaml', $files, 'the config serves the API spec');

        $sources = $this->copySources();
        foreach ($files as $file) {
            $this->assertFileExists($this->root() . '/web/' . $file, "web/{$file} exists");
            $this->assertTrue(
                $this->copied('web/' . $file, $sources),
                "docker/nginx/Dockerfile.prod copies web/{$file}, which default.conf serves"
            );
        }
    }

    public function testTheMatchingOfCopySourcesIsExact(): void
    {
        $this->assertTrue($this->copied('web/logo.svg', ['web/*.svg']));
        $this->assertTrue($this->copied('web/openapi.yaml', ['web/openapi.yaml']));
        $this->assertFalse($this->copied('web/openapi.yaml', ['web/*.svg', 'web/js', 'web/index.php']));
    }

    /**
     * The files of exact locations (`location = /name {`) that are served
     * from disk (`try_files $uri`).
     *
     * @return list<string>
     */
    private function staticFilesServed(): array
    {
        $config = (string)file_get_contents($this->root() . '/docker/nginx/default.conf');
        preg_match_all('~location = /([A-Za-z0-9._-]+) \{(.*?)\n\s*\}~s', $config, $matches, PREG_SET_ORDER);
        $files = [];
        foreach ($matches as $match) {
            if (str_contains($match[2], 'try_files $uri')) {
                $files[] = $match[1];
            }
        }

        return $files;
    }

    /**
     * The sources of the COPY instructions of the prebuilt nginx image.
     *
     * @return list<string>
     */
    private function copySources(): array
    {
        $dockerfile = (string)file_get_contents($this->root() . '/docker/nginx/Dockerfile.prod');
        preg_match_all('~^COPY\s+(?:--\S+\s+)*(\S+)\s+\S+\s*$~m', $dockerfile, $matches);

        return array_values($matches[1]);
    }

    /**
     * @param list<string> $sources
     */
    private function copied(string $path, array $sources): bool
    {
        foreach ($sources as $source) {
            if (fnmatch($source, $path, FNM_PATHNAME)) {
                return true;
            }
        }

        return false;
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
