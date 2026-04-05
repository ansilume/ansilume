<?php

declare(strict_types=1);

namespace app\services\notification;

/**
 * Mustache-like template renderer used by notification channels.
 *
 * Replaces {{ variable.path }} placeholders with provided values. Missing
 * variables are replaced with an empty string.
 *
 * Variables come from the dispatcher as a flat string map (e.g. 'job.id' =>
 * '42'). The helper buildVariables() flattens an arbitrary payload tree into
 * that shape so any producer can hand us structured context without thinking
 * about keys.
 */
class TemplateRenderer
{
    /**
     * Render a template string with the given variables.
     *
     * @param array<string, string> $variables Flat dot-keyed map (e.g. 'job.id' => '42')
     */
    public function render(string $template, array $variables): string
    {
        return (string)preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            static function (array $matches) use ($variables): string {
                return $variables[$matches[1]] ?? '';
            },
            $template
        );
    }

    /**
     * Flatten a nested payload into the dot-key map the template engine expects.
     * Non-scalar leaves are JSON-encoded so templates can still reference them.
     *
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    public function buildVariables(array $payload): array
    {
        $out = [
            'timestamp' => date('Y-m-d H:i:s T'),
            'app.url' => $this->baseUrl(),
        ];
        $this->flatten($payload, '', $out);
        return $out;
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, string> $out
     */
    private function flatten(array $value, string $prefix, array &$out): void
    {
        foreach ($value as $key => $item) {
            $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
            if (is_array($item)) {
                $this->flatten($item, $path, $out);
                continue;
            }
            if (is_scalar($item) || $item === null) {
                $out[$path] = (string)($item ?? '');
                continue;
            }
            $out[$path] = (string)json_encode($item);
        }
    }

    private function baseUrl(): string
    {
        if (\Yii::$app->has('request') && \Yii::$app->request instanceof \yii\web\Request) {
            return (string)\Yii::$app->request->hostInfo;
        }
        if (!empty(\Yii::$app->params['appBaseUrl'])) {
            return rtrim((string)\Yii::$app->params['appBaseUrl'], '/');
        }
        return '';
    }
}
