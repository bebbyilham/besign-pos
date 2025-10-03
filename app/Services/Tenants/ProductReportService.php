<?php

namespace App\Services\Tenants;

use App\Models\Tenants\About;
use App\Models\Tenants\Profile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

class ProductReportService
{
    public function generate(array $data)
    {
        $profile   = Profile::get();
        $timezone  = 'Asia/Jakarta';
        $about     = About::first();
        $startDate = Carbon::parse($data['start_date'])->startOfDay();
        $endDate   = Carbon::parse($data['end_date'])->endOfDay();

        $products = DB::table('products as p')
            ->select(
                'p.id',
                'p.name',
                'p.sku',
                DB::raw('COALESCE(last_tx.stok_awal,0) as beginning_stock'),
                DB::raw('COALESCE(ip.total_in,0) as total_in'),
                DB::raw('COALESCE(op.total_out,0) as total_out'),
                DB::raw('(COALESCE(last_tx.stok_awal,0) + COALESCE(ip.total_in,0) - COALESCE(op.total_out,0)) as ending_stock')
            )

            // stok awal (fix pakai akumulasi kalau bukan opname)
            ->leftJoin(DB::raw("
                (
                    SELECT final.product_id,
                           CASE 
                               WHEN final.source = 'opname' THEN final.stok_awal
                               ELSE (
                                   COALESCE((SELECT SUM(st.init_stock) 
                                             FROM stocks st 
                                             WHERE st.product_id = final.product_id 
                                               AND st.type='in' 
                                               AND st.date <= final.last_date),0)
                                   - COALESCE((SELECT SUM(sd.qty) 
                                               FROM selling_details sd 
                                               JOIN sellings s ON s.id = sd.selling_id 
                                               WHERE sd.product_id = final.product_id 
                                                 AND s.date <= final.last_date),0)
                               )
                           END as stok_awal
                    FROM (
                        SELECT t.product_id, t.stok_awal, t.last_date, t.source
                        FROM (
                            -- pembelian
                            SELECT s.product_id,
                                   SUM(s.init_stock) as stok_awal,
                                   MAX(s.date) as last_date,
                                   'pembelian' as source
                            FROM stocks s
                            WHERE s.type='in' AND s.date < '{$startDate->toDateTimeString()}'
                            GROUP BY s.product_id

                            UNION ALL

                            -- penjualan
                            SELECT sd.product_id,
                                   -SUM(sd.qty) as stok_awal,
                                   MAX(s.date) as last_date,
                                   'penjualan' as source
                            FROM selling_details sd
                            JOIN sellings s ON s.id = sd.selling_id
                            WHERE s.date < '{$startDate->toDateTimeString()}'
                            GROUP BY sd.product_id

                            UNION ALL

                            -- opname
                            SELECT soi.product_id,
                                   soi.actual_stock as stok_awal,
                                   MAX(soi.created_at) as last_date,
                                   'opname' as source
                            FROM stock_opname_items soi
                            WHERE soi.created_at < '{$startDate->toDateTimeString()}'
                            GROUP BY soi.product_id
                        ) t
                        JOIN (
                            -- cari transaksi terakhir per produk
                            SELECT product_id, MAX(last_date) as last_date
                            FROM (
                                SELECT s.product_id, MAX(s.date) as last_date
                                FROM stocks s
                                WHERE s.type='in' AND s.date < '{$startDate->toDateTimeString()}'
                                GROUP BY s.product_id

                                UNION ALL
                                SELECT sd.product_id, MAX(s.date) as last_date
                                FROM selling_details sd
                                JOIN sellings s ON s.id = sd.selling_id
                                WHERE s.date < '{$startDate->toDateTimeString()}'
                                GROUP BY sd.product_id

                                UNION ALL
                                SELECT soi.product_id, MAX(soi.created_at) as last_date
                                FROM stock_opname_items soi
                                WHERE soi.created_at < '{$startDate->toDateTimeString()}'
                                GROUP BY soi.product_id
                            ) x
                            GROUP BY product_id
                        ) last_event
                        ON t.product_id = last_event.product_id AND t.last_date = last_event.last_date
                    ) final
                ) last_tx
            "), 'last_tx.product_id', '=', 'p.id')

            // stok masuk periode
            ->leftJoin(DB::raw("
                (
                    SELECT s.product_id, SUM(s.init_stock) as total_in
                    FROM stocks s
                    WHERE s.type='in' AND s.date BETWEEN '{$startDate->toDateTimeString()}' AND '{$endDate->toDateTimeString()}'
                    GROUP BY s.product_id
                ) ip
            "), 'ip.product_id', '=', 'p.id')

            // stok keluar periode
            ->leftJoin(DB::raw("
                (
                    SELECT sd.product_id, SUM(sd.qty) as total_out
                    FROM selling_details sd
                    JOIN sellings s ON s.id = sd.selling_id
                    WHERE s.date BETWEEN '{$startDate->toDateTimeString()}' AND '{$endDate->toDateTimeString()}'
                    GROUP BY sd.product_id
                ) op
            "), 'op.product_id', '=', 'p.id')

            ->get();


        // === perhitungan footer & reports ===
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

    private function formatCurrency($value)
    {
        return Number::format($value);
    }
}
