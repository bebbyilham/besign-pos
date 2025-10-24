<?php

namespace App\Services\Tenants;

use App\Models\Tenants\About;
use App\Models\Tenants\Profile;
use App\Models\Tenants\Product;
use App\Models\Tenants\Stock;
use App\Models\Tenants\SellingDetail;
use App\Models\Tenants\StockOpnameItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Support\Facades\Log;

class ProductReportService
{
    private const DEFAULT_TIMEZONE = 'Asia/Jakarta';

    public function generate(array $data)
    {
        try {
            $this->validateInput($data);

            $profile = Profile::get();
            $timezone = config('app.timezone', self::DEFAULT_TIMEZONE);
            $about = About::first();

            $startDate = Carbon::parse($data['start_date'])->startOfDay();
            $endDate = Carbon::parse($data['end_date'])->endOfDay();

            // Get all products
            $products = Product::all();

            $reports = [];
            $footer = $this->initializeFooter();

            foreach ($products as $product) {
                // Hitung stok awal dengan mensimulasikan transaksi
                $stokAwal = $this->hitungStokAwal($product->id, $startDate);

                // Ambil data transaksi dalam periode
                $transaksiPeriode = $this->getTransaksiPeriode($product->id, $startDate, $endDate);

                // Hitung stok akhir
                $mutasi = $transaksiPeriode['pembelian_qty'] - $transaksiPeriode['penjualan_qty'];
                $stokAkhir = $stokAwal + $mutasi;

                // Hitung saldo
                $endingStockBalance = $stokAkhir * $product->initial_price;
                $endingStockBalanceSell = $stokAkhir * $product->selling_price;

                // Format row
                $reportRow = [
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'initial_price' => $this->formatCurrency($product->initial_price),
                    'selling_price' => $this->formatCurrency($product->selling_price),
                    'beginning_stock' => $stokAwal,
                    'mutation' => $mutasi,
                    'ending_stock' => $stokAkhir,
                    'qty' => $transaksiPeriode['penjualan_qty'],
                    'selling' => $this->formatCurrency($transaksiPeriode['penjualan_bruto']),
                    'discount_price' => $this->formatCurrency($transaksiPeriode['total_diskon']),
                    'cost' => $this->formatCurrency($transaksiPeriode['total_cost']),
                    'total_after_discount' => $this->formatCurrency($transaksiPeriode['penjualan_bruto'] - $transaksiPeriode['total_diskon']),
                    'gross_profit' => $this->formatCurrency($transaksiPeriode['penjualan_bruto'] - $transaksiPeriode['total_cost']),
                    'net_profit' => $this->formatCurrency($transaksiPeriode['penjualan_bruto'] - $transaksiPeriode['total_cost'] - $transaksiPeriode['total_diskon']),
                    'ending_stock_balance' => $this->formatCurrency($endingStockBalance),
                    'ending_stock_balance_sell' => $this->formatCurrency($endingStockBalanceSell),
                    'purchase_qty' => $transaksiPeriode['pembelian_qty'],
                    'purchase_total' => $this->formatCurrency($transaksiPeriode['pembelian_total']),
                    'total_money_product' => $this->formatCurrency($transaksiPeriode['penjualan_bruto'] + $endingStockBalanceSell),
                ];

                $reports[] = $reportRow;

                // Update footer
                $footer['total_cost'] += $transaksiPeriode['total_cost'];
                $footer['total_gross'] += $transaksiPeriode['penjualan_bruto'];
                $footer['total_net'] += ($transaksiPeriode['penjualan_bruto'] - $transaksiPeriode['total_diskon']);
                $footer['total_discount'] += $transaksiPeriode['total_diskon'];
                $footer['total_gross_profit'] += ($transaksiPeriode['penjualan_bruto'] - $transaksiPeriode['total_cost']);
                $footer['total_net_profit_before_discount_selling'] += ($transaksiPeriode['penjualan_bruto'] - $transaksiPeriode['total_cost']);
                $footer['total_net_profit_after_discount_selling'] += ($transaksiPeriode['penjualan_bruto'] - $transaksiPeriode['total_cost'] - $transaksiPeriode['total_diskon']);
                $footer['total_qty'] += $transaksiPeriode['penjualan_qty'];
                $footer['total_ending_stock'] += $stokAkhir;
                $footer['total_ending_stock_balance'] += $endingStockBalance;
                $footer['total_ending_stock_balance_sell'] += $endingStockBalanceSell;
                $footer['total_pembelian'] += $transaksiPeriode['pembelian_total'];
                $footer['total_money_product'] += ($transaksiPeriode['penjualan_bruto'] + $endingStockBalanceSell);
            }

            $this->formatFooter($footer);

            return [
                'reports' => $reports,
                'footer' => $footer,
                'header' => $this->formatHeader($about, $startDate, $endDate, $timezone),
            ];
        } catch (\Exception $e) {
            Log::error('Product Report Generation Failed', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            throw $e;
        }
    }

    private function validateInput(array $data): void
    {
        if (empty($data['start_date']) || empty($data['end_date'])) {
            throw new \InvalidArgumentException('Start date and end date are required');
        }

        $startDate = Carbon::parse($data['start_date']);
        $endDate = Carbon::parse($data['end_date']);

        if ($endDate->lt($startDate)) {
            throw new \InvalidArgumentException('End date must be after start date');
        }
    }

    /**
     * Hitung stok awal dengan mensimulasikan semua transaksi sebelum periode
     * Mengikuti logika StockCardReportService
     */
    private function hitungStokAwal($productId, Carbon $startDate)
    {
        // Ambil stok masuk pertama sebagai stok awal
        $stokAwalRow = Stock::where('product_id', $productId)
            ->where('type', 'in')
            ->orderBy('date')
            ->orderBy('created_at')
            ->first();

        if (!$stokAwalRow) {
            return 0;
        }

        $stokAkhir = $stokAwalRow->init_stock;

        // Ambil semua transaksi sebelum startDate (kecuali stok awal pertama)
        $logs = collect();

        // 1. Pembelian sebelum periode
        $pembelian = Stock::where('product_id', $productId)
            ->whereNotNull('purchasing_id')
            ->where('date', '<', $startDate->toDateTimeString())
            ->orderBy('created_at')
            ->get();

        foreach ($pembelian as $p) {
            $logs->push([
                'waktu_input' => $p->created_at,
                'jenis' => 'masuk',
                'jumlah' => $p->init_stock,
            ]);
        }

        // 2. Penjualan sebelum periode
        $penjualan = SellingDetail::whereHas('selling', function ($q) use ($startDate) {
            $q->where('date', '<', $startDate->toDateTimeString());
        })
            ->where('product_id', $productId)
            ->with('selling')
            ->get();

        foreach ($penjualan as $sd) {
            $logs->push([
                'waktu_input' => $sd->selling->created_at,
                'jenis' => 'keluar',
                'jumlah' => $sd->qty,
            ]);
        }

        // 3. Stock Opname sebelum periode
        $opname = StockOpnameItem::where('product_id', $productId)
            ->where('created_at', '<', $startDate->toDateTimeString())
            ->orderBy('created_at')
            ->get();

        foreach ($opname as $o) {
            $logs->push([
                'waktu_input' => $o->created_at,
                'jenis' => 'opname',
                'stok_set' => $o->actual_stock,
            ]);
        }

        // Sort berdasarkan waktu input dan simulasikan
        $logs = $logs->sortBy('waktu_input');

        foreach ($logs as $log) {
            if (isset($log['stok_set'])) {
                // Stock Opname: set langsung ke nilai actual_stock
                $stokAkhir = $log['stok_set'];
            } else {
                // Pembelian/Penjualan: tambah/kurang
                if ($log['jenis'] === 'masuk') {
                    $stokAkhir += $log['jumlah'];
                } else {
                    $stokAkhir -= $log['jumlah'];
                }
            }
        }

        return $stokAkhir;
    }

    /**
     * Ambil transaksi dalam periode
     */
    private function getTransaksiPeriode($productId, Carbon $startDate, Carbon $endDate)
    {
        $result = [
            'pembelian_qty' => 0,
            'pembelian_total' => 0,
            'penjualan_qty' => 0,
            'penjualan_bruto' => 0,
            'total_cost' => 0,
            'total_diskon' => 0,
        ];

        // Pembelian dalam periode
        $pembelian = Stock::where('product_id', $productId)
            ->whereNotNull('purchasing_id')
            ->whereBetween('date', [$startDate->toDateTimeString(), $endDate->toDateTimeString()])
            ->get();

        foreach ($pembelian as $p) {
            $result['pembelian_qty'] += $p->init_stock;
            $result['pembelian_total'] += ($p->init_stock * $p->product->initial_price);
        }

        // Penjualan dalam periode
        $penjualan = SellingDetail::whereHas('selling', function ($q) use ($startDate, $endDate) {
            $q->whereBetween('date', [$startDate->toDateTimeString(), $endDate->toDateTimeString()]);
        })
            ->where('product_id', $productId)
            ->get();

        foreach ($penjualan as $sd) {
            $result['penjualan_qty'] += $sd->qty;
            $result['penjualan_bruto'] += $sd->price;
            $result['total_cost'] += $sd->cost;
            $result['total_diskon'] += $sd->discount_price;
        }

        return $result;
    }

    private function initializeFooter(): array
    {
        return [
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
            'total_ending_stock_balance_sell' => 0,
            'total_pembelian' => 0,
            'total_money_product' => 0,
        ];
    }

    private function formatFooter(array &$footer): void
    {
        $currencyFields = [
            'total_cost',
            'total_gross',
            'total_net',
            'total_discount',
            'total_gross_profit',
            'total_net_profit_before_discount_selling',
            'total_net_profit_after_discount_selling',
            'total_ending_stock_balance',
            'total_ending_stock_balance_sell',
            'total_pembelian',
            'total_money_product'
        ];

        foreach ($currencyFields as $field) {
            $footer[$field] = $this->formatCurrency($footer[$field]);
        }
    }

    private function formatHeader($about, Carbon $startDate, Carbon $endDate, string $timezone): array
    {
        return [
            'shop_name' => $about?->shop_name,
            'shop_location' => $about?->shop_location,
            'business_type' => $about?->business_type,
            'owner_name' => $about?->owner_name,
            'start_date' => $startDate->setTimezone($timezone)->format('d F Y'),
            'end_date' => $endDate->setTimezone($timezone)->format('d F Y'),
        ];
    }

    private function formatCurrency($value): string
    {
        return 'Rp ' . Number::format($value, 0);
    }
}
