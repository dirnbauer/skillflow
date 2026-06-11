<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Exception;

/**
 * Thrown when skill execution is blocked, e.g. because the installation
 * is not a local DDEV development environment or credentials are missing.
 */
final class ExecutionBlockedException extends \RuntimeException
{
}
