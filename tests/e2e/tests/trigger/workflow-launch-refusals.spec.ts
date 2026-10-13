import { test, expect } from '@playwright/test';
import { TRIGGER_HEADER, generateTriggerToken, openWorkflowTemplate, revokeTriggerTokenIfPresent } from '../../lib/triggers';
import { EMPTY_WORKFLOW, SOLO_GATE_REFUSAL, SOLO_GATE_WORKFLOW, runCount } from '../../lib/workflows';

/**
 * A workflow trigger whose launch is refused for a reason the caller can fix
 * answers 422 with the reason, as POST /trigger/fire does, and starts no run.
 * Fixtures (commands/E2eWorkflowGateSeeder.php): e2e-empty-workflow has no
 * steps; e2e-solo-gate-workflow has an approval step that only e2e-admin may
 * decide, and a trigger runs as the user who generated its token, here
 * e2e-admin, who never decides on their own launch. Each test revokes the
 * token it generated.
 */
test.describe('Workflow trigger launch refusals', () => {
  const refusals: [string, (id: string) => string][] = [
    [EMPTY_WORKFLOW, (id) => `Workflow template #${id} has no steps.`],
    [SOLO_GATE_WORKFLOW, () => SOLO_GATE_REFUSAL],
  ];
  for (const [workflow, reason] of refusals) {
    test(`a trigger of ${workflow} answers 422 with the reason, and no run starts`, async ({ page, request }) => {
      const before = await runCount(page, workflow);
      const id = await openWorkflowTemplate(page, workflow);
      const token = await generateTriggerToken(page, 'wf-');
      try {
        const response = await request.post('/trigger/fire-workflow', { headers: { [TRIGGER_HEADER]: token } });
        expect(response.status()).toBe(422);
        expect(await response.json()).toEqual({ error: reason(id) });
      } finally {
        await openWorkflowTemplate(page, workflow);
        await revokeTriggerTokenIfPresent(page);
      }
      expect(await runCount(page, workflow), 'no run was written').toBe(before);
    });
  }
});
