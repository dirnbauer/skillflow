<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Permission\BackendUserScopeResolver;
use Webconsulting\Abilities\Policy\PolicyProvider;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;
use Webconsulting\Skillflow\Configuration\ExtensionSettings;
use Webconsulting\Skillflow\Domain\AbilityFinding;

/**
 * Resolves the abilities a skill declares against the typo3-abilities
 * registry: the MCP tools the Claude CLI runner allows, and the findings
 * skillflow:skills:check reports.
 *
 * typo3-abilities is optional. Its services arrive as nullable constructor
 * arguments: when the extension is not active (installed or not), autowiring
 * passes null and every declared ability is reported as missing.
 */
final readonly class SkillAbilityResolver
{
    public function __construct(
        private ExtensionSettings $settings,
        private ?AbilitiesRegistry $registry = null,
        private ?PolicyProvider $policyProvider = null,
        private ?BackendUserScopeResolver $scopeResolver = null,
    ) {}

    public function isAvailable(): bool
    {
        return $this->registry !== null;
    }

    /**
     * Claude Code permission rules for the registered abilities, e.g.
     * "mcp__typo3__ability_news_list" (AbilityDefinition::mcpToolName() on
     * the configured abilities MCP server). Unregistered names and abilities
     * the MCP server does not expose are skipped; the policy is enforced by
     * the MCP server when the tool is called.
     *
     * @param list<string> $abilities
     * @return list<string>
     */
    public function allowedTools(array $abilities): array
    {
        if ($this->registry === null) {
            return [];
        }

        $prefix = 'mcp__' . $this->settings->abilitiesMcpServer . '__';
        $tools = [];
        foreach ($abilities as $name) {
            if (!$this->registry->has($name)) {
                continue;
            }
            $definition = $this->registry->getDefinition($name);
            if ($definition->isExposedTo(ExecutionContext::SURFACE_MCP)) {
                $tools[] = $prefix . $definition->mcpToolName();
            }
        }

        return array_values(array_unique($tools));
    }

    /**
     * Check each declared ability for the MCP surface a skill run uses:
     * missing when it is not registered, denied when the site policy, the
     * ability's exposure or the scopes of $user rule it out. Without a user
     * the check trusts the caller the way the MCP server trusts its session
     * (policy only).
     *
     * @param list<string> $abilities
     * @return list<AbilityFinding>
     */
    public function check(array $abilities, ?BackendUserAuthentication $user = null): array
    {
        $userRecord = is_array($user?->user) ? $user->user : [];

        return $this->checkWith(
            $abilities,
            $user !== null && $userRecord !== [] ? $this->scopeResolver?->resolveForUser($user) : null,
            $userRecord,
        );
    }

    /**
     * check() for another backend user, identified by user name.
     *
     * @param list<string> $abilities
     * @return list<AbilityFinding>
     * @throws \InvalidArgumentException when the user does not exist
     */
    public function checkAsUser(array $abilities, string $username): array
    {
        if ($this->scopeResolver === null) {
            return $this->checkWith($abilities, null, []);
        }
        $userRecord = $this->scopeResolver->findUserByUsername($username)
            ?? throw new \InvalidArgumentException(sprintf('Backend user "%s" not found.', $username), 1758620001);

        return $this->checkWith($abilities, $this->scopeResolver->resolveForUserRecord($userRecord), $userRecord);
    }

    /**
     * @param list<string> $abilities
     * @param list<string>|null $grantedScopes null: scope checks are skipped
     * @param array<array-key, mixed> $userRecord be_users row, [] for none
     * @return list<AbilityFinding>
     */
    private function checkWith(array $abilities, ?array $grantedScopes, array $userRecord): array
    {
        if ($this->registry === null || $this->policyProvider === null) {
            return array_map(
                static fn(string $name): AbilityFinding => new AbilityFinding(
                    $name,
                    AbilityFinding::MISSING,
                    sprintf('Ability "%s" cannot be resolved: the extension typo3-abilities is not active.', $name),
                ),
                $abilities,
            );
        }

        $userUid = is_numeric($userRecord['uid'] ?? null) ? (int)$userRecord['uid'] : null;
        $username = is_string($userRecord['username'] ?? null) ? $userRecord['username'] : '';
        $context = ExecutionContext::mcp($userUid)->withGrantedScopes($grantedScopes);
        $policy = $this->policyProvider->get();

        $findings = [];
        foreach ($abilities as $name) {
            if (!$this->registry->has($name)) {
                $findings[] = new AbilityFinding(
                    $name,
                    AbilityFinding::MISSING,
                    sprintf('Ability "%s" is not registered in this installation.', $name),
                );
                continue;
            }

            $definition = $this->registry->getDefinition($name);
            if (!$definition->isExposedTo(ExecutionContext::SURFACE_MCP)) {
                $findings[] = new AbilityFinding(
                    $name,
                    AbilityFinding::DENIED,
                    sprintf('Ability "%s" is not exposed to the MCP surface a skill run uses.', $name),
                );
                continue;
            }

            $decision = $policy->decide($definition, $context);
            if (!$decision->allowed) {
                $findings[] = new AbilityFinding($name, AbilityFinding::DENIED, $decision->reason ?? 'Denied by the abilities policy.');
                continue;
            }

            $missingScopes = $context->missingScopes($definition->scopes);
            if ($missingScopes !== []) {
                $findings[] = new AbilityFinding(
                    $name,
                    AbilityFinding::DENIED,
                    sprintf(
                        'Ability "%s" needs the scope(s) %s, which the backend user "%s" does not have.',
                        $name,
                        implode(', ', $missingScopes),
                        $username,
                    ),
                );
            }
        }

        return $findings;
    }
}
