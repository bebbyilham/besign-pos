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
  <x-table class="w-full table-fixed">
    <x-table-header>
      <x-table-header-cell>SKU</x-table-header-cell>
      <x-table-header-cell>{{ __('Product Name') }}</x-table-header-cell>
      <x-table-header-cell class="number">{{ __('Initial Price') }}</x-table-header-cell>
      <x-table-header-cell class="number">{{ __('Price') }}</x-table-header-cell>
      <x-table-header-cell class="number">{{ __('Qty') }}</x-table-header-cell>
      <x-table-header-cell class="number">{{ __('Selling') }}</x-table-header-cell>
      <x-table-header-cell class="number">{{ __('Discount') }}</x-table-header-cell>
      <x-table-header-cell class="number">{{ __('Cost') }}</x-table-header-cell>
      <x-table-header-cell class="number">{{ __('Net Selling') }}</x-table-header-cell>
      <x-table-header-cell class="number">{{ __('Gross Profit') }}</x-table-header-cell>
      <x-table-header-cell class="number">{{ __('Net Profit') }}</x-table-header-cell>
      <x-table-header-cell class="number">{{ __('Stok Akhir') }}</x-table-header-cell>
      <x-table-header-cell class="number">{{ __('Saldo Stok Akhir') }}</x-table-header-cell>
    </x-table-header>
    <tbody>
      @foreach($reports as $report)
        <x-table-row>
          <x-table-cell>{{ $report['sku'] }}</x-table-cell>
          <x-table-cell>{{ $report['name'] }}</x-table-cell>
          <x-table-cell class="number">{{ $report['initial_price'] }}</x-table-cell>
          <x-table-cell class="number">{{ $report['selling_price'] }}</x-table-cell>
          <x-table-cell class="number">{{ $report['qty'] }}</x-table-cell>
          <x-table-cell class="number">{{ $report['selling'] }}</x-table-cell>
          <x-table-cell class="number">{{ $report['discount_price'] }}</x-table-cell>
          <x-table-cell class="number">{{ $report['cost'] }}</x-table-cell>
          <x-table-cell class="number">{{ $report['total_after_discount'] }}</x-table-cell>
          <x-table-cell class="number">{{ $report['gross_profit'] }}</x-table-cell>
          <x-table-cell class="number">{{ $report['net_profit'] }}</x-table-cell>
          <x-table-cell class="number">{{ $report['ending_stock'] }}</x-table-cell>
          <x-table-cell class="number">{{ $report['ending_stock_balance'] }}</x-table-cell>
        </x-table-row>
      @endforeach

      {{-- ROW TOTAL --}}
      <x-table-row class="font-semibold bg-gray-50">
        <x-table-cell colspan="4" class="text-right">{{ __('Total') }}</x-table-cell>
        <x-table-cell class="number">{{ $footer['total_qty'] }}</x-table-cell>
        <x-table-cell class="number">{{ $footer['total_gross'] }}</x-table-cell>
        <x-table-cell class="number">{{ $footer['total_discount'] }}</x-table-cell>
        <x-table-cell colspan="2"></x-table-cell>
        <x-table-cell class="number">{{ $footer['total_gross_profit'] }}</x-table-cell>
        <x-table-cell class="number">{{ $footer['total_net_profit_before_discount_selling'] }}</x-table-cell>
        <x-table-cell colspan="2"></x-table-cell>
      </x-table-row>
    </tbody>
  </x-table>

  {{-- TABLE GRAND TOTAL --}}
  <x-table class="w-full table-fixed mt-4">
    <x-table-header>
      <x-table-row>
        <x-table-header-cell colspan="8" class="text-center">
          {{ __('Grand Total') }}
        </x-table-header-cell>
      </x-table-row>
      <x-table-row>
        <x-table-header-cell>{{ __('Total Biaya') }}</x-table-header-cell>
        <x-table-header-cell>{{ __('Penjualan') }}</x-table-header-cell>
        <x-table-header-cell>{{ __('Discount per Penjualan') }}</x-table-header-cell>
        <x-table-header-cell>{{ __('Discount per Item') }}</x-table-header-cell>
        <x-table-header-cell>{{ __('Penjualan Setelah Discount') }}</x-table-header-cell>
        <x-table-header-cell>{{ __('Keuntungan Kotor') }}</x-table-header-cell>
        <x-table-header-cell>{{ __('Keuntungan Bersih Sebelum Diskon Penjualan') }}</x-table-header-cell>
        <x-table-header-cell>{{ __('Keuntungan Bersih Setelah Diskon Penjualan') }}</x-table-header-cell>
      </x-table-row>
    </x-table-header>
    <tbody>
      <x-table-row class="font-bold bg-gray-50">
        <x-table-cell class="number">{{ $footer['total_cost'] }}</x-table-cell>
        <x-table-cell class="number">{{ $footer['total_gross'] }}</x-table-cell>
        <x-table-cell class="number">{{ $footer['total_discount'] }}</x-table-cell>
        <x-table-cell class="number">{{ $footer['total_net'] }}</x-table-cell>
        <x-table-cell class="number">{{ $footer['total_gross_profit'] }}</x-table-cell>
        <x-table-cell class="number">{{ $footer['total_net_profit_before_discount_selling'] }}</x-table-cell>
        <x-table-cell class="number">{{ $footer['total_net_profit_after_discount_selling'] }}</x-table-cell>
      </x-table-row>
    </tbody>
  </x-table>
</div>
