<?php

declare(strict_types=1);

namespace Lavertia\Tables\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Lavertia\Tables\Components\SelectFilter;
use Lavertia\Tables\Components\Table;
use Lavertia\Tables\Components\TextColumn;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AdvancedTableTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('lavertia_test_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name');
            $table->string('status');
            $table->string('private_secret');
        });

        TableTestRecord::query()->create(['tenant_id' => 1, 'name' => 'Alpha', 'status' => 'active', 'private_secret' => 'secret-a']);
        TableTestRecord::query()->create(['tenant_id' => 1, 'name' => 'Beta', 'status' => 'pending', 'private_secret' => 'secret-b']);
        TableTestRecord::query()->create(['tenant_id' => 2, 'name' => 'Hidden', 'status' => 'active', 'private_secret' => 'secret-c']);
    }

    public function test_scoped_eloquent_pagination_never_serializes_unlisted_fields(): void
    {
        $this->app->instance('request', Request::create('/admin/model-demo?lvt_records_page=2'));

        $props = $this->table()->getProps();

        self::assertSame('eloquent', $props['mode']);
        self::assertSame(2, $props['pagination']['total']);
        self::assertSame(2, $props['pagination']['page']);
        self::assertSame('Beta', $props['rows'][0]['name']);
        self::assertArrayNotHasKey('private_secret', $props['rows'][0]);
        self::assertCount(1, $props['rows']);
    }

    public function test_only_declared_filters_and_sorts_are_applied(): void
    {
        $this->app->instance('request', Request::create('/admin/model-demo?lvt_records_filter_status=pending&lvt_records_sort=private_secret'));

        $props = $this->table()->getProps();

        self::assertSame('Beta', $props['rows'][0]['name']);
        self::assertNull($props['appliedSort']);
        self::assertSame(['status' => 'pending'], $props['appliedFilters']);
    }

    public function test_unauthorized_query_is_rejected(): void
    {
        $this->expectException(HttpException::class);

        $this->table(false)->getProps();
    }

    public function test_non_matching_filter_option_is_ignored(): void
    {
        $this->app->instance('request', Request::create('/admin/model-demo?lvt_records_filter_status=deleted'));

        $props = $this->table()->getProps();

        self::assertSame([], $props['appliedFilters']);
        self::assertSame(2, $props['pagination']['total']);
    }


    public function test_server_pagination_uses_a_stable_primary_key_order_by_default(): void
    {
        $this->app->instance('request', Request::create('/admin/model-demo?lvt_records_page=2'));

        $firstPage = $this->table()->getProps();

        self::assertSame('Beta', $firstPage['rows'][0]['name']);
        self::assertSame(2, $firstPage['pagination']['total']);
    }

    public function test_client_sort_overrides_builder_default_and_uses_primary_key_tie_breaker(): void
    {
        TableTestRecord::query()->create([
            'tenant_id' => 1,
            'name' => 'Alpha',
            'status' => 'active',
            'private_secret' => 'secret-d',
        ]);

        $this->app->instance('request', Request::create('/admin/model-demo?lvt_records_sort=name&lvt_records_direction=asc&lvt_records_page=2'));

        $table = Table::make('records')
            ->columns([
                TextColumn::make('name')->sortable(),
            ])
            ->eloquent(
                TableTestRecord::query()->where('tenant_id', 1)->orderBy('name', 'desc'),
                static fn (): bool => true,
                1,
            );

        $props = $table->getProps();

        self::assertSame('Alpha', $props['rows'][0]['name']);
        self::assertSame(3, $props['pagination']['total']);
        self::assertSame('name', $props['appliedSort']);
    }

    public function test_developer_selected_order_is_preserved_when_no_client_sort_exists(): void
    {
        $this->app->instance('request', Request::create('/admin/model-demo'));

        $table = Table::make('records')
            ->columns([TextColumn::make('name')])
            ->eloquent(
                TableTestRecord::query()->where('tenant_id', 1)->orderBy('name', 'desc'),
                static fn (): bool => true,
                1,
            );

        self::assertSame('Beta', $table->getProps()['rows'][0]['name']);
    }


    public function test_scoped_eloquent_sort_is_deterministic_with_tied_values(): void
    {
        TableTestRecord::query()->create([
            'tenant_id' => 1,
            'name' => 'Alpha',
            'status' => 'active',
            'private_secret' => 'secret-d',
        ]);

        $this->app->instance('request', Request::create(
            '/admin/model-demo?lvt_records_sort=name&lvt_records_direction=asc&lvt_records_page=2'
        ));

        $table = Table::make('records')
            ->columns([TextColumn::make('name')->sortable()])
            ->eloquent(
                TableTestRecord::query()->where('tenant_id', 1)->orderBy('name', 'desc'),
                static fn (): bool => true,
                1,
            );

        self::assertSame('Alpha', $table->getProps()['rows'][0]['name']);
    }

    public function test_invalid_sort_and_unknown_filter_are_ignored(): void
    {
        $this->app->instance('request', Request::create(
            '/admin/model-demo?lvt_records_sort=private_secret&lvt_records_filter_status=deleted'
        ));

        $props = $this->table()->getProps();

        self::assertNull($props['appliedSort']);
        self::assertSame([], $props['appliedFilters']);
        self::assertSame(2, $props['pagination']['total']);
    }

    private function table(bool $authorized = true): Table
    {
        return Table::make('records')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'active' => 'Active',
                    'pending' => 'Pending',
                ]),
            ])
            ->eloquent(
                TableTestRecord::query()->where('tenant_id', 1),
                static fn (): bool => $authorized,
                1,
            );
    }
}

final class TableTestRecord extends Model
{
    protected $table = 'lavertia_test_records';

    protected $guarded = [];

    public $timestamps = false;
}
