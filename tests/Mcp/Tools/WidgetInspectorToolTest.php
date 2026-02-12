<?php

declare(strict_types=1);

namespace codechap\yii2boost\tests\Mcp\Tools;

use codechap\yii2boost\Mcp\Tools\WidgetInspectorTool;

class WidgetInspectorToolTest extends ToolTestCase
{
    private WidgetInspectorTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new WidgetInspectorTool([
            'basePath' => __DIR__ . '/../../fixtures/app',
        ]);
    }

    public function testGetName(): void
    {
        $this->assertSame('widget_inspector', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $this->assertNotEmpty($this->tool->getDescription());
    }

    public function testGetInputSchema(): void
    {
        $schema = $this->tool->getInputSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('widget', $schema['properties']);
        $this->assertArrayHasKey('include', $schema['properties']);
    }

    public function testListWidgets(): void
    {
        $result = $this->tool->execute([]);

        $this->assertArrayHasKey('total', $result);
        $this->assertGreaterThan(0, $result['total']);

        // Should have framework widgets
        $this->assertArrayHasKey('framework', $result);
        $this->assertNotEmpty($result['framework']);

        // Each widget entry should have class and description
        $first = $result['framework'][0];
        $this->assertArrayHasKey('class', $first);
    }

    public function testListWidgetsIncludesAppWidgets(): void
    {
        $result = $this->tool->execute([]);

        $this->assertArrayHasKey('application', $result);
        $classes = array_column($result['application'], 'class');
        $this->assertContains('app\\widgets\\TestWidget', $classes);
    }

    public function testInspectFrameworkWidget(): void
    {
        $result = $this->tool->execute([
            'widget' => 'yii\\widgets\\Menu',
            'include' => ['properties'],
        ]);

        $this->assertSame('yii\\widgets\\Menu', $result['class']);
        $this->assertArrayHasKey('properties', $result);
        $this->assertNotEmpty($result['properties']);
        // Menu should have 'items' property
        $this->assertArrayHasKey('items', $result['properties']);
    }

    public function testInspectAppWidget(): void
    {
        $result = $this->tool->execute([
            'widget' => 'app\\widgets\\TestWidget',
            'include' => ['properties'],
        ]);

        $this->assertSame('app\\widgets\\TestWidget', $result['class']);
        $this->assertArrayHasKey('properties', $result);
        $this->assertArrayHasKey('title', $result['properties']);
    }

    public function testInspectWidgetProperties(): void
    {
        $result = $this->tool->execute([
            'widget' => 'app\\widgets\\TestWidget',
            'include' => ['properties'],
        ]);

        $props = $result['properties'];

        // Check title property
        $this->assertArrayHasKey('title', $props);
        $this->assertSame('string', $props['title']['type']);
        $this->assertSame('Default Title', $props['title']['default']);
        $this->assertArrayHasKey('description', $props['title']);
        $this->assertStringContainsString('Title displayed', $props['title']['description']);

        // Check options property
        $this->assertArrayHasKey('options', $props);
        $this->assertSame('array', $props['options']['type']);
        $this->assertSame([], $props['options']['default']);
    }

    public function testInspectWidgetMethods(): void
    {
        $result = $this->tool->execute([
            'widget' => 'app\\widgets\\TestWidget',
            'include' => ['methods'],
        ]);

        $this->assertArrayHasKey('methods', $result);
        $this->assertArrayHasKey('run', $result['methods']);

        $run = $result['methods']['run'];
        $this->assertSame('app\\widgets\\TestWidget', $run['declared_in']);
        $this->assertSame('string', $run['return_type']);
        $this->assertArrayHasKey('description', $run);
    }

    public function testInspectWidgetEvents(): void
    {
        $result = $this->tool->execute([
            'widget' => 'app\\widgets\\TestWidget',
            'include' => ['events'],
        ]);

        $this->assertArrayHasKey('events', $result);
        $this->assertNotEmpty($result['events']);

        $constants = array_column($result['events'], 'constant');
        $this->assertContains('EVENT_CUSTOM_ACTION', $constants);

        // Should also include inherited widget events
        $this->assertContains('EVENT_BEFORE_RUN', $constants);
        $this->assertContains('EVENT_AFTER_RUN', $constants);
    }

    public function testInspectWidgetHierarchy(): void
    {
        $result = $this->tool->execute([
            'widget' => 'app\\widgets\\TestWidget',
            'include' => ['hierarchy'],
        ]);

        $this->assertArrayHasKey('hierarchy', $result);
        $hierarchy = $result['hierarchy'];

        $this->assertSame('app\\widgets\\TestWidget', $hierarchy[0]);
        $this->assertContains('yii\\base\\Widget', $hierarchy);
    }

    public function testIncludeAll(): void
    {
        $result = $this->tool->execute([
            'widget' => 'app\\widgets\\TestWidget',
            'include' => ['all'],
        ]);

        $this->assertArrayHasKey('properties', $result);
        $this->assertArrayHasKey('methods', $result);
        $this->assertArrayHasKey('events', $result);
        $this->assertArrayHasKey('hierarchy', $result);
    }

    public function testShortNameResolution(): void
    {
        $result = $this->tool->execute([
            'widget' => 'ActiveForm',
            'include' => ['hierarchy'],
        ]);

        $this->assertSame('yii\\widgets\\ActiveForm', $result['class']);
    }

    public function testShortNameResolutionAppWidget(): void
    {
        $result = $this->tool->execute([
            'widget' => 'TestWidget',
            'include' => ['hierarchy'],
        ]);

        $this->assertSame('app\\widgets\\TestWidget', $result['class']);
    }

    public function testWidgetNotFound(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('not found');

        $this->tool->execute([
            'widget' => 'NonexistentWidget',
        ]);
    }

    public function testDefaultInclude(): void
    {
        $result = $this->tool->execute([
            'widget' => 'app\\widgets\\TestWidget',
        ]);

        // Default include is [properties, hierarchy]
        $this->assertArrayHasKey('properties', $result);
        $this->assertArrayHasKey('hierarchy', $result);
        $this->assertArrayNotHasKey('methods', $result);
        $this->assertArrayNotHasKey('events', $result);
    }

    public function testWidgetHasDescription(): void
    {
        $result = $this->tool->execute([
            'widget' => 'app\\widgets\\TestWidget',
            'include' => ['properties'],
        ]);

        $this->assertArrayHasKey('description', $result);
        $this->assertStringContainsString('Test widget', $result['description']);
    }
}
