<?php

namespace App\Services\Tenants;

use App\Models\Tenants\About;
use App\Models\Tenants\Profile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

class ProductReportService
{
    public function generate(array $data): array
    {
        $timezone  = 'Asia/Jakarta';
        $about     = About::first();
        $startDate = Carbon::parse($data['start_date'])->startOfDay();
        $endDate   = Carbon::parse($data['end_date'])->endOfDay();

        $startDateSql = $startDate->toDateTimeString();
        $endDateSql = $endDate->toDateTimeString();

        $rows = DB::table('products as p')
            ->select([
                'p.id',
                'p.sku',
                'p.name',
                'p.initial_price',
                'p.selling_price',

                // 1. STOK AWAL: Prioritas SO (lo) HANYA jika TIDAK ADA mutasi_awal
                DB::raw("
                CASE 
                    WHEN lo.actual_stock IS NOT NULL AND mutasi_awal.has_mutations IS NULL 
                        THEN lo.actual_stock 
                    ELSE (COALESCE(ib.total_in,0) - COALESCE(ob_stock.total_out,0))
                END as stok_awal
                "),

                // 2. MUTASI PERIODE (Stok Masuk - Stok Keluar TOTAL dalam periode laporan)
                DB::raw("(COALESCE(ip.total_in,0) - COALESCE(outp_stock.total_out,0)) as mutasi"),

                // 3. STOK AKHIR: Stok Awal + Mutasi Periode
                DB::raw("
                (
                    CASE 
                        WHEN lo.actual_stock IS NOT NULL AND mutasi_awal.has_mutations IS NULL 
                            THEN lo.actual_stock
                        ELSE (COALESCE(ib.total_in,0) - COALESCE(ob_stock.total_out,0))
                    END
                ) + (COALESCE(ip.total_in,0) - COALESCE(outp_stock.total_out,0)) as stok_akhir
                "),

                // 4. TRANSAKSI PENJUALAN (Untuk nominal dan laba)
                DB::raw("COALESCE(op.total_out,0) as qty"),
                DB::raw("COALESCE(op.total_price,0) as penjualan_bruto"),
                DB::raw("COALESCE(op.total_cost,0) as total_cost"),
                DB::raw("COALESCE(op.total_discount,0) as total_diskon"),
                DB::raw("(COALESCE(op.total_price,0) - COALESCE(op.total_discount,0)) as total_after_discount"),
                DB::raw("(COALESCE(op.total_price,0) - COALESCE(op.total_cost,0)) as laba_kotor"),
                DB::raw("(COALESCE(op.total_price,0) - COALESCE(op.total_cost,0) - COALESCE(op.total_discount,0)) as laba_bersih"),

                // 5. SALDO AKHIR MODAL & JUAL (menggunakan kolom stok_akhir)
                DB::raw("((
                    CASE 
                        WHEN lo.actual_stock IS NOT NULL AND mutasi_awal.has_mutations IS NULL 
                            THEN lo.actual_stock
                        ELSE (COALESCE(ib.total_in,0) - COALESCE(ob_stock.total_out,0))
                    END
                ) + (COALESCE(ip.total_in,0) - COALESCE(outp_stock.total_out,0))) * p.initial_price
                ) as saldo_akhir"),
                DB::raw("((
                    CASE 
                        WHEN lo.actual_stock IS NOT NULL AND mutasi_awal.has_mutations IS NULL 
                            THEN lo.actual_stock
                        ELSE (COALESCE(ib.total_in,0) - COALESCE(ob_stock.total_out,0))
                    END
                ) + (COALESCE(ip.total_in,0) - COALESCE(outp_stock.total_out,0))) * p.selling_price
                ) as saldo_akhir_jual"),

                // 6. PEMBELIAN
                DB::raw("COALESCE(pb.total_in,0) as qty_pembelian"),
                DB::raw("COALESCE(pb.total_purchase,0) as pembelian_bruto")
            ])

            // ---------------------------------------------------------------------
            // JOIN SEBELUM PERIODE
            // ---------------------------------------------------------------------

            // lo: Stok Opname terakhir sebelum periode (LAST OPNAME)
            ->leftJoin(DB::raw("(SELECT product_id, actual_stock, created_at as so_date
                FROM (
                    SELECT product_id, actual_stock, created_at,
                        ROW_NUMBER() OVER (PARTITION BY product_id ORDER BY created_at DESC) as rn
                    FROM stock_opname_items
                    WHERE created_at < '{$startDateSql}'
                ) t
                WHERE rn = 1
            ) lo"), 'lo.product_id', '=', 'p.id')

            // mutasi_awal: Cek apakah ada mutasi (transaksi) antara SO terakhir (lo.so_date) dan start_date
            // Kita hanya perlu mengetahui keberadaannya, bukan jumlah mutasinya.
            ->leftJoin(DB::raw("
                SELECT 
                    s.product_id, 
                    1 as has_mutations -- Flag untuk menandakan adanya transaksi
                FROM stocks s
                JOIN (
                    SELECT product_id, MAX(so_date) as latest_so_date
                    FROM (
                        SELECT product_id, created_at as so_date,
                            ROW_NUMBER() OVER (PARTITION BY product_id ORDER BY created_at DESC) as rn
                        FROM stock_opname_items
                        WHERE created_at < '{$startDateSql}'
                    ) t
                    WHERE rn = 1
                    GROUP BY product_id
                ) latest_so ON latest_so.product_id = s.product_id
                WHERE s.date > latest_so.latest_so_date AND s.date < '{$startDateSql}'
                GROUP BY s.product_id
            "), 'mutasi_awal.product_id', '=', 'p.id')

            // ib: Stok Masuk sebelum periode (digunakan sebagai Fallback)
            ->leftJoin(DB::raw("(SELECT product_id, SUM(init_stock) as total_in
                FROM stocks
                WHERE type='in' AND date < '{$startDateSql}'
                GROUP BY product_id
            ) ib"), 'ib.product_id', '=', 'p.id')

            // ob_stock: Stok Keluar TOTAL sebelum periode (digunakan sebagai Fallback)
            ->leftJoin(DB::raw("(SELECT product_id, SUM(qty) as total_out
                FROM stocks 
                WHERE type='out' AND date < '{$startDateSql}'
                GROUP BY product_id
            ) ob_stock"), 'ob_stock.product_id', '=', 'p.id')

            // ---------------------------------------------------------------------
            // JOIN DALAM PERIODE (SAMA)
            // ---------------------------------------------------------------------

            // ip: Stok Masuk dalam periode
            ->leftJoin(DB::raw("(SELECT product_id, SUM(init_stock) as total_in
                FROM stocks
                WHERE type='in' AND date BETWEEN '{$startDateSql}' AND '{$endDateSql}'
                GROUP BY product_id
            ) ip"), 'ip.product_id', '=', 'p.id')

            // outp_stock: Stok Keluar TOTAL dalam periode
            ->leftJoin(DB::raw("(SELECT product_id, SUM(qty) as total_out
                FROM stocks 
                WHERE type='out' AND date BETWEEN '{$startDateSql}' AND '{$endDateSql}'
                GROUP BY product_id
            ) outp_stock"), 'outp_stock.product_id', '=', 'p.id')

            // pb: Khusus Pembelian
            ->leftJoin(DB::raw("(SELECT s.product_id,
                SUM(s.init_stock) as total_in,
                SUM(s.init_stock * p.initial_price) as total_purchase
                FROM stocks s
                JOIN products p ON p.id = s.product_id
                WHERE s.type='in'
                AND s.purchasing_id IS NOT NULL
                AND s.date BETWEEN '{$startDateSql}' AND '{$endDateSql}'
                GROUP BY s.product_id
            ) pb"), 'pb.product_id', '=', 'p.id')

            // op: Penjualan Nominal
            ->leftJoin(DB::raw("(SELECT sd.product_id,
                SUM(sd.qty) as total_out,
                SUM(sd.price) as total_price,
                SUM(sd.cost) as total_cost,
                SUM(sd.discount_price) as total_discount
                FROM selling_details sd
                JOIN sellings s ON s.id = sd.selling_id
                WHERE s.date BETWEEN '{$startDateSql}' AND '{$endDateSql}'
                GROUP BY sd.product_id
            ) op"), 'op.product_id', '=', 'p.id')

            ->get();

        // --- (Logika Footer dan Reports tetap sama) ---

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
            'total_ending_stock_balance_sell' => 0,
            'total_pembelian' => 0,
            'total_money_product' => 0,
        ];

        foreach ($rows as $row) {
            $reports[] = [
                'sku' => $row->sku,
                'name' => $row->name,
                'initial_price' => $this->formatCurrency($row->initial_price),
                'selling_price' => $this->formatCurrency($row->selling_price),
                'beginning_stock' => (int) $row->stok_awal,
                'mutation' => (int) $row->mutasi,
                'ending_stock' => (int) $row->stok_akhir,
                'qty' => (int) $row->qty,
                'selling' => $this->formatCurrency($row->penjualan_bruto),
                'discount_price' => $this->formatCurrency($row->total_diskon),
                'cost' => $this->formatCurrency($row->total_cost),
                'total_after_discount' => $this->formatCurrency($row->total_after_discount),
                'gross_profit' => $this->formatCurrency($row->laba_kotor),
                'net_profit' => $this->formatCurrency($row->laba_bersih),
                'ending_stock_balance' => $this->formatCurrency($row->saldo_akhir),
                'ending_stock_balance_sell' => $this->formatCurrency($row->saldo_akhir_jual),
                'purchase_qty' => (int) $row->qty_pembelian,
                'purchase_total' => $this->formatCurrency($row->pembelian_bruto),
                'total_money_product' => $this->formatCurrency($row->penjualan_bruto + $row->saldo_akhir_jual),
            ];

            $footer['total_cost'] += $row->total_cost;
            $footer['total_gross'] += $row->penjualan_bruto;
            $footer['total_net'] += $row->total_after_discount;
            $footer['total_discount'] += $row->total_diskon;
            $footer['total_gross_profit'] += $row->laba_kotor;
            $footer['total_net_profit_before_discount_selling'] += $row->laba_kotor;
            $footer['total_net_profit_after_discount_selling'] += $row->laba_bersih;
            $footer['total_qty'] += (int) $row->qty;
            $footer['total_ending_stock'] += $row->stok_akhir;
            $footer['total_ending_stock_balance'] += $row->saldo_akhir;
            $footer['total_ending_stock_balance_sell'] += $row->saldo_akhir_jual;
            $footer['total_pembelian'] += $row->pembelian_bruto;
            $footer['total_money_product'] += $row->penjualan_bruto + $row->saldo_akhir_jual;
        }

        foreach (
            [
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
            ] as $key
        ) {
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

    /**
     * Helper function to format currency value.
     *
     * @param mixed $value
     * @return string
     */
    private function formatCurrency($value): string
    {
        return Number::format($value);
    }
}
