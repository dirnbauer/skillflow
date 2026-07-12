<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webconsulting\Skillflow\Service\Security\SkillspectorScanner;
use Webconsulting\Skillflow\Service\SkillImportService;

/**
 * Re-run the security + license + SkillSpector review checks on all stored
 * skills, without re-importing the sources. Useful after the scan rules
 * change, after installing the SkillSpector binary, or to check skills
 * imported before this feature existed. Advisory only — never disables.
 */
#[AsCommand(
    name: 'skillflow:check',
    description: 'Re-scan all skills for security patterns, license compatibility and SkillSpector findings (advisory).'
)]
final class CheckSkillsCommand extends Command
{
    public function __construct(
        private readonly SkillImportService $skillImportService,
        private readonly SkillspectorScanner $skillspectorScanner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->skillspectorScanner->isEnabled() && !$this->skillspectorScanner->isAvailable()) {
            $io->note(
                'NVIDIA SkillSpector is enabled but its binary was not found — skills are reviewed with the '
                . 'built-in checks only. Install it with: ' . SkillspectorScanner::INSTALL_HINT
            );
        }
        $result = $this->skillImportService->recheckAllSkills();
        $io->success(sprintf('Reviewed %d skill(s). See the Skills module for per-skill findings.', $result['checked']));
        if ($result['quarantined'] > 0) {
            $io->warning(sprintf(
                '%d skill(s) have danger-level security findings and were quarantined (hidden). '
                . 'They were NOT deleted — review them in the Skills module and unhide any that are safe.',
                $result['quarantined']
            ));
        }
        return Command::SUCCESS;
    }
}
