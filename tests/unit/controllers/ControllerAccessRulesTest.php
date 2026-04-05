<?php

declare(strict_types=1);

namespace app\tests\unit\controllers;

use app\controllers\ApprovalController;
use app\controllers\ApprovalRuleController;
use app\controllers\AuditLogController;
use app\controllers\CredentialController;
use app\controllers\JobTemplateController;
use app\controllers\ProjectController;
use app\controllers\RoleController;
use app\controllers\WorkflowJobController;
use app\controllers\WorkflowTemplateController;
use PHPUnit\Framework\TestCase;

/**
 * Tests that controllers declare the correct access rules and verb restrictions.
 *
 * These tests protect against accidental removal of permission checks:
 * if someone edits a controller and drops a required role, the test fails.
 *
 * The controllers extend BaseController which composes accessRules() and
 * verbRules() into Yii2 behavior filters.
 */
class ControllerAccessRulesTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Extract access rules from a controller via reflection.
     */
    private function getAccessRules(string $controllerClass): array
    {
        $ctrl = $this->getMockBuilder($controllerClass)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $ref = new \ReflectionMethod($ctrl, 'accessRules');
        $ref->setAccessible(true);
        return $ref->invoke($ctrl);
    }

    private function getVerbRules(string $controllerClass): array
    {
        $ctrl = $this->getMockBuilder($controllerClass)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $ref = new \ReflectionMethod($ctrl, 'verbRules');
        $ref->setAccessible(true);
        return $ref->invoke($ctrl);
    }

    /**
     * Assert that a specific action requires a specific role in the access rules.
     */
    private function assertActionRequiresRole(array $rules, string $action, string $role, string $message = ''): void
    {
        $found = false;
        foreach ($rules as $rule) {
            $actions = $rule['actions'] ?? [];
            $roles   = $rule['roles'] ?? [];
            if (in_array($action, $actions, true) && in_array($role, $roles, true)) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, $message ?: "Action '{$action}' must require role '{$role}'.");
    }

    /**
     * Assert that a specific action is POST-only.
     */
    private function assertActionIsPostOnly(array $verbRules, string $action): void
    {
        $this->assertArrayHasKey($action, $verbRules, "Action '{$action}' must have a verb rule.");
        $this->assertContains('POST', $verbRules[$action], "Action '{$action}' must allow POST.");
        $this->assertNotContains('GET', $verbRules[$action], "Action '{$action}' must NOT allow GET.");
    }

    // -------------------------------------------------------------------------
    // AuditLogController
    // -------------------------------------------------------------------------

    public function testAuditLogIndexRequiresPermission(): void
    {
        $rules = $this->getAccessRules(AuditLogController::class);
        $this->assertActionRequiresRole($rules, 'index', 'user.view');
    }

    public function testAuditLogViewRequiresPermission(): void
    {
        $rules = $this->getAccessRules(AuditLogController::class);
        $this->assertActionRequiresRole($rules, 'view', 'user.view');
    }

    public function testAuditLogHasNoVerbRules(): void
    {
        $verbs = $this->getVerbRules(AuditLogController::class);
        $this->assertEmpty($verbs, 'AuditLogController should have no verb restrictions (read-only).');
    }

    // -------------------------------------------------------------------------
    // JobTemplateController
    // -------------------------------------------------------------------------

    public function testJobTemplateViewRequiresPermission(): void
    {
        $rules = $this->getAccessRules(JobTemplateController::class);
        $this->assertActionRequiresRole($rules, 'index', 'job-template.view');
        $this->assertActionRequiresRole($rules, 'view', 'job-template.view');
    }

    public function testJobTemplateCreateRequiresPermission(): void
    {
        $rules = $this->getAccessRules(JobTemplateController::class);
        $this->assertActionRequiresRole($rules, 'create', 'job-template.create');
    }

    public function testJobTemplateUpdateRequiresPermission(): void
    {
        $rules = $this->getAccessRules(JobTemplateController::class);
        $this->assertActionRequiresRole($rules, 'update', 'job-template.update');
    }

    public function testJobTemplateDeleteRequiresPermission(): void
    {
        $rules = $this->getAccessRules(JobTemplateController::class);
        $this->assertActionRequiresRole($rules, 'delete', 'job-template.delete');
    }

    public function testJobTemplateLaunchRequiresPermission(): void
    {
        $rules = $this->getAccessRules(JobTemplateController::class);
        $this->assertActionRequiresRole($rules, 'launch', 'job.launch');
    }

    public function testJobTemplateDeleteIsPostOnly(): void
    {
        $verbs = $this->getVerbRules(JobTemplateController::class);
        $this->assertActionIsPostOnly($verbs, 'delete');
    }

    public function testJobTemplateTriggerTokenIsPostOnly(): void
    {
        $verbs = $this->getVerbRules(JobTemplateController::class);
        $this->assertActionIsPostOnly($verbs, 'generate-trigger-token');
        $this->assertActionIsPostOnly($verbs, 'revoke-trigger-token');
    }

    // -------------------------------------------------------------------------
    // CredentialController
    // -------------------------------------------------------------------------

    public function testCredentialViewRequiresPermission(): void
    {
        $rules = $this->getAccessRules(CredentialController::class);
        $this->assertActionRequiresRole($rules, 'index', 'credential.view');
        $this->assertActionRequiresRole($rules, 'view', 'credential.view');
    }

    public function testCredentialCreateRequiresPermission(): void
    {
        $rules = $this->getAccessRules(CredentialController::class);
        $this->assertActionRequiresRole($rules, 'create', 'credential.create');
    }

    public function testCredentialUpdateRequiresPermission(): void
    {
        $rules = $this->getAccessRules(CredentialController::class);
        $this->assertActionRequiresRole($rules, 'update', 'credential.update');
    }

    public function testCredentialDeleteRequiresPermission(): void
    {
        $rules = $this->getAccessRules(CredentialController::class);
        $this->assertActionRequiresRole($rules, 'delete', 'credential.delete');
    }

    public function testCredentialDeleteIsPostOnly(): void
    {
        $verbs = $this->getVerbRules(CredentialController::class);
        $this->assertActionIsPostOnly($verbs, 'delete');
    }

    public function testCredentialSshKeyGenerationIsPostOnly(): void
    {
        $verbs = $this->getVerbRules(CredentialController::class);
        $this->assertActionIsPostOnly($verbs, 'generate-ssh-key');
    }

    // -------------------------------------------------------------------------
    // ProjectController
    // -------------------------------------------------------------------------

    public function testProjectViewRequiresPermission(): void
    {
        $rules = $this->getAccessRules(ProjectController::class);
        $this->assertActionRequiresRole($rules, 'index', 'project.view');
        $this->assertActionRequiresRole($rules, 'view', 'project.view');
    }

    public function testProjectCreateRequiresPermission(): void
    {
        $rules = $this->getAccessRules(ProjectController::class);
        $this->assertActionRequiresRole($rules, 'create', 'project.create');
    }

    public function testProjectUpdateRequiresPermission(): void
    {
        $rules = $this->getAccessRules(ProjectController::class);
        $this->assertActionRequiresRole($rules, 'update', 'project.update');
    }

    public function testProjectDeleteRequiresPermission(): void
    {
        $rules = $this->getAccessRules(ProjectController::class);
        $this->assertActionRequiresRole($rules, 'delete', 'project.delete');
    }

    public function testProjectDeleteIsPostOnly(): void
    {
        $verbs = $this->getVerbRules(ProjectController::class);
        $this->assertActionIsPostOnly($verbs, 'delete');
    }

    public function testProjectSyncIsPostOnly(): void
    {
        $verbs = $this->getVerbRules(ProjectController::class);
        $this->assertActionIsPostOnly($verbs, 'sync');
    }

    public function testProjectSyncRequiresUpdatePermission(): void
    {
        $rules = $this->getAccessRules(ProjectController::class);
        $this->assertActionRequiresRole($rules, 'sync', 'project.update');
    }

    // -------------------------------------------------------------------------
    // ApprovalRuleController
    // -------------------------------------------------------------------------

    public function testApprovalRuleViewRequiresPermission(): void
    {
        $rules = $this->getAccessRules(ApprovalRuleController::class);
        $this->assertActionRequiresRole($rules, 'index', 'approval-rule.view');
        $this->assertActionRequiresRole($rules, 'view', 'approval-rule.view');
    }

    public function testApprovalRuleCreateRequiresPermission(): void
    {
        $rules = $this->getAccessRules(ApprovalRuleController::class);
        $this->assertActionRequiresRole($rules, 'create', 'approval-rule.create');
    }

    public function testApprovalRuleDeleteRequiresPermission(): void
    {
        $rules = $this->getAccessRules(ApprovalRuleController::class);
        $this->assertActionRequiresRole($rules, 'delete', 'approval-rule.delete');
    }

    public function testApprovalRuleDeleteIsPostOnly(): void
    {
        $verbs = $this->getVerbRules(ApprovalRuleController::class);
        $this->assertActionIsPostOnly($verbs, 'delete');
    }

    // -------------------------------------------------------------------------
    // ApprovalController
    // -------------------------------------------------------------------------

    public function testApprovalViewRequiresPermission(): void
    {
        $rules = $this->getAccessRules(ApprovalController::class);
        $this->assertActionRequiresRole($rules, 'index', 'approval.view');
        $this->assertActionRequiresRole($rules, 'view', 'approval.view');
    }

    public function testApprovalDecideRequiresPermission(): void
    {
        $rules = $this->getAccessRules(ApprovalController::class);
        $this->assertActionRequiresRole($rules, 'approve', 'approval.decide');
        $this->assertActionRequiresRole($rules, 'reject', 'approval.decide');
    }

    public function testApprovalDecideIsPostOnly(): void
    {
        $verbs = $this->getVerbRules(ApprovalController::class);
        $this->assertActionIsPostOnly($verbs, 'approve');
        $this->assertActionIsPostOnly($verbs, 'reject');
    }

    // -------------------------------------------------------------------------
    // WorkflowTemplateController
    // -------------------------------------------------------------------------

    public function testWorkflowTemplateViewRequiresPermission(): void
    {
        $rules = $this->getAccessRules(WorkflowTemplateController::class);
        $this->assertActionRequiresRole($rules, 'index', 'workflow-template.view');
        $this->assertActionRequiresRole($rules, 'view', 'workflow-template.view');
    }

    public function testWorkflowTemplateCreateRequiresPermission(): void
    {
        $rules = $this->getAccessRules(WorkflowTemplateController::class);
        $this->assertActionRequiresRole($rules, 'create', 'workflow-template.create');
    }

    public function testWorkflowTemplateDeleteRequiresPermission(): void
    {
        $rules = $this->getAccessRules(WorkflowTemplateController::class);
        $this->assertActionRequiresRole($rules, 'delete', 'workflow-template.delete');
    }

    public function testWorkflowTemplateLaunchRequiresPermission(): void
    {
        $rules = $this->getAccessRules(WorkflowTemplateController::class);
        $this->assertActionRequiresRole($rules, 'launch', 'workflow.launch');
    }

    public function testWorkflowTemplateDeleteIsPostOnly(): void
    {
        $verbs = $this->getVerbRules(WorkflowTemplateController::class);
        $this->assertActionIsPostOnly($verbs, 'delete');
    }

    public function testWorkflowTemplateLaunchIsPostOnly(): void
    {
        $verbs = $this->getVerbRules(WorkflowTemplateController::class);
        $this->assertActionIsPostOnly($verbs, 'launch');
    }

    // -------------------------------------------------------------------------
    // WorkflowJobController
    // -------------------------------------------------------------------------

    public function testWorkflowJobViewRequiresPermission(): void
    {
        $rules = $this->getAccessRules(WorkflowJobController::class);
        $this->assertActionRequiresRole($rules, 'index', 'workflow.view');
        $this->assertActionRequiresRole($rules, 'view', 'workflow.view');
    }

    public function testWorkflowJobCancelRequiresPermission(): void
    {
        $rules = $this->getAccessRules(WorkflowJobController::class);
        $this->assertActionRequiresRole($rules, 'cancel', 'workflow.cancel');
    }

    public function testWorkflowJobCancelIsPostOnly(): void
    {
        $verbs = $this->getVerbRules(WorkflowJobController::class);
        $this->assertActionIsPostOnly($verbs, 'cancel');
    }

    // -------------------------------------------------------------------------
    // RoleController
    // -------------------------------------------------------------------------

    public function testRoleViewRequiresPermission(): void
    {
        $rules = $this->getAccessRules(RoleController::class);
        $this->assertActionRequiresRole($rules, 'index', 'role.view');
        $this->assertActionRequiresRole($rules, 'view', 'role.view');
    }

    public function testRoleCreateRequiresPermission(): void
    {
        $rules = $this->getAccessRules(RoleController::class);
        $this->assertActionRequiresRole($rules, 'create', 'role.create');
    }

    public function testRoleUpdateRequiresPermission(): void
    {
        $rules = $this->getAccessRules(RoleController::class);
        $this->assertActionRequiresRole($rules, 'update', 'role.update');
    }

    public function testRoleDeleteRequiresPermission(): void
    {
        $rules = $this->getAccessRules(RoleController::class);
        $this->assertActionRequiresRole($rules, 'delete', 'role.delete');
    }

    public function testRoleDeleteIsPostOnly(): void
    {
        $verbs = $this->getVerbRules(RoleController::class);
        $this->assertActionIsPostOnly($verbs, 'delete');
    }
}
