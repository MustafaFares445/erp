@php
    $labels = [
        'installation-photos' => __('Installation photos'),
        'commissioning-documents' => __('Commissioning documents'),
        'customer-acceptance' => __('Customer acceptance'),
    ];
@endphp
<div class="space-y-4" data-testid="installation-evidence">
    @foreach($collections as $collection)
        @php($files = $installation->media->where('collection_name', $collection))
        <div>
            <div class="text-sm font-medium">{{ $labels[$collection] }}</div>
            @forelse($files as $media)
                <div class="mt-1 flex items-center gap-3 text-sm">
                    <span class="truncate">{{ $media->file_name }}</span>
                    <a class="text-primary-600" href="{{ route('admin.equipment-installations.media.preview', ['installation' => $installation, 'media' => $media]) }}" target="_blank" rel="noopener">{{ __('Preview') }}</a>
                    <a class="text-primary-600" href="{{ route('admin.equipment-installations.media.download', ['installation' => $installation, 'media' => $media]) }}">{{ __('Download') }}</a>
                </div>
            @empty
                <p class="mt-1 text-sm text-gray-500">{{ __('No files uploaded.') }}</p>
            @endforelse
        </div>
    @endforeach
</div>
