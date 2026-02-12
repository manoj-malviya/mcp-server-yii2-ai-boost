<?php

declare(strict_types=1);

namespace codechap\yii2boost\tests\Mcp\Tools;

use codechap\yii2boost\Mcp\Tools\PerformanceProfilerTool;

class PerformanceProfilerToolTest extends ToolTestCase
{
    private PerformanceProfilerTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new PerformanceProfilerTool([
            'basePath' => __DIR__ . '/../../fixtures/app',
        ]);
    }

    public function testGetName(): void
    {
        $this->assertSame('performance_profiler', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $this->assertNotEmpty($this->tool->getDescription());
    }

    public function testGetInputSchema(): void
    {
        $schema = $this->tool->getInputSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('sql', $schema['properties']);
        $this->assertArrayHasKey('params', $schema['properties']);
        $this->assertArrayHasKey('table', $schema['properties']);
        $this->assertArrayHasKey('db', $schema['properties']);
    }

    public function testDefaultMode(): void
    {
        $result = $this->tool->execute([]);

        $this->assertSame('overview', $result['mode']);
        $this->assertSame('sqlite', $result['driver']);
        $this->assertArrayHasKey('table_count', $result);
        $this->assertGreaterThan(0, $result['table_count']);
        $this->assertArrayHasKey('tables', $result);
    }

    public function testOverview(): void
    {
        $result = $this->tool->execute([]);

        $this->assertSame('overview', $result['mode']);
        $this->assertArrayHasKey('user', $result['tables']);
        $this->assertArrayHasKey('post', $result['tables']);
        $this->assertArrayHasKey('category', $result['tables']);

        // Each table should have row_count and index_count
        $userEntry = $result['tables']['user'];
        $this->assertArrayHasKey('row_count', $userEntry);
        $this->assertArrayHasKey('index_count', $userEntry);
        $this->assertArrayHasKey('fk_column_count', $userEntry);
    }

    public function testExplainSelect(): void
    {
        $result = $this->tool->execute([
            'sql' => 'SELECT * FROM user WHERE id = 1',
        ]);

        $this->assertSame('explain', $result['mode']);
        $this->assertSame('sqlite', $result['driver']);
        $this->assertArrayHasKey('plan', $result);
        $this->assertNotEmpty($result['plan']);
        $this->assertArrayHasKey('duration_ms', $result);
        $this->assertSame('SELECT * FROM user WHERE id = 1', $result['original_sql']);
    }

    public function testExplainWithParams(): void
    {
        $result = $this->tool->execute([
            'sql' => 'SELECT * FROM user WHERE id = :id',
            'params' => [':id' => 1],
        ]);

        $this->assertSame('explain', $result['mode']);
        $this->assertArrayHasKey('plan', $result);
        $this->assertNotEmpty($result['plan']);
    }

    public function testExplainInvalidSql(): void
    {
        $result = $this->tool->execute([
            'sql' => 'SELECT * FROM nonexistent_table_xyz',
        ]);

        $this->assertSame('explain', $result['mode']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testExplainEmptySql(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('cannot be empty');

        $this->tool->execute(['sql' => '  ']);
    }

    public function testTableAnalysis(): void
    {
        $result = $this->tool->execute([
            'table' => 'post',
        ]);

        $this->assertSame('table_analysis', $result['mode']);
        $this->assertSame('post', $result['table']);
        $this->assertSame('sqlite', $result['driver']);
        $this->assertArrayHasKey('columns', $result);
        $this->assertArrayHasKey('primary_key', $result);
        $this->assertArrayHasKey('indexes', $result);
        $this->assertArrayHasKey('foreign_key_columns', $result);
        $this->assertArrayHasKey('missing_indexes', $result);
        $this->assertArrayHasKey('stats', $result);
    }

    public function testTableAnalysisNotFound(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('not found');

        $this->tool->execute(['table' => 'nonexistent_table_xyz']);
    }

    public function testMissingIndexDetection(): void
    {
        // The post table has user_id and category_id columns (FK-like, *_id suffix)
        // but no indexes on them — they should be flagged as missing
        $result = $this->tool->execute([
            'table' => 'post',
        ]);

        $this->assertContains('user_id', $result['foreign_key_columns']);
        $this->assertContains('category_id', $result['foreign_key_columns']);
        $this->assertContains('user_id', $result['missing_indexes']);
        $this->assertContains('category_id', $result['missing_indexes']);
    }

    public function testIndexedColumnNotFlagged(): void
    {
        // Create an index on post.user_id, then verify it's NOT in missing_indexes
        $db = \Yii::$app->db;
        $db->createCommand()->createIndex('idx_post_user_id', 'post', 'user_id')->execute();

        try {
            $result = $this->tool->execute([
                'table' => 'post',
            ]);

            // user_id is still an FK-like column
            $this->assertContains('user_id', $result['foreign_key_columns']);
            // But it should NOT be flagged as missing since it now has an index
            $this->assertNotContains('user_id', $result['missing_indexes']);
            // category_id should still be missing
            $this->assertContains('category_id', $result['missing_indexes']);
        } finally {
            $db->createCommand()->dropIndex('idx_post_user_id', 'post')->execute();
        }
    }

    public function testTableStats(): void
    {
        $result = $this->tool->execute([
            'table' => 'user',
        ]);

        $stats = $result['stats'];
        $this->assertArrayHasKey('row_count', $stats);
        $this->assertArrayHasKey('index_count', $stats);
        $this->assertSame(0, $stats['row_count']);
    }

    public function testOverviewMissingIndexSummary(): void
    {
        $result = $this->tool->execute([]);

        // The post table has unindexed *_id columns, so there should be a summary
        $this->assertArrayHasKey('missing_index_summary', $result);
        $this->assertContains('post.user_id', $result['missing_index_summary']);
        $this->assertContains('post.category_id', $result['missing_index_summary']);
    }

    public function testDbConnectionParam(): void
    {
        // The default 'db' connection should work
        $result = $this->tool->execute(['db' => 'db']);
        $this->assertSame('overview', $result['mode']);
    }

    public function testDbConnectionNotFound(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('not found');

        $this->tool->execute(['db' => 'nonexistent_db']);
    }
}
