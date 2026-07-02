<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Domain;

final class ImportResult
{
    public int $created = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    public int $files = 0;
    public int $skippedFiles = 0;
    /** Skills hidden on import because a danger-level security finding was matched (never deleted). */
    public int $quarantined = 0;

    /** @var string[] */
    public array $errors = [];

    public function summary(): string
    {
        $parts = sprintf('%d created, %d updated, %d unchanged', $this->created, $this->updated, $this->unchanged);
        if ($this->files > 0 || $this->skippedFiles > 0) {
            $parts .= sprintf(', %d attachment files', $this->files);
            if ($this->skippedFiles > 0) {
                $parts .= sprintf(' (%d skipped: binary or too large)', $this->skippedFiles);
            }
        }
        if ($this->quarantined > 0) {
            $parts .= sprintf(', %d quarantined (hidden — security review needed)', $this->quarantined);
        }
        if ($this->errors !== []) {
            $parts .= sprintf(', %d errors: %s', count($this->errors), implode(' | ', $this->errors));
        }
        return $parts;
    }
}
