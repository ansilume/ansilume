<?php

declare(strict_types=1);

namespace app\components;

/**
 * Wraps an ansible-playbook command in `docker run --rm` for container isolation.
 */
class DockerCommandWrapper
{
    /**
     * @param string[] $ansibleCmd
     * @return string[]
     */
    public static function wrap(array $ansibleCmd, string $projectPath): array
    {
        $image = $_ENV['RUNNER_DOCKER_IMAGE'] ?? 'cytopia/ansible:latest';

        $dockerCmd = [
            'docker', 'run', '--rm',
            '--user', posix_getuid() . ':' . posix_getgid(),
            '-v', $projectPath . ':/workspace:ro',
            '-v', sys_get_temp_dir() . ':' . sys_get_temp_dir(),
            '--workdir', '/workspace',
            $image,
        ];

        foreach ($ansibleCmd as $i => $part) {
            if ($i === 0) {
                continue;
            }
            if ($projectPath !== '' && str_starts_with($part, $projectPath)) {
                $dockerCmd[] = '/workspace/' . ltrim(substr($part, strlen($projectPath)), '/');
            } else {
                $dockerCmd[] = $part;
            }
        }

        return $dockerCmd;
    }
}
