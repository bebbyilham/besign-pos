<?php

namespace App\Services\Tenants;

use App\Models\Tenants\About;
use App\Models\Tenants\Product;
use App\Models\Tenants\Profile;
use App\Models\Tenants\Stock;
use App\Models\Tenants\SellingDetail;
use App\Models\Tenants\StockOpnameItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

class ProductReportService
{
    public function generate(array $data)
    {
        $profile = Profile::get();
        $timezone = $profile->timezone ?: config('app.timezone', 'UTC');
        $about = About::first();

        // Parse tanggal pakai timezone user, simpan query dalam UTC
        $startDate = Carbon::parse($data['start_date'], $timezone)->startOfDay()->setTimezone('UTC');
        $endDate   = Carbon::parse($data['end_date'], $timezone)->endOfDay()->setTimezone('UTC');

        $products = Product::all();

        // Mutasi sebelum periode
        $stockInBefore = Stock::select('product_id', DB::raw('SUM(init_stock) as total'))
            ->where('type', 'in')
            ->where('date', '<', $startDate)
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        $stockOutBefore = SellingDetail::select('product_id', DB::raw('SUM(qty) as total'))
            ->whereHas('selling', fn($q) => $q->where('date', '<', $startDate))
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        // Mutasi dalam periode
        $stockInPeriod = Stock::select('product_id', DB::raw('SUM(init_stock) as total'))
            ->where('type', 'in')
            ->whereBetween('date', [$startDate, $endDate])
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        $stockOutPeriod = SellingDetail::select('product_id', DB::raw('SUM(qty) as total'))
            ->whereHas('selling', fn($q) => $q->whereBetween('date', [$startDate, $endDate]))
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        // Opname terakhir sebelum periode
        $lastOpnameBefore = StockOpnameItem::where('created_at', '<', $startDate)
            ->orderBy('created_at', 'desc')
            ->get()
            ->unique('product_id')
            ->pluck('actual_stock', 'product_id');

        // Opname terakhir dalam periode
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

        // Ringkasan penjualan
        $sellingSummary = SellingDetail::select(
            'product_id',
            DB::raw('SUM(price * qty) as total_price'),
            DB::raw('SUM(cost * qty) as total_cost'),
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
            'total_after_discount' => 0,
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

            $totalBeforeDiscountPerSelling = (float) ($summary->total_price ?? 0);
            $totalCostPerSelling           = (float) ($summary->total_cost ?? 0);
            $totalDiscountPerItem          = (float) ($summary->total_discount ?? 0);
            $totalQtyPerSelling            = (float) ($summary->total_qty ?? 0);

            $totalAfterDiscountPerSelling  = $totalBeforeDiscountPerSelling - $totalDiscountPerItem;
            $totalGrossProfitPerSelling    = $totalBeforeDiscountPerSelling - $totalCostPerSelling;
            $totalNetProfitPerSelling      = $totalGrossProfitPerSelling - $totalDiscountPerItem;

            // --- Stok Awal ---
            if (isset($lastOpnameBefore[$pid])) {
                $stokAwal = (float) $lastOpnameBefore[$pid];
            } else {
                $stokAwal = (float) ($stockInBefore[$pid] ?? 0) - (float) ($stockOutBefore[$pid] ?? 0);
            }

            // --- Stok Akhir ---
            if (isset($lastOpnameInPeriod[$pid])) {
                $op = $lastOpnameInPeriod[$pid];
                $opDate = Carbon::parse($op['date'])->setTimezone('UTC');

                $stockInAfterOpname = (float) Stock::where('product_id', $pid)
                    ->where('type', 'in')
                    ->whereBetween('date', [$opDate, $endDate])
                    ->sum('init_stock');

                $stockOutAfterOpname = (float) SellingDetail::where('product_id', $pid)
                    ->whereHas('selling', fn($q) => $q->whereBetween('date', [$opDate, $endDate]))
                    ->sum('qty');

                $stokAkhir = (float) $op['actual'] + $stockInAfterOpname - $stockOutAfterOpname;
            } else {
                $stokAkhir = $stokAwal
                    + (float) ($stockInPeriod[$pid] ?? 0)
                    - (float) ($stockOutPeriod[$pid] ?? 0);
            }

            $saldoAkhir = $stokAkhir * (float) $product->initial_price;

            $reports[] = [
                'sku' => $product->sku,
                'name' => $product->name,
                'qty' => $totalQtyPerSelling,
                'selling' => $this->formatCurrency($totalBeforeDiscountPerSelling),
                'discount_price' => $this->formatCurrency($totalDiscountPerItem),
                'cost' => $this->formatCurrency($totalCostPerSelling),
                'total_after_discount' => $this->formatCurrency($totalAfterDiscountPerSelling),
                'gross_profit' => $this->formatCurrency($totalGrossProfitPerSelling),
                'net_profit' => $this->formatCurrency($totalNetProfitPerSelling),
                'ending_stock' => $stokAkhir,
                'ending_stock_balance' => $this->formatCurrency($saldoAkhir),
            ];

            // --- Akumulasi footer ---
            $footer['total_cost'] += $totalCostPerSelling;
            $footer['total_gross'] += $totalBeforeDiscountPerSelling;
            $footer['total_net'] += $totalAfterDiscountPerSelling;
            $footer['total_after_discount'] += $totalAfterDiscountPerSelling;
            $footer['total_discount'] += $totalDiscountPerItem;
            $footer['total_gross_profit'] += $totalGrossProfitPerSelling;
            $footer['total_net_profit_before_discount_selling'] += $totalGrossProfitPerSelling;
            $footer['total_net_profit_after_discount_selling'] += $totalNetProfitPerSelling;
            $footer['total_qty'] += $totalQtyPerSelling;
            $footer['total_ending_stock'] += $stokAkhir;
            $footer['total_ending_stock_balance'] += $saldoAkhir;
        }

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
