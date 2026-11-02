<?php

declare(strict_types=1);

namespace Lavertia\Tables\Components;

use InvalidArgumentException;
use Lavertia\Support\Components\AbstractComponent;
use Lavertia\Support\Contracts\TableColumnComponent;

/**
 * Safe thumbnail/placeholder column. Values are scalar from a declared source.
 * No executable markup or untrusted paths are accepted.
 */
final class ImageColumn extends AbstractComponent implements TableColumnComponent
{
    private function __construct(private readonly string $name) {}

    public static function make(string $name): self
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,127}$/D', $name) !== 1) {
            throw new InvalidArgumentException('Invalid image column name.');
        }

        return (new self($name))->key($name);
    }

    public function getType(): string
    {
        return 'image-column';
    }

    public function getColumnName(): string
    {
        return $this->name;
    }

    public function label(string $label): static
    {
        return $this->withProp('label', $label);
    }

    public function toggleable(bool $enabled = true, bool $hiddenByDefault = false): static
    {
        return $this->withProp('toggleable', $enabled)
            ->withProp('hiddenByDefault', $hiddenByDefault);
    }

    /** @return array<string, mixed> */
    public function getProps(): array
    {
        return ['name' => $this->name, 'label' => $this->name, ...parent::getProps()];
    }
}
