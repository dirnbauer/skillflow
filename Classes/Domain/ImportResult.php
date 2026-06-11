<?php

declare(strict_types=1);

namespace Webconsulting\Skills\Domain;

final class ImportResult
{
    public int $created = 0;
    public int $updated = 0;
    public int $unchanged = 0;

    /** @var string[] */
    public array $errors = [];

    public function summary(): string
    {
        $parts = sprintf('%d created, %d updated, %d unchanged', $this->created, $this->updated, $this->unchanged);
        if ($this->errors !== []) {
            $parts .= sprintf(', %d errors: %s', count($this->errors), implode(' | ', $this->errors));
        }
        return $parts;
    }
}
