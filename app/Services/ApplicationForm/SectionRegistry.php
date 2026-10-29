<?php

namespace App\Services\ApplicationForm;

use App\Services\ApplicationForm\Sections\Section;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The wizard's steps. Authoritative for the server; the wizard renders steps from the payload
 * this registry produces. Paper-form order, except that personal details come before the fee
 * step: the fee (and whether the fee step applies at all) depends on the category, gender,
 * PwBD and ex-serviceman details entered there. Each step keeps its paper-form reference.
 */
class SectionRegistry
{
    private const SECTIONS = [
        Sections\PersonalSection::class,
        Sections\PaymentSection::class,
        Sections\PostSection::class,
        Sections\ExServicemanSection::class,
        Sections\QualificationsSection::class,
        Sections\EmploymentSection::class,
        Sections\CriminalSection::class,
        Sections\MeritSection::class,
        Sections\TrainingSection::class,
        Sections\MembershipsSection::class,
        Sections\SuitabilitySection::class,
        Sections\RefereesSection::class,
        Sections\DeclarationsSection::class,
        Sections\AchievementsSection::class,
        Sections\CandidateDeclarationSection::class,
        Sections\EndorsementSection::class,
        Sections\ChecklistSection::class,
    ];

    /** @var array<string, Section>|null */
    private ?array $instances = null;

    public function __construct(private Container $container) {}

    /** @return array<string, Section> */
    public function all(): array
    {
        if ($this->instances === null) {
            $this->instances = [];
            foreach (self::SECTIONS as $class) {
                $section = $this->container->make($class);
                $this->instances[$section->key()] = $section;
            }
        }

        return $this->instances;
    }

    public function get(string $key): Section
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("Unknown section [$key].");
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }
}
