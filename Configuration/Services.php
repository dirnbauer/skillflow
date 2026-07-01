<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Webconsulting\Skillflow\Runner\ContextAwareSkillRunnerInterface;

return static function (ContainerConfigurator $configurator, ContainerBuilder $containerBuilder): void {
    // Any autoconfigured service — in ANY extension — implementing the
    // interface is tagged and thereby registered as an execution engine.
    // No skillflow configuration and no hard dependency in either direction.
    $containerBuilder->registerForAutoconfiguration(ContextAwareSkillRunnerInterface::class)
        ->addTag('skillflow.context_runner');
};
