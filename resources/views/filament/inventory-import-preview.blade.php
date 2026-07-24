<div class="space-y-3">
    @foreach ($items as $item)
        <div class="rounded-lg border p-3">
            <div class="font-medium">Row {{ $item->row_number }} — {{ $item->status }}</div>
            @if ($item->errors)
                <div class="mt-1 text-sm text-danger-600">{{ json_encode($item->errors) }}</div>
            @endif
            <div class="mt-1 text-sm text-gray-600">{{ json_encode($item->payload) }}</div>
        </div>
    @endforeach
</div>
