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
        $timezone  = $profile->timezone ?: config('app.timezone', 'Asia/Jakarta');
        $about     = About::first();

        $startDate = Carbon::parse($data['start_date'], $timezone)->startOfDay()->setTimezone('UTC');
        $endDate   = Carbon::parse($data['end_date'], $timezone)->endOfDay()->setTimezone('UTC');

        // === Jalankan SQL dengan CTE ===
        $sql = "
            WITH last_opname_before AS (
              SELECT product_id, actual_stock
              FROM (
                SELECT product_id, actual_stock, created_at,
                       ROW_NUMBER() OVER (PARTITION BY product_id ORDER BY created_at DESC) as rn
                FROM stock_opname_items
                WHERE created_at < :start_date
              ) t
              WHERE rn = 1
            ),
            last_opname_in_period AS (
              SELECT product_id, actual_stock, created_at
              FROM (
                SELECT product_id, actual_stock, created_at,
                       ROW_NUMBER() OVER (PARTITION BY product_id ORDER BY created_at DESC) as rn
                FROM stock_opname_items
                WHERE created_at BETWEEN :start_date AND :end_date
              ) t
              WHERE rn = 1
            ),
            in_before AS (
              SELECT product_id, SUM(init_stock) as total_in
              FROM stocks
              WHERE type='in' AND date < :start_date
              GROUP BY product_id
            ),
            out_before AS (
              SELECT sd.product_id, SUM(sd.qty) as total_out
              FROM selling_details sd
              JOIN sellings s ON s.id = sd.selling_id
              WHERE s.date < :start_date
              GROUP BY sd.product_id
            ),
            in_period AS (
              SELECT product_id, SUM(init_stock) as total_in
              FROM stocks
              WHERE type='in' AND date BETWEEN :start_date AND :end_date
              GROUP BY product_id
            ),
            out_period AS (
              SELECT sd.product_id,
                     SUM(sd.qty) as total_out,
                     SUM(sd.price * sd.qty) as total_price,
                     SUM(COALESCE(sd.cost, p.initial_price) * sd.qty) as total_cost,
                     SUM(sd.discount_price) as total_discount
              FROM selling_details sd
              JOIN sellings s ON s.id = sd.selling_id
              JOIN products p ON p.id = sd.product_id
              WHERE s.date BETWEEN :start_date AND :end_date
              GROUP BY sd.product_id
            )
            SELECT p.id, p.sku, p.name, p.initial_price, p.selling_price,
                   COALESCE(lo.actual_stock, (COALESCE(ib.total_in,0) - COALESCE(ob.total_out,0))) as stok_awal,
                   (COALESCE(ip.total_in,0) - COALESCE(op.total_out,0)) as mutasi,
                   COALESCE(li.actual_stock,
                     COALESCE(lo.actual_stock, (COALESCE(ib.total_in,0) - COALESCE(ob.total_out,0)))
                     + COALESCE(ip.total_in,0) - COALESCE(op.total_out,0)
                   ) as stok_akhir,
                   COALESCE(op.total_price,0) as penjualan_bruto,
                   COALESCE(op.total_cost,0) as total_cost,
                   COALESCE(op.total_discount,0) as total_diskon,
                   (COALESCE(op.total_price,0) - COALESCE(op.total_cost,0)) as laba_kotor,
                   (COALESCE(op.total_price,0) - COALESCE(op.total_cost,0) - COALESCE(op.total_discount,0)) as laba_bersih,
                   (COALESCE(li.actual_stock,
                     COALESCE(lo.actual_stock, (COALESCE(ib.total_in,0) - COALESCE(ob.total_out,0)))
                     + COALESCE(ip.total_in,0) - COALESCE(op.total_out,0)
                   ) * p.initial_price) as saldo_akhir
            FROM products p
            LEFT JOIN last_opname_before lo ON lo.product_id=p.id
            LEFT JOIN last_opname_in_period li ON li.product_id=p.id
            LEFT JOIN in_before ib ON ib.product_id=p.id
            LEFT JOIN out_before ob ON ob.product_id=p.id
            LEFT JOIN in_period ip ON ip.product_id=p.id
            LEFT JOIN out_period op ON op.product_id=p.id
        ";

        $rows = DB::select($sql, [
            'start_date' => $startDate->toDateTimeString(),
            'end_date'   => $endDate->toDateTimeString(),
        ]);

        // === Format ke reports & footer ===
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

        foreach ($rows as $row) {
            $reports[] = [
                'sku' => $row->sku,
                'name' => $row->name,
                'initial_price' => $this->formatCurrency($row->initial_price),
                'selling_price' => $this->formatCurrency($row->selling_price),
                'beginning_stock' => $row->stok_awal,
                'mutation' => $row->mutasi,
                'ending_stock' => $row->stok_akhir,
                'selling' => $this->formatCurrency($row->penjualan_bruto),
                'discount_price' => $this->formatCurrency($row->total_diskon),
                'cost' => $this->formatCurrency($row->total_cost),
                'gross_profit' => $this->formatCurrency($row->laba_kotor),
                'net_profit' => $this->formatCurrency($row->laba_bersih),
                'ending_stock_balance' => $this->formatCurrency($row->saldo_akhir),
            ];

            // akumulasi footer
            $footer['total_cost'] += $row->total_cost;
            $footer['total_gross'] += $row->penjualan_bruto;
            $footer['total_net'] += $row->penjualan_bruto - $row->total_diskon;
            $footer['total_discount'] += $row->total_diskon;
            $footer['total_gross_profit'] += $row->laba_kotor;
            $footer['total_net_profit_before_discount_selling'] += $row->laba_kotor;
            $footer['total_net_profit_after_discount_selling'] += $row->laba_bersih;
            $footer['total_qty'] += $row->stok_awal; // bisa diganti total terjual kalau mau
            $footer['total_ending_stock'] += $row->stok_akhir;
            $footer['total_ending_stock_balance'] += $row->saldo_akhir;
        }

        // format footer currency
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
                'end_date'   => $endDate->setTimezone($timezone)->format('d F Y'),
            ],
        ];
    }

    private function formatCurrency($value)
    {
        return Number::format($value);
    }
}
