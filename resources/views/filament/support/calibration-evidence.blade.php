@php
    $labels = [
        'calibration-certificates' => __('Calibration certificates'),
        'calibration-evidence' => __('Calibration evidence'),
    ];
@endphp
<div class="space-y-4" data-testid="calibration-evidence">
    @foreach($collections as $collection)
        @php($files = $calibration->media->where('collection_name', $collection))
        <div>
            <div class="text-sm font-medium">{{ $labels[$collection] }}</div>
            @forelse($files as $media)
                <div class="mt-1 flex items-center gap-3 text-sm">
                    <span class="truncate">{{ $media->file_name }}</span>
                    <a class="text-primary-600" href="{{ route('admin.equipment-calibrations.media.preview', ['calibration' => $calibration, 'media' => $media]) }}" target="_blank" rel="noopener">{{ __('Preview') }}</a>
                    <a class="text-primary-600" href="{{ route('admin.equipment-calibrations.media.download', ['calibration' => $calibration, 'media' => $media]) }}">{{ __('Download') }}</a>
                </div>
            @empty
                <p class="mt-1 text-sm text-gray-500">{{ __('No files uploaded.') }}</p>
            @endforelse
        </div>
    @endforeach
</div>
