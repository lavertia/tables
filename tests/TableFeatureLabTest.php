<?php

declare(strict_types=1);

namespace Lavertia\Tables\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Lavertia\Tables\Components\IconColumn;
use Lavertia\Tables\Components\Table;
use Lavertia\Tables\Components\TextColumn;
use Orchestra\Testbench\TestCase;

final class TableFeatureLabTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('feature_lab_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('team_id');
            $table->string('title');
            $table->boolean('featured')->default(false);
        });

        foreach (range(1, 3) as $index) {
            FeatureLabRecord::query()->create([
                'team_id' => 1, 'title' => 'Article '.$index, 'featured' => $index === 1,
            ]);
        }

        FeatureLabRecord::query()->create(['team_id' => 2, 'title' => 'Secret post', 'featured' => true]);
    }

    private function table(int $perPage = 10): Table
    {
        return Table::make('lab')
            ->columns([
                TextColumn::make('id')->hidden(),
                TextColumn::make('title')->searchable()->sortable(),
                IconColumn::make('featured')->boolean(),
            ])
            ->eloquent(FeatureLabRecord::query()->where('team_id', 1), static fn (): bool => true, $perPage);
    }

    public function test_appending_columns_does_not_replace_existing_columns(): void
    {
        $table = Table::make('lab')->columns([TextColumn::make('title')])
            ->pushColumns([IconColumn::make('featured')->boolean()]);

        self::assertSame(['text-column', 'icon-column'], array_column($table->toArray()['components'], 'type'));
    }

    public function test_default_size_and_page_options_are_enforced_on_server(): void
    {
        $this->app->instance('request', Request::create('/admin/lab?lvt_lab_perPage=999'));

        $props = $this->table()
            ->paginationPageOptions([10, 25])
            ->defaultPaginationPageOption(25)
            ->getProps();

        self::assertSame([10, 25], $props['paginationOptions']);
        self::assertSame(25, $props['pagination']['perPage']);
        self::assertSame(3, $props['pagination']['total']);
        self::assertCount(3, $props['rows']);
    }

    public function test_simple_mode_does_not_claim_total_count_or_last_page(): void
    {
        $this->app->instance('request', Request::create('/admin/lab?lvt_lab_page=1'));

        $props = $this->table(1)->paginationMode('simple')->getProps();

        self::assertSame('simple', $props['paginationMode']);
        self::assertNull($props['pagination']['total']);
        self::assertNull($props['pagination']['lastPage']);
        self::assertTrue($props['pagination']['hasMorePages']);
        self::assertCount(1, $props['rows']);
        self::assertSame('Article 1', $props['rows'][0]['title']);
    }

    public function test_no_pagination_is_still_bounded_to_100_records(): void
    {
        foreach (range(4, 111) as $index) {
            FeatureLabRecord::query()->create([
                'team_id' => 1, 'title' => 'Article '.$index,
            ]);
        }

        $props = $this->table()->paginated(false)->getProps();

        self::assertFalse($props['paginationEnabled']);
        self::assertTrue($props['pagination']['truncated']);
        self::assertSame(100, $props['pagination']['total']);
        self::assertCount(100, $props['rows']);
    }

    public function test_record_urls_are_resolved_only_from_the_authorized_scope(): void
    {
        $props = $this->table()->recordUrl(
            static fn (Model $model): string => '/admin/tables-feature-lab?feature=15&record='.$model->getKey(),
        )->getProps();

        self::assertSame(3, $props['pagination']['total']);
        self::assertSame('/admin/tables-feature-lab?feature=15&record=1', $props['rows'][0]['__recordUrl']);
        self::assertStringNotContainsString('Secret post', json_encode($props['rows'], JSON_THROW_ON_ERROR));
    }

    public function test_external_record_urls_cannot_be_serialized(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->table()->recordUrl(static fn (): string => '//attacker.invalid')->getProps();
    }

    public function test_striping_preset_and_polling_are_serialized_without_executing_actions(): void
    {
        $props = $this->table()->heading('Posts')
            ->striped()
            ->poll(10)
            ->recordTones('title', ['Article 1' => 'success'])
            ->extremePaginationLinks(false)
            ->persistPerPageInSession(false)
            ->getProps();

        self::assertSame('Posts', $props['label']);
        self::assertTrue($props['striped']);
        self::assertSame(10000, $props['pollIntervalMs']);
        self::assertFalse($props['extremePaginationLinks']);
        self::assertFalse($props['persistPerPageInSession']);
        self::assertSame('success', $props['recordTones']['tones']['Article 1']);
    }

    public function test_unsupported_pagination_modes_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Table::make('lab')->paginationMode('cursor');
    }

    public function test_cannot_allow_unbounded_page_sizes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Table::make('lab')->paginationPageOptions([10, 1000000]);
    }
}

final class FeatureLabRecord extends Model
{
    protected $table = 'feature_lab_records';

    protected $guarded = [];

    public $timestamps = false;
}
