<?php

namespace App\Services\Tenants;

use App\Models\Tenants\About;
use App\Models\Tenants\Product;
use App\Models\Tenants\Profile;
use App\Models\Tenants\SellingDetail;
use App\Models\Tenants\Stock;
use App\Models\Tenants\StockOpnameItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

class ProductReportService
{
    public function generate(array $data)
    {
        $profile = Profile::get();
        $timezone = $profile->timezone ?: config('app.timezone', 'Asia/Jakarta');
        $about = About::first();

        // Pastikan tanggal query pakai UTC, output pakai timezone user
        $startDate = Carbon::parse($data['start_date'], $timezone)->startOfDay()->setTimezone('UTC');
        $endDate   = Carbon::parse($data['end_date'], $timezone)->endOfDay()->setTimezone('UTC');

        $products = Product::all();

        // === Mutasi stok sebelum & selama periode ===
        $stockInBefore = Stock::select('product_id', DB::raw('SUM(init_stock) as total'))
            ->where('type', 'in')
            ->where('date', '<', $startDate)
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        $stockInPeriod = Stock::select('product_id', DB::raw('SUM(init_stock) as total'))
            ->where('type', 'in')
            ->whereBetween('date', [$startDate, $endDate])
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        $stockOutBefore = SellingDetail::select('product_id', DB::raw('SUM(qty) as total'))
            ->whereHas('selling', fn($q) => $q->where('date', '<', $startDate))
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        $stockOutPeriod = SellingDetail::select('product_id', DB::raw('SUM(qty) as total'))
            ->whereHas('selling', fn($q) => $q->whereBetween('date', [$startDate, $endDate]))
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        // === Opname ===
        $lastOpnameBefore = StockOpnameItem::where('created_at', '<', $startDate)
            ->orderBy('created_at', 'desc')
            ->get()
            ->unique('product_id')
            ->pluck('actual_stock', 'product_id');

        $lastOpnameInPeriod = StockOpnameItem::whereBetween('created_at', [$startDate, $endDate])
            ->orderBy('created_at', 'desc')
            ->get()
            ->unique('product_id')
            ->mapWithKeys(fn($item) => [
                $item->product_id => [
                    'actual' => $item->actual_stock,
                    'date'   => $item->created_at,
                ]
            ]);

        $opnamePeriodAdj = StockOpnameItem::select('product_id', DB::raw('SUM(actual_stock - current_stock) as adj'))
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('product_id')
            ->pluck('adj', 'product_id');

        // === Ringkasan penjualan per produk ===
        $sellingSummary = SellingDetail::select(
            'product_id',
            DB::raw('SUM(price * qty) as total_price'),
            DB::raw('SUM(COALESCE(cost,0) * qty) as total_cost'),
            DB::raw('SUM(qty) as total_qty'),
            DB::raw('SUM(discount_price) as total_discount')
        )
            ->whereHas('selling', fn($q) => $q->whereBetween('date', [$startDate, $endDate]))
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $reports = [];
        $footer = [
            'total_cost' => 0,
            'total_gross' => 0,
            'total_net' => 0,
            'total_discount' => 0,
            'total_gross_profit' => 0,
            'total_net_profit_before_discount_selling' => 0,
            'total_net_profit_after_discount_selling' => 0,
            'total_qty' => 0,
            'total_ending_stock' => 0,
            'total_ending_stock_balance' => 0,
        ];

        foreach ($products as $product) {
            $pid = $product->id;
            $summary = $sellingSummary->get($pid);

            $totalBruto   = (float) ($summary->total_price ?? 0);
            $totalCost    = (float) ($summary->total_cost ?? 0);
            $totalDiskon  = (float) ($summary->total_discount ?? 0);
            $totalQty     = (float) ($summary->total_qty ?? 0);

            // fallback jika cost kosong
            if ($totalCost == 0 && $totalQty > 0) {
                $totalCost = $totalQty * (float) $product->initial_price;
            }

            $totalNetto   = $totalBruto - $totalDiskon;
            $grossProfit  = $totalBruto - $totalCost;
            $netProfit    = $grossProfit - $totalDiskon;

            // --- Stok awal ---
            if (isset($lastOpnameBefore[$pid])) {
                $stokAwal = (float) $lastOpnameBefore[$pid];
            } else {
                $stokAwal = (float) ($stockInBefore[$pid] ?? 0) - (float) ($stockOutBefore[$pid] ?? 0);
            }

            // --- Stok akhir ---
            if (isset($lastOpnameInPeriod[$pid])) {
                $op = $lastOpnameInPeriod[$pid];
                $opDate = Carbon::parse($op['date'])->setTimezone('UTC');

                $stockInAfter = (float) Stock::where('product_id', $pid)
                    ->where('type', 'in')
                    ->whereBetween('date', [$opDate, $endDate])
                    ->sum('init_stock');

                $stockOutAfter = (float) SellingDetail::where('product_id', $pid)
                    ->whereHas('selling', fn($q) => $q->whereBetween('date', [$opDate, $endDate]))
                    ->sum('qty');

                $stokAkhir = (float) $op['actual'] + $stockInAfter - $stockOutAfter;
            } else {
                $stokAkhir = $stokAwal
                    + (float) ($stockInPeriod[$pid] ?? 0)
                    - (float) ($stockOutPeriod[$pid] ?? 0)
                    + (float) ($opnamePeriodAdj[$pid] ?? 0);
            }

            $saldoAkhir = $stokAkhir * (float) $product->initial_price;

            $reports[] = [
                'sku' => $product->sku,
                'name' => $product->name,
                'initial_price' => $this->formatCurrency($product->initial_price),
                'selling_price' => $this->formatCurrency($product->selling_price),
                'qty' => $totalQty,
                'selling' => $this->formatCurrency($totalBruto),
                'discount_price' => $this->formatCurrency($totalDiskon),
                'cost' => $this->formatCurrency($totalCost),
                'total_after_discount' => $this->formatCurrency($totalNetto),
                'gross_profit' => $this->formatCurrency($grossProfit),
                'net_profit' => $this->formatCurrency($netProfit),
                'ending_stock' => $stokAkhir,
                'ending_stock_balance' => $this->formatCurrency($saldoAkhir),
            ];

            // --- Footer akumulasi ---
            $footer['total_cost'] += $totalCost;
            $footer['total_gross'] += $totalBruto;
            $footer['total_net'] += $totalNetto;
            $footer['total_discount'] += $totalDiskon;
            $footer['total_gross_profit'] += $grossProfit;
            $footer['total_net_profit_before_discount_selling'] += $grossProfit;
            $footer['total_net_profit_after_discount_selling'] += $netProfit;
            $footer['total_qty'] += $totalQty;
            $footer['total_ending_stock'] += $stokAkhir;
            $footer['total_ending_stock_balance'] += $saldoAkhir;
        }

        // format footer angka
        foreach (['total_cost', 'total_gross', 'total_net', 'total_discount', 'total_gross_profit', 'total_net_profit_before_discount_selling', 'total_net_profit_after_discount_selling', 'total_ending_stock_balance'] as $key) {
            $footer[$key] = $this->formatCurrency($footer[$key]);
        }

        return [
            'reports' => $reports,
            'footer' => $footer,
            'header' => [
                'shop_name' => $about?->shop_name,
                'shop_location' => $about?->shop_location,
                'business_type' => $about?->business_type,
                'owner_name' => $about?->owner_name,
                'start_date' => $startDate->setTimezone($timezone)->format('d F Y'),
                'end_date' => $endDate->setTimezone($timezone)->format('d F Y'),
            ],
        ];
    }

    private function formatCurrency($value)
    {
        return Number::format($value);
    }
}
