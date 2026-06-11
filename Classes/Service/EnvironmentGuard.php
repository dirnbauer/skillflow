<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use Webconsulting\Skillflow\Exception\ExecutionBlockedException;

/**
 * Security guard: skill execution sends content to an AI runner and may
 * execute a local CLI with tool access. By default this is only allowed on
 * a local DDEV installation running in Development context.
 */
final class EnvironmentGuard
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    public function isExecutionAllowed(): bool
    {
        return $this->getBlockReason() === null;
    }

    public function getBlockReason(): ?string
    {
        if (!$this->requiresLocalEnvironment()) {
            return null;
        }
        if (!Environment::getContext()->isDevelopment()) {
            return 'Skill execution is blocked: TYPO3 application context is "' . Environment::getContext()
                . '" but must be Development. Skills send content to an AI runner and must only run on local installations.';
        }
        if (getenv('IS_DDEV_PROJECT') !== 'true') {
            return 'Skill execution is blocked: this process does not run inside a DDEV project (IS_DDEV_PROJECT is not "true").'
                . ' Skills must only run on local DDEV installations.';
        }
        return null;
    }

    public function assertExecutionAllowed(): void
    {
        $reason = $this->getBlockReason();
        if ($reason !== null) {
            throw new ExecutionBlockedException($reason, 1760000003);
        }
    }

    public function requiresLocalEnvironment(): bool
    {
        try {
            $conf = $this->extensionConfiguration->get('skillflow');
        } catch (\Throwable) {
            return true;
        }
        if (!is_array($conf)) {
            return true;
        }
        return (bool)($conf['requireLocalEnvironment'] ?? true);
    }
}
