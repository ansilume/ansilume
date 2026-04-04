<?php

declare(strict_types=1);

namespace app\tests\unit\services\notification;

use app\services\notification\TemplateRenderer;
use PHPUnit\Framework\TestCase;

class TemplateRendererTest extends TestCase
{
    private TemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new TemplateRenderer();
    }

    public function testRendersSimpleVariable(): void
    {
        $result = $this->renderer->render('Hello {{ name }}!', ['name' => 'World']);
        $this->assertSame('Hello World!', $result);
    }

    public function testRendersDottedVariable(): void
    {
        $result = $this->renderer->render('Job #{{ job.id }}', ['job.id' => '42']);
        $this->assertSame('Job #42', $result);
    }

    public function testMissingVariableReplacedWithEmpty(): void
    {
        $result = $this->renderer->render('Status: {{ job.status }}', []);
        $this->assertSame('Status: ', $result);
    }

    public function testMultipleVariables(): void
    {
        $tpl = '{{ job.id }} — {{ job.status }} — {{ template.name }}';
        $vars = ['job.id' => '1', 'job.status' => 'failed', 'template.name' => 'Deploy'];
        $this->assertSame('1 — failed — Deploy', $this->renderer->render($tpl, $vars));
    }

    public function testWhitespaceInsideBraces(): void
    {
        $result = $this->renderer->render('{{  job.id  }}', ['job.id' => '99']);
        $this->assertSame('99', $result);
    }

    public function testSpecialCharactersInValues(): void
    {
        $result = $this->renderer->render('{{ msg }}', ['msg' => '<script>alert(1)</script>']);
        $this->assertSame('<script>alert(1)</script>', $result);
    }

    public function testEmptyTemplate(): void
    {
        $this->assertSame('', $this->renderer->render('', ['foo' => 'bar']));
    }

    public function testNoVariablesInTemplate(): void
    {
        $this->assertSame('plain text', $this->renderer->render('plain text', []));
    }

    public function testUnderscoreInVariableName(): void
    {
        $result = $this->renderer->render('{{ launched_by }}', ['launched_by' => 'admin']);
        $this->assertSame('admin', $result);
    }
}
