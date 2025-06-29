<?php

namespace App\Http\Controllers;

use App\Services\Tenants\StockCardReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class StockCardReportController extends Controller
{
    public function __invoke(Request $request, StockCardReportService $stockCardReportService)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        $reportData = $stockCardReportService->generate($request->all());
        $reports = $reportData['reports'];
        $footer = $reportData['footer'];
        $header = $reportData['header'];

        $pdf = Pdf::loadView('reports.stockcard', compact('reports', 'footer', 'header'))
            ->setPaper('a4', 'landscape');
        $pdf->output();
        $domPdf = $pdf->getDomPDF();
        $canvas = $domPdf->getCanvas();
        $canvas->page_text(720, 570, 'Halaman {PAGE_NUM} dari {PAGE_COUNT}', null, 10, [0, 0, 0]);
        if ($request->ajax()) {
            return $pdf->download();
        }

        return $pdf->stream();
    }
}
