<?php

declare(strict_types=1);

namespace Lavertia\Tables\Components;

use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Lavertia\Support\Components\AbstractComponent;
use Lavertia\Support\Contracts\Component;
use Lavertia\Support\Contracts\TableColumnComponent;
use Lavertia\Support\Contracts\TableComponent;

final class Table extends AbstractComponent implements TableComponent
{
    /** @var list<TableColumnComponent> */
    private array $columns = [];

    /** @var list<SelectFilter> */
    private array $filters = [];

    private ?Builder $query = null;

    private ?Closure $authorize = null;

    private int $perPage = 15;

    private function __construct(private readonly string $name)
    {
    }

    public static function make(string $name): self
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,50}$/D', $name) !== 1) {
            throw new InvalidArgumentException('Invalid table name.');
        }

        return (new self($name))->key($name);
    }

    public function getType(): string
    {
        return 'table';
    }

    public function getTableName(): string
    {
        return $this->name;
    }

    public function label(string $label): static
    {
        return $this->withProp('label', $label);
    }

    public function description(string $description): static
    {
        return $this->withProp('description', $description);
    }

    public function searchable(bool $enabled = true): static
    {
        return $this->withProp('searchable', $enabled);
    }

    public function reorderableColumns(bool $enabled = true): static
    {
        return $this->withProp('reorderableColumns', $enabled);
    }

    public function columnManager(bool $enabled = true): static
    {
        return $this->withProp('columnManager', $enabled);
    }

    /** @param list<TableColumnComponent> $columns */
    public function columns(array $columns): static
    {
        if (! array_is_list($columns) || $columns === []) {
            throw new InvalidArgumentException('Tables require a non-empty list of columns.');
        }

        $names = [];

        foreach ($columns as $column) {
            if (! $column instanceof TableColumnComponent) {
                throw new InvalidArgumentException('Columns must implement TableColumnComponent.');
            }

            if (isset($names[$column->getColumnName()])) {
                throw new InvalidArgumentException('Duplicate table column.');
            }

            $names[$column->getColumnName()] = true;
        }

        $copy = clone $this;
        $copy->columns = $columns;

        return $copy;
    }

    /** @param list<SelectFilter> $filters */
    public function filters(array $filters): static
    {
        if (! array_is_list($filters)) {
            throw new InvalidArgumentException('Filters must be a list.');
        }

        $names = [];

        foreach ($filters as $filter) {
            if (! $filter instanceof SelectFilter || isset($names[$filter->getFilterName()])) {
                throw new InvalidArgumentException('Duplicate or invalid table filter.');
            }

            $names[$filter->getFilterName()] = true;
        }

        $copy = clone $this;
        $copy->filters = $filters;

        return $copy;
    }

    /**
     * The caller MUST supply a tenant/permission-scoped Eloquent Builder and an explicit
     * authorization callback. Do not pass unscoped Model::query() in a multi-tenant app.
     *
     * The Builder and callback never cross the Inertia JSON boundary.
     */
    public function eloquent(Builder $query, Closure $authorize, int $perPage = 15): static
    {
        if ($perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('Rows per page must be between 1 and 100.');
        }

        $copy = clone $this;
        $copy->query = clone $query;
        $copy->authorize = $authorize;
        $copy->perPage = $perPage;

        return $copy;
    }

    /** @return list<Component> */
    public function getComponents(): array
    {
        return [...$this->columns, ...$this->filters];
    }

    /**
     * Snapshot mode is for small, non-sensitive datasets and has a strict upper bound.
     *
     * @param list<array<string, scalar|null>> $rows
     */
    public function rows(array $rows): static
    {
        if (! array_is_list($rows) || count($rows) > 100) {
            throw new InvalidArgumentException('Table rows must be a list of at most 100 items.');
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('Invalid table row.');
            }

            foreach ($row as $value) {
                if ($value !== null && ! is_scalar($value)) {
                    throw new InvalidArgumentException('Table cells must contain scalar values.');
                }
            }
        }

        return $this->withProp('rows', $rows);
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     */
    private function hasPrimaryKeyOrder(array $orders, string $qualifiedKey, string $key): bool
    {
        foreach ($orders as $order) {
            if (! isset($order['column']) || ! is_string($order['column'])) {
                continue;
            }

            if ($order['column'] === $key || $order['column'] === $qualifiedKey) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function getProps(): array
    {
        $base = [
            'name' => $this->name,
            'rows' => [],
            'mode' => 'snapshot',
            'locale' => function_exists('app') && app() instanceof \Illuminate\Foundation\Application ? app()->getLocale() : 'en',
            ...parent::getProps(),
        ];

        if ($this->query === null) {
            return $base;
        }

        // Client-side column hiding does not affect authorization or data projections.
        if (($this->authorize) === null || ($this->authorize)() !== true) {
            abort(403);
        }

        $query = clone $this->query;
        $columns = [];
        $sortable = [];
        $searchable = [];

        foreach ($this->columns as $column) {
            $name = $column->getColumnName();
            $columns[] = $name;

            if ($column->getProps()['sortable'] ?? false) {
                $sortable[] = $name;
            }

            if ($column->getProps()['searchable'] ?? false) {
                $searchable[] = $name;
            }
        }

        // Only simple, predeclared model attributes may participate in SQL operations.
        foreach ([...$sortable, ...$searchable] as $name) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
                throw new InvalidArgumentException('Server-side columns must use direct attributes.');
            }
        }

        $prefix = 'lvt_'.$this->name.'_';
        $search = request()->query($prefix.'search', '');
        $search = is_string($search) ? mb_substr(trim($search), 0, 100) : '';

        if ($search !== '' && $searchable !== [] && ($base['searchable'] ?? false)) {
            $query->where(function (Builder $nested) use ($searchable, $search): void {
                foreach ($searchable as $attribute) {
                    $nested->orWhere($attribute, 'like', '%'.$search.'%');
                }
            });
        }

        $appliedFilters = [];

        foreach ($this->filters as $filter) {
            $name = $filter->getFilterName();
            $options = $filter->getProps()['options'] ?? [];
            $value = request()->query($prefix.'filter_'.$name);

            if (is_string($value) && array_key_exists($value, $options)) {
                $query->where($name, '=', $value);
                $appliedFilters[$name] = $value;
            }
        }

        $sort = request()->query($prefix.'sort');
        $sort = is_string($sort) && in_array($sort, $sortable, true) ? $sort : null;
        $direction = request()->query($prefix.'direction') === 'desc' ? 'desc' : 'asc';

        // Offset pagination needs a deterministic total ordering. Preserve
        // app-defined defaults, but let the explicit table sort take precedence.
        // Qualified identifiers avoid ambiguity with joined queries.
        $model = $query->getModel();
        $key = $model->getQualifiedKeyName();
        $orders = $query->getQuery()->orders ?? [];

        if ($sort !== null) {
            $query->reorder()->orderBy($model->qualifyColumn($sort), $direction);

            if ($model->qualifyColumn($sort) !== $key) {
                $query->orderBy($key);
            }
        } elseif ($orders === [] || ! $this->hasPrimaryKeyOrder($orders, $key, $model->getKeyName())) {
            // Keep predeclared builder ordering as the primary sort, adding a
            // unique tie-breaker only where one is not already present.
            $query->orderBy($key);
        }

        $pageParam = $prefix.'page';
        $page = filter_var(request()->query($pageParam, 1), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000000],
        ]);
        $page = $page === false ? 1 : $page;

        $paginator = $query->paginate($this->perPage, ['*'], $pageParam, $page)->withQueryString();

        $rows = $paginator->getCollection()->map(function (Model $model) use ($columns): array {
            $result = [];

            foreach ($columns as $name) {
                $value = $model->getAttribute($name);

                if ($value instanceof DateTimeInterface) {
                    $value = $value->format(DATE_ATOM);
                }

                if ($value !== null && ! is_scalar($value)) {
                    throw new InvalidArgumentException('Table data must be projected to scalar values.');
                }

                $result[$name] = $value;
            }

            return $result;
        })->values()->all();

        return [
            ...$base,
            'rows' => $rows,
            'mode' => 'eloquent',
            'statePrefix' => $prefix,
            'appliedSearch' => $search,
            'appliedSort' => $sort,
            'appliedDirection' => $direction,
            'appliedFilters' => $appliedFilters,
            'pagination' => [
                'page' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'nextUrl' => $paginator->nextPageUrl(),
                'previousUrl' => $paginator->previousPageUrl(),
            ],
        ];
    }
}
