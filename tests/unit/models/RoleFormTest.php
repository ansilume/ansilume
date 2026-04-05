<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\RoleForm;
use PHPUnit\Framework\TestCase;

/**
 * Pure validation tests for RoleForm. Uniqueness check is exercised in
 * RoleServiceTest (which needs a real auth manager).
 */
class RoleFormTest extends TestCase
{
    public function testNameRequired(): void
    {
        $form = $this->makeForm(['name' => '']);
        $form->validate(['name']);
        $this->assertTrue($form->hasErrors('name'));
    }

    public function testNameTooShortFails(): void
    {
        $form = $this->makeForm(['name' => 'ab']);
        $form->validate(['name']);
        $this->assertTrue($form->hasErrors('name'));
    }

    public function testNameWithUppercaseFails(): void
    {
        $form = $this->makeForm(['name' => 'MyRole']);
        $form->validate(['name']);
        $this->assertTrue($form->hasErrors('name'));
    }

    public function testNameStartingWithDigitFails(): void
    {
        $form = $this->makeForm(['name' => '1role']);
        $form->validate(['name']);
        $this->assertTrue($form->hasErrors('name'));
    }

    public function testNameWithSpacesFails(): void
    {
        $form = $this->makeForm(['name' => 'my role']);
        $form->validate(['name']);
        $this->assertTrue($form->hasErrors('name'));
    }

    public function testValidNamePasses(): void
    {
        $form = $this->makeForm(['name' => 'reader-plus_1']);
        $form->validate(['name']);
        $this->assertFalse($form->hasErrors('name'));
    }

    public function testNameTooLongFails(): void
    {
        $form = $this->makeForm(['name' => str_repeat('a', 41)]);
        $form->validate(['name']);
        $this->assertTrue($form->hasErrors('name'));
    }

    public function testReservedNameRejected(): void
    {
        foreach (RoleForm::RESERVED_NAMES as $reserved) {
            $form = $this->makeForm(['name' => $reserved]);
            $form->validate(['name']);
            $this->assertTrue(
                $form->hasErrors('name'),
                "Reserved name '{$reserved}' should be rejected"
            );
        }
    }

    public function testDescriptionTooLongFails(): void
    {
        $form = $this->makeForm(['description' => str_repeat('x', 256)]);
        $form->validate(['description']);
        $this->assertTrue($form->hasErrors('description'));
    }

    public function testValidPermissionPasses(): void
    {
        $form = $this->makeForm(['permissions' => ['project.view', 'job.view']]);
        $form->validate(['permissions']);
        $this->assertFalse($form->hasErrors('permissions'));
    }

    public function testUnknownPermissionRejected(): void
    {
        $form = $this->makeForm(['permissions' => ['project.view', 'not.real']]);
        $form->validate(['permissions']);
        $this->assertTrue($form->hasErrors('permissions'));
    }

    public function testEmptyPermissionsAllowed(): void
    {
        $form = $this->makeForm(['permissions' => []]);
        $form->validate(['permissions']);
        $this->assertFalse($form->hasErrors('permissions'));
    }

    public function testAttributeLabels(): void
    {
        $form = new RoleForm();
        $labels = $form->attributeLabels();
        $this->assertSame('Name', $labels['name']);
        $this->assertSame('Description', $labels['description']);
        $this->assertSame('Permissions', $labels['permissions']);
    }

    /**
     * Returns a RoleForm subclass that strips the unique validator so tests
     * don't need to hit the auth manager.
     */
    private function makeForm(array $overrides = []): RoleForm
    {
        $form = new class extends RoleForm {
            public function rules(): array
            {
                return array_values(array_filter(parent::rules(), static function ($rule) {
                    return ($rule[1] ?? null) !== 'validateUnique';
                }));
            }
        };
        $form->name = 'valid-name';
        $form->description = '';
        $form->permissions = [];
        foreach ($overrides as $k => $v) {
            $form->$k = $v;
        }
        return $form;
    }
}
