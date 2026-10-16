# Lavertia Tables

**Experimental v0.2 package – private, not published as a stable Composer release.**

Canonical home of `Table`, `TextColumn` and `SelectFilter` PHP components. Accepts bounded scalar snapshots (100 rows max) or Laravel Eloquent queries with explicit authorization and a page size limited to 1–100. Always scope your Builder to the current user, tenant or team **before** invoking `eloquent()`.

```php
use Lavertia\Tables\Components\{Table, TextColumn, SelectFilter};

$table = Table::make('members')
    ->searchable()
    ->columns([
        TextColumn::make('name')->sortable()->searchable(),
        TextColumn::make('status')->badge(),
    ])
    ->filters([
        SelectFilter::make('status')->options(['active' => 'Active']),
    ])
    ->eloquent(
        User::query()->where('tenant_id', $tenant->id),
        fn (): bool => auth()->user()?->can('viewAny', User::class) === true,
        25,
    );
```

Includes **server-side** pagination, allowlisted sort/search/filter parameters, a deterministic unique primary-key tie-breaker and JSON-safe projections. Client-side hiding is only presentation; it is **not** row- or column-level authorization.

React/Vue/Svelte frontend controls currently live in [Panels PR #6](https://github.com/lavertia/panels/pull/6). An eventual compatibility alias from `Lavertia\Panels\Components\*` to this package must be verified before changing existing consumers.

Run `composer test` and `composer lint` after configuring a `lavertia/support` path repository. GitHub Actions PHP tests require a read-only `LAVERTIA_READ_TOKEN` while Support is private.

[Release readiness checklist](https://github.com/lavertia/lavertia/issues/3)
