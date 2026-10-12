<?php

declare(strict_types=1);

namespace app\components;

/**
 * Value object representing a single survey field definition.
 *
 * Stored as JSON in job_template.survey_fields:
 *   [{"name":"version","label":"Target Version","type":"text","required":true,"default":"latest"}]
 */
class SurveyField
{
    public const TYPE_TEXT = 'text';
    public const TYPE_TEXTAREA = 'textarea';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_SELECT = 'select';
    public const TYPE_PASSWORD = 'password';

    public string $name;
    public string $label;
    public string $type;
    public bool $required;
    public string $default;
    /** @var string[] Options list for TYPE_SELECT */
    public array $options;
    public string $hint;

    /**
     * @param string[] $options
     */
    public function __construct(
        string $name,
        string $label = '',
        string $type = self::TYPE_TEXT,
        bool $required = false,
        string $default = '',
        array $options = [],
        string $hint = ''
    ) {
        $this->name = $name;
        $this->label = $label ?: $name;
        $this->type = $type;
        $this->required = $required;
        $this->default = $default;
        $this->options = $options;
        $this->hint = $hint;
    }

    /**
     * A field from a stored definition. Values that are lists or objects,
     * which the API stored before survey definitions were checked, are read
     * as missing instead of failing with "Array to string conversion".
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            self::text($data['name'] ?? null),
            self::text($data['label'] ?? null),
            self::text($data['type'] ?? null, self::TYPE_TEXT),
            (bool)($data['required'] ?? false),
            self::text($data['default'] ?? null),
            self::options($data['options'] ?? null),
            self::text($data['hint'] ?? null)
        );
    }

    /**
     * $value as the (string) cast reads it; null, lists and objects give
     * $fallback.
     */
    private static function text(mixed $value, string $fallback = ''): string
    {
        return is_scalar($value) ? (string)$value : $fallback;
    }

    /**
     * The options as a list of text; entries that are lists or objects are
     * left out.
     *
     * @return string[]
     */
    private static function options(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $option): string => self::text($option), array_filter($value, 'is_scalar')));
    }

    /**
     * @return array{name: string, label: string, type: string, required: bool, default: string, options: string[], hint: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'default' => $this->default,
            'options' => $this->options,
            'hint' => $this->hint,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_TEXT => 'Text',
            self::TYPE_TEXTAREA => 'Multi-line text',
            self::TYPE_INTEGER => 'Integer',
            self::TYPE_BOOLEAN => 'Boolean (checkbox)',
            self::TYPE_SELECT => 'Select (dropdown)',
            self::TYPE_PASSWORD => 'Password (masked)',
        ];
    }

    /**
     * Parse the JSON survey_fields column into an array of SurveyField objects.
     *
     * @return self[]
     */
    public static function parseJson(?string $json): array
    {
        if (empty($json)) {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        return array_values(array_filter(
            array_map(fn ($d) => is_array($d) ? self::fromArray($d) : null, $data)
        ));
    }
}
