<div>
    @if(empty($notes))
        <p class="visit-voice-notes__empty">No voice notes recorded for this visit.</p>
    @else
        <div class="visit-voice-notes">
            @foreach($notes as $note)
                <div class="visit-voice-note">
                    <div class="visit-voice-note__meta">
                        <span>{{ $note['language'] ?? 'Auto-detect' }}</span>
                        <span>{{ $note['duration_seconds'] !== null ? $note['duration_seconds'].'s' : '—' }}</span>
                    </div>
                    @if($note['play_url'])
                        <audio controls preload="none" class="visit-voice-note__player" src="{{ $note['play_url'] }}"></audio>
                    @else
                        <p class="visit-voice-note__missing">No audio attached</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <style>
        .visit-voice-notes {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(16rem, 1fr));
            gap: 0.75rem;
        }

        .visit-voice-note {
            border: 1px solid rgb(229 231 235);
            border-radius: 0.75rem;
            padding: 0.75rem;
        }

        .dark .visit-voice-note {
            border-color: rgb(255 255 255 / 10%);
        }

        .visit-voice-note__meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: rgb(107 114 128);
            margin-bottom: 0.5rem;
        }

        .dark .visit-voice-note__meta {
            color: rgb(156 163 175);
        }

        .visit-voice-note__player {
            width: 100%;
        }

        .visit-voice-note__missing {
            font-size: 0.875rem;
            color: rgb(107 114 128);
            margin: 0;
        }

        .dark .visit-voice-note__missing {
            color: rgb(156 163 175);
        }

        .visit-voice-notes__empty {
            font-size: 0.875rem;
            color: rgb(107 114 128);
        }

        .dark .visit-voice-notes__empty {
            color: rgb(156 163 175);
        }
    </style>
</div>
