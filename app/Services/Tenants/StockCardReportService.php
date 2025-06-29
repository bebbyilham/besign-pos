<?php

namespace App\Services\Tenants;

use App\Models\Tenants\About;
use App\Models\Tenants\Product;
use App\Models\Tenants\Profile;
use App\Models\Tenants\Stock;
use App\Models\Tenants\SellingDetail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

class StockCardReportService
{
    public function generate(array $data)
    {
        $timezone = Profile::first()?->timezone ?? config('app.timezone', 'Asia/Jakarta');
        $about = About::first();

        $tzName = Carbon::parse($data['start_date'])->getTimezone()->getName();
        $startDate = Carbon::parse($data['start_date'], $timezone)->setTimezone('UTC');
        $endDate = Carbon::parse($data['end_date'], $timezone)->addDay()->setTimezone('UTC');

        $productId = $data['product_id'] ?? null;
        if (!$productId) {
            return ['error' => 'Produk harus dipilih'];
        }

        $product = Product::find($productId);

        // Header info
        $header = [
            'shop_name' => $about?->shop_name,
            'shop_location' => $about?->shop_location,
            'business_type' => $about?->business_type,
            'owner_name' => $about?->owner_name,
            'start_date' => $startDate->setTimezone($timezone)->format('d F Y'),
            'end_date' => $endDate->subDay()->setTimezone($timezone)->format('d F Y'),
            'product_name' => $product?->name ?? '-',
        ];

        $logs = collect();

        // Pembelian
        $logs = $logs->merge(
            Stock::with('product', 'purchasing')
                ->where('product_id', $productId)
                ->whereNotNull('purchasing_id')
                ->whereBetween('date', [$startDate, $endDate])
                ->get()
                ->map(function ($s) {
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
        $logs = $logs->merge(
            SellingDetail::with('product', 'selling')
                ->where('product_id', $productId)
                ->whereHas('selling', fn($q) => $q->whereBetween('date', [$startDate, $endDate]))
                ->get()
                ->map(function ($sd) {
                    return [
                        'tanggal' => $sd->selling->date,
                        'jenis_perubahan' => 'Keluar (Penjualan)',
                        'jumlah' => $sd->qty,
                        'sumber' => 'Penjualan - ' . ($sd->selling->code ?? '-'),
                        'waktu_input' => $sd->selling->created_at,
                    ];
                })
        );

        // Stock Opname
        $logs = $logs->merge(
            Stock::with('product')
                ->where('product_id', $productId)
                ->whereNotNull('type')
                ->whereBetween('date', [$startDate, $endDate])
                ->get()
                ->map(function ($s) {
                    return [
                        'tanggal' => $s->date,
                        'jenis_perubahan' => $s->type === 'in' ? 'Masuk (Stock Opname)' : 'Keluar (Stock Opname)',
                        // 'jumlah' => abs($s->stock - $s->init_stock),
                        'jumlah' => $s->init_stock,
                        'sumber' => 'Stock Opname',
                        'waktu_input' => $s->created_at,
                    ];
                })
        );

        // Sort & hitung stok akhir
        $stokAkhir = 0;
        $reports = $logs
            ->sortBy(fn($log) => $log['waktu_input']) // Hitung dulu dari urutan lama
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
            ->reverse(); // Baru balik tampilannya

        $footer = [];

        return [
            'header' => $header,
            'reports' => $reports,
            'footer' => $footer,
        ];
    }

    private function formatCurrency($value)
    {
        return Number::format($value);
    }
}
