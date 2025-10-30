<?php

namespace App\Filament\Tenant\Pages;

use App\Exports\ProductReportExport;
use App\Filament\Tenant\Pages\Traits\HasReportPageSidebar;
use App\Services\Tenants\ProductReportService;
use App\Traits\HasTranslatableResource;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;

class ProductReport extends Page implements HasActions, HasForms
{
    use HasReportPageSidebar, HasTranslatableResource, InteractsWithFormActions, InteractsWithForms;

    protected static ?string $title = '';

    public static ?string $label = 'Product Report';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.tenant.pages.product-report';

    #[Url]
    public ?array $data = [
        'start_date' => null,
        'end_date' => null,
    ];

    public $reports = null;

    public function mount()
    {
        $this->generate(new ProductReportService);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('start_date')
                ->translateLabel()
                ->date()
                ->closeOnDateSelection()
                ->required()
                ->default(now())
                ->native(false),
            DatePicker::make('end_date')
                ->translateLabel()
                ->date()
                ->required()
                ->closeOnDateSelection()
                ->default(now())
                ->native(false),
        ])
            ->columns(2)
            ->statePath('data');
    }

    public function getFormActions(): array
    {
        return [
            Action::make(__('Generate'))
                ->action('generate')
                ->color('primary')
                ->icon('heroicon-o-magnifying-glass'),

            Action::make('download-excel')
                ->label(__('Download Excel'))
                ->action('downloadExcel')
                ->color('success')
                ->icon('heroicon-o-arrow-down-tray'),

            Action::make(__('Print'))
                ->color('gray')
                ->extraAttributes(['id' => 'print-btn'])
                ->icon('heroicon-o-printer'),
        ];
    }

    public function generate(ProductReportService $productReportService)
    {
        $this->validate([
            'data.start_date' => 'required',
            'data.end_date' => 'required',
        ]);

        try {
            $this->reports = $productReportService->generate($this->data);

            if (empty($this->reports['reports'])) {
                Notification::make()
                    ->title(__('No data found'))
                    ->warning()
                    ->send();
                return;
            }

            Notification::make()
                ->title(__('Report generated successfully'))
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title(__('Failed to generate report'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function downloadExcel()
    {
        $this->validate([
            'data.start_date' => 'required|date',
            'data.end_date' => 'required|date|after_or_equal:data.start_date',
        ]);

        try {
            $productReportService = app(ProductReportService::class);
            $reportData = $productReportService->generate($this->data);

            if (empty($reportData['reports'])) {
                Notification::make()
                    ->title(__('No data found'))
                    ->warning()
                    ->send();
                return;
            }

            $filename = sprintf(
                'laporan-produk-%s-to-%s.xlsx',
                date('Y-m-d', strtotime($this->data['start_date'])),
                date('Y-m-d', strtotime($this->data['end_date']))
            );

            return Excel::download(
                new ProductReportExport(
                    $reportData['reports'],
                    $reportData['footer'],
                    $reportData['header']
                ),
                $filename
            );
        } catch (\Exception $e) {
            \Log::error('Excel download failed', [
                'error' => $e->getMessage(),
                'data' => $this->data
            ]);

            Notification::make()
                ->title(__('Failed to download Excel'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
