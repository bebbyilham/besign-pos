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
        $profile   = Profile::first();
        $about     = About::first();
        $timezone  = 'Asia/Jakarta';

        $startDate = Carbon::parse($data['start_date'])->startOfDay()->timezone($timezone);
        $endDate   = Carbon::parse($data['end_date'])->endOfDay()->timezone($timezone);

        $rows = DB::table('products as p')
            // stok opname terakhir sebelum periode
            ->leftJoin(DB::raw("
                (
                    SELECT product_id, actual_stock, MAX(created_at) as created_at
                    FROM log_opnames
                    WHERE deleted_at IS NULL
                    GROUP BY product_id
                ) lo
            "), 'lo.product_id', '=', 'p.id')

            // stok masuk sebelum periode
            ->leftJoin(DB::raw("
                (
                    SELECT product_id, SUM(quantity) as total_in, MAX(created_at) as last_in_date
                    FROM stock_ins
                    WHERE deleted_at IS NULL AND created_at < '{$startDate->toDateTimeString()}'
                    GROUP BY product_id
                ) ib
            "), 'ib.product_id', '=', 'p.id')

            // stok keluar sebelum periode
            ->leftJoin(DB::raw("
                (
                    SELECT product_id, SUM(quantity) as total_out, MAX(created_at) as last_out_date
                    FROM stock_outs
                    WHERE deleted_at IS NULL AND created_at < '{$startDate->toDateTimeString()}'
                    GROUP BY product_id
                ) ob
            "), 'ob.product_id', '=', 'p.id')

            // stok masuk selama periode
            ->leftJoin(DB::raw("
                (
                    SELECT product_id, SUM(quantity) as total_in
                    FROM stock_ins
                    WHERE deleted_at IS NULL 
                        AND created_at BETWEEN '{$startDate->toDateTimeString()}' AND '{$endDate->toDateTimeString()}'
                    GROUP BY product_id
                ) ip
            "), 'ip.product_id', '=', 'p.id')

            // stok keluar selama periode
            ->leftJoin(DB::raw("
                (
                    SELECT product_id, SUM(quantity) as total_out
                    FROM stock_outs
                    WHERE deleted_at IS NULL 
                        AND created_at BETWEEN '{$startDate->toDateTimeString()}' AND '{$endDate->toDateTimeString()}'
                    GROUP BY product_id
                ) op
            "), 'op.product_id', '=', 'p.id')

            // transaksi terakhir sebelum periode
            ->leftJoin(DB::raw("
                (
                    SELECT product_id, MAX(created_at) as last_tx_date
                    FROM (
                        SELECT product_id, created_at FROM stock_ins
                        UNION ALL
                        SELECT product_id, created_at FROM stock_outs
                    ) tx
                    WHERE tx.created_at < '{$startDate->toDateTimeString()}'
                    GROUP BY product_id
                ) last_tx
            "), 'last_tx.product_id', '=', 'p.id')

            ->select([
                'p.id',
                'p.sku',
                'p.name',
                'p.initial_price',
                'p.selling_price',

                // stok awal
                DB::raw("
                    CASE
                        WHEN lo.actual_stock IS NOT NULL
                             AND lo.created_at <= '{$startDate->toDateTimeString()}'
                             AND lo.created_at >= COALESCE(last_tx.last_tx_date, '1970-01-01')
                        THEN lo.actual_stock
                        ELSE COALESCE(ib.total_in,0) - COALESCE(ob.total_out,0)
                    END AS stok_awal
                "),

                // stok mutasi (masuk - keluar selama periode)
                DB::raw("(COALESCE(ip.total_in,0) - COALESCE(op.total_out,0)) AS mutasi"),

                // stok akhir
                DB::raw("
                    CASE
                        WHEN lo.actual_stock IS NOT NULL
                             AND lo.created_at <= '{$startDate->toDateTimeString()}'
                             AND lo.created_at >= COALESCE(last_tx.last_tx_date, '1970-01-01')
                        THEN lo.actual_stock + (COALESCE(ip.total_in,0) - COALESCE(op.total_out,0))
                        ELSE (COALESCE(ib.total_in,0) - COALESCE(ob.total_out,0)) + (COALESCE(ip.total_in,0) - COALESCE(op.total_out,0))
                    END AS stok_akhir
                "),

                // penjualan bruto = stok keluar * harga jual
                DB::raw("(COALESCE(op.total_out,0) * p.selling_price) AS penjualan_bruto"),

                // pembelian bruto = stok masuk * harga beli
                DB::raw("(COALESCE(ip.total_in,0) * p.initial_price) AS pembelian_bruto"),

                // saldo akhir (berdasarkan harga beli)
                DB::raw("
                    (
                        CASE
                            WHEN lo.actual_stock IS NOT NULL
                                 AND lo.created_at <= '{$startDate->toDateTimeString()}'
                                 AND lo.created_at >= COALESCE(last_tx.last_tx_date, '1970-01-01')
                            THEN lo.actual_stock + (COALESCE(ip.total_in,0) - COALESCE(op.total_out,0))
                            ELSE (COALESCE(ib.total_in,0) - COALESCE(ob.total_out,0)) + (COALESCE(ip.total_in,0) - COALESCE(op.total_out,0))
                        END
                    ) * p.initial_price AS saldo_akhir
                "),

                // saldo akhir (berdasarkan harga jual)
                DB::raw("
                    (
                        CASE
                            WHEN lo.actual_stock IS NOT NULL
                                 AND lo.created_at <= '{$startDate->toDateTimeString()}'
                                 AND lo.created_at >= COALESCE(last_tx.last_tx_date, '1970-01-01')
                            THEN lo.actual_stock + (COALESCE(ip.total_in,0) - COALESCE(op.total_out,0))
                            ELSE (COALESCE(ib.total_in,0) - COALESCE(ob.total_out,0)) + (COALESCE(ip.total_in,0) - COALESCE(op.total_out,0))
                        END
                    ) * p.selling_price AS saldo_akhir_jual
                "),

                // laba kotor
                DB::raw("((COALESCE(op.total_out,0) * p.selling_price) - (COALESCE(op.total_out,0) * p.initial_price)) AS laba_kotor"),
            ])
            ->orderBy('p.name')
            ->get();

        // 🧾 perhitungan footer
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
                'qty' => (int) $row->stok_akhir,
                'selling' => $this->formatCurrency($row->penjualan_bruto),
                'discount_price' => $this->formatCurrency(0),
                'cost' => $this->formatCurrency($row->pembelian_bruto),
                'total_after_discount' => $this->formatCurrency($row->penjualan_bruto),
                'gross_profit' => $this->formatCurrency($row->laba_kotor),
                'net_profit' => $this->formatCurrency($row->laba_kotor),
                'ending_stock_balance' => $this->formatCurrency($row->saldo_akhir),
                'ending_stock_balance_sell' => $this->formatCurrency($row->saldo_akhir_jual),
                'purchase_qty' => (int) $row->stok_akhir,
                'purchase_total' => $this->formatCurrency($row->pembelian_bruto),
                'total_money_product' => $this->formatCurrency($row->penjualan_bruto + $row->saldo_akhir_jual),
            ];

            $footer['total_cost'] += $row->pembelian_bruto;
            $footer['total_gross'] += $row->penjualan_bruto;
            $footer['total_net'] += $row->penjualan_bruto;
            $footer['total_discount'] += 0;
            $footer['total_gross_profit'] += $row->laba_kotor;
            $footer['total_net_profit_before_discount_selling'] += $row->laba_kotor;
            $footer['total_net_profit_after_discount_selling'] += $row->laba_kotor;
            $footer['total_qty'] += $row->stok_akhir;
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
                'start_date' => $startDate->format('d F Y'),
                'end_date' => $endDate->format('d F Y'),
            ],
        ];
    }

    private function formatCurrency($value)
    {
        return 'Rp ' . Number::format($value, 0);
    }
}
