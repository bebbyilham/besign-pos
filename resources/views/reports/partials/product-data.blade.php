<div class="max-w-full">
  <div class="text-center space-y-2">
    <h1 class="text-3xl font-semibold">{{ __('Product Report') }}</h1>
    <h3 class="text-xl">{{ $header['shop_name'] }}</h3>
  </div>

  <p class="mb-4">
    {{ __('Period') }}: 
    <b>{{ $header['start_date'] }} - {{ $header['end_date'] }}</b>
  </p>

  {{-- TABLE DETAIL PRODUK --}}
  <x-table class="w-full table-fixed border-collapse
    [&_td]:whitespace-normal [&_td]:break-words [&_td]:align-top
    [&_th]:whitespace-normal [&_th]:break-words [&_th]:align-top">
    <x-table-header>
      <x-table-row>
        <x-table-header-cell class="w-[80px]">SKU</x-table-header-cell>
        <x-table-header-cell class="w-[200px]">{{ __('Product Name') }}</x-table-header-cell>
        <x-table-header-cell class="w-[100px] text-right">{{ __('Initial Price') }}</x-table-header-cell>
        <x-table-header-cell class="w-[100px] text-right">{{ __('Price') }}</x-table-header-cell>
        <x-table-header-cell class="w-[80px] text-right">{{ __('Qty') }}</x-table-header-cell>
        <x-table-header-cell class="w-[100px] text-right">{{ __('Pembelian') }}</x-table-header-cell>
        <x-table-header-cell class="w-[100px] text-right">{{ __('Selling') }}</x-table-header-cell>
        <x-table-header-cell class="w-[100px] text-right">{{ __('Discount') }}</x-table-header-cell>
        <x-table-header-cell class="w-[100px] text-right">{{ __('Cost') }}</x-table-header-cell>
        <x-table-header-cell class="w-[120px] text-right">{{ __('Net Selling') }}</x-table-header-cell>
        <x-table-header-cell class="w-[120px] text-right">{{ __('Gross Profit') }}</x-table-header-cell>
        <x-table-header-cell class="w-[120px] text-right">{{ __('Net Profit') }}</x-table-header-cell>
        <x-table-header-cell class="w-[100px] text-right">{{ __('Stok Akhir') }}</x-table-header-cell>
        <x-table-header-cell class="w-[140px] text-right">{{ __('Saldo Stok Akhir') }}</x-table-header-cell>
      </x-table-row>
    </x-table-header>
    <tbody>
      @foreach($reports as $report)
        <x-table-row>
          <x-table-cell>{{ $report['sku'] }}</x-table-cell>
          <x-table-cell>{{ $report['name'] }}</x-table-cell>
          <x-table-cell class="text-right">{{ $report['initial_price'] }}</x-table-cell>
          <x-table-cell class="text-right">{{ $report['selling_price'] }}</x-table-cell>
          <x-table-cell class="text-right">{{ $report['qty'] }}</x-table-cell>
          <x-table-cell class="text-right">{{ $report['purchase_qty'] }}</x-table-cell>
          <x-table-cell class="text-right">{{ $report['selling'] }}</x-table-cell>
          <x-table-cell class="text-right">{{ $report['discount_price'] }}</x-table-cell>
          <x-table-cell class="text-right">{{ $report['cost'] }}</x-table-cell>
          <x-table-cell class="text-right">{{ $report['total_after_discount'] }}</x-table-cell>
          <x-table-cell class="text-right">{{ $report['gross_profit'] }}</x-table-cell>
          <x-table-cell class="text-right">{{ $report['net_profit'] }}</x-table-cell>
          <x-table-cell class="text-right">{{ $report['ending_stock'] }}</x-table-cell>
          <x-table-cell class="text-right">{{ $report['ending_stock_balance'] }}</x-table-cell>
        </x-table-row>
      @endforeach

      {{-- ROW TOTAL --}}
      <x-table-row class="font-semibold bg-gray-50">
        <x-table-cell colspan="4" class="text-right">{{ __('Total') }}</x-table-cell>
        <x-table-cell class="text-right">{{ $footer['total_qty'] }}</x-table-cell>
        <x-table-cell class="text-right">{{ $footer['total_gross'] }}</x-table-cell>
        <x-table-cell class="text-right">{{ $footer['total_discount'] }}</x-table-cell>
        <x-table-cell colspan="2"></x-table-cell>
        <x-table-cell class="text-right">{{ $footer['total_gross_profit'] }}</x-table-cell>
        <x-table-cell class="text-right">{{ $footer['total_net_profit_before_discount_selling'] }}</x-table-cell>
        <x-table-cell colspan="2"></x-table-cell>
      </x-table-row>
    </tbody>
  </x-table>

  {{-- TABLE GRAND TOTAL --}}
  <x-table class="w-full table-fixed mt-4 border-collapse
    [&_td]:whitespace-normal [&_td]:break-words [&_td]:align-top
    [&_th]:whitespace-normal [&_th]:break-words [&_th]:align-top">
    <x-table-header>
      <x-table-row>
        <x-table-header-cell colspan="8" class="text-center font-semibold">
          {{ __('Grand Total') }}
        </x-table-header-cell>
      </x-table-row>
      <x-table-row>
        <x-table-header-cell class="w-[140px]">{{ __('Total Saldo Stok') }}</x-table-header-cell>
        <x-table-header-cell class="w-[120px]">{{ __('Total Biaya') }}</x-table-header-cell>
        <x-table-header-cell class="w-[120px]">{{ __('Penjualan') }}</x-table-header-cell>
        <x-table-header-cell class="w-[160px]">{{ __('Discount per Penjualan') }}</x-table-header-cell>
        <x-table-header-cell class="w-[180px]">{{ __('Penjualan Setelah Discount') }}</x-table-header-cell>
        <x-table-header-cell class="w-[140px]">{{ __('Keuntungan Kotor') }}</x-table-header-cell>
        <x-table-header-cell class="w-[200px]">{{ __('Keuntungan Bersih Sebelum Diskon Penjualan') }}</x-table-header-cell>
        <x-table-header-cell class="w-[200px]">{{ __('Keuntungan Bersih Setelah Diskon Penjualan') }}</x-table-header-cell>
      </x-table-row>
    </x-table-header>
    <tbody>
      <x-table-row class="font-bold bg-gray-50">
        <x-table-cell class="text-right">{{ $footer['total_ending_stock_balance'] }}</x-table-cell>
        <x-table-cell class="text-right">{{ $footer['total_cost'] }}</x-table-cell>
        <x-table-cell class="text-right">{{ $footer['total_gross'] }}</x-table-cell>
        <x-table-cell class="text-right">{{ $footer['total_discount'] }}</x-table-cell>
        <x-table-cell class="text-right">{{ $footer['total_net'] }}</x-table-cell>
        <x-table-cell class="text-right">{{ $footer['total_gross_profit'] }}</x-table-cell>
        <x-table-cell class="text-right">{{ $footer['total_net_profit_before_discount_selling'] }}</x-table-cell>
        <x-table-cell class="text-right">{{ $footer['total_net_profit_after_discount_selling'] }}</x-table-cell>
      </x-table-row>
    </tbody>
  </x-table>
</div>
