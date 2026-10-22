<?php

declare(strict_types=1);

namespace app\tests\unit\docker;

use Symfony\Component\Yaml\Yaml;

/**
 * Renders the templates of the deploy role (deploy/roles/ansilume) with its
 * defaults, the way Ansible would: with Jinja2, Ansible's filters, and the
 * defaults that are templates themselves (ansilume_image_tag) rendered
 * before they are used.
 */
trait RendersDeployRole
{
    /**
     * The role's compose template, rendered and parsed.
     *
     * @param array<string, mixed> $variables set on top of the role defaults
     * @return array<string, mixed>
     */
    private function renderDeployTemplate(bool $prebuilt, array $variables = []): array
    {
        $compose = Yaml::parse($this->renderDeployFile('docker-compose.yaml', ['ansilume_use_prebuilt_images' => $prebuilt] + $variables));
        $this->assertIsArray($compose);

        return $compose;
    }

    /**
     * @param array<string, mixed> $variables set on top of the role defaults
     */
    private function renderDeployFile(string $template, array $variables = []): string
    {
        return $this->renderDeployText((string)file_get_contents(dirname(__DIR__, 3) . '/deploy/roles/ansilume/templates/' . $template), $variables);
    }

    /**
     * Renders $text, a template such as the value of a task's argument, with
     * the role defaults and $variables.
     *
     * @param array<string, mixed> $variables set on top of the role defaults
     */
    private function renderDeployText(string $text, array $variables = []): string
    {
        if ($this->python(['-c', 'import jinja2, yaml, ansible'])['rc'] !== 0) {
            $this->markTestSkipped('python3 with jinja2, PyYAML and Ansible is needed to render the deploy role.');
        }
        $script = <<<'PY'
            import json, sys, yaml, jinja2
            from ansible.plugins.filter.core import FilterModule
            role, text, overrides = sys.argv[1], sys.argv[2], json.loads(sys.argv[3])
            variables = yaml.safe_load(open(role + '/defaults/main.yaml')) or {}
            variables.update({'ansible_managed': 'Ansible managed'}, **overrides)
            environment = jinja2.Environment(undefined=jinja2.StrictUndefined, keep_trailing_newline=True)
            # Ansible's own filters, but Jinja's default and map: Ansible's need its templar.
            environment.filters.update({name: f for name, f in FilterModule().filters().items() if name not in environment.filters})
            for name, value in list(variables.items()):
                if isinstance(value, str) and '{{' in value:
                    variables[name] = environment.from_string(value).render(**variables)
            sys.stdout.write(environment.from_string(text).render(**variables))
            PY;
        $result = $this->python(['-c', $script, dirname(__DIR__, 3) . '/deploy/roles/ansilume', $text, (string)json_encode((object)$variables)]);
        $this->assertSame(0, $result['rc'], $result['err']);

        return $result['out'];
    }

    /**
     * @param list<string> $args
     * @return array{rc: int, out: string, err: string}
     */
    private function python(array $args): array
    {
        $process = proc_open(['python3', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return ['rc' => 127, 'out' => '', 'err' => 'python3 not available'];
        }
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['rc' => proc_close($process), 'out' => $out, 'err' => $err];
    }
}
