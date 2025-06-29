<?php

namespace App\Filament\Tenant\Pages;

use App\Filament\Tenant\Pages\Traits\HasReportPageSidebar;
use App\Services\Tenants\StockCardReportService;
use App\Traits\HasTranslatableResource;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Livewire\Attributes\Url;
use Filament\Forms\Components\Select;
use App\Models\Tenants\Product;

class StockCardReport extends Page implements HasActions, HasForms
{
    use HasReportPageSidebar, HasTranslatableResource, InteractsWithFormActions, InteractsWithForms;

    protected static ?string $title = '';
    public static ?string $label = 'Stock Card Report';
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static string $view = 'filament.tenant.pages.stock-card-report';

    #[Url]
    public ?array $data = [
        'start_date' => null,
        'end_date' => null,
        'product_id' => null,
    ];

    public $reports = null;

    public function mount()
    {
        $this->generate(new StockCardReportService);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('start_date')
                ->translateLabel()
                ->date()
                ->required()
                ->closeOnDateSelection()
                ->default(now())
                ->native(false),

            DatePicker::make('end_date')
                ->translateLabel()
                ->date()
                ->required()
                ->closeOnDateSelection()
                ->default(now())
                ->native(false),

            Select::make('product_id')
                ->label('Product')
                ->options(
                    Product::query()
                        ->whereNotNull('name')
                        ->pluck('name', 'id')
                        ->toArray()
                )
                ->searchable()
                ->required()
        ])
            ->columns(3)
            ->statePath('data');
    }

    public function getFormActions(): array
    {
        return [
            Action::make(__('Generate'))
                ->action('generate'),
            Action::make(__('Print'))
                ->color('warning')
                ->extraAttributes([
                    'id' => 'print-btn',
                ])
                ->icon('heroicon-o-printer'),
            Action::make('download-pdf')
                ->label(__('Download as PDF'))
                ->action('downloadPdf')
                ->color('warning')
                ->icon('heroicon-o-arrow-down-on-square'),
        ];
    }

    public function generate(StockCardReportService $StockCardReportService)
    {
        $this->validate([
            'data.start_date' => 'required',
            'data.end_date' => 'required',
            'data.product_id' => 'required',
        ]);

        $this->reports = $StockCardReportService->generate([
            'start_date' => $this->data['start_date'],
            'end_date' => $this->data['end_date'],
            'product_id' => $this->data['product_id'] ?? null,
        ]);
    }

    public function downloadPdf()
    {
        $this->validate([
            'data.start_date' => 'required',
            'data.end_date' => 'required',
        ]);

        return $this->redirectRoute('stock-card-report.generate', $this->data);
    }
}
