<?php

namespace App\Services\Tenants;

use App\Models\Tenants\About;
use App\Models\Tenants\Profile;
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
            // Validasi input
            $this->validateInput($data);

            $profile = Profile::get();
            $timezone = config('app.timezone', self::DEFAULT_TIMEZONE);
            $about = About::first();

            $startDate = Carbon::parse($data['start_date'])->startOfDay();
            $endDate = Carbon::parse($data['end_date'])->endOfDay();

            // Get report data
            $rows = $this->getReportData($startDate, $endDate);

            // Process results
            return $this->processReportData($rows, $about, $startDate, $endDate, $timezone);
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

    private function getReportData(Carbon $startDate, Carbon $endDate)
    {
        // Gunakan prepared statement style dengan placeholder
        $startDateStr = $startDate->toDateTimeString();
        $endDateStr = $endDate->toDateTimeString();

        // Escape untuk keamanan (fallback jika binding tidak tersedia)
        $startDateEscaped = DB::getPdo()->quote($startDateStr);
        $endDateEscaped = DB::getPdo()->quote($endDateStr);

        return DB::table('products as p')
            ->select([
                'p.id',
                'p.sku',
                'p.name',
                'p.initial_price',
                'p.selling_price',

                // Stok awal: gunakan transaksi terakhir sebelum periode
                // Priority: 1. Cek tanggal terakhir antara (opname, pembelian, penjualan)
                //           2. Jika opname terakhir → pakai actual_stock
                //           3. Jika pembelian terakhir → pakai total_in pembelian - total_out penjualan
                //           4. Jika penjualan terakhir → pakai total_in pembelian - total_out penjualan
                DB::raw("
                    CASE 
                        -- Jika stok opname adalah transaksi terakhir
                        WHEN lo.actual_stock IS NOT NULL 
                             AND lo.created_at IS NOT NULL
                             AND (pb_before.last_purchase_date IS NULL OR lo.created_at > pb_before.last_purchase_date)
                             AND (sb_before.last_selling_date IS NULL OR lo.created_at > sb_before.last_selling_date)
                        THEN lo.actual_stock
                        
                        -- Jika pembelian atau penjualan lebih baru dari opname (atau tidak ada opname)
                        ELSE (COALESCE(pb_before.total_in, 0) - COALESCE(sb_before.total_out, 0))
                    END AS stok_awal
                "),

                // Mutasi selama periode (pembelian - penjualan dalam periode)
                DB::raw("(COALESCE(ip.total_in, 0) - COALESCE(op.total_out, 0)) AS mutasi"),

                // Stok akhir = stok awal + mutasi
                DB::raw("
                    (CASE 
                        WHEN lo.actual_stock IS NOT NULL 
                             AND lo.created_at IS NOT NULL
                             AND (pb_before.last_purchase_date IS NULL OR lo.created_at > pb_before.last_purchase_date)
                             AND (sb_before.last_selling_date IS NULL OR lo.created_at > sb_before.last_selling_date)
                        THEN lo.actual_stock
                        ELSE (COALESCE(pb_before.total_in, 0) - COALESCE(sb_before.total_out, 0))
                    END) + (COALESCE(ip.total_in, 0) - COALESCE(op.total_out, 0)) AS stok_akhir
                "),

                // Penjualan & laba
                DB::raw("COALESCE(op.total_out, 0) AS qty"),
                DB::raw("COALESCE(op.total_price, 0) AS penjualan_bruto"),
                DB::raw("COALESCE(op.total_cost, 0) AS total_cost"),
                DB::raw("COALESCE(op.total_discount, 0) AS total_diskon"),
                DB::raw("(COALESCE(op.total_price, 0) - COALESCE(op.total_discount, 0)) AS total_after_discount"),
                DB::raw("(COALESCE(op.total_price, 0) - COALESCE(op.total_cost, 0)) AS laba_kotor"),
                DB::raw("(COALESCE(op.total_price, 0) - COALESCE(op.total_cost, 0) - COALESCE(op.total_discount, 0)) AS laba_bersih"),

                // Pembelian
                DB::raw("COALESCE(pb.total_in, 0) AS qty_pembelian"),
                DB::raw("COALESCE(pb.total_purchase, 0) AS pembelian_bruto")
            ])

            // Opname terakhir sebelum periode
            ->leftJoin(
                DB::raw("(
                    SELECT soi.product_id, soi.actual_stock, soi.created_at
                    FROM stock_opname_items soi
                    JOIN (
                        SELECT product_id, MAX(created_at) AS max_created
                        FROM stock_opname_items
                        WHERE created_at < {$startDateEscaped}
                        GROUP BY product_id
                    ) last_opname 
                    ON last_opname.product_id = soi.product_id 
                    AND last_opname.max_created = soi.created_at
                ) lo"),
                'lo.product_id',
                '=',
                'p.id'
            )

            // Pembelian sebelum periode (stok masuk dari purchasing) + tanggal transaksi terakhir
            ->leftJoin(
                DB::raw("(
                    SELECT product_id, 
                           SUM(init_stock) AS total_in,
                           MAX(date) AS last_purchase_date
                    FROM stocks
                    WHERE type = 'in' 
                      AND purchasing_id IS NOT NULL 
                      AND date < {$startDateEscaped}
                    GROUP BY product_id
                ) pb_before"),
                'pb_before.product_id',
                '=',
                'p.id'
            )

            // Penjualan sebelum periode + tanggal transaksi terakhir
            ->leftJoin(
                DB::raw("(
                    SELECT sd.product_id, 
                           SUM(sd.qty) AS total_out,
                           MAX(s.date) AS last_selling_date
                    FROM selling_details sd
                    JOIN sellings s ON s.id = sd.selling_id
                    WHERE s.date < {$startDateEscaped}
                    GROUP BY sd.product_id
                ) sb_before"),
                'sb_before.product_id',
                '=',
                'p.id'
            )

            // Stok masuk dalam periode (pembelian dalam periode)
            ->leftJoin(
                DB::raw("(
                    SELECT product_id, SUM(init_stock) AS total_in
                    FROM stocks
                    WHERE type = 'in' 
                      AND purchasing_id IS NOT NULL
                      AND date BETWEEN {$startDateEscaped} AND {$endDateEscaped}
                    GROUP BY product_id
                ) ip"),
                'ip.product_id',
                '=',
                'p.id'
            )

            // Pembelian dalam periode
            ->leftJoin(
                DB::raw("(
                    SELECT s.product_id,
                        SUM(s.init_stock) AS total_in,
                        SUM(s.init_stock * p.initial_price) AS total_purchase
                    FROM stocks s
                    JOIN products p ON p.id = s.product_id
                    WHERE s.type = 'in'
                      AND s.purchasing_id IS NOT NULL
                      AND s.date BETWEEN {$startDateEscaped} AND {$endDateEscaped}
                    GROUP BY s.product_id
                ) pb"),
                'pb.product_id',
                '=',
                'p.id'
            )

            // Penjualan dalam periode
            ->leftJoin(
                DB::raw("(
                    SELECT sd.product_id,
                        SUM(sd.qty) AS total_out,
                        SUM(sd.price) AS total_price,
                        SUM(sd.cost) AS total_cost,
                        SUM(sd.discount_price) AS total_discount
                    FROM selling_details sd
                    JOIN sellings s ON s.id = sd.selling_id
                    WHERE s.date BETWEEN {$startDateEscaped} AND {$endDateEscaped}
                    GROUP BY sd.product_id
                ) op"),
                'op.product_id',
                '=',
                'p.id'
            )

            ->get();
    }

    private function processReportData($rows, $about, Carbon $startDate, Carbon $endDate, string $timezone)
    {
        $reports = [];
        $footer = $this->initializeFooter();

        foreach ($rows as $row) {
            $reportRow = $this->formatReportRow($row);
            $reports[] = $reportRow;
            $this->updateFooter($footer, $row);
        }

        $this->formatFooter($footer);

        return [
            'reports' => $reports,
            'footer' => $footer,
            'header' => $this->formatHeader($about, $startDate, $endDate, $timezone),
        ];
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

    private function formatReportRow($row): array
    {
        $endingStock = (int) $row->stok_akhir;
        $endingStockBalance = $endingStock * $row->initial_price;
        $endingStockBalanceSell = $endingStock * $row->selling_price;

        return [
            'sku' => $row->sku,
            'name' => $row->name,
            'initial_price' => $this->formatCurrency($row->initial_price),
            'selling_price' => $this->formatCurrency($row->selling_price),
            'beginning_stock' => (int) $row->stok_awal,
            'mutation' => (int) $row->mutasi,
            'ending_stock' => $endingStock,
            'qty' => (int) $row->qty,
            'selling' => $this->formatCurrency($row->penjualan_bruto),
            'discount_price' => $this->formatCurrency($row->total_diskon),
            'cost' => $this->formatCurrency($row->total_cost),
            'total_after_discount' => $this->formatCurrency($row->total_after_discount),
            'gross_profit' => $this->formatCurrency($row->laba_kotor),
            'net_profit' => $this->formatCurrency($row->laba_bersih),
            'ending_stock_balance' => $this->formatCurrency($endingStockBalance),
            'ending_stock_balance_sell' => $this->formatCurrency($endingStockBalanceSell),
            'purchase_qty' => (int) $row->qty_pembelian,
            'purchase_total' => $this->formatCurrency($row->pembelian_bruto),
            'total_money_product' => $this->formatCurrency($row->penjualan_bruto + $endingStockBalanceSell),
        ];
    }

    private function updateFooter(array &$footer, $row): void
    {
        $endingStock = (int) $row->stok_akhir;
        $endingStockBalance = $endingStock * $row->initial_price;
        $endingStockBalanceSell = $endingStock * $row->selling_price;

        $footer['total_cost'] += $row->total_cost;
        $footer['total_gross'] += $row->penjualan_bruto;
        $footer['total_net'] += $row->total_after_discount;
        $footer['total_discount'] += $row->total_diskon;
        $footer['total_gross_profit'] += $row->laba_kotor;
        $footer['total_net_profit_before_discount_selling'] += $row->laba_kotor;
        $footer['total_net_profit_after_discount_selling'] += $row->laba_bersih;
        $footer['total_qty'] += (int) $row->qty;
        $footer['total_ending_stock'] += $endingStock;
        $footer['total_ending_stock_balance'] += $endingStockBalance;
        $footer['total_ending_stock_balance_sell'] += $endingStockBalanceSell;
        $footer['total_pembelian'] += $row->pembelian_bruto;
        $footer['total_money_product'] += $row->penjualan_bruto + $endingStockBalanceSell;
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
