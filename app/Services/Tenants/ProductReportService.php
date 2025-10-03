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

        $rows = DB::table('products as p')
            ->select([
                'p.id',
                'p.sku',
                'p.name',
                'p.initial_price',
                'p.selling_price',

                // stok awal (akumulasi atau opname terakhir)
                DB::raw("COALESCE(sa.stok_awal,0) as stok_awal"),

                // mutasi dalam periode
                DB::raw("(COALESCE(ip.total_in,0) - COALESCE(op.total_out,0)) as mutasi"),

                // stok akhir = stok_awal + mutasi
                DB::raw("GREATEST(COALESCE(sa.stok_awal,0) + (COALESCE(ip.total_in,0) - COALESCE(op.total_out,0)),0) as stok_akhir"),

                // transaksi penjualan
                DB::raw("COALESCE(op.total_out,0) as qty"),
                DB::raw("COALESCE(op.total_price,0) as penjualan_bruto"),
                DB::raw("COALESCE(op.total_cost,0) as total_cost"),
                DB::raw("COALESCE(op.total_discount,0) as total_diskon"),
                DB::raw("(COALESCE(op.total_price,0) - COALESCE(op.total_discount,0)) as total_after_discount"),
                DB::raw("(COALESCE(op.total_price,0) - COALESCE(op.total_cost,0)) as laba_kotor"),
                DB::raw("(COALESCE(op.total_price,0) - COALESCE(op.total_cost,0) - COALESCE(op.total_discount,0)) as laba_bersih"),

                // saldo akhir (stok akhir x harga modal)
                DB::raw("GREATEST((COALESCE(sa.stok_awal,0) + (COALESCE(ip.total_in,0) - COALESCE(op.total_out,0))) * p.initial_price,0) as saldo_akhir"),

                // saldo akhir jual (stok akhir x harga jual)
                DB::raw("GREATEST((COALESCE(sa.stok_awal,0) + (COALESCE(ip.total_in,0) - COALESCE(op.total_out,0))) * p.selling_price,0) as saldo_akhir_jual"),

                // pembelian
                DB::raw("COALESCE(pb.total_in,0) as qty_pembelian"),
                DB::raw("COALESCE(pb.total_purchase,0) as pembelian_bruto")
            ])

            // stok awal (ambil opname terakhir, kalau tidak ada → akumulasi pembelian - penjualan)
            ->leftJoin(DB::raw("
        (
            SELECT p.id as product_id,
                   COALESCE((
                       SELECT soi.actual_stock
                       FROM stock_opname_items soi
                       WHERE soi.product_id = p.id
                         AND soi.created_at < '{$startDate->toDateTimeString()}'
                       ORDER BY soi.created_at DESC
                       LIMIT 1
                   ),
                   (
                       COALESCE((SELECT SUM(st.init_stock)
                                 FROM stocks st
                                 WHERE st.product_id = p.id
                                   AND st.type='in'
                                   AND st.date < '{$startDate->toDateTimeString()}'),0)
                       -
                       COALESCE((SELECT SUM(sd.qty)
                                 FROM selling_details sd
                                 JOIN sellings s ON s.id = sd.selling_id
                                 WHERE sd.product_id = p.id
                                   AND s.date < '{$startDate->toDateTimeString()}'),0)
                   ),0) as stok_awal
            FROM products p
        ) sa
    "), 'sa.product_id', '=', 'p.id')

            // stok masuk dalam periode
            ->leftJoin(DB::raw("(SELECT product_id, SUM(init_stock) as total_in
        FROM stocks
        WHERE type='in' AND date BETWEEN '{$startDate->toDateTimeString()}' AND '{$endDate->toDateTimeString()}'
        GROUP BY product_id
    ) ip"), 'ip.product_id', '=', 'p.id')

            // pembelian dalam periode
            ->leftJoin(DB::raw("(SELECT s.product_id,
        SUM(s.init_stock) as total_in,
        SUM(s.init_stock * p.initial_price) as total_purchase
    FROM stocks s
    JOIN products p ON p.id = s.product_id
    WHERE s.type='in'
      AND s.purchasing_id IS NOT NULL
      AND s.date BETWEEN '{$startDate->toDateTimeString()}' AND '{$endDate->toDateTimeString()}'
    GROUP BY s.product_id
    ) pb"), 'pb.product_id', '=', 'p.id')

            // penjualan dalam periode
            ->leftJoin(DB::raw("(SELECT sd.product_id,
        SUM(sd.qty) as total_out,
        SUM(sd.price) as total_price,
        SUM(sd.cost) as total_cost,
        SUM(sd.discount_price) as total_discount
    FROM selling_details sd
    JOIN sellings s ON s.id = sd.selling_id
    WHERE s.date BETWEEN '{$startDate->toDateTimeString()}' AND '{$endDate->toDateTimeString()}'
    GROUP BY sd.product_id
    ) op"), 'op.product_id', '=', 'p.id')

            ->get();


        // === perhitungan footer & reports (sama dengan punyamu) ===
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
