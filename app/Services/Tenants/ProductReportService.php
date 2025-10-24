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
        // Gunakan parameter binding untuk keamanan
        $startDateStr = $startDate->toDateTimeString();
        $endDateStr = $endDate->toDateTimeString();

        return DB::table('products as p')
            ->select([
                'p.id',
                'p.sku',
                'p.name',
                'p.initial_price',
                'p.selling_price',

                // Stok awal dengan parameter binding
                DB::raw("
                    CASE 
                        WHEN lo.actual_stock IS NOT NULL 
                             AND lo.created_at <= ?
                        THEN lo.actual_stock
                        ELSE (COALESCE(ib.total_in, 0) - COALESCE(ob.total_out, 0))
                    END AS stok_awal
                "),

                // Mutasi selama periode
                DB::raw("(COALESCE(ip.total_in, 0) - COALESCE(op.total_out, 0)) AS mutasi"),

                // Stok akhir
                DB::raw("
                    CASE 
                        WHEN lo.actual_stock IS NOT NULL 
                             AND lo.created_at <= ?
                        THEN lo.actual_stock + (COALESCE(ip.total_in, 0) - COALESCE(op.total_out, 0))
                        ELSE (COALESCE(ib.total_in, 0) - COALESCE(ob.total_out, 0)) + (COALESCE(ip.total_in, 0) - COALESCE(op.total_out, 0))
                    END AS stok_akhir
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
                $this->getLastOpnameSubquery($startDateStr),
                'lo.product_id',
                '=',
                'p.id'
            )

            // Stok masuk sebelum periode
            ->leftJoin(
                $this->getStockBeforePeriodSubquery($startDateStr),
                'ib.product_id',
                '=',
                'p.id'
            )

            // Penjualan sebelum periode
            ->leftJoin(
                $this->getSalesBeforePeriodSubquery($startDateStr),
                'ob.product_id',
                '=',
                'p.id'
            )

            // Stok masuk dalam periode
            ->leftJoin(
                $this->getStockInPeriodSubquery($startDateStr, $endDateStr),
                'ip.product_id',
                '=',
                'p.id'
            )

            // Pembelian dalam periode
            ->leftJoin(
                $this->getPurchaseInPeriodSubquery($startDateStr, $endDateStr),
                'pb.product_id',
                '=',
                'p.id'
            )

            // Penjualan dalam periode
            ->leftJoin(
                $this->getSalesInPeriodSubquery($startDateStr, $endDateStr),
                'op.product_id',
                '=',
                'p.id'
            )

            ->addBinding([$startDateStr, $startDateStr], 'select')
            ->get();
    }

    private function getLastOpnameSubquery(string $startDate)
    {
        return DB::raw("(
            SELECT soi.product_id, soi.actual_stock, soi.created_at
            FROM stock_opname_items soi
            JOIN (
                SELECT product_id, MAX(created_at) AS max_created
                FROM stock_opname_items
                WHERE created_at <= ?
                GROUP BY product_id
            ) last_opname 
            ON last_opname.product_id = soi.product_id 
            AND last_opname.max_created = soi.created_at
        ) lo")
            ->addBinding($startDate, 'join');
    }

    private function getStockBeforePeriodSubquery(string $startDate)
    {
        return DB::raw("(
            SELECT product_id, SUM(init_stock) AS total_in
            FROM stocks
            WHERE type = 'in' AND date < ?
            GROUP BY product_id
        ) ib")
            ->addBinding($startDate, 'join');
    }

    private function getSalesBeforePeriodSubquery(string $startDate)
    {
        return DB::raw("(
            SELECT sd.product_id, SUM(sd.qty) AS total_out
            FROM selling_details sd
            JOIN sellings s ON s.id = sd.selling_id
            WHERE s.date < ?
            GROUP BY sd.product_id
        ) ob")
            ->addBinding($startDate, 'join');
    }

    private function getStockInPeriodSubquery(string $startDate, string $endDate)
    {
        return DB::raw("(
            SELECT product_id, SUM(init_stock) AS total_in
            FROM stocks
            WHERE type = 'in' AND date BETWEEN ? AND ?
            GROUP BY product_id
        ) ip")
            ->addBinding([$startDate, $endDate], 'join');
    }

    private function getPurchaseInPeriodSubquery(string $startDate, string $endDate)
    {
        return DB::raw("(
            SELECT s.product_id,
                SUM(s.init_stock) AS total_in,
                SUM(s.init_stock * p.initial_price) AS total_purchase
            FROM stocks s
            JOIN products p ON p.id = s.product_id
            WHERE s.type = 'in'
              AND s.purchasing_id IS NOT NULL
              AND s.date BETWEEN ? AND ?
            GROUP BY s.product_id
        ) pb")
            ->addBinding([$startDate, $endDate], 'join');
    }

    private function getSalesInPeriodSubquery(string $startDate, string $endDate)
    {
        return DB::raw("(
            SELECT sd.product_id,
                SUM(sd.qty) AS total_out,
                SUM(sd.price) AS total_price,
                SUM(sd.cost) AS total_cost,
                SUM(sd.discount_price) AS total_discount
            FROM selling_details sd
            JOIN sellings s ON s.id = sd.selling_id
            WHERE s.date BETWEEN ? AND ?
            GROUP BY sd.product_id
        ) op")
            ->addBinding([$startDate, $endDate], 'join');
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
