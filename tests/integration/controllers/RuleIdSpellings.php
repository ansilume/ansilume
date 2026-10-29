<?php

declare(strict_types=1);

namespace app\tests\integration\controllers;

use app\models\ApprovalRule;
use app\models\AuditLog;
use Symfony\Component\Yaml\Yaml;

/**
 * The spellings of an approval rule's ID that the integer rule of a job
 * template or an inventory reads as that ID, and that the approval_rule_id
 * column stores as it: what a request may send for the rule. Use in
 * WebControllerTestCase subclasses, with SendsJsonNumbers for a JSON body.
 *
 * Regression: ApprovalRuleAssignmentPolicy::normalizeRuleId() read only an
 * int and a text of digits as a rule ID and kept every other spelling as
 * sent, although the integer rule accepted it and the column stored the
 * ID. So the check of who may assign a rule, and the audit of a job
 * template, read another value than the save stored: an admin's 5.0, 5e0,
 * "+5" or "5\n" gated the template by rule 5 while job-template.created and
 * job-template.updated recorded no approval_rule, or a change to another
 * rule as its removal, and a user without approval-rule.update who sent
 * the stored rule so was refused (403 ASSIGN_REFUSED), on job templates and
 * inventories alike.
 */
trait RuleIdSpellings
{
    /**
     * Each spelling as JSON text, for sprintf() with the rule's ID; the
     * first four went wrong, the others are their controls.
     *
     * @return array<string, array{0: string}>
     */
    public static function jsonSpellingProvider(): array
    {
        return [
            'a whole float (5.0)' => ['%d.0'],
            'an exponent (5e0)' => ['%de0'],
            'a plus sign ("+5")' => ['"+%d"'],
            'a final line break ("5\n")' => ['"%d\n"'],
            'leading zeros ("005")' => ['"00%d"'],
            'its digits ("5")' => ['"%d"'],
            'the ID (5)' => ['%d'],
        ];
    }

    /**
     * Each spelling as a url-encoded form posts it, for sprintf() with the
     * rule's ID; the first two went wrong, the others are their controls.
     *
     * @return array<string, array{0: string}>
     */
    public static function formSpellingProvider(): array
    {
        return [
            'a plus sign' => ['+%d'],
            'a final line break' => ["%d\n"],
            'leading zeros' => ['00%d'],
            'its digits' => ['%d'],
        ];
    }

    /**
     * Values that are no rule ID: the integer rule refuses them, so they
     * stay a change of the rule for everyone and a validation error for
     * admins. As JSON text, for sprintf() with the rule's ID.
     *
     * @return array<string, array{0: string}>
     */
    public static function noRuleIdProvider(): array
    {
        return [
            'an exponent as text ("5e0")' => ['"%de0"'],
            'a whole float as text ("5.0")' => ['"%d.0"'],
            'a leading space (" 5")' => ['" %d"'],
            'a fraction (5.5)' => ['%d.5'],
            'a list ([5])' => ['[%d]'],
            'true' => ['true'],
            'text ("abc")' => ['"abc"'],
        ];
    }

    /**
     * The JSON text of $fields with approval_rule_id set to $ruleJson as
     * written: a JSON value such as 5.0 that json_encode() would write
     * otherwise.
     *
     * @param array<string, mixed> $fields
     */
    protected function jsonWithRule(array $fields, string $ruleJson): string
    {
        $fields['approval_rule_id'] = '@rule@';

        return str_replace('"@rule@"', $ruleJson, (string)json_encode($fields));
    }

    /**
     * A rule as an audit entry names it.
     *
     * @return array{id: int, name: string}
     */
    protected function ruleEntry(ApprovalRule $rule): array
    {
        return ['id' => (int)$rule->id, 'name' => (string)$rule->name];
    }

    /**
     * The context of the newest $action entry of $objectType #$objectId.
     *
     * @return array<string, mixed>
     */
    protected function newestAuditContext(string $action, string $objectType, int $objectId): array
    {
        $entry = AuditLog::find()
            ->where(['action' => $action, 'object_type' => $objectType, 'object_id' => $objectId])
            ->orderBy(['id' => SORT_DESC])
            ->one();
        $this->assertInstanceOf(AuditLog::class, $entry, "a {$action} entry of {$objectType} #{$objectId}");
        $context = json_decode((string)$entry->metadata, true);
        $this->assertIsArray($context);

        return $context;
    }

    protected function newestAuditId(): int
    {
        return (int)AuditLog::find()->max('id');
    }

    /**
     * The description of approval_rule_id in the schema $schema of
     * web/openapi.yaml (JobTemplateInput, InventoryInput), on one line.
     */
    protected function ruleFieldSpecText(string $schema): string
    {
        $spec = Yaml::parseFile(dirname(__DIR__, 3) . '/web/openapi.yaml');
        $this->assertIsArray($spec);
        $text = $spec['components']['schemas'][$schema]['properties']['approval_rule_id']['description'] ?? null;
        $this->assertIsString($text, "web/openapi.yaml: {$schema}.approval_rule_id has a description.");

        return trim((string)preg_replace('/\s+/', ' ', $text));
    }
}
