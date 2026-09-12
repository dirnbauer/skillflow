<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use Netresearch\NrLlm\Domain\Enum\SkillAuditEvent;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\SkillSource;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Domain\Repository\SkillSourceRepository;
use Netresearch\NrLlm\Domain\ValueObject\SyncResult;
use Netresearch\NrLlm\Service\Skill\SkillAuditService;
use Netresearch\NrLlm\Service\Skill\SkillSyncService;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use Webconsulting\Skillflow\Domain\SkillEnableOutcome;

/**
 * Scripts the two administrative operations of nr_llm's
 * "AI → Authoring → Skills" module: synchronizing a skill source and
 * enabling a reviewed skill. nr_llm stays the owner of sources and skills.
 *
 * Enabling mirrors nr_llm's SkillSourceController::toggleSkillAction():
 * an Extbase update, persistAll() and one append-only audit row. Orphaned
 * skills are never enabled.
 *
 * nr_llm coupling (constraint ^0.34): SkillSyncService, SkillAuditService,
 * SyncResult and the Skill/SkillSource models carry no @internal marker.
 * SkillRepository and SkillSourceRepository are @internal (ADR-127); this is
 * the only class using them, limited to the base Extbase repository methods
 * (findAll, findByUid, update) and SkillRepository::findBySource().
 */
final class SkillAdministrationService
{
    public function __construct(
        private readonly SkillSourceRepository $sourceRepository,
        private readonly SkillRepository $skillRepository,
        private readonly SkillSyncService $syncService,
        private readonly SkillAuditService $auditService,
        private readonly PersistenceManagerInterface $persistenceManager,
    ) {}

    /**
     * @return list<SkillSource> ordered by title
     */
    public function findSources(): array
    {
        $sources = [];
        foreach ($this->sourceRepository->findAll() as $source) {
            if ($source instanceof SkillSource) {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    /**
     * Resolves a source by uid or by its title (case-insensitive).
     *
     * @throws \RuntimeException when several sources share the title
     */
    public function findSource(string $uidOrTitle): ?SkillSource
    {
        $uidOrTitle = trim($uidOrTitle);
        if ($uidOrTitle === '') {
            return null;
        }
        if (ctype_digit($uidOrTitle)) {
            $source = $this->sourceRepository->findByUid((int)$uidOrTitle);

            return $source instanceof SkillSource ? $source : null;
        }

        $matches = array_values(array_filter(
            $this->findSources(),
            static fn(SkillSource $source): bool => strcasecmp($source->getTitle(), $uidOrTitle) === 0,
        ));
        if (count($matches) > 1) {
            throw new \RuntimeException(sprintf(
                'Skill source title "%s" is ambiguous (uids %s); use the uid.',
                $uidOrTitle,
                implode(', ', array_map(static fn(SkillSource $source): int => (int)$source->getUid(), $matches)),
            ), 1757700001);
        }

        return $matches[0] ?? null;
    }

    /**
     * Runs nr_llm's sync for one source: new skills are created disabled for
     * review, changed enabled skills are disabled again, removed skills are
     * orphaned. The source row records the resulting status.
     */
    public function sync(SkillSource $source): SyncResult
    {
        return $this->syncService->sync($source);
    }

    /**
     * @return list<Skill> ordered by name
     */
    public function findSkills(?SkillSource $source = null, bool $enabledOnly = false): array
    {
        $candidates = $source === null
            ? $this->skillRepository->findAll()
            : $this->skillRepository->findBySource((int)$source->getUid());
        $skills = [];
        foreach ($candidates as $skill) {
            if ($skill instanceof Skill && (!$enabledOnly || $skill->isEnabled())) {
                $skills[] = $skill;
            }
        }

        return $skills;
    }

    /**
     * Resolves a skill by uid, by its full nr_llm identifier
     * ("<source uid>:<path>"), by the path alone when a source is given, or
     * by its unique name (case-insensitive).
     *
     * @throws \RuntimeException when several skills share the name
     */
    public function findSkill(string $reference, ?SkillSource $source = null): ?Skill
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }
        if (ctype_digit($reference)) {
            $skill = $this->skillRepository->findByUid((int)$reference);
            if (!$skill instanceof Skill) {
                return null;
            }

            return $source === null || $skill->getSource() === (int)$source->getUid() ? $skill : null;
        }

        $identifiers = [$reference];
        if ($source !== null) {
            $identifiers[] = (int)$source->getUid() . ':' . $reference;
        }
        $byName = [];
        foreach ($this->findSkills($source) as $skill) {
            if (in_array($skill->getIdentifier(), $identifiers, true)) {
                return $skill;
            }
            if (strcasecmp($skill->getName(), $reference) === 0) {
                $byName[] = $skill;
            }
        }
        if (count($byName) > 1) {
            throw new \RuntimeException(sprintf(
                'Skill name "%s" is ambiguous (uids %s); use the uid or identifier.',
                $reference,
                implode(', ', array_map(static fn(Skill $skill): int => (int)$skill->getUid(), $byName)),
            ), 1757700002);
        }

        return $byName[0] ?? null;
    }

    /**
     * Enables a reviewed skill exactly like nr_llm's backend module does.
     */
    public function enable(Skill $skill): SkillEnableOutcome
    {
        if ($skill->isOrphaned()) {
            return SkillEnableOutcome::Orphaned;
        }
        if ($skill->isEnabled()) {
            return SkillEnableOutcome::AlreadyEnabled;
        }

        $skill->setEnabled(true);
        $this->skillRepository->update($skill);
        $this->persistenceManager->persistAll();
        $this->auditService->recordSkillEvent(SkillAuditEvent::ENABLED, $skill);

        return SkillEnableOutcome::Enabled;
    }
}
