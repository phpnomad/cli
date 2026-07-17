<?php

namespace PHPNomad\Cli\Tests\Indexer;

use PHPNomad\Cli\Indexer\ProjectIndexer;
use PHPUnit\Framework\TestCase;

class ProjectIndexerStalenessTest extends TestCase
{
    private string $projectPath;

    protected function setUp(): void
    {
        $this->projectPath = sys_get_temp_dir() . '/phpnomad-staleness-test-' . uniqid();
        mkdir($this->projectPath . '/.phpnomad', 0755, true);
        mkdir($this->projectPath . '/lib', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectPath);
    }

    public function testFreshIndexProducesNoWarning(): void
    {
        $sourceFile = $this->projectPath . '/lib/Example.php';
        file_put_contents($sourceFile, "<?php class Example {}\n");
        touch($sourceFile, time() - 3600);

        $indexer = $this->makeIndexer();

        $this->assertNull($indexer->getStalenessWarning($this->projectPath, date('c')));
    }

    public function testTouchedSourceFileTriggersWarning(): void
    {
        $sourceFile = $this->projectPath . '/lib/Example.php';
        file_put_contents($sourceFile, "<?php class Example {}\n");
        touch($sourceFile, time() + 60);

        $indexedAt = date('c', time() - 3600);
        $warning = $this->makeIndexer()->getStalenessWarning($this->projectPath, $indexedAt);

        $this->assertNotNull($warning);
        $this->assertStringContainsString('index is stale', $warning);
        $this->assertStringContainsString($indexedAt, $warning);
        $this->assertStringContainsString('phpnomad index', $warning);
        $this->assertStringContainsString('--fresh', $warning);
    }

    public function testVendorChangesDoNotTriggerWarning(): void
    {
        mkdir($this->projectPath . '/vendor', 0755, true);
        $vendorFile = $this->projectPath . '/vendor/Dep.php';
        file_put_contents($vendorFile, "<?php class Dep {}\n");
        touch($vendorFile, time() + 60);

        $this->assertNull(
            $this->makeIndexer()->getStalenessWarning($this->projectPath, date('c', time() - 3600))
        );
    }

    public function testUnparseableIndexedAtProducesNoWarning(): void
    {
        file_put_contents($this->projectPath . '/lib/Example.php', "<?php class Example {}\n");

        $indexer = $this->makeIndexer();

        $this->assertNull($indexer->getStalenessWarning($this->projectPath, ''));
        $this->assertNull($indexer->getStalenessWarning($this->projectPath, 'not-a-date'));
    }

    public function testLoadEmitsWarningWhenStaleAndStaysSilentWhenFresh(): void
    {
        $sourceFile = $this->projectPath . '/lib/Example.php';
        file_put_contents($sourceFile, "<?php class Example {}\n");
        touch($sourceFile, time() - 3600);

        $indexer = $this->makeIndexer();

        // Fresh: indexed now, source an hour old.
        $this->writeMeta(date('c'));
        $this->assertNotNull($indexer->load($this->projectPath));
        $this->assertSame([], $indexer->emitted);

        // Stale: indexed two hours ago, source an hour old.
        $this->writeMeta(date('c', time() - 7200));
        $this->assertNotNull($indexer->load($this->projectPath));
        $this->assertCount(1, $indexer->emitted);
        $this->assertStringContainsString('index is stale', $indexer->emitted[0]);
    }

    /**
     * @return ProjectIndexer&object{emitted: string[]}
     */
    private function makeIndexer(): ProjectIndexer
    {
        return new class extends ProjectIndexer {
            /** @var string[] */
            public array $emitted = [];

            public function __construct()
            {
            }

            protected function emitStalenessWarning(string $message): void
            {
                $this->emitted[] = $message;
            }
        };
    }

    private function writeMeta(string $indexedAt): void
    {
        file_put_contents(
            $this->projectPath . '/.phpnomad/meta.json',
            json_encode(['projectPath' => $this->projectPath, 'indexedAt' => $indexedAt])
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
