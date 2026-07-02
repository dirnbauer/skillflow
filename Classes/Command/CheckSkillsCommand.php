<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webconsulting\Skillflow\Service\SkillImportService;

/**
 * Re-run the security + license review checks on all stored skills, without
 * re-importing the sources. Useful after the scan rules change, or to check
 * skills imported before this feature existed. Advisory only — never disables.
 */
#[AsCommand(
    name: 'skillflow:check',
    description: 'Re-scan all skills for security patterns and license compatibility (advisory).'
)]
final class CheckSkillsCommand extends Command
{
    public function __construct(
        private readonly SkillImportService $skillImportService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $checked = $this->skillImportService->recheckAllSkills();
        $io->success(sprintf('Reviewed %d skill(s). See the Skills module for per-skill findings.', $checked));
        return Command::SUCCESS;
    }
}
