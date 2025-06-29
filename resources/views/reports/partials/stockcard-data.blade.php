<div class="space-y-4 p-4 bg-white shadow rounded-md text-sm">

  {{-- HEADER --}}
  <div class="border-b pb-2">
      <h2 class="text-lg font-semibold">Kartu Stok Produk</h2>
      <p><strong>Produk:</strong> {{ $header['product_name'] ?? '-' }}</p>
      <p><strong>Periode:</strong> {{ $header['start_date'] ?? '-' }} - {{ $header['end_date'] ?? '-' }}</p>
  </div>

  {{-- TABLE --}}
  <div class="overflow-auto">
      <table class="w-full text-left border border-gray-300">
          <thead class="bg-gray-100">
              <tr>
                  <th class="border px-2 py-1">Tanggal</th>
                  <th class="border px-2 py-1">Jenis Perubahan</th>
                  <th class="border px-2 py-1">Jumlah</th>
                  <th class="border px-2 py-1">Sumber</th>
                  <th class="border px-2 py-1">Waktu Input</th>
                  <th class="border px-2 py-1">Stok Akhir</th>
              </tr>
          </thead>
          <tbody>
              @forelse($reports as $row)
                  <tr>
                      <td class="border px-2 py-1">{{ $row['tanggal'] }}</td>
                      <td class="border px-2 py-1">{{ $row['jenis_perubahan'] }}</td>
                      <td class="border px-2 py-1 text-right">{{ $row['jumlah'] }}</td>
                      <td class="border px-2 py-1">{{ $row['sumber'] }}</td>
                      <td class="border px-2 py-1">{{ $row['waktu_input'] }}</td>
                      <td class="border px-2 py-1 text-right">{{ $row['stok_akhir'] }}</td>
                  </tr>
              @empty
                  <tr>
                      <td colspan="6" class="text-center py-2">Tidak ada data transaksi.</td>
                  </tr>
              @endforelse
          </tbody>
      </table>
  </div>

</div>
