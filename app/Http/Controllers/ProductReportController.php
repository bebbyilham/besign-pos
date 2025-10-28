<?php

namespace App\Http\Controllers;

use App\Services\Tenants\ProductReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProductReportController extends Controller
{
    private const MAX_EXECUTION_TIME = 300;
    private const MEMORY_LIMIT = '1024M'; // Increased to 1GB

    public function __invoke(Request $request, ProductReportService $productReportService)
    {
        // Clear any previous output
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Increase limits
        set_time_limit(self::MAX_EXECUTION_TIME);
        ini_set('memory_limit', self::MEMORY_LIMIT);
        ini_set('max_execution_time', self::MAX_EXECUTION_TIME);

        try {
            $validated = $request->validate([
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'format' => 'nullable|in:download,stream',
            ]);

            Log::info('Product report generation started', [
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
            ]);

            // Generate report data
            $reportData = $productReportService->generate($validated);

            if (empty($reportData['reports'])) {
                Log::warning('No data found for report');
                return back()->with('warning', 'Tidak ada data untuk periode yang dipilih.');
            }

            $reports = $reportData['reports'];
            $footer = $reportData['footer'];
            $header = $reportData['header'];

            Log::info('Data generated', [
                'record_count' => count($reports),
                'memory_usage' => $this->formatBytes(memory_get_usage(true))
            ]);

            // Check memory before PDF generation
            $memoryUsage = memory_get_usage(true);
            $memoryLimit = $this->parseSize(ini_get('memory_limit'));

            if ($memoryUsage > ($memoryLimit * 0.8)) {
                Log::error('Memory usage too high before PDF generation', [
                    'used' => $this->formatBytes($memoryUsage),
                    'limit' => $this->formatBytes($memoryLimit)
                ]);
                throw new \Exception('Data terlalu besar. Silakan pilih rentang tanggal yang lebih kecil.');
            }

            // Generate PDF with error handling
            $pdf = Pdf::loadView('reports.product', compact('reports', 'footer', 'header'))
                ->setPaper('a4', 'landscape')
                ->setOption('isHtml5ParserEnabled', true)
                ->setOption('isRemoteEnabled', false)
                ->setOption('debugPng', false)
                ->setOption('debugKeepTemp', false)
                ->setOption('debugCss', false);

            // Render PDF
            $pdf->output();

            // Add page numbers
            $domPdf = $pdf->getDomPDF();
            $canvas = $domPdf->getCanvas();
            $canvas->page_text(
                720,
                570,
                'Halaman {PAGE_NUM} dari {PAGE_COUNT}',
                null,
                10,
                [0, 0, 0]
            );

            Log::info('PDF generated successfully', [
                'memory_usage' => $this->formatBytes(memory_get_usage(true)),
                'peak_memory' => $this->formatBytes(memory_get_peak_usage(true))
            ]);

            $filename = sprintf(
                'laporan-produk-%s-to-%s.pdf',
                date('Y-m-d', strtotime($validated['start_date'])),
                date('Y-m-d', strtotime($validated['end_date']))
            );

            // Download or stream
            if ($request->input('format') === 'download' || $request->ajax()) {
                return response()->streamDownload(function () use ($pdf) {
                    echo $pdf->output();
                }, $filename, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                ]);
            }

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $filename . '"',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validation error', ['errors' => $e->errors()]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('Product report generation failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'memory_usage' => $this->formatBytes(memory_get_usage(true)),
                'peak_memory' => $this->formatBytes(memory_get_peak_usage(true)),
            ]);

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal generate laporan PDF.',
                    'error' => app()->environment('local') ? $e->getMessage() : 'Internal server error'
                ], 500);
            }

            return back()->with('error', 'Gagal generate laporan: ' . $e->getMessage());
        }
    }

    private function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    private function parseSize($size): int
    {
        $unit = strtoupper(substr($size, -1));
        $value = (int) $size;

        switch ($unit) {
            case 'G':
                $value *= 1024;
            case 'M':
                $value *= 1024;
            case 'K':
                $value *= 1024;
        }

        return $value;
    }
}
