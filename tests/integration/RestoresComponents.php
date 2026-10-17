<?php

declare(strict_types=1);

namespace app\tests\integration;

/**
 * Application components that a test replaces, put back as they were, and
 * only those.
 *
 * PHPUnit runs tearDown() also after a setUp() that failed, and Yii's
 * set($id, null) removes a component. A test that captured a component
 * only after a write of its setUp() (a write fails, for one, on a deadlock
 * in a concurrent run) and restored it in tearDown() whatever happened
 * passed null for a component it had never captured: the component was
 * gone for every later test of the process ('Unknown component ID').
 *
 * So keep or swap a component right after parent::setUp(), before any
 * write, and call restoreComponents() first in tearDown(): it puts back
 * what was kept, and nothing else.
 */
trait RestoresComponents
{
    /**
     * @var array<string, mixed> the kept components by ID, as getComponents(true) had them; null: not registered
     */
    private array $keptComponents = [];

    /**
     * restoreComponents() puts component $id back as it is now, whatever the
     * test sets it to; the first call for an ID counts.
     */
    protected function keepComponent(string $id): void
    {
        if (!array_key_exists($id, $this->keptComponents)) {
            $this->keptComponents[$id] = \Yii::$app->getComponents(true)[$id] ?? null;
        }
    }

    /**
     * Component $id is $replacement until restoreComponents().
     */
    protected function swapComponent(string $id, mixed $replacement): void
    {
        $this->keepComponent($id);
        \Yii::$app->set($id, $replacement);
    }

    /**
     * Puts back every kept component as it was, and no other one: an ID that
     * was not registered is removed again.
     */
    protected function restoreComponents(): void
    {
        foreach ($this->keptComponents as $id => $definition) {
            \Yii::$app->set($id, $definition);
        }
        $this->keptComponents = [];
    }
}
