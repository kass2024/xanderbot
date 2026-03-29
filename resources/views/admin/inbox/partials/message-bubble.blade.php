@php
    $out = $message->direction === 'outgoing';
    $failed = $out && ($message->status ?? '') === 'failed';
@endphp
<div data-message-id="{{ $message->id }}" class="inbox-msg-row flex {{ $out ? 'justify-end' : 'justify-start' }} mb-3">
    <div class="inbox-msg-col max-w-[min(100%,28rem)] sm:max-w-[min(100%,32rem)]">
        <div class="inbox-bubble {{ $out ? 'inbox-bubble--out' : 'inbox-bubble--in' }} shadow-sm">
            @if(($message->media_type ?? null) === 'image' && ($message->media_url ?? null))
                <a href="{{ $message->media_url }}" target="_blank" rel="noopener" class="inbox-media-link block overflow-hidden rounded-xl">
                    <img src="{{ $message->media_url }}" alt="" class="inbox-media-img max-h-64 w-full object-cover" loading="lazy">
                </a>
            @endif
            @if(($message->media_type ?? null) === 'video' && ($message->media_url ?? null))
                <div class="inbox-media-card overflow-hidden rounded-xl">
                    <video controls preload="metadata" playsinline class="inbox-video max-h-64 w-full bg-black/20" src="{{ $message->media_url }}"></video>
                </div>
            @endif
            @if(($message->media_type ?? null) === 'audio' && ($message->media_url ?? null))
                <div class="inbox-voice-row mb-2 flex items-center gap-2 rounded-xl bg-black/15 px-2 py-2">
                    <span class="inbox-voice-icon flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[var(--inbox-accent)] text-[var(--inbox-accent-contrast)]" aria-hidden="true">
                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"/><path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/></svg>
                    </span>
                    <audio controls preload="metadata" class="min-w-0 flex-1 max-w-full h-9" src="{{ $message->media_url }}"></audio>
                </div>
            @endif
            @if(($message->media_type ?? null) === 'document' && ($message->media_url ?? null))
                <a href="{{ $message->media_url }}" target="_blank" rel="noopener" class="inbox-doc-card mb-2 flex items-center gap-3 rounded-xl border border-[var(--inbox-border)] bg-black/10 px-3 py-2.5 transition hover:bg-black/15">
                    <span class="text-2xl" aria-hidden="true">📄</span>
                    <span class="min-w-0 flex-1 text-sm font-medium truncate">{{ $message->filename ?? 'Document' }}</span>
                    <span class="shrink-0 text-xs font-semibold text-[var(--inbox-accent)]">Open</span>
                </a>
            @endif
            @if(filled($message->content) && $message->content !== '[Attachment]')
                <div class="inbox-msg-text whitespace-pre-wrap text-sm leading-relaxed">{!! nl2br(e($message->content)) !!}</div>
            @endif
        </div>
        <div class="inbox-msg-meta mt-1 flex flex-wrap items-center gap-x-2 text-[11px] {{ $out ? 'justify-end' : 'justify-start' }}">
            <span class="opacity-80">{{ $message->created_at?->format('H:i') }}</span>
            @if($out)
                @if($failed)
                    <span class="rounded bg-red-500/90 px-1.5 py-0.5 text-[10px] font-bold text-white">Not delivered</span>
                @elseif(filled($message->status ?? null) && $message->status === 'sent')
                    <span class="opacity-60">✓</span>
                @endif
                @if($message->source ?? null)
                    <span class="opacity-60">· {{ str_replace('_', ' ', $message->source) }}</span>
                @endif
            @endif
        </div>
    </div>
</div>
