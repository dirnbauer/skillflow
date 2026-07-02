<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service\Security;

use Webconsulting\Skillflow\Domain\Security\SkillCheckReport;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Runs the mandatory review checks on one imported skill: a security scan of
 * the body + code examples, and a license-compatibility assessment against
 * TYPO3's GPL-2.0-or-later. Produces a SkillCheckReport the importer persists
 * and the Skills module renders. Never disables a skill — advisory only.
 */
final class SkillCheckService
{
    public function __construct(
        private readonly SkillSecurityScanner $securityScanner,
        private readonly LicenseChecker $licenseChecker,
    ) {
    }

    /**
     * @param array<string, string> $files relative path => content (SKILL.md excluded)
     * @param array<string, mixed> $metadata parsed frontmatter metadata (may hold `license`)
     */
    public function check(string $body, array $files, array $metadata): SkillCheckReport
    {
        $hasCode = CodeDetection::skillHasCode($body, $files);
        $findings = $this->securityScanner->scan($body, $files);
        $license = $this->licenseChecker->assess($this->extractLicense($metadata), $hasCode);

        return new SkillCheckReport($findings, $license, $hasCode, time());
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function extractLicense(array $metadata): ?string
    {
        foreach (['license', 'License', 'licence', 'spdx', 'SPDX-License-Identifier'] as $key) {
            if (isset($metadata[$key])) {
                $value = $metadata[$key];
                if (is_array($value)) {
                    $value = reset($value);
                }
                $string = Typed::string($value);
                if ($string !== '') {
                    return $string;
                }
            }
        }
        return null;
    }
}
