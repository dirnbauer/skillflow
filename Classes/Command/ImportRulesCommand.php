<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webconsulting\Skillflow\Service\RuleImportService;
use Webconsulting\Skillflow\Support\Typed;

#[AsCommand(
    name: 'skillflow:rules:import',
    description: 'Import TYPO3 agent rules from a rules/ directory as source_type=rules skills'
)]
final class ImportRulesCommand extends Command
{
    public function __construct(
        private readonly RuleImportService $ruleImportService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'path',
            InputArgument::OPTIONAL,
            'Absolute path to the rules/ directory (category subdirs with *.md rule files)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $path = Typed::string($input->getArgument('path'));
        if ($path === '') {
            $io->error('No rules directory given. Pass it as the first argument.');
            return Command::INVALID;
        }

        $result = $this->ruleImportService->importFromPath($path);
        $io->section('Rules: ' . $path);
        $io->writeln($result->summary());

        return $result->errors !== [] ? Command::FAILURE : Command::SUCCESS;
    }
}
