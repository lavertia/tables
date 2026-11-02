<?php

declare(strict_types=1);

namespace Lavertia\Tables\Components;

use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
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

    private ?Closure $recordUrl = null;

    private int $perPage = 15;

    /** @var list<int> */
    private array $pageOptions = [5, 10, 25, 50, 100];

    private bool $customPageOptions = false;

    private ?int $defaultPageOption = null;

    private bool $paginationEnabled = true;

    private bool $extremePageLinks = true;

    private bool $persistPerPage = false;

    private string $paginationMode = 'length-aware';

    /** @var array<string, string> */
    private array $tabs = [];

    private ?string $tabField = null;

    /** @var list<string> */
    private array $summaryColumns = [];

    private function __construct(private readonly string $name) {}

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

    public function heading(string $heading): static
    {
        return $this->withProp('label', $heading);
    }

    public function striped(bool $enabled = true): static
    {
        return $this->withProp('striped', $enabled);
    }

    /**
     * Use a semantic server-defined tone rather than passing arbitrary
     * class strings from record data into the browser.
     *
     * @param  array<string, 'success'|'warning'|'danger'|'info'|'neutral'>  $tones
     */
    public function recordTones(string $column, array $tones): static
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $column) !== 1) {
            throw new InvalidArgumentException('Invalid row tone attribute.');
        }

        foreach ($tones as $value => $tone) {
            if (! is_string($value) || ! in_array($tone, ['success', 'warning', 'danger', 'info', 'neutral'], true)) {
                throw new InvalidArgumentException('Invalid row tone.');
            }
        }

        return $this->withProp('recordTones', ['field' => $column, 'tones' => $tones]);
    }

    /** @param  list<int>  $options */
    public function paginationPageOptions(array $options): static
    {
        if (! array_is_list($options) || $options === [] || count($options) > 10) {
            throw new InvalidArgumentException('Pagination options must be a short list.');
        }

        foreach ($options as $option) {
            if (! is_int($option) || $option < 1 || $option > 100) {
                throw new InvalidArgumentException('Pagination size must be within 1 to 100.');
            }
        }

        if (count(array_unique($options)) !== count($options)) {
            throw new InvalidArgumentException('Duplicate pagination option.');
        }

        $copy = clone $this;
        $copy->pageOptions = $options;
        $copy->customPageOptions = true;

        return $copy;
    }

    public function defaultPaginationPageOption(int $size): static
    {
        if ($size < 1 || $size > 100) {
            throw new InvalidArgumentException('Invalid default pagination size.');
        }

        $copy = clone $this;
        $copy->defaultPageOption = $size;

        return $copy;
    }

    public function extremePaginationLinks(bool $enabled = true): static
    {
        $copy = clone $this;
        $copy->extremePageLinks = $enabled;

        return $copy;
    }

    public function paginated(bool $enabled = true): static
    {
        $copy = clone $this;
        $copy->paginationEnabled = $enabled;

        return $copy;
    }

    /** Only offset or simple mode is supported; cursor mode requires a separate keyed contract. */
    public function paginationMode(string $mode): static
    {
        if (! in_array($mode, ['length-aware', 'simple'], true)) {
            throw new InvalidArgumentException('Unsupported pagination mode.');
        }

        $copy = clone $this;
        $copy->paginationMode = $mode;

        return $copy;
    }

    /** Explicitly opt in; the default is not to persist the page size. */
    public function persistPerPageInSession(bool $enabled = true): static
    {
        $copy = clone $this;
        $copy->persistPerPage = $enabled;

        return $copy;
    }

    /** Accept only bounded polling intervals and only poll Eloquent views on the client. */
    public function poll(int $seconds): static
    {
        if ($seconds < 5 || $seconds > 300) {
            throw new InvalidArgumentException('Polling must be between 5 and 300 seconds.');
        }

        return $this->withProp('pollIntervalMs', $seconds * 1000);
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

    /** Control client-side record selection without changing query authorization. */
    public function selectable(bool $enabled = true): static
    {
        return $this->withProp('selectable', $enabled);
    }

    /** Control the read-only row action menu in the shared table renderer. */
    public function recordActions(bool $enabled = true): static
    {
        return $this->withProp('recordActions', $enabled);
    }

    /**
     * Hide pagination UI for a focused preview; SQL pagination and the
     * per-page safety limit remain enforced server-side.
     */
    public function paginationControls(bool $enabled = true): static
    {
        return $this->withProp('paginationControls', $enabled);
    }

    /**
     * Group headers are applied to the displayed page (not the entire result set).
     *
     * @param  array<string, string>  $options  column => label
     */
    public function groupable(array $options): static
    {
        $this->validateOptions($options, 15);

        return $this->withProp('groupOptions', $options);
    }

    /**
     * Predefined server-filtered tabs; user input can select values, not SQL fields.
     *
     * @param  array<string, string>  $options  status => label
     */
    public function tabs(string $attribute, array $options): static
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $attribute) !== 1) {
            throw new InvalidArgumentException('Invalid tab attribute.');
        }

        $this->validateOptions($options, 20);

        $copy = clone $this;
        $copy->tabField = $attribute;
        $copy->tabs = $options;

        return $copy;
    }

    /**
     * Sum fields on the tenant-scoped filtered query, before pagination.
     *
     * @param  list<string>  $attributes
     */
    public function summarize(array $attributes): static
    {
        if (! array_is_list($attributes) || count($attributes) > 8) {
            throw new InvalidArgumentException('Invalid summary columns.');
        }

        foreach ($attributes as $attribute) {
            if (! is_string($attribute)
                || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $attribute) !== 1) {
                throw new InvalidArgumentException('Invalid summary attribute.');
            }
        }

        $copy = clone $this;
        $copy->summaryColumns = array_values(array_unique($attributes));

        return $copy;
    }

    /** @param array<string, string> $options */
    private function validateOptions(array $options, int $limit): void
    {
        if (count($options) > $limit) {
            throw new InvalidArgumentException('Too many table options.');
        }

        foreach ($options as $value => $label) {
            if (! is_string($value)
                || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $value) !== 1
                || ! is_string($label) || mb_strlen($label) > 80) {
                throw new InvalidArgumentException('Invalid table option.');
            }
        }
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

    /**
     * Record URLs are evaluated server-side only after authorization and tenant
     * filtering. Limit destinations to same-origin relative URLs.
     *
     * @param  Closure(Model): string  $resolver
     */
    public function recordUrl(Closure $resolver): static
    {
        $copy = clone $this;
        $copy->recordUrl = $resolver;

        return $copy;
    }

    /** @param  list<TableColumnComponent>  $columns */
    public function pushColumns(array $columns): static
    {
        return $this->columns([...$this->columns, ...$columns]);
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
        // Native column names cannot contain hyphens, so filter keys stay
        // distinct without changing the existing column identity.
        $filters = array_map(
            static fn (SelectFilter $filter): SelectFilter => $filter->key('filter-'.$filter->getFilterName()),
            $this->filters,
        );

        return [...$this->columns, ...$filters];
    }

    /**
     * Snapshot mode is for small, non-sensitive datasets and has a strict upper bound.
     *
     * @param  list<array<string, scalar|null>>  $rows
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
     * @param  array<int, array<string, mixed>>  $orders
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
        $pageOptions = $this->customPageOptions
            ? $this->pageOptions
            : array_values(array_unique([...$this->pageOptions, $this->perPage]));

        $base = [
            'name' => $this->name,
            'rows' => [],
            'mode' => 'snapshot',
            'tabs' => $this->tabs,
            'summaryColumns' => $this->summaryColumns,
            'paginationOptions' => $pageOptions,
            'defaultPaginationPageOption' => $this->defaultPageOption ?? $this->perPage,
            'paginationEnabled' => $this->paginationEnabled,
            'paginationMode' => $this->paginationMode,
            'extremePaginationLinks' => $this->extremePageLinks,
            'persistPerPageInSession' => $this->persistPerPage,
            'locale' => function_exists('app') && app() instanceof Application && app()->bound('config') ? app()->getLocale() : 'en',
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

        foreach ($this->summaryColumns as $attribute) {
            if (! in_array($attribute, $columns, true)) {
                throw new InvalidArgumentException('Summaries require a declared column.');
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

        $tabCounts = [];

        if ($this->tabField !== null && $this->tabs !== []) {
            $tabCounts = (clone $this->query)
                ->select($this->tabField)
                ->selectRaw('COUNT(*) AS aggregate_count')
                ->groupBy($this->tabField)
                ->pluck('aggregate_count', $this->tabField)
                ->all();

            $tabValue = request()->query($prefix.'tab');

            if (is_string($tabValue) && array_key_exists($tabValue, $this->tabs)) {
                $query->where($this->tabField, '=', $tabValue);
            }
        }

        $appliedFilters = [];

        foreach ($this->filters as $filter) {
            $name = $filter->getFilterName();
            $attribute = $filter->getProps()['source'] ?? $name;
            $options = $filter->getProps()['options'] ?? [];
            $value = request()->query($prefix.'filter_'.$name);

            if (is_string($value) && array_key_exists($value, $options)) {
                if (($filter->getProps()['presence'] ?? false) === true) {
                    if ($value === 'yes') {
                        $query->whereNotNull($attribute);
                    } elseif ($value === 'no') {
                        $query->whereNull($attribute);
                    }
                } else {
                    $query->where($attribute, '=', $value);
                }
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

        $totals = [];

        foreach ($this->summaryColumns as $attribute) {
            $totals[$attribute] = (float) (clone $query)->sum($attribute);
        }

        $defaultSize = $this->defaultPageOption ?? $this->perPage;

        if (! in_array($defaultSize, $pageOptions, true)) {
            throw new InvalidArgumentException('Default page size must be one of the enforced pagination options.');
        }

        $selectedPerPage = request()->query($prefix.'perPage');
        $candidate = is_string($selectedPerPage) && ctype_digit($selectedPerPage)
            ? (int) $selectedPerPage : null;
        $sessionKey = 'lavertia.table.'.hash('sha256', request()->path().':'.$this->name).'.perPage';

        if ($candidate === null && $this->persistPerPage && request()->hasSession()) {
            $candidate = request()->session()->get($sessionKey);
        }

        $perPage = is_int($candidate) && in_array($candidate, $pageOptions, true)
            ? $candidate : $defaultSize;

        if ($this->persistPerPage && request()->hasSession()) {
            request()->session()->put($sessionKey, $perPage);
        }

        $pageParam = $prefix.'page';
        $page = filter_var(request()->query($pageParam, 1), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000000],
        ]);
        $page = $page === false ? 1 : $page;

        if (! $this->paginationEnabled) {
            // Unpaginated views are deliberately bounded to 100 records to prevent
            // accidental million-row Inertia payloads.
            $models = (clone $query)->limit(101)->get();
            $hasMore = $models->count() > 100;
            $models = $models->take(100);
            $pagination = [
                'page' => 1,
                'lastPage' => 1,
                'perPage' => $models->count(),
                'total' => $models->count(),
                'hasMorePages' => false,
                'truncated' => $hasMore,
            ];
        } elseif ($this->paginationMode === 'simple') {
            $paginator = $query->simplePaginate($perPage, ['*'], $pageParam, $page)->withQueryString();
            $models = $paginator->getCollection();
            $pagination = [
                'page' => $paginator->currentPage(),
                'lastPage' => null,
                'perPage' => $paginator->perPage(),
                'total' => null,
                'hasMorePages' => $paginator->hasMorePages(),
                'nextUrl' => $paginator->nextPageUrl(),
                'previousUrl' => $paginator->previousPageUrl(),
            ];
        } else {
            $paginator = $query->paginate($perPage, ['*'], $pageParam, $page)->withQueryString();
            $models = $paginator->getCollection();
            $pagination = [
                'page' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'hasMorePages' => $paginator->hasMorePages(),
                'nextUrl' => $paginator->nextPageUrl(),
                'previousUrl' => $paginator->previousPageUrl(),
            ];
        }

        $rows = $models->map(function (Model $model) use ($columns): array {
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

            if ($this->recordUrl !== null) {
                $url = ($this->recordUrl)($model);

                if (! is_string($url) || ! str_starts_with($url, '/')
                    || str_starts_with($url, '//') || str_contains($url, '\\')
                    || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
                    throw new InvalidArgumentException('Record URL must be a safe same-origin path.');
                }

                $result['__recordUrl'] = $url;
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
            'appliedTab' => is_string(request()->query($prefix.'tab')) && array_key_exists(request()->query($prefix.'tab'), $this->tabs) ? request()->query($prefix.'tab') : null,
            'tabCounts' => $tabCounts,
            'summaryTotals' => $totals,
            'groupOptions' => array_intersect_key(parent::getProps()['groupOptions'] ?? [], array_fill_keys($columns, true)),
            'pagination' => $pagination,
        ];
    }
}
