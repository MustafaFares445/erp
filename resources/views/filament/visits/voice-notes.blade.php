<div>
    @if(empty($notes))
        <div class="ierp-empty">No voice notes recorded for this visit.</div>
    @else
        <div class="grid grid-cols-[repeat(auto-fill,minmax(16rem,1fr))] gap-3">
            @foreach($notes as $note)
                <div class="ierp-tile">
                    <div class="mb-2 flex justify-between text-xs text-gray-500 dark:text-gray-400">
                        <span>{{ $note['language'] ?? 'Auto-detect' }}</span>
                        <span>{{ $note['duration_seconds'] !== null ? $note['duration_seconds'].'s' : '—' }}</span>
                    </div>
                    @if($note['play_url'])
                        <audio controls preload="none" class="w-full" src="{{ $note['play_url'] }}"></audio>
                    @else
                        <p class="text-sm text-gray-500 dark:text-gray-400">No audio attached</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
