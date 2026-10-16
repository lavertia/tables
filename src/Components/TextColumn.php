<?php

declare(strict_types=1);

namespace Lavertia\Tables\Components;

use InvalidArgumentException;
use Lavertia\Support\Components\AbstractComponent;
use Lavertia\Support\Contracts\TableColumnComponent;

final class TextColumn extends AbstractComponent implements TableColumnComponent
{
    private function __construct(private readonly string $name) {}

    public static function make(string $name): self
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,127}$/D', $name) !== 1) {
            throw new InvalidArgumentException('Invalid table column name.');
        }

        return (new self($name))->key($name);
    }

    public function getType(): string
    {
        return 'text-column';
    }

    public function getColumnName(): string
    {
        return $this->name;
    }

    public function label(string $label): static
    {
        return $this->withProp('label', $label);
    }

    public function sortable(bool $sortable = true): static
    {
        return $this->withProp('sortable', $sortable);
    }

    public function searchable(bool $searchable = true): static
    {
        return $this->withProp('searchable', $searchable);
    }

    public function toggleable(bool $enabled = true, bool $hiddenByDefault = false): static
    {
        return $this->withProp('toggleable', $enabled)->withProp('hiddenByDefault', $hiddenByDefault);
    }

    public function hidden(bool $hidden = true): static
    {
        return $this->withProp('hidden', $hidden);
    }

    /** @param array<string, 'success'|'warning'|'danger'|'info'|'neutral'> $tones */
    public function badge(array $tones = []): static
    {
        foreach ($tones as $value => $tone) {
            if (! is_string($value) || ! is_string($tone)
                || ! in_array($tone, ['success', 'warning', 'danger', 'info', 'neutral'], true)) {
                throw new InvalidArgumentException('Invalid column badge tone.');
            }
        }

        return $this->withProp('badge', true)->withProp('badgeTones', $tones);
    }

    /** @return array<string, mixed> */
    public function getProps(): array
    {
        return ['name' => $this->name, 'label' => $this->name, ...parent::getProps()];
    }
}
