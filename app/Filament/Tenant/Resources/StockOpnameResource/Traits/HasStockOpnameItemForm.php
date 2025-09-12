<?php

namespace App\Filament\Tenant\Resources\StockOpnameResource\Traits;

use App\Models\Tenants\Product;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;

trait HasStockOpnameItemForm
{
    public function getStockOpnameItemForm($relation = 'product'): array
    {
        return [
            Select::make('product_id')
                ->translateLabel()
                ->required()
                ->native(false)
                ->placeholder(__('Search...'))
                ->relationship(name: $relation, titleAttribute: 'name')
                ->searchable(['name', 'barcode', 'sku'])
                ->live()
                ->afterStateUpdated(function (Set $set, ?string $state) {
                    $product = Product::find($state);
                    if ($product) {
                        $set('current_stock', $product->stock);
                        // reset stok actual & missing kalau ganti produk
                        $set('actual_stock', null);
                        $set('missing_stock', null);
                    }
                }),

            TextInput::make('current_stock')
                ->translateLabel()
                ->readOnly()
                ->numeric(),

            Select::make('adjustment_type')
                ->translateLabel()
                ->options([
                    'broken' => __('Broken'),
                    'lost' => __('Lost'),
                    'expired' => __('Expired'),
                    'manual_input' => __('Manual Input'),
                ])
                ->required()
                ->live(),

            TextInput::make('actual_stock')
                ->translateLabel()
                ->required()
                ->disabled(fn(Get $get) => ! $get('adjustment_type'))
                ->live(onBlur: true)
                ->afterStateUpdated(function (Set $set, Get $get, ?string $state) {
                    $product = Product::find($get('product_id'));
                    if (! $product) {
                        Notification::make()
                            ->title(__('Please select the product first'))
                            ->warning()
                            ->send();
                        $set('actual_stock', 0);
                        $set('missing_stock', 0);
                        return;
                    }

                    $actual = max(0, (int) $state);
                    $current = (int) $product->stock;

                    // hitung selisih
                    $missing = $current - $actual;
                    if ($missing < 0) {
                        // kalau lebih besar dari stok, missing dianggap 0
                        $missing = 0;
                    }

                    $set('actual_stock', $actual);
                    $set('missing_stock', $missing);
                })
                ->numeric()
                ->minValue(0)
                ->maxValue(fn(Get $get) => $get('current_stock')),

            TextInput::make('missing_stock')
                ->translateLabel()
                ->readOnly()
                ->numeric(),

            FileUpload::make('attachment')
                ->translateLabel()
                ->image()
                ->maxWidth(1024),
        ];
    }
}
