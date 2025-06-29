
<x-filament-panels::page>
    <x-filament-panels::form
        id="form"
        wire:key="{{ 'forms.' . $this->getFormStatePath() }}"
    >
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="$this->getCachedFormActions()"
            :full-width="$this->hasFullWidthFormActions()"
        />
    </x-filament-panels::form>
    <div id="printable-element" class="max-w-full space-y-2">
      @if(is_array($reports) && isset($reports['header']))
          @include('reports.partials.stockcard-data', [
              'header' => $reports['header'],
              'reports' => $reports['reports'],
              'footer' => $reports['footer'] ?? [],
          ])
      @elseif(isset($reports['error']))
          <div class="p-4 text-red-500 bg-red-100 border border-red-200 rounded">
              {{ $reports['error'] }}
          </div>
      @endif
  </div>
  
</x-filament-panels::page>
@script()
<script>
  document.getElementById('print-btn').addEventListener('click', () => {
    const printContents = document.getElementById("printable-element").innerHTML;
    const originalContents = document.body.innerHTML;

    document.body.innerHTML = printContents;

    window.print();

    window.location.reload();
  });
</script>
@endscript
