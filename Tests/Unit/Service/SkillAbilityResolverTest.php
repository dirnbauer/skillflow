<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Policy\PolicyProvider;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;
use Webconsulting\Skillflow\Configuration\ExtensionSettings;
use Webconsulting\Skillflow\Domain\AbilityFinding;
use Webconsulting\Skillflow\Service\SkillAbilityResolver;
use Webconsulting\Skillflow\Tests\Unit\Fixtures\Abilities\CliOnlyAbility;
use Webconsulting\Skillflow\Tests\Unit\Fixtures\Abilities\EchoAbility;

final class SkillAbilityResolverTest extends TestCase
{
    private string $policyFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->policyFile = sys_get_temp_dir() . '/skillflow-policy-' . bin2hex(random_bytes(4)) . '.yaml';
    }

    protected function tearDown(): void
    {
        if (is_file($this->policyFile)) {
            unlink($this->policyFile);
        }
        parent::tearDown();
    }

    public function testWithoutTheAbilitiesExtensionNothingIsAllowedAndEverythingIsMissing(): void
    {
        $resolver = new SkillAbilityResolver(new ExtensionSettings());

        self::assertFalse($resolver->isAvailable());
        self::assertSame([], $resolver->allowedTools(['demo/echo']));
        $findings = $resolver->check(['demo/echo', 'news/list']);
        self::assertSame([AbilityFinding::MISSING, AbilityFinding::MISSING], array_map(static fn(AbilityFinding $finding): string => $finding->code, $findings));
        self::assertStringContainsString('typo3-abilities is not active', $findings[0]->message);
    }

    public function testAllowedToolsUseTheMcpToolNameOfRegisteredMcpAbilities(): void
    {
        $resolver = $this->resolver(ExtensionSettings::fromArray([]));

        self::assertTrue($resolver->isAvailable());
        self::assertSame(
            ['mcp__typo3__ability_demo_echo'],
            $resolver->allowedTools(['demo/echo', 'news/list', 'demo/cli-only', 'demo/echo']),
            'Unregistered and CLI-only abilities are no MCP tools',
        );
    }

    public function testAllowedToolsFollowTheConfiguredServerName(): void
    {
        $resolver = $this->resolver(ExtensionSettings::fromArray(['abilitiesMcpServer' => 'lab']));

        self::assertSame(['mcp__lab__ability_demo_echo'], $resolver->allowedTools(['demo/echo']));
    }

    public function testCheckReportsMissingAndNotExposedAbilities(): void
    {
        $findings = $this->resolver(new ExtensionSettings())->check(['demo/echo', 'news/list', 'demo/cli-only']);

        self::assertSame(
            [
                ['ability' => 'news/list', 'code' => AbilityFinding::MISSING],
                ['ability' => 'demo/cli-only', 'code' => AbilityFinding::DENIED],
            ],
            array_map(static fn(AbilityFinding $finding): array => ['ability' => $finding->ability, 'code' => $finding->code], $findings),
        );
        self::assertStringContainsString('not registered', $findings[0]->message);
        self::assertStringContainsString('not exposed to the MCP surface', $findings[1]->message);
    }

    public function testCheckReportsAbilitiesTheSitePolicyDenies(): void
    {
        file_put_contents($this->policyFile, "policy:\n  name: test\n  deny:\n    - 'demo/*'\n");

        $findings = $this->resolver(new ExtensionSettings())->check(['demo/echo']);

        self::assertCount(1, $findings);
        self::assertSame(AbilityFinding::DENIED, $findings[0]->code);
        self::assertStringContainsString('denied by rule "demo/*"', $findings[0]->message);
    }

    public function testCheckReportsAbilitiesThatNeedHumanReview(): void
    {
        file_put_contents($this->policyFile, "policy:\n  review_required:\n    - 'demo/echo'\n");

        $findings = $this->resolver(new ExtensionSettings())->check(['demo/echo']);

        self::assertSame(AbilityFinding::DENIED, $findings[0]->code ?? null);
        self::assertStringContainsString('requires human review', $findings[0]->message);
    }

    private function resolver(ExtensionSettings $settings): SkillAbilityResolver
    {
        return new SkillAbilityResolver(
            $settings,
            new AbilitiesRegistry([new EchoAbility(), new CliOnlyAbility()]),
            new PolicyProvider($this->policyFile),
        );
    }
}
