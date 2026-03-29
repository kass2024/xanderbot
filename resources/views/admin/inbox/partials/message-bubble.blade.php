@php
    $out = $message->direction === 'outgoing';
    $bubble = $out
        ? 'bg-[#005c4b] text-[#e9edef] rounded-br-sm rounded-2xl'
        : 'bg-[#202c33] text-[#e9edef] rounded-bl-sm rounded-2xl';
@endphp
<div data-message-id="{{ $message->id }}" class="flex {{ $out ? 'justify-end' : 'justify-start' }}">
    <div class="max-w-[85%] sm:max-w-[70%]">
        <div class="px-3 py-2 text-sm shadow-sm {{ $bubble }}">
            @if(($message->media_type ?? null) === 'image' && ($message->media_url ?? null))
                <a href="{{ $message->media_url }}" target="_blank" rel="noopener" class="mb-1 block">
                    <img src="{{ $message->media_url }}" alt="" class="max-h-48 max-w-[240px] rounded-lg object-cover" loading="lazy">
                </a>
            @endif
            @if(($message->media_type ?? null) === 'audio' && ($message->media_url ?? null))
                <audio controls preload="metadata" class="mb-1 w-52 max-w-full" src="{{ $message->media_url }}"></audio>
            @endif
            @if(filled($message->content) && $message->content !== '[Attachment]')
                <div class="whitespace-pre-wrap">{!! nl2br(e($message->content)) !!}</div>
            @endif
        </div>
        <div class="mt-0.5 text-[10px] text-[#8696a0] {{ $out ? 'text-right' : '' }}">
            {{ $message->created_at?->format('H:i') }}
            @if($out && ($message->source ?? null))
                <span class="ml-1 opacity-70">· {{ str_replace('_', ' ', $message->source) }}</span>
            @endif
        </div>
    </div>
</div>
