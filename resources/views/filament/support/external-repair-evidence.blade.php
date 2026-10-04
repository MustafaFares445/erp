@php
    $labels = [
        'rma-documents' => __('RMA documents'),
        'supplier-reports' => __('Supplier reports'),
        'shipping-documents' => __('Shipping documents'),
    ];
@endphp
<div class="space-y-4" data-testid="external-repair-evidence">
    @foreach($collections as $collection)
        @php($files = $repair->media->where('collection_name', $collection))
        <div>
            <div class="text-sm font-medium">{{ $labels[$collection] }}</div>
            @forelse($files as $media)
                <div class="mt-1 flex items-center gap-3 text-sm">
                    <span class="truncate">{{ $media->file_name }}</span>
                    <a class="text-primary-600" href="{{ route('admin.external-repairs.media.preview', ['repair' => $repair, 'media' => $media]) }}" target="_blank" rel="noopener">{{ __('Preview') }}</a>
                    <a class="text-primary-600" href="{{ route('admin.external-repairs.media.download', ['repair' => $repair, 'media' => $media]) }}">{{ __('Download') }}</a>
                </div>
            @empty
                <p class="mt-1 text-sm text-gray-500">{{ __('No files uploaded.') }}</p>
            @endforelse
        </div>
    @endforeach
</div>
