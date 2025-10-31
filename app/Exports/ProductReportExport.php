<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ProductReportExport implements
    FromCollection,
    WithHeadings,
    WithMapping,
    WithStyles,
    WithTitle,
    WithColumnWidths,
    WithEvents
{
    protected $reports;
    protected $footer;
    protected $header;
    protected $rowNumber = 0;

    public function __construct($reports, $footer, $header)
    {
        $this->reports = collect($reports);
        $this->footer = $footer;
        $this->header = $header;
    }

    public function collection()
    {
        return $this->reports;
    }

    public function headings(): array
    {
        return [
            'No',
            'SKU',
            'Nama Produk',
            'Harga Modal',
            'Harga Jual',
            'Stok Awal',
            'Selsih Stok (Awal - Akhir)',
            'Stok Akhir',
            'Qty Jual',
            'Penjualan Bruto',
            'Diskon',
            'Total HPP',
            'Penjualan Netto',
            'Laba Kotor',
            'Laba Bersih',
            'Saldo Akhir (Modal)',
            'Saldo Akhir (Jual)',
            'Qty Pembelian',
            'Total Pembelian',
            'Total Uang + Stok',
        ];
    }

    public function map($report): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $report['sku'],
            $report['name'],
            $this->cleanCurrency($report['initial_price']),
            $this->cleanCurrency($report['selling_price']),
            $report['beginning_stock'],
            $report['mutation'],
            $report['ending_stock'],
            $report['qty'],
            $this->cleanCurrency($report['selling']),
            $this->cleanCurrency($report['discount_price']),
            $this->cleanCurrency($report['cost']),
            $this->cleanCurrency($report['total_after_discount']),
            $this->cleanCurrency($report['gross_profit']),
            $this->cleanCurrency($report['net_profit']),
            $this->cleanCurrency($report['ending_stock_balance']),
            $this->cleanCurrency($report['ending_stock_balance_sell']),
            $report['purchase_qty'],
            $this->cleanCurrency($report['purchase_total']),
            $this->cleanCurrency($report['total_money_product']),
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $lastRow = $this->reports->count() + 7;

        return [
            '1:5' => [
                'font' => ['bold' => true, 'size' => 11],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
            ],
            6 => [
                'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4472C4']
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => '000000']
                    ]
                ]
            ],
            $lastRow => [
                'font' => ['bold' => true, 'size' => 10],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E7E6E6']
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => '000000']
                    ]
                ]
            ]
        ];
    }

    public function title(): string
    {
        return 'Laporan Produk';
    }

    public function columnWidths(): array
    {
        return [
            'A' => 5,
            'B' => 12,
            'C' => 25,
            'D' => 13,
            'E' => 13,
            'F' => 10,
            'G' => 10,
            'H' => 10,
            'I' => 10,
            'J' => 15,
            'K' => 12,
            'L' => 15,
            'M' => 15,
            'N' => 15,
            'O' => 15,
            'P' => 17,
            'Q' => 17,
            'R' => 12,
            'S' => 15,
            'T' => 17,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->insertNewRowBefore(1, 5);

                $sheet->setCellValue('A1', 'LAPORAN PRODUK');
                $sheet->setCellValue('A2', 'Nama Toko: ' . ($this->header['shop_name'] ?? '-'));
                $sheet->setCellValue('A3', 'Lokasi: ' . ($this->header['shop_location'] ?? '-'));
                $sheet->setCellValue('A4', 'Jenis Usaha: ' . ($this->header['business_type'] ?? '-'));
                $sheet->setCellValue('A5', 'Periode: ' . ($this->header['start_date'] ?? '-') . ' s/d ' . ($this->header['end_date'] ?? '-'));

                $sheet->mergeCells('A1:T1');
                $sheet->getStyle('A1')->getFont()->setSize(14)->setBold(true);
                $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $lastRow = $this->reports->count() + 7;

                $sheet->setCellValue('A' . $lastRow, 'TOTAL');
                $sheet->setCellValue('I' . $lastRow, $this->footer['total_qty']);
                $sheet->setCellValue('J' . $lastRow, $this->cleanCurrency($this->footer['total_gross']));
                $sheet->setCellValue('K' . $lastRow, $this->cleanCurrency($this->footer['total_discount']));
                $sheet->setCellValue('L' . $lastRow, $this->cleanCurrency($this->footer['total_cost']));
                $sheet->setCellValue('M' . $lastRow, $this->cleanCurrency($this->footer['total_net']));
                $sheet->setCellValue('N' . $lastRow, $this->cleanCurrency($this->footer['total_gross_profit']));
                $sheet->setCellValue('O' . $lastRow, $this->cleanCurrency($this->footer['total_net_profit_after_discount_selling']));
                $sheet->setCellValue('P' . $lastRow, $this->cleanCurrency($this->footer['total_ending_stock_balance']));
                $sheet->setCellValue('Q' . $lastRow, $this->cleanCurrency($this->footer['total_ending_stock_balance_sell']));
                $sheet->setCellValue('S' . $lastRow, $this->cleanCurrency($this->footer['total_pembelian']));
                $sheet->setCellValue('T' . $lastRow, $this->cleanCurrency($this->footer['total_money_product']));

                $sheet->mergeCells('A' . $lastRow . ':H' . $lastRow);
                $sheet->getStyle('A' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $dataRange = 'A6:T' . $lastRow;
                $sheet->getStyle($dataRange)->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => '000000']
                        ]
                    ]
                ]);

                $numericColumns = ['A', 'F', 'G', 'H', 'I', 'R'];
                foreach ($numericColumns as $col) {
                    $sheet->getStyle($col . '7:' . $col . $lastRow)
                        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                $currencyColumns = ['D', 'E', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'S', 'T'];
                foreach ($currencyColumns as $col) {
                    $sheet->getStyle($col . '7:' . $col . $lastRow)
                        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    $sheet->getStyle($col . '7:' . $col . $lastRow)
                        ->getNumberFormat()->setFormatCode('#,##0');
                }
            },
        ];
    }

    private function cleanCurrency($value)
    {
        if (is_numeric($value)) {
            return (int) $value;
        }
        return (int) str_replace(['Rp ', '.', ','], '', $value);
    }
}
