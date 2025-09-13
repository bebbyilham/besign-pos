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

        // Parse user dates WITH user timezone, then convert to UTC for DB queries
        $startDate = Carbon::parse($data['start_date'], $timezone)->startOfDay()->setTimezone('UTC');
        $endDate   = Carbon::parse($data['end_date'], $timezone)->endOfDay()->setTimezone('UTC');

        // load products (if sangat banyak, ganti ->all() dengan ->cursor())
        $products = Product::all();

        // ambil mutasi stok (grouped) — tanggal sudah UTC
        $stockInBefore = Stock::select('product_id', DB::raw('SUM(stock) as total'))
            ->where('type', 'in')
            ->where('date', '<', $startDate)
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        $stockInPeriod = Stock::select('product_id', DB::raw('SUM(stock) as total'))
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

        // Jika tidak ada opname, ini masih berguna sebagai "penyesuaian period"
        $opnamePeriod = StockOpnameItem::select('product_id', DB::raw('SUM(actual_stock - current_stock) as adj'))
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('product_id')
            ->pluck('adj', 'product_id');

        // ambil opname terakhir sebelum startDate (dipakai sebagai stok awal override)
        $lastOpnameBefore = StockOpnameItem::where('created_at', '<', $startDate)
            ->orderBy('created_at', 'desc')
            ->get()
            ->unique('product_id') // keep latest per product
            ->pluck('actual_stock', 'product_id');

        // ambil opname terakhir di dalam periode (dipakai sbg base untuk ending stock jika ada)
        $lastOpnameInPeriod = StockOpnameItem::whereBetween('created_at', [$startDate, $endDate])
            ->orderBy('created_at', 'desc')
            ->get()
            ->unique('product_id')
            ->mapWithKeys(function ($item) {
                return [$item->product_id => [
                    'actual' => $item->actual_stock,
                    'date' => $item->created_at,
                ]];
            });

        // penjualan summary untuk periode (dipakai untuk laporan revenue/cost)
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
            'total_discount_per_item' => 0,
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

            // ----- stok: buka opsi override oleh opname terakhir sebelum periode -----
            if (isset($lastOpnameBefore[$pid])) {
                $stokAwal = (float) $lastOpnameBefore[$pid];
            } else {
                $stokAwal = (float) ($stockInBefore[$pid] ?? 0) - (float) ($stockOutBefore[$pid] ?? 0);
            }

            // Jika ada opname di periode, gunakan opname terakhir di periode sebagai base untuk ending stock
            if (isset($lastOpnameInPeriod[$pid])) {
                $op = $lastOpnameInPeriod[$pid];
                $opDate = Carbon::parse($op['date'])->setTimezone('UTC');

                // ambil mutasi setelah opname terakhir sampai endDate (per-product query; biasanya sedikit)
                $stockInAfterOpname = (float) Stock::where('product_id', $pid)
                    ->where('type', 'in')
                    ->whereBetween('date', [$opDate, $endDate])
                    ->sum('stock');

                $stockOutAfterOpname = (float) SellingDetail::where('product_id', $pid)
                    ->whereHas('selling', fn($q) => $q->whereBetween('date', [$opDate, $endDate]))
                    ->sum('qty');

                $stokAkhir = (float) $op['actual'] + $stockInAfterOpname - $stockOutAfterOpname;
            } else {
                // tidak ada opname di periode -> normal calc + penyesuaian opname (jika ada)
                $stokAkhir = $stokAwal
                    + (float) ($stockInPeriod[$pid] ?? 0)
                    - (float) ($stockOutPeriod[$pid] ?? 0)
                    + (float) ($opnamePeriod[$pid] ?? 0);
            }

            $saldoAkhir = (float) $stokAkhir * (float) $product->initial_price;

            // Simpan raw + formatted (lebih fleksibel untuk export/view)
            $reports[] = [
                'sku' => $product->sku,
                'initial_price_raw' => (float) $product->initial_price,
                'initial_price' => $this->formatCurrency((float) $product->initial_price),
                'selling_price_raw' => (float) $product->selling_price,
                'selling_price' => $this->formatCurrency((float) $product->selling_price),
                'name' => $product->name,
                'qty_raw' => $totalQtyPerSelling,
                'qty' => $totalQtyPerSelling,
                'selling_price_total_raw' => $totalBeforeDiscountPerSelling,
                'selling' => $this->formatCurrency($totalBeforeDiscountPerSelling),
                'discount_price_raw' => $totalDiscountPerItem,
                'discount_price' => $this->formatCurrency($totalDiscountPerItem),
                'cost_raw' => $totalCostPerSelling,
                'cost' => $this->formatCurrency($totalCostPerSelling),
                'total_after_discount_raw' => $totalAfterDiscountPerSelling,
                'total_after_discount' => $this->formatCurrency($totalAfterDiscountPerSelling),
                'gross_profit_raw' => $totalGrossProfitPerSelling,
                'gross_profit' => $this->formatCurrency($totalGrossProfitPerSelling),
                'net_profit_raw' => $totalNetProfitPerSelling,
                'net_profit' => $this->formatCurrency($totalNetProfitPerSelling),
                'ending_stock' => $stokAkhir,
                'ending_stock_balance_raw' => $saldoAkhir,
                'ending_stock_balance' => $this->formatCurrency($saldoAkhir),
            ];

            // akumulasi footer (raw)
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

        // format footer tampil
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
                // format kembali pakai timezone user
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
