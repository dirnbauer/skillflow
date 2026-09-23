<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Domain;

/**
 * A problem with one ability a skill declares, found by
 * {@see \Webconsulting\Skillflow\Service\SkillAbilityResolver::check()}.
 */
final readonly class AbilityFinding
{
    /** Declared, but not registered in this installation (or typo3-abilities is not active). */
    public const string MISSING = 'ability_missing';

    /** Registered, but the abilities policy or the user's scopes deny it on the MCP surface. */
    public const string DENIED = 'ability_denied';

    public function __construct(
        public string $ability,
        public string $code,
        public string $message,
    ) {}

    /** @return array{ability: string, code: string, message: string} */
    public function toArray(): array
    {
        return ['ability' => $this->ability, 'code' => $this->code, 'message' => $this->message];
    }
}
