<?php

declare(strict_types=1);

namespace app\tests\integration\controllers\api\v1;

use app\controllers\api\v1\ApprovalsController;
use app\tests\integration\controllers\WebControllerTestCase;
use app\tests\integration\services\CancellationFixtures;
use app\tests\integration\TeamScopeFixtures;

/**
 * The ApprovalRequest schema of web/openapi.yaml lists its fields, in
 * `properties` and in `required`, in the order in which GET
 * /api/v1/approvals/{id} and the list answer them, so that the spec reads as
 * the answer does and its order cannot drift again.
 */
class OpenApiApprovalRequestOrderContractTest extends WebControllerTestCase
{
    use CancellationFixtures;
    use OpenApiSchemaAssertions;
    use TeamScopeFixtures;

    /**
     * regression: 2.12.0 answers required_by, inventory_id and
     * inventory_name right after approval_rule_id, but the schema listed
     * them last, after the workflow fields, in `properties` and in
     * `required`; the contract tests compared only the sets of keys, so
     * nothing failed.
     */
    public function testRegressionTheSchemaListedTheGateFieldsAfterTheWorkflowFields(): void
    {
        $admin = $this->userWithRole('order-admin', 'admin');
        $operator = $this->userWithRole('order-operator', 'operator');
        $this->loginAs($admin);
        $this->recordNotifications();
        try {
            ['request' => $request] = $this->jobWaitingForApproval($this->jobTemplateOwnedBy($admin), (int)$operator->id, (int)$admin->id);
        } finally {
            $this->restoreNotifications();
        }

        $ctrl = new ApprovalsController('api/v1/approvals', $this->app());
        $answers = [
            'GET /api/v1/approvals/{id}' => $this->data($ctrl->actionView((int)$request->id)),
            'GET /api/v1/approvals' => $this->listItem($ctrl->actionIndex(), (int)$request->id),
        ];

        foreach ($answers as $answer => $data) {
            $keys = array_keys($data);
            $this->assertSame($keys, $this->required('ApprovalRequest'), "ApprovalRequest.required lists the fields in the order of {$answer}.");
            $this->assertSame($keys, array_keys($this->properties('ApprovalRequest')), "ApprovalRequest.properties list the fields in the order of {$answer}.");
        }
    }
}
