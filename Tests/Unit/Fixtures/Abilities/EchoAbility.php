<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Unit\Fixtures\Abilities;

use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Registry\AbstractAbility;

#[AsAbility(
    name: 'demo/echo',
    title: 'Echo',
    description: 'Returns its input.',
    category: 'demo',
    scopes: ['demo:read'],
)]
final class EchoAbility extends AbstractAbility
{
    public function execute(array $input, ExecutionContext $context): mixed
    {
        return $input;
    }
}
