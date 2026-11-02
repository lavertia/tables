<?php

declare(strict_types=1);

namespace Lavertia\Tables\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Lavertia\Tables\Components\ImageColumn;
use Lavertia\Tables\Components\SelectFilter;
use Lavertia\Tables\Components\Table;
use Lavertia\Tables\Components\TextColumn;
use Orchestra\Testbench\TestCase;

final class TableWorkspacesTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('table_workspace_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('tenant_id');
            $table->string('name');
            $table->string('status');
            $table->decimal('price', 10, 2);
            $table->timestamp('verified_at')->nullable();
        });

        TableWorkspaceRecord::query()->create(['tenant_id' => 1, 'name' => 'A', 'status' => 'active', 'price' => '25.00', 'verified_at' => now()]);
        TableWorkspaceRecord::query()->create(['tenant_id' => 1, 'name' => 'B', 'status' => 'pending', 'price' => '15.00', 'verified_at' => null]);
        TableWorkspaceRecord::query()->create(['tenant_id' => 2, 'name' => 'Secret', 'status' => 'active', 'price' => '9000.00', 'verified_at' => now()]);
    }

    private function table(): Table
    {
        return Table::make('records')
            ->searchable()
            ->groupable(['status' => 'Status'])
            ->tabs('status', ['active' => 'Active', 'pending' => 'Pending'])
            ->columns([
                TextColumn::make('name')->sortable()->searchable(),
                TextColumn::make('status')->sortable(),
                TextColumn::make('price')->money('USD')->sortable(),
                ImageColumn::make('id')->label('Placeholder')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('verified_at')
                    ->options(['yes' => 'Verified', 'no' => 'Unverified'])
                    ->presence(),
            ])
            ->summarize(['price'])
            ->eloquent(TableWorkspaceRecord::query()->where('tenant_id', 1), static fn (): bool => true, 1);
    }

    public function test_tabs_counts_summaries_and_group_options_remain_tenant_scoped(): void
    {
        $this->app->instance('request', Request::create('/admin/tables?lvt_records_tab=active'));

        $props = $this->table()->getProps();

        self::assertSame(1, $props['pagination']['total']);
        self::assertSame('active', $props['appliedTab']);
        self::assertSame('A', $props['rows'][0]['name']);
        self::assertSame(25.0, $props['summaryTotals']['price']);
        self::assertSame(['status' => 'Status'], $props['groupOptions']);
        self::assertEquals(['active' => 1, 'pending' => 1], $props['tabCounts']);
    }

    public function test_per_page_and_presence_filters_use_allowlisted_values(): void
    {
        $this->app->instance('request', Request::create('/admin/tables?lvt_records_perPage=5&lvt_records_filter_verified_at=no'));

        $props = $this->table()->getProps();

        self::assertSame(5, $props['pagination']['perPage']);
        self::assertSame(1, $props['pagination']['total']);
        self::assertSame('B', $props['rows'][0]['name']);
        self::assertSame(['verified_at' => 'no'], $props['appliedFilters']);
    }

    public function test_invalid_tab_is_ignored_and_cannot_change_sql_identifier(): void
    {
        $this->app->instance('request', Request::create('/admin/tables?lvt_records_tab=private_secret&lvt_records_sort=tenant_id&lvt_records_perPage=99999'));

        $props = $this->table()->getProps();

        self::assertNull($props['appliedTab']);
        self::assertNull($props['appliedSort']);
        self::assertSame(1, $props['pagination']['perPage']);
        self::assertSame(2, $props['pagination']['total']);
    }

    public function test_cannot_summarize_a_field_that_is_not_declared_as_column(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Table::make('private')
            ->columns([TextColumn::make('name')])
            ->summarize(['price'])
            ->eloquent(TableWorkspaceRecord::query()->where('tenant_id', 1), static fn (): bool => true)
            ->getProps();
    }

    public function test_invalid_filter_source_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SelectFilter::make('status')->source('status OR 1=1');
    }
}

final class TableWorkspaceRecord extends Model
{
    protected $table = 'table_workspace_records';

    protected $guarded = [];

    public $timestamps = false;
}
