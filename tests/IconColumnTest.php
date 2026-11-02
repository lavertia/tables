<?php

declare(strict_types=1);

namespace Lavertia\Tables\Tests;

use InvalidArgumentException;
use Lavertia\Tables\Components\IconColumn;
use Lavertia\Tables\Components\Table;
use Lavertia\Tables\Components\TextColumn;
use Orchestra\Testbench\TestCase;

final class IconColumnTest extends TestCase
{
    public function test_boolean_column_is_serialized_alongside_text_columns(): void
    {
        $table = Table::make('posts')
            ->columns([
                TextColumn::make('title')->sortable(),
                TextColumn::make('slug'),
                IconColumn::make('is_featured')->boolean()->label('Featured')->toggleable(),
            ])
            ->rows([
                ['title' => 'First', 'slug' => 'first', 'is_featured' => true],
                ['title' => 'Second', 'slug' => 'second', 'is_featured' => false],
            ]);

        $tree = $table->toArray();

        self::assertSame('table', $tree['type']);
        self::assertSame(['text-column', 'text-column', 'icon-column'], array_column($tree['components'], 'type'));
        self::assertSame('is_featured', $tree['components'][2]['props']->name);
        self::assertTrue($tree['components'][2]['props']->boolean);
        self::assertSame('Featured', $tree['components'][2]['props']->label);
        self::assertSame(true, $table->getProps()['rows'][0]['is_featured']);
        self::assertSame(false, $table->getProps()['rows'][1]['is_featured']);
    }

    public function test_icon_column_follows_the_same_attribute_allowlist_as_text_columns(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IconColumn::make('is_featured; DROP TABLE posts');
    }

    public function test_duplicate_column_names_are_rejected_across_column_types(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Table::make('posts')->columns([
            TextColumn::make('is_featured'),
            IconColumn::make('is_featured')->boolean(),
        ]);
    }

    public function test_boolean_icon_mode_is_opt_in_and_immutable(): void
    {
        $column = IconColumn::make('is_featured');
        $booleanColumn = $column->boolean();

        self::assertArrayNotHasKey('boolean', $column->getProps());
        self::assertTrue($booleanColumn->getProps()['boolean']);
    }
}
