<?php

namespace PHPNomad\Cli\Tests\Indexer\Adapters;

use PHPNomad\Cli\Indexer\Adapters\GraphNodeAdapter;
use PHPNomad\Cli\Indexer\Models\GraphEdge;
use PHPNomad\Cli\Indexer\Models\GraphNode;
use PHPUnit\Framework\TestCase;

class GraphNodeAdapterTest extends TestCase
{
    private GraphNodeAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new GraphNodeAdapter();
    }

    public function testToArrayWithTargetPeerField(): void
    {
        $node = new GraphNode('App\\Service', 'lib/Service.php', [
            new GraphEdge('injects', 'App\\Datastore'),
            new GraphEdge('implements', 'App\\ServiceInterface'),
        ]);

        $array = $this->adapter->toArray($node, 'target');

        $this->assertSame('App\\Service', $array['fqcn']);
        $this->assertSame('lib/Service.php', $array['file']);
        $this->assertCount(2, $array['edges']);
        $this->assertSame('injects', $array['edges'][0]['type']);
        $this->assertSame('App\\Datastore', $array['edges'][0]['target']);
        $this->assertArrayNotHasKey('source', $array['edges'][0]);
    }

    public function testToArrayWithSourcePeerField(): void
    {
        $node = new GraphNode('App\\Datastore', 'lib/Datastore.php', [
            new GraphEdge('injected-by', 'App\\Service'),
        ]);

        $array = $this->adapter->toArray($node, 'source');

        $this->assertSame('App\\Service', $array['edges'][0]['source']);
        $this->assertArrayNotHasKey('target', $array['edges'][0]);
    }

    public function testViaFieldIncludedWhenPresent(): void
    {
        $node = new GraphNode('App\\Interface', null, [
            new GraphEdge('resolves-to', 'App\\Concrete', 'App\\Init'),
        ]);

        $array = $this->adapter->toArray($node, 'target');

        $this->assertSame('App\\Init', $array['edges'][0]['via']);
    }

    public function testViaFieldOmittedWhenNull(): void
    {
        $node = new GraphNode('App\\Service', 'lib/Service.php', [
            new GraphEdge('injects', 'App\\Datastore'),
        ]);

        $array = $this->adapter->toArray($node, 'target');

        $this->assertArrayNotHasKey('via', $array['edges'][0]);
    }

    public function testNullFilePreserved(): void
    {
        $node = new GraphNode('Vendor\\Interface', null, []);

        $array = $this->adapter->toArray($node);

        $this->assertNull($array['file']);
    }

    public function testRoundtripWithTarget(): void
    {
        $original = new GraphNode('App\\Service', 'lib/Service.php', [
            new GraphEdge('injects', 'App\\Datastore'),
            new GraphEdge('resolves-to', 'App\\Concrete', 'App\\Init'),
        ]);

        $array = $this->adapter->toArray($original, 'target');
        $restored = $this->adapter->fromArray($array, 'target');

        $this->assertSame($original->fqcn, $restored->fqcn);
        $this->assertSame($original->file, $restored->file);
        $this->assertCount(count($original->edges), $restored->edges);

        $this->assertSame($original->edges[0]->type, $restored->edges[0]->type);
        $this->assertSame($original->edges[0]->fqcn, $restored->edges[0]->fqcn);
        $this->assertNull($restored->edges[0]->via);

        $this->assertSame($original->edges[1]->type, $restored->edges[1]->type);
        $this->assertSame($original->edges[1]->fqcn, $restored->edges[1]->fqcn);
        $this->assertSame('App\\Init', $restored->edges[1]->via);
    }

    public function testRoundtripWithSource(): void
    {
        $original = new GraphNode('App\\Datastore', 'lib/Datastore.php', [
            new GraphEdge('injected-by', 'App\\Service'),
        ]);

        $array = $this->adapter->toArray($original, 'source');
        $restored = $this->adapter->fromArray($array, 'source');

        $this->assertSame($original->fqcn, $restored->fqcn);
        $this->assertSame($original->edges[0]->type, $restored->edges[0]->type);
        $this->assertSame($original->edges[0]->fqcn, $restored->edges[0]->fqcn);
    }
}
