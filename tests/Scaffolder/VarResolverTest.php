<?php

namespace PHPNomad\Cli\Tests\Scaffolder;

use PHPNomad\Cli\Scaffolder\VarResolver;
use PHPUnit\Framework\TestCase;

class VarResolverTest extends TestCase
{
    private VarResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new VarResolver();
    }

    public function testUserVarsIncluded(): void
    {
        $result = $this->resolver->resolve(
            ['name' => 'Payout'],
            [],
            'App\\Services'
        );

        $this->assertSame('Payout', $result['name']);
    }

    public function testNamespaceInjected(): void
    {
        $result = $this->resolver->resolve([], [], 'App\\Services');

        $this->assertSame('App\\Services', $result['namespace']);
    }

    public function testLowerTransform(): void
    {
        $result = $this->resolver->resolve(
            ['name' => 'Payout'],
            [],
            'App'
        );

        $this->assertSame('payout', $result['nameLower']);
    }

    public function testSnakeTransform(): void
    {
        $result = $this->resolver->resolve(
            ['name' => 'PayoutService'],
            [],
            'App'
        );

        $this->assertSame('payout_service', $result['nameSnake']);
    }

    public function testFileVarsOverrideUserVars(): void
    {
        $result = $this->resolver->resolve(
            ['name' => 'Original'],
            ['name' => 'Override'],
            'App'
        );

        $this->assertSame('Override', $result['name']);
    }

    public function testRecursiveVarResolution(): void
    {
        $result = $this->resolver->resolve(
            ['name' => 'Payout'],
            ['className' => '{{name}}Created'],
            'App\\Events'
        );

        $this->assertSame('PayoutCreated', $result['className']);
    }

    public function testNestedVarResolution(): void
    {
        $result = $this->resolver->resolve(
            ['name' => 'Payout'],
            ['event' => '{{namespace}}\\{{name}}Created'],
            'App\\Events'
        );

        $this->assertSame('App\\Events\\PayoutCreated', $result['event']);
    }

    public function testAllUserVarsGetTransforms(): void
    {
        $result = $this->resolver->resolve(
            ['entity' => 'UserProfile'],
            [],
            'App'
        );

        $this->assertSame('UserProfile', $result['entity']);
        $this->assertSame('userProfile', $result['entityLower']);
        $this->assertSame('user_profile', $result['entitySnake']);
    }
}
