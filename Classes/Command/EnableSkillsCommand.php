<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Command;

use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\SkillSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webconsulting\Skillflow\Domain\SkillEnableOutcome;
use Webconsulting\Skillflow\Service\SkillAdministrationService;
use Webconsulting\Skillflow\Support\Typed;

/**
 * CLI counterpart of the enable toggle in nr_llm's AI → Authoring → Skills
 * module: the same Extbase update and audit row, the same refusal to enable
 * orphaned skills.
 */
#[AsCommand(
    name: 'skillflow:skills:enable',
    description: 'Enable reviewed nr_llm skills so they can be assigned and run'
)]
final class EnableSkillsCommand extends Command
{
    public function __construct(
        private readonly SkillAdministrationService $skills,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('skills', InputArgument::IS_ARRAY, 'Skill uids, identifiers ("<source uid>:<path>", or the path alone with --source) or unique names')
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'Restrict to one skill source (uid or title)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Enable every skill that is not orphaned (of --source when given)')
            ->setHelp(<<<'HELP'
Enables skills exactly like nr_llm's backend module does, including the audit
trail entry:

  <info>%command.full_name% 12 3:skills/review/SKILL.md "SEO check"</info>
  <info>%command.full_name% --source=3 skills/review/SKILL.md</info>
  <info>%command.full_name% --source="Company skills" --all</info>

Orphaned skills are never enabled. With an explicit list an orphaned or unknown
skill yields exit code 1; with --all orphaned skills are skipped.
HELP);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $references = $this->references($input->getArgument('skills'));
        $all = (bool)$input->getOption('all');

        if ($all === ($references !== [])) {
            $io->error('Pass exactly one of: a list of skills, or --all.');
            return Command::INVALID;
        }

        try {
            $source = $this->resolveSource(Typed::string($input->getOption('source')));
            [$targets, $failed] = $all ? [$this->skills->findSkills($source), 0] : $this->resolveTargets($references, $source, $io);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        if ($targets === []) {
            $io->warning('No skills matched.');
            return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        $rows = [];
        $counts = array_fill_keys(array_column(SkillEnableOutcome::cases(), 'value'), 0);
        foreach ($targets as $skill) {
            $outcome = $this->skills->enable($skill);
            $counts[$outcome->value]++;
            if ($outcome === SkillEnableOutcome::Orphaned && !$all) {
                $failed++;
            }
            $rows[] = [(int)$skill->getUid(), $skill->getIdentifier(), $skill->getName(), $outcome->value];
        }
        $io->table(['UID', 'Identifier', 'Name', 'Outcome'], $rows);
        $io->text(sprintf(
            '%d enabled, %d already enabled, %d orphaned (skipped), %d not found.',
            $counts[SkillEnableOutcome::Enabled->value],
            $counts[SkillEnableOutcome::AlreadyEnabled->value],
            $counts[SkillEnableOutcome::Orphaned->value],
            $failed - ($all ? 0 : $counts[SkillEnableOutcome::Orphaned->value]),
        ));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function references(mixed $argument): array
    {
        $references = [];
        foreach (is_array($argument) ? $argument : [] as $value) {
            $value = trim(Typed::string($value));
            if ($value !== '') {
                $references[] = $value;
            }
        }

        return $references;
    }

    /**
     * @throws \RuntimeException for an unknown or ambiguous source
     */
    private function resolveSource(string $reference): ?SkillSource
    {
        if ($reference === '') {
            return null;
        }

        return $this->skills->findSource($reference)
            ?? throw new \RuntimeException(sprintf('Skill source not found: %s', $reference), 1757700005);
    }

    /**
     * @param list<string> $references
     * @return array{0: list<Skill>, 1: int} matched skills (deduplicated) and the number of unresolved references
     */
    private function resolveTargets(array $references, ?SkillSource $source, SymfonyStyle $io): array
    {
        $targets = [];
        $failed = 0;
        foreach ($references as $reference) {
            $skill = $this->skills->findSkill($reference, $source);
            if ($skill === null) {
                $io->error(sprintf('Skill not found: %s', $reference));
                $failed++;
                continue;
            }
            $targets[(int)$skill->getUid()] = $skill;
        }

        return [array_values($targets), $failed];
    }
}
