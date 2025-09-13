<?php

namespace App\Services\Tenants;

use App\Models\Tenants\Product;
use App\Models\Tenants\Purchasing;
use App\Models\Tenants\Setting;
use App\Models\Tenants\Stock;

class StockService
{
    const SELLING_NORMAL = 'normal';
    const SELLING_FIFO   = 'fifo';
    const SELLING_LIFO   = 'lifo';

    /**
     * Ambil stok sesuai metode penjualan (FIFO/LIFO/Normal).
     * 🔑 Perubahan: tidak lagi pakai where('stock', '>', 0)
     * supaya stok yang sudah ada meskipun 0 tetap bisa dipakai untuk update.
     */
    // private function adjustStockPrepare(Product $product): ?Stock
    // {
    //     $method = Setting::get('selling_method', env('SELLING_METHOD', self::SELLING_FIFO));

    //     if ($method === self::SELLING_NORMAL) {
    //         return $product->stocks()
    //             ->orderBy('date', 'asc')
    //             ->first();
    //     }

    //     if ($method === self::SELLING_FIFO) {
    //         return $product->stocks()
    //             ->orderBy('date', 'asc') // stok lama keluar dulu
    //             ->first();
    //     }

    //     if ($method === self::SELLING_LIFO) {
    //         return $product->stocks()
    //             ->orderBy('date', 'desc') // stok baru keluar dulu
    //             ->first();
    //     }

    //     return $product->stockLatestCalculateIn()->first();
    // }

    private function adjustStockPrepare(Product $product): ?Stock
    {
        if (Setting::get('selling_method', env('SELLING_METHOD', 'fifo')) == 'normal') {
            $lastStock = $product
                ->stocks()
                ->where('stock', '>', 0)
                ->orderBy('date', 'asc')
                ->first();
        } else {
            $lastStock = $product->stockLatestCalculateIn()->first();
        }

        return $lastStock;
    }

    /**
     * Tambah stok produk.
     */
    public function addStock(Product $product, int $qty): void
    {
        $lastStock = $this->adjustStockPrepare($product);

        if ($lastStock) {
            // kalau sudah ada stok meski 0 → update record lama
            $lastStock->stock += $qty;
            $lastStock->save();
        } else {
            // kalau benar-benar belum ada record stok → buat baru
            $stock = new Stock();
            $stock->product()->associate($product);
            $stock->date = now();
            $stock->init_stock = $qty;
            $stock->stock = $qty;
            $stock->save();
        }

        // 🔥 sinkronkan total ke product->stock
        $product->stock = $product->stocks()->sum('stock');
        $product->save();

        \Log::info('StockService:addStock', [
            'product_id'   => $product->id,
            'added_qty'    => $qty,
            'total_stocks' => $product->stocks()->sum('stock'),
            'product_stock_field' => $product->stock,
        ]);
    }

    /**
     * Kurangi stok produk.
     */
    public function reduceStock(Product $product, int $qty): void
    {
        while ($qty > 0) {
            $lastStock = $this->adjustStockPrepare($product);

            if (!$lastStock) {
                // fallback langsung kurangi product
                $product->stock = max(0, $product->stock - $qty);
                $product->save();
                break;
            }

            if ($lastStock->stock <= $qty) {
                $qty -= $lastStock->stock;
                $lastStock->stock = 0;
            } else {
                $lastStock->stock -= $qty;
                $qty = 0;
            }

            $lastStock->save();
        }

        // 🔥 update total stok product dari tabel stocks
        $product->stock = $product->stocks()->sum('stock');
        $product->save();

        \Log::info('StockService:reduceStock', [
            'product_id'   => $product->id,
            'product_stock_field' => $product->stock,
            'total_stocks' => $product->stocks()->sum('stock'),
        ]);
    }

    /**
     * Buat record stok baru.
     */
    public function create(array $data, ?Purchasing $purchasing = null): Stock
    {
        $data['stock'] = $data['stock'] ?? 0;
        $data['date'] = $data['date'] ?? now();
        $data['init_stock'] = $data['stock'];

        $stock = new Stock();
        $stock->fill($data);
        $stock->product()->associate(Product::findOrFail($data['product_id']));

        if ($purchasing) {
            $stock->purchasing()->associate($purchasing);
        }

        $stock->save();

        // sinkron ke field product->stock
        $stock->product->stock = $stock->product->stocks()->sum('stock');
        $stock->product->save();

        return $stock;
    }

    /**
     * Update record stok.
     */
    public function update(Stock $stock, array $data, ?Purchasing $purchasing = null): void
    {
        // init_stock tidak diubah saat update
        unset($data['init_stock']);

        $stock->fill($data);
        $stock->product()->associate(Product::findOrFail($data['product_id']));

        if ($purchasing) {
            $stock->purchasing()->associate($purchasing);
        }

        $stock->save();

        // sinkron ke field product->stock
        $stock->product->stock = $stock->product->stocks()->sum('stock');
        $stock->product->save();
    }
}
