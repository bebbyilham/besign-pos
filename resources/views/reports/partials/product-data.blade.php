<div class="max-w-full">
  <div class="text-center space-y-2 mb-4">
    <h1 class="text-3xl font-semibold">{{ __('Product Report') }}</h1>
    <h3 class="text-xl">{{ $header['shop_name'] }}</h3>
    <p>
      {{ __('Period') }}: 
      <b>{{ $header['start_date'] }} - {{ $header['end_date'] }}</b>
    </p>
  </div>

  {{-- TABLE DETAIL PRODUK --}}
  <table class="w-full table-fixed border-collapse border text-xs">
    <thead class="bg-gray-100">
      <tr>
        <th class="w-[80px] px-2 py-1 border break-words whitespace-normal">SKU</th>
        <th class="w-[200px] px-2 py-1 border break-words whitespace-normal">Product Name</th>
        <th class="w-[100px] px-2 py-1 border text-right">Initial Price</th>
        <th class="w-[100px] px-2 py-1 border text-right">Price</th>
        <th class="w-[80px] px-2 py-1 border text-right">Qty</th>
        <th class="w-[100px] px-2 py-1 border text-right">Pembelian</th>
        <th class="w-[100px] px-2 py-1 border text-right">Selling</th>
        <th class="w-[100px] px-2 py-1 border text-right">Discount</th>
        <th class="w-[100px] px-2 py-1 border text-right">Cost</th>
        <th class="w-[120px] px-2 py-1 border text-right">Net Selling</th>
        <th class="w-[120px] px-2 py-1 border text-right">Gross Profit</th>
        <th class="w-[120px] px-2 py-1 border text-right">Net Profit</th>
        <th class="w-[100px] px-2 py-1 border text-right">Stok Akhir</th>
        <th class="w-[140px] px-2 py-1 border text-right">Saldo Stok Akhir</th>
      </tr>
    </thead>
    <tbody>
      @foreach($reports as $report)
      <tr>
        <td class="w-[80px] max-w-[80px] px-2 py-1 border break-words whitespace-normal">{{ $report['sku'] }}</td>
        <td class="w-[200px] max-w-[200px] px-2 py-1 border break-words whitespace-normal">{{ $report['name'] }}</td>
        <td class="w-[100px] px-2 py-1 border text-right">{{ $report['initial_price'] }}</td>
        <td class="w-[100px] px-2 py-1 border text-right">{{ $report['selling_price'] }}</td>
        <td class="w-[80px] px-2 py-1 border text-right">{{ $report['qty'] }}</td>
        <td class="w-[100px] px-2 py-1 border text-right">{{ $report['purchase_qty'] }}</td>
        <td class="w-[100px] px-2 py-1 border text-right">{{ $report['selling'] }}</td>
        <td class="w-[100px] px-2 py-1 border text-right">{{ $report['discount_price'] }}</td>
        <td class="w-[100px] px-2 py-1 border text-right">{{ $report['cost'] }}</td>
        <td class="w-[120px] px-2 py-1 border text-right">{{ $report['total_after_discount'] }}</td>
        <td class="w-[120px] px-2 py-1 border text-right">{{ $report['gross_profit'] }}</td>
        <td class="w-[120px] px-2 py-1 border text-right">{{ $report['net_profit'] }}</td>
        <td class="w-[100px] px-2 py-1 border text-right">{{ $report['ending_stock'] }}</td>
        <td class="w-[140px] px-2 py-1 border text-right">{{ $report['ending_stock_balance'] }}</td>
      </tr>
      @endforeach

      {{-- ROW TOTAL --}}
      <tr class="font-semibold bg-gray-50">
        <td colspan="4" class="px-2 py-1 border text-right">Total</td>
        <td class="px-2 py-1 border text-right">{{ $footer['total_qty'] }}</td>
        <td class="px-2 py-1 border text-right">{{ $footer['total_gross'] }}</td>
        <td class="px-2 py-1 border text-right">{{ $footer['total_discount'] }}</td>
        <td colspan="2" class="border"></td>
        <td class="px-2 py-1 border text-right">{{ $footer['total_gross_profit'] }}</td>
        <td class="px-2 py-1 border text-right">{{ $footer['total_net_profit_before_discount_selling'] }}</td>
        <td colspan="2" class="border"></td>
      </tr>
    </tbody>
  </table>

  {{-- TABLE GRAND TOTAL --}}
  <table class="w-full table-fixed mt-4 border-collapse border text-xs">
    <thead class="bg-gray-100">
      <tr>
        <th colspan="8" class="px-2 py-1 border text-center">Grand Total</th>
      </tr>
      <tr>
        <th class="w-[150px] px-2 py-1 border">Total Saldo Stok</th>
        <th class="w-[120px] px-2 py-1 border">Total Biaya</th>
        <th class="w-[120px] px-2 py-1 border">Penjualan</th>
        <th class="w-[140px] px-2 py-1 border">Discount per Penjualan</th>
        <th class="w-[160px] px-2 py-1 border">Penjualan Setelah Discount</th>
        <th class="w-[140px] px-2 py-1 border">Keuntungan Kotor</th>
        <th class="w-[180px] px-2 py-1 border">Keuntungan Bersih Sebelum Diskon</th>
        <th class="w-[180px] px-2 py-1 border">Keuntungan Bersih Setelah Diskon</th>
      </tr>
    </thead>
    <tbody>
      <tr class="font-bold bg-gray-50">
        <td class="px-2 py-1 border text-right">{{ $footer['total_ending_stock_balance'] }}</td>
        <td class="px-2 py-1 border text-right">{{ $footer['total_cost'] }}</td>
        <td class="px-2 py-1 border text-right">{{ $footer['total_gross'] }}</td>
        <td class="px-2 py-1 border text-right">{{ $footer['total_discount'] }}</td>
        <td class="px-2 py-1 border text-right">{{ $footer['total_net'] }}</td>
        <td class="px-2 py-1 border text-right">{{ $footer['total_gross_profit'] }}</td>
        <td class="px-2 py-1 border text-right">{{ $footer['total_net_profit_before_discount_selling'] }}</td>
        <td class="px-2 py-1 border text-right">{{ $footer['total_net_profit_after_discount_selling'] }}</td>
      </tr>
    </tbody>
  </table>
</div>
