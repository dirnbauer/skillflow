<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Unit\Fixtures\Abilities;

use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Registry\AbstractAbility;

#[AsAbility(
    name: 'demo/cli-only',
    title: 'CLI only',
    description: 'Not exposed to MCP.',
    category: 'demo',
    expose: [ExecutionContext::SURFACE_CLI],
)]
final class CliOnlyAbility extends AbstractAbility
{
    public function execute(array $input, ExecutionContext $context): mixed
    {
        return null;
    }
}
