<?php

namespace App\Services\Tenants;

use App\Constants\StockOpnameStatus;
use App\Events\RecalculateEvent;
use App\Models\Tenants\Product;
use App\Models\Tenants\StockOpname;
use App\Models\Tenants\StockOpnameItem;
use App\Services\Tenants\Traits\HasNumber;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class StockOpnameService
{
    use HasNumber;

    protected string $model = StockOpname::class;

    private StockService $stockService;

    public function __construct(StockService $stockService)
    {
        $this->stockService = $stockService;
    }

    public function create(array $data): StockOpname
    {
        return DB::transaction(function () use ($data) {
            $stockOpname = new StockOpname();
            $stockOpname->fill($data);
            $stockOpname->save();

            return $stockOpname;
        });
    }

    public function update(StockOpname $stockOpname, array $data): StockOpname
    {
        return DB::transaction(function () use ($stockOpname, $data) {
            $stockOpname->fill($data);
            $stockOpname->save();

            return $stockOpname;
        });
    }

    public function delete(StockOpname $stockOpname): void
    {
        DB::transaction(function () use ($stockOpname) {
            // rollback stok hanya jika opname sudah disetujui
            if ($stockOpname->status === StockOpnameStatus::approved) {
                $stockOpname->stockOpnameItems->each(function (StockOpnameItem $sOItem) {
                    // kembalikan stok ke nilai sebelum opname
                    $sOItem->product->stock += $sOItem->actual_stock;
                    $sOItem->product->save();
                });
            }

            $stockOpname->delete();
        });
    }

    public function updateStatus(StockOpname $so, string $status): bool
    {
        return DB::transaction(function () use ($so, $status) {
            $so->status = $status;

            if ($status === StockOpnameStatus::approved) {
                $so->approved_at = now();

                if ($so->stockOpnameItems->isEmpty()) {
                    Notification::make()
                        ->title(__('Stock Opname Item is required'))
                        ->warning()
                        ->send();

                    return false; // gagal update status
                }

                foreach ($so->stockOpnameItems as $soItem) {
                    if ($soItem->missing_stock < 0) {
                        $this->stockService->addStock($soItem->product, abs($soItem->missing_stock));
                    } elseif ($soItem->missing_stock > 0) {
                        $this->stockService->reduceStock($soItem->product, $soItem->missing_stock);
                    }
                }

                RecalculateEvent::dispatch(
                    Product::whereIn('id', $so->stockOpnameItems->pluck('product_id')->unique())->get(),
                    []
                );
            }

            $so->save();
            return true;
        });
    }
}
