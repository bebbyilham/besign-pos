<?php

namespace App\Services\Tenants;

use App\Models\Tenants\About;
use App\Models\Tenants\Product;
use App\Models\Tenants\Profile;
use App\Models\Tenants\Stock;
use App\Models\Tenants\SellingDetail;
use App\Models\Tenants\StockOpnameItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

class StockCardReportService
{
    public function generate(array $data)
    {
        $timezone = Profile::first()?->timezone ?? config('app.timezone', 'Asia/Jakarta');
        $about = About::first();
        $tzName = $timezone;

        $startDateInput = $data['start_date'] ?? null;
        $endDateInput = $data['end_date'] ?? null;

        $startDate = $startDateInput ? Carbon::parse($startDateInput, $timezone)->setTimezone('UTC') : null;
        $endDate = $endDateInput ? Carbon::parse($endDateInput, $timezone)->addDay()->setTimezone('UTC') : null;

        $productId = $data['product_id'] ?? null;
        if (!$productId) {
            return ['error' => 'Produk harus dipilih'];
        }

        $product = Product::find($productId);
        if (!$product) {
            return ['error' => 'Produk tidak ditemukan'];
        }

        // Ambil logs berdasarkan tanggal (jika ada)
        $logs = $this->ambilLogs($productId, $startDate, $endDate);

        // Jika tidak ada transaksi dalam rentang, ambil semua data
        if ($startDate && $endDate && $logs->isEmpty()) {
            $startDate = null;
            $endDate = null;
            $logs = $this->ambilLogs($productId, null, null);
        }

        // Header
        $header = [
            'shop_name' => $about?->shop_name,
            'shop_location' => $about?->shop_location,
            'business_type' => $about?->business_type,
            'owner_name' => $about?->owner_name,
            'start_date' => $startDate ? $startDate->copy()->setTimezone($tzName)->format('d F Y') : 'Semua data',
            'end_date' => $endDate ? $endDate->copy()->subDay()->setTimezone($tzName)->format('d F Y') : 'Semua data',
            'product_name' => $product->name,
        ];

        // Sort dan hitung stok
        $stokAkhir = 0;
        $reports = $logs
            ->sortBy(fn($log) => $log['waktu_input'])
            ->values()
            ->map(function ($log) use (&$stokAkhir, $tzName) {
                $stokAkhir += str_starts_with($log['jenis_perubahan'], 'Masuk') ? $log['jumlah'] : -$log['jumlah'];

                return [
                    'tanggal' => Carbon::parse($log['tanggal'])->setTimezone($tzName)->format('d F Y'),
                    'jenis_perubahan' => $log['jenis_perubahan'],
                    'jumlah' => $this->formatCurrency($log['jumlah']),
                    'sumber' => $log['sumber'],
                    'waktu_input' => Carbon::parse($log['waktu_input'])->setTimezone($tzName)->format('d F Y H:i'),
                    'stok_akhir' => $this->formatCurrency($stokAkhir),
                ];
            })
            ->reverse();

        return [
            'header' => $header,
            'reports' => $reports,
            'footer' => [],
        ];
    }

    private function ambilLogs($productId, $startDate = null, $endDate = null)
    {
        $logs = collect();

        // Pembelian
        $pembelian = Stock::with('product', 'purchasing')
            ->where('product_id', $productId)
            ->whereNotNull('purchasing_id');

        if ($startDate && $endDate) {
            $pembelian->whereBetween('date', [$startDate, $endDate]);
        }

        $logs = $logs->merge(
            $pembelian->get()->map(function ($s) {
                return [
                    'tanggal' => $s->date,
                    'jenis_perubahan' => 'Masuk (Pembelian)',
                    'jumlah' => $s->stock - $s->init_stock,
                    'sumber' => 'Pembelian - ' . ($s->purchasing->number ?? '-'),
                    'waktu_input' => $s->created_at,
                ];
            })
        );

        // Penjualan
        $penjualan = SellingDetail::with('product', 'selling')
            ->where('product_id', $productId);

        if ($startDate && $endDate) {
            $penjualan->whereHas('selling', fn($q) => $q->whereBetween('date', [$startDate, $endDate]));
        }

        $logs = $logs->merge(
            $penjualan->get()->map(function ($sd) {
                return [
                    'tanggal' => $sd->selling->date,
                    'jenis_perubahan' => 'Keluar (Penjualan)',
                    'jumlah' => $sd->qty,
                    'sumber' => 'Penjualan - ' . ($sd->selling->code ?? '-'),
                    'waktu_input' => $sd->selling->created_at,
                ];
            })
        );

        // Stock Opname (dari stock_opname_items)
        $opname = StockOpnameItem::where('product_id', $productId);

        if ($startDate && $endDate) {
            $opname->whereBetween('created_at', [$startDate, $endDate]);
        }

        $logs = $logs->merge(
            $opname->get()->map(function ($s) {
                $jenis = $s->missing_stock < 0 ? 'Masuk (Stock Opname)' : 'Keluar (Stock Opname)';

                return [
                    'tanggal' => $s->created_at,
                    'jenis_perubahan' => $jenis,
                    'jumlah' => abs($s->missing_stock),
                    'sumber' => 'Stock Opname',
                    'waktu_input' => $s->created_at,
                ];
            })
        );

        return $logs;
    }

    private function formatCurrency($value)
    {
        return Number::format($value);
    }
}
