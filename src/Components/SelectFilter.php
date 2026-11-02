<?php

declare(strict_types=1);

namespace Lavertia\Tables\Components;

use InvalidArgumentException;
use Lavertia\Support\Components\AbstractComponent;

final class SelectFilter extends AbstractComponent
{
    private function __construct(private readonly string $name) {}

    public static function make(string $name): self
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $name) !== 1) {
            throw new InvalidArgumentException('Invalid filter name.');
        }

        return (new self($name))->key($name);
    }

    public function getType(): string
    {
        return 'select-filter';
    }

    public function getFilterName(): string
    {
        return $this->name;
    }

    public function label(string $label): static
    {
        return $this->withProp('label', $label);
    }

    /**
     * @param  array<int|string, string>  $options
     */
    public function options(array $options): static
    {
        if (count($options) > 100) {
            throw new InvalidArgumentException('Filters may have at most 100 options.');
        }

        foreach ($options as $value => $label) {
            if ((! is_string($value) && ! is_int($value)) || ! is_string($label)
                || (string) $value === '' || mb_strlen((string) $value) > 100) {
                throw new InvalidArgumentException('Invalid filter option.');
            }
        }

        return $this->withProp('options', $options);
    }

    /**
     * Interpret 'yes' / 'no' options as NULL presence without exposing SQL.
     */
    public function presence(bool $enabled = true): static
    {
        return $this->withProp('presence', $enabled);
    }

    /**
     * Explicit server-side database attribute for joined Eloquent queries.
     * The client never supplies this identifier.
     */
    public function source(string $attribute): static
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\.[A-Za-z_][A-Za-z0-9_]*)?$/D', $attribute) !== 1) {
            throw new InvalidArgumentException('Invalid filter source column.');
        }

        return $this->withProp('source', $attribute);
    }

    /** @return array<string, mixed> */
    public function getProps(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->name,
            'options' => [],
            ...parent::getProps(),
        ];
    }
}
