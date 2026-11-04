<?php

declare(strict_types=1);

namespace app\tests\integration\models;

use app\models\NotificationDelivery;
use app\models\NotificationTemplate;
use app\tests\integration\DbTestCase;

/**
 * The refs of a delivery (NotificationDelivery::getRefs(), setRefs()): the
 * objects its event is about, one column each, as
 * NotificationVisibility::allows() takes them, so that the notification
 * worker checks the same objects when it delivers as the dispatcher did
 * when the event happened.
 */
class NotificationDeliveryRefsTest extends DbTestCase
{
    public function testTheRefsAreTheColumnsOfTheObjectsAnEventIsAbout(): void
    {
        $this->assertSame(
            ['workflow_job_id', 'job_id', 'approval_request_id', 'project_id', 'job_template_id'],
            NotificationDelivery::REF_ATTRIBUTES
        );
    }

    /**
     * The refs set are stored and read back as whole numbers; refs not set
     * stay NULL and are left out.
     */
    public function testRefsAreStoredAndReadBack(): void
    {
        $delivery = $this->delivery();
        $delivery->setRefs(['project_id' => 12, 'job_template_id' => 34]);
        $this->assertTrue($delivery->save(), (string)json_encode($delivery->errors));

        $stored = NotificationDelivery::findOne($delivery->id);
        $this->assertInstanceOf(NotificationDelivery::class, $stored);
        $this->assertSame(['project_id' => 12, 'job_template_id' => 34], $stored->getRefs());
        $this->assertNull($stored->workflow_job_id);
        $this->assertNull($stored->job_id);
        $this->assertNull($stored->approval_request_id);
    }

    public function testEveryRefIsReadInTheOrderOfItsColumns(): void
    {
        $delivery = $this->delivery();
        $delivery->setRefs(['job_template_id' => 5, 'approval_request_id' => 3, 'job_id' => 2, 'project_id' => 4]);

        $this->assertSame(['job_id' => 2, 'approval_request_id' => 3, 'project_id' => 4, 'job_template_id' => 5], $delivery->getRefs());
    }

    /**
     * setRefs() sets every ref: one not given is cleared.
     */
    public function testSetRefsClearsTheRefsNotGiven(): void
    {
        $delivery = $this->delivery();
        $delivery->setRefs(['job_id' => 2, 'project_id' => 4]);

        $delivery->setRefs(['project_id' => 9]);

        $this->assertSame(['project_id' => 9], $delivery->getRefs());
        $this->assertNull($delivery->job_id);
        $delivery->setRefs([]);
        $this->assertSame([], $delivery->getRefs());
    }

    /**
     * A ref read from the database is a number, also when the driver hands
     * it over as a string.
     */
    public function testARefOfTheDatabaseIsAWholeNumber(): void
    {
        $delivery = $this->delivery();
        $delivery->setAttribute('project_id', '41');

        $this->assertSame(['project_id' => 41], $delivery->getRefs());
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function invalidRefs(): array
    {
        return [
            'project_id not a number' => ['project_id', 'abc'],
            'project_id a fraction' => ['project_id', 12.5],
            'job_template_id not a number' => ['job_template_id', 'x'],
            'job_template_id a fraction' => ['job_template_id', 2.9999999999999996],
        ];
    }

    /**
     * @dataProvider invalidRefs
     */
    public function testValidationRefusesARefThatIsNoWholeNumber(string $attribute, mixed $value): void
    {
        $delivery = $this->delivery();
        $delivery->setAttribute($attribute, $value);

        $this->assertFalse($delivery->validate([$attribute]));
        $this->assertArrayHasKey($attribute, $delivery->errors);
    }

    private function delivery(): NotificationDelivery
    {
        $template = $this->createNotificationTemplate((int)$this->createUser('refs')->id);
        $delivery = new NotificationDelivery();
        $delivery->notification_template_id = (int)$template->id;
        $delivery->event = NotificationTemplate::EVENT_PROJECT_SYNC_FAILED;
        $delivery->setVariableMap(['project.id' => '12']);
        $delivery->next_attempt_at = 1700000000;

        return $delivery;
    }
}
