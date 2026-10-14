<?php

declare(strict_types=1);

namespace app\tests\unit\models;

use app\models\Project;
use PHPUnit\Framework\TestCase;
use yii\db\BaseActiveRecord;
use yii\helpers\Json;
use yii\web\AssetManager;
use yii\web\View;

/**
 * The branch of a git project (scm_branch) cannot start with "-" nor hold
 * whitespace: Project::BRANCH_FORMAT. A manual project's branch never
 * reaches git and is not checked. No database required.
 *
 * regression: the model took any branch up to 128 characters, so the form
 * and the API accepted "--upload-pack=<command>", which git read as one of
 * its options up to 2.10.1. Since git gets the branch after "--", every sync
 * of such a project fails.
 */
class ProjectBranchFormatTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedBranchProvider(): array
    {
        return [
            'main' => ['main'],
            'a branch in a folder' => ['feature/x'],
            'a dash and dots' => ['release-1.2'],
            'a tag' => ['v1.0.0'],
            'a commit' => ['0123456789abcdef0123456789abcdef01234567'],
            'underscore and dash' => ['user/name_with-dash'],
            'a dash at the end' => ['hotfix-'],
            'letters beyond ASCII' => ['feature/ümlaut'],
            'one character' => ['x'],
            '128 characters' => [str_repeat('b', 128)],
        ];
    }

    /**
     * @dataProvider acceptedBranchProvider
     */
    public function testABranchThatStartsWithNoDashAndHoldsNoWhitespaceIsAccepted(string $branch): void
    {
        $project = $this->project($branch);

        $this->assertTrue($project->validate(['scm_branch']), (string)json_encode($branch));
        $this->assertSame([], $project->getErrors('scm_branch'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedBranchProvider(): array
    {
        return [
            'a dash first' => ['-x'],
            'an option of git' => ['--upload-pack=touch /tmp/pwned'],
            'only a dash' => ['-'],
            'a space first' => [' main'],
            'a space last' => ['main '],
            'a space inside' => ['a b'],
            'only a space' => [' '],
            'a tab first' => ["\tmain"],
            'a tab inside' => ["a\tb"],
            'a tab last' => ["main\t"],
            'a newline first' => ["\nmain"],
            'a newline inside' => ["a\nb"],
            // PCRE's "$" alone also matches before a last newline.
            'a newline last' => ["main\n"],
            'a carriage return' => ["main\r"],
            'a vertical tab' => ["a\x0Bb"],
            'a form feed' => ["a\x0Cb"],
            'a no-break space' => ["a\u{00A0}b"],
            'a line separator' => ["a\u{2028}b"],
            'an ideographic space' => ["a\u{3000}b"],
            'a dash after a space' => [' -x'],
        ];
    }

    /**
     * regression: the old model accepted each of these branches.
     *
     * @dataProvider refusedBranchProvider
     */
    public function testRegressionABranchThatStartsWithADashOrHoldsWhitespaceIsRefused(string $branch): void
    {
        $project = $this->project($branch);

        $this->assertFalse($project->validate(['scm_branch']), 'refused: ' . json_encode($branch));
        $this->assertSame([Project::BRANCH_FORMAT], $project->getErrors('scm_branch'), 'one error, with the exact text');
    }

    /**
     * A manual project's branch never reaches git (only git projects are
     * synced, on the server and on runners), and the project form hides
     * its Branch field: the format is checked for git projects only.
     *
     * regression (wave 5f): the rule applied whatever the type, so a branch
     * typed under Git and then hidden by switching the form to Manual (no
     * SCM), or one stored through the API before 2.11.0, kept a manual
     * project from being saved, with the error on a field the form hides.
     *
     * @dataProvider refusedBranchProvider
     */
    public function testRegressionTheBranchOfAManualProjectIsNotChecked(string $branch): void
    {
        $project = $this->project($branch, Project::SCM_TYPE_MANUAL);

        $this->assertTrue($project->validate(['scm_branch']), 'not checked: ' . json_encode($branch));
        $this->assertSame([], $project->getErrors('scm_branch'));
    }

    /**
     * Every save that results in a git project checks the branch: a manual
     * project switched to Git is refused until its branch is changed.
     */
    public function testAManualProjectSwitchedToGitIsCheckedAgain(): void
    {
        $project = $this->project('feature x', Project::SCM_TYPE_MANUAL);
        $this->assertTrue($project->validate(['scm_branch']), 'manual: not checked');

        $project->scm_type = Project::SCM_TYPE_GIT;

        $this->assertFalse($project->validate(['scm_branch']), 'git: checked');
        $this->assertSame([Project::BRANCH_FORMAT], $project->getErrors('scm_branch'));

        $project->scm_branch = 'feature/x';
        $this->assertTrue($project->validate(['scm_branch']), 'git, with a branch git can check out');
    }

    /**
     * Only a git or a manual project is saved: a project of another type is
     * refused for its type, so skipping its branch lets nothing through.
     */
    public function testAProjectOfAnotherTypeIsRefusedForItsType(): void
    {
        $project = $this->project('-x', 'svn');

        $this->assertFalse($project->validate());
        $this->assertSame(['Scm Type is invalid.'], $project->getErrors('scm_type'));
        $this->assertSame([], $project->getErrors('scm_branch'));
    }

    /**
     * The pattern reads UTF-8: a branch of other bytes matches nothing and
     * is refused too. A browser sends the form as UTF-8; only a crafted
     * request sends other bytes.
     */
    public function testABranchThatIsNoValidUtf8IsRefused(): void
    {
        $project = $this->project("main\xFF");

        $this->assertFalse($project->validate(['scm_branch']));
        $this->assertSame([Project::BRANCH_FORMAT], $project->getErrors('scm_branch'));
    }

    /**
     * An empty branch is allowed where it was allowed before: the format is
     * not checked.
     *
     * @return array<string, array{0: string|null}>
     */
    public static function emptyBranchProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
        ];
    }

    /**
     * @dataProvider emptyBranchProvider
     */
    public function testAnEmptyBranchIsNotCheckedForTheFormat(?string $branch): void
    {
        $project = $this->project($branch);

        $this->assertTrue($project->validate(['scm_branch']));
    }

    /**
     * A branch too long for the column gets only the length error, not the
     * format error as well.
     */
    public function testABranchThatIsTooLongGetsOnlyTheLengthError(): void
    {
        $project = $this->project('-' . str_repeat('x', 128));

        $this->assertFalse($project->validate(['scm_branch']));
        $this->assertCount(1, $project->getErrors('scm_branch'));
        $this->assertSame('Scm Branch should contain at most 128 characters.', $project->getFirstError('scm_branch'));
    }

    /**
     * The form checks the branch in the browser too, with the same pattern
     * and text (yii.validation.regularExpression): the pattern reaches it
     * without the PCRE-only D flag, and with u.
     */
    public function testTheFormChecksTheBranchInTheBrowserWithTheSameRule(): void
    {
        $project = $this->project('main');
        $view = new View();
        // The tests have no web root: the validators' asset bundle is a dummy.
        $view->setAssetManager(new AssetManager(['bundles' => false, 'basePath' => sys_get_temp_dir(), 'baseUrl' => '/assets']));
        $scripts = [];
        foreach ($project->getActiveValidators('scm_branch') as $validator) {
            $scripts[] = (string)$validator->clientValidateAttribute($project, 'scm_branch', $view);
        }
        $script = implode("\n", $scripts);

        $this->assertStringContainsString('yii.validation.regularExpression(value, messages, {"pattern":/^[^\s-]\S*$/u,"not":false,', $script);
        // Json::htmlEncode() writes the quotes of the text as \u0022.
        $this->assertStringContainsString('"message":' . Json::htmlEncode(Project::BRANCH_FORMAT) . ',"skipOnEmpty":1', $script);
        $this->assertStringContainsString('\u0022-\u0022', $script);
    }

    private function project(mixed $branch, string $scmType = Project::SCM_TYPE_GIT): Project
    {
        $project = $this->getMockBuilder(Project::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['attributes', 'save'])
            ->getMock();
        $project->method('attributes')->willReturn(
            ['id', 'name', 'description', 'scm_type', 'scm_url', 'scm_branch',
             'local_path', 'scm_credential_id', 'status', 'last_synced_at',
             'last_sync_error', 'created_by', 'created_at', 'updated_at', 'vault_password_source']
        );
        $project->method('save')->willReturn(true);
        $attributes = new \ReflectionProperty(BaseActiveRecord::class, '_attributes');
        $attributes->setValue($project, [
            'name' => 'branch-format',
            'scm_type' => $scmType,
            'scm_url' => 'https://git.example.com/org/repo.git',
            'scm_branch' => $branch,
            'status' => Project::STATUS_NEW,
        ]);

        return $project;
    }
}
