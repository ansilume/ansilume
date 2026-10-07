<?php

declare(strict_types=1);

namespace app\components;

use app\models\Credential;
use app\models\Inventory;
use app\models\JobTemplate;
use yii\db\ActiveQuery;
use yii\db\Expression;

/**
 * Problems of job templates that were saved before Ansilume rejected them.
 * Such templates keep running; the template page, the template list and the
 * REST API show these warnings so operators can find and fix them.
 *
 * - multiple_vault_credentials: more than one vault password; Ansible only
 *   ever gets the first one.
 * - inventory_other_project: a file or dynamic inventory of another project,
 *   which the runner never reads.
 */
final class JobTemplateWarnings
{
    public const MULTIPLE_VAULT_CREDENTIALS = 'multiple_vault_credentials';
    public const INVENTORY_OTHER_PROJECT = 'inventory_other_project';
    public const CODES = [self::MULTIPLE_VAULT_CREDENTIALS, self::INVENTORY_OTHER_PROJECT];

    /**
     * @return list<array{code: string, message: string, credential_ids: list<int>}>
     */
    public static function forTemplate(JobTemplate $template): array
    {
        $warnings = [];
        $vaults = VaultCredentialRule::vaults($template->credentialSnapshot());
        if (count($vaults) > 1) {
            $ignored = array_slice($vaults, 1);
            $warnings[] = [
                'code' => self::MULTIPLE_VAULT_CREDENTIALS,
                'message' => sprintf(
                    'This template has %d vault passwords, but Ansible gets only one: %s takes precedence and %s %s ignored. '
                    . 'Saving the template is rejected until only one is left.',
                    count($vaults),
                    VaultCredentialRule::names([$vaults[0]]),
                    VaultCredentialRule::names($ignored),
                    count($ignored) === 1 ? 'is' : 'are'
                ),
                'credential_ids' => array_column($ignored, 'id'),
            ];
        }
        if ($template->hasCrossProjectInventory()) {
            $warnings[] = [
                'code' => self::INVENTORY_OTHER_PROJECT,
                'message' => sprintf(
                    'The inventory "%s" is a file or dynamic inventory of another project. The runner checks out only this '
                    . 'template\'s project and looks for the inventory there, so jobs do not use it. '
                    . 'Switch to an inventory of this project or a static inventory.',
                    (string)($template->inventory->name ?? '')
                ),
                'credential_ids' => [],
            ];
        }

        return $warnings;
    }

    /**
     * Restricts a job template query to templates with the given warning.
     * False for an unknown code; the query is then left unchanged.
     */
    public static function filter(ActiveQuery $query, string $code): bool
    {
        if ($code === self::MULTIPLE_VAULT_CREDENTIALS) {
            $query->andWhere(new Expression(
                '(SELECT COUNT(DISTINCT vc.id) FROM {{%credential}} vc WHERE vc.credential_type = :vault_type'
                . ' AND (vc.id = {{%job_template}}.credential_id OR vc.id IN'
                . ' (SELECT vp.credential_id FROM {{%job_template_credential}} vp WHERE vp.job_template_id = {{%job_template}}.id))) > 1',
                [':vault_type' => Credential::TYPE_VAULT]
            ));
            return true;
        }
        if ($code === self::INVENTORY_OTHER_PROJECT) {
            $query->innerJoin(['winv' => Inventory::tableName()], 'winv.id = {{%job_template}}.inventory_id')
                ->andWhere(['winv.inventory_type' => [Inventory::TYPE_FILE, Inventory::TYPE_DYNAMIC]])
                ->andWhere(['not', ['winv.project_id' => null]])
                ->andWhere(new Expression('winv.project_id <> {{%job_template}}.project_id'));
            return true;
        }

        return false;
    }

    /**
     * How many templates of the query have each warning.
     *
     * @return array<string, int> code => count
     */
    public static function counts(ActiveQuery $query): array
    {
        $counts = [];
        foreach (self::CODES as $code) {
            $filtered = clone $query;
            self::filter($filtered, $code);
            $counts[$code] = (int)$filtered->count();
        }

        return $counts;
    }

    public static function label(string $code): string
    {
        return match ($code) {
            self::MULTIPLE_VAULT_CREDENTIALS => 'more than one vault password',
            self::INVENTORY_OTHER_PROJECT => 'an inventory of another project',
            default => $code,
        };
    }
}
