<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Clears the targets that do not belong to a workflow step's type: the job
 * template of approval and pause steps, and the approval rule of job and
 * pause steps. The type is compared exactly, as dispatch compares it: a step
 * of another type, such as 1 or JOB, keeps both targets. Runs fail at such a
 * step, and its target tells the operator what to add again in its place.
 *
 * Older versions stored them. The add-step form posted the hidden dropdowns
 * of the other step types, and the API saved job_template_id on every step.
 * Dispatch never used them, but the workflow page showed a leftover job
 * template as the step's target, possibly one of a project the user may not
 * see. Team scoping counts only job steps, so this cleanup is not needed for
 * access; it keeps the data consistent with what dispatch and the page use.
 *
 * Data only. Running it again changes nothing.
 */
class m000078_000000_clear_step_targets_of_other_types extends Migration
{
    public function safeUp(): void
    {
        // The step types as stored, not the model's constants: a migration
        // must keep working when the model changes. The column's collation
        // ignores case and trailing spaces, so the cast compares exactly.
        $this->update(
            '{{%workflow_step}}',
            ['job_template_id' => null],
            ['and', "CAST([[step_type]] AS BINARY) IN ('approval', 'pause')", ['not', ['job_template_id' => null]]]
        );
        $this->update(
            '{{%workflow_step}}',
            ['approval_rule_id' => null],
            ['and', "CAST([[step_type]] AS BINARY) IN ('job', 'pause')", ['not', ['approval_rule_id' => null]]]
        );
    }

    public function safeDown(): bool
    {
        // Nothing to restore: dispatch never used the cleared values, and the
        // schema is unchanged, so reverting needs no data back.
        return true;
    }
}
