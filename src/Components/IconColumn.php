<?php

declare(strict_types=1);

namespace Lavertia\Tables\Components;

use InvalidArgumentException;
use Lavertia\Support\Components\AbstractComponent;
use Lavertia\Support\Contracts\TableColumnComponent;

/**
 * Declarative, presentation-only icon column.
 *
 * The frontend owns the icon artwork. No HTML or icon markup is serialized
 * from the server, and authorization remains enforced by the Table query.
 */
final class IconColumn extends AbstractComponent implements TableColumnComponent
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
        return 'icon-column';
    }

    public function getColumnName(): string
    {
        return $this->name;
    }

    public function label(string $label): static
    {
        return $this->withProp('label', $label);
    }

    public function boolean(bool $enabled = true): static
    {
        return $this->withProp('boolean', $enabled);
    }

    public function sortable(bool $enabled = true): static
    {
        return $this->withProp('sortable', $enabled);
    }

    public function toggleable(bool $enabled = true, bool $hiddenByDefault = false): static
    {
        return $this->withProp('toggleable', $enabled)
            ->withProp('hiddenByDefault', $hiddenByDefault);
    }

    public function hidden(bool $hidden = true): static
    {
        return $this->withProp('hidden', $hidden);
    }

    /** @return array<string, mixed> */
    public function getProps(): array
    {
        return ['name' => $this->name, 'label' => $this->name, ...parent::getProps()];
    }
}
