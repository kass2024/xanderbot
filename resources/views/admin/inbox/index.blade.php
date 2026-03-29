@extends('layouts.admin')

@section('title', 'Inbox')

@section('content')

@php
    $pollUrl = $activeConversation
        ? route('admin.inbox.fetch', $activeConversation)
        : null;
@endphp

<div class="-m-4 flex min-h-[calc(100vh-3.5rem)] overflow-hidden bg-[#0b141a] font-sans antialiased lg:-m-8" style="min-height: 560px;">

    {{-- Conversation list (WhatsApp-style rail) --}}
    <aside id="inbox-sidebar"
           class="fixed inset-y-0 left-0 z-40 flex w-full max-w-[380px] flex-col border-r border-white/5 bg-[#111b21] transition-transform duration-200 md:static md:z-0 md:max-w-[360px] md:translate-x-0 -translate-x-full">
        <div class="flex items-center justify-between gap-2 border-b border-white/5 px-3 py-3">
            <h2 class="text-sm font-semibold tracking-wide text-[#e9edef]">Chats</h2>
            <div class="flex flex-wrap items-center justify-end gap-1.5">
                <a href="{{ route('admin.dashboard') }}"
                   class="rounded-lg bg-xander-navy px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-xander-secondary">Overview</a>
                <a href="{{ url('/admin/bulk') }}"
                   class="rounded-lg bg-emerald-600 px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-emerald-700">Bulk</a>
            </div>
        </div>

        <form method="get" action="{{ route('admin.inbox.index') }}" class="border-b border-white/5 p-2">
            @if(request('conversation'))
                <input type="hidden" name="conversation" value="{{ request('conversation') }}">
            @endif
            <input type="hidden" name="filter" value="{{ $filter }}">
            <div class="flex gap-2">
                <input type="search" name="search" value="{{ $search }}"
                       placeholder="Search or start new chat"
                       class="min-w-0 flex-1 rounded-xl border-0 bg-[#202c33] px-3 py-2 text-sm text-[#e9edef] placeholder:text-[#8696a0] focus:ring-2 focus:ring-[#00a884]">
                <button type="submit" class="shrink-0 rounded-xl bg-[#00a884] px-3 py-2 text-xs font-bold text-[#111b21]">Go</button>
            </div>
        </form>

        <div class="flex gap-1 overflow-x-auto border-b border-white/5 px-2 py-2 text-[11px] font-semibold">
            @foreach(['all','unread','human','bot','closed'] as $f)
                <a href="{{ route('admin.inbox.index', array_filter(['filter' => $f, 'search' => $search, 'conversation' => request('conversation')])) }}"
                   class="whitespace-nowrap rounded-full px-3 py-1 {{ $filter === $f ? 'bg-[#00a884] text-[#111b21]' : 'bg-[#202c33] text-[#8696a0] hover:text-[#e9edef]' }}">
                    {{ ucfirst($f) }}
                </a>
            @endforeach
        </div>

        <div class="flex-1 overflow-y-auto">
            @foreach($conversations as $conversation)
                <a href="{{ route('admin.inbox.index', ['conversation' => $conversation->id, 'filter' => $filter, 'search' => $search]) }}"
                   class="flex items-center gap-3 border-b border-white/5 px-3 py-2.5 hover:bg-[#202c33] {{ (string)request('conversation') === (string)$conversation->id ? 'bg-[#2a3942]' : '' }}">
                    <div class="relative shrink-0">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-[#6b7c85] text-sm font-bold text-white">
                            {{ strtoupper(substr($conversation->customer_name ?? 'U', 0, 1)) }}
                        </div>
                        @if($conversation->is_online)
                            <span class="absolute bottom-0 right-0 h-3 w-3 rounded-full border-2 border-[#111b21] bg-[#00a884]" title="Online"></span>
                        @endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-[#e9edef]">
                            {{ $conversation->customer_name ?? $conversation->phone_number }}
                            @if($conversation->status === 'human')
                                <span class="ml-1 rounded bg-amber-500/90 px-1.5 py-0.5 text-[10px] font-bold text-[#111b21]">AGENT</span>
                            @endif
                        </p>
                        <p class="truncate text-xs text-[#8696a0]">{{ $conversation->customer_email ?? $conversation->phone_number }}</p>
                    </div>
                    @if($conversation->unread_count > 0)
                        <span class="shrink-0 rounded-full bg-[#00a884] px-2 py-0.5 text-[11px] font-bold text-[#111b21]">{{ $conversation->unread_count }}</span>
                    @endif
                </a>
            @endforeach
        </div>

        <div class="border-t border-white/5 p-2 text-center text-[11px] text-[#8696a0]">
            {{ $conversations->links() }}
        </div>
    </aside>

    {{-- Main thread --}}
    <section class="flex min-w-0 flex-1 flex-col bg-[#0b141a] md:bg-[#0b141a]">

        @if($activeConversation)

            {{-- Header --}}
            <header class="flex items-center justify-between gap-2 border-b border-white/5 bg-[#202c33] px-2 py-2 sm:px-4">
                <div class="flex min-w-0 items-center gap-3">
                    <button type="button" onclick="document.getElementById('inbox-sidebar').classList.toggle('-translate-x-full')"
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-[#e9edef] md:hidden hover:bg-white/10" aria-label="Open chats">☰</button>
                    <div class="relative shrink-0">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-[#6b7c85] text-sm font-bold text-white">
                            {{ strtoupper(substr($activeConversation->customer_name ?? 'U', 0, 1)) }}
                        </div>
                        <span class="absolute bottom-0 right-0 h-2.5 w-2.5 rounded-full border-2 border-[#202c33] {{ $activeConversation->is_online ? 'bg-[#00a884]' : 'bg-[#8696a0]' }}"></span>
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-[#e9edef]">{{ $activeConversation->customer_name }}</p>
                        <p id="presence-line" class="truncate text-xs text-[#8696a0]">{{ $activeConversation->customerLastSeenLabel() }}</p>
                    </div>
                </div>

                <div class="hidden flex-wrap items-center justify-end gap-1.5 sm:flex">
                    <form method="POST" action="{{ route('admin.inbox.toggle', $activeConversation) }}" class="inline">@csrf
                        <button type="submit" class="rounded-lg px-3 py-1.5 text-xs font-semibold {{ $activeConversation->status === 'bot' ? 'bg-amber-500 text-[#111b21]' : 'bg-[#00a884] text-[#111b21]' }}">
                            {{ $activeConversation->status === 'bot' ? 'Handoff to human' : 'Back to bot' }}
                        </button>
                    </form>
                    <form method="POST" action="{{ route('admin.inbox.close', $activeConversation) }}" class="inline">@csrf
                        <button type="submit" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">Close</button>
                    </form>
                    <form method="POST" action="{{ route('admin.inbox.delete', $activeConversation) }}" class="inline" onsubmit="return confirm('Delete conversation?')">@csrf @method('DELETE')
                        <button type="submit" class="rounded-lg bg-[#2a3942] px-3 py-1.5 text-xs font-semibold text-[#e9edef]">Delete</button>
                    </form>
                </div>
            </header>

            {{-- Messages --}}
            <div id="chatBox" class="chat-bg flex-1 overflow-y-auto px-2 py-4 sm:px-6"
                 data-poll-url="{{ $pollUrl }}">
                @foreach($activeConversation->messages as $message)
                    @include('admin.inbox.partials.message-bubble', ['message' => $message])
                @endforeach
            </div>

            {{-- Composer (WhatsApp-like) --}}
            <div class="border-t border-white/5 bg-[#202c33] p-2 sm:p-3">
                <form id="reply-form" method="POST" action="{{ route('admin.inbox.reply', $activeConversation) }}" enctype="multipart/form-data" class="flex items-end gap-2">
                    @csrf
                    <input type="file" id="attachment-input" name="attachment" accept="image/*,application/pdf,.doc,.docx,audio/*,.mp3,.m4a,.ogg,.opus,.webm" class="hidden">

                    <button type="button" onclick="document.getElementById('attachment-input').click()"
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-[#8696a0] hover:bg-[#2a3942] hover:text-[#e9edef]" title="Attach" aria-label="Attach file">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                    </button>

                    <button type="button" id="mic-btn" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-[#8696a0] hover:bg-[#2a3942] hover:text-[#e9edef]" title="Hold to record" aria-label="Record voice note">
                        <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"/><path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/></svg>
                    </button>

                    <div class="relative min-w-0 flex-1">
                        <input type="text" name="message" id="message-input" placeholder="Type a message"
                               class="w-full rounded-3xl border-0 bg-[#2a3942] px-4 py-3 text-sm text-[#e9edef] placeholder:text-[#8696a0] focus:ring-2 focus:ring-[#00a884]">
                        <p id="record-indicator" class="pointer-events-none absolute inset-0 hidden items-center rounded-3xl bg-red-600/90 px-4 text-xs font-bold text-white">Recording… release to stop</p>
                    </div>

                    <button type="submit" class="flex h-11 min-w-[4.5rem] items-center justify-center rounded-full bg-[#00a884] px-4 text-sm font-bold text-[#111b21] hover:bg-[#06cf9c]">
                        Send
                    </button>
                </form>
                <p class="mt-1 text-center text-[10px] text-[#8696a0]">Voice notes use your browser mic (WebM). WhatsApp Cloud API may require MP3/OGG for delivery—upload an audio file if send fails.</p>
            </div>

        @else
            <div class="flex flex-1 flex-col items-center justify-center gap-3 p-8 text-center text-[#8696a0]">
                <p class="text-lg font-semibold text-[#e9edef]">Select a conversation</p>
                <p class="max-w-sm text-sm">Choose a chat on the left to reply with text, files, or voice notes.</p>
                <button type="button" class="rounded-full bg-[#00a884] px-4 py-2 text-sm font-bold text-[#111b21] md:hidden"
                        onclick="document.getElementById('inbox-sidebar').classList.remove('-translate-x-full')">Open chats</button>
            </div>
        @endif
    </section>
</div>

@if($activeConversation && $pollUrl)
@push('styles')
<style>
.chat-bg {
    background-color: #0b141a;
    background-image:
        radial-gradient(circle at 1px 1px, rgba(134,150,160,0.08) 1px, transparent 0);
    background-size: 24px 24px;
}
</style>
@endpush
@endif

@if($activeConversation && $pollUrl)
@push('scripts')
<script>
(function () {
    const chatBox = document.getElementById('chatBox');
    const pollUrl = chatBox?.dataset.pollUrl;
    const presenceLine = document.getElementById('presence-line');
    if (!chatBox || !pollUrl) return;

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function renderMessage(m) {
        const out = m.direction === 'outgoing';
        const align = out ? 'justify-end' : 'justify-start';
        const bubble = out
            ? 'bg-[#005c4b] text-[#e9edef] rounded-br-sm rounded-2xl'
            : 'bg-[#202c33] text-[#e9edef] rounded-bl-sm rounded-2xl';
        let inner = '';
        if (m.media_type === 'image' && m.media_url) {
            inner += `<a href="${escapeHtml(m.media_url)}" target="_blank" rel="noopener"><img src="${escapeHtml(m.media_url)}" alt="" class="mb-1 max-h-48 max-w-[240px] rounded-lg object-cover" loading="lazy"></a>`;
        }
        if (m.media_type === 'audio' && m.media_url) {
            inner += `<audio controls class="mb-1 w-52 max-w-full" preload="metadata" src="${escapeHtml(m.media_url)}"></audio>`;
        }
        if (m.content && m.content !== '[Attachment]') {
            inner += `<div class="whitespace-pre-wrap text-sm">${escapeHtml(m.content).replace(/\n/g, '<br>')}</div>`;
        }
        if (!inner) inner = '<span class="text-xs opacity-70">(empty)</span>';
        return `<div class="flex ${align} mb-1" data-message-id="${m.id}">
            <div class="max-w-[85%] sm:max-w-[70%]">
                <div class="px-3 py-2 shadow-sm ${bubble}">${inner}</div>
                <div class="mt-0.5 text-[10px] text-[#8696a0] ${out ? 'text-right' : ''}">${escapeHtml(m.time || '')}</div>
            </div>
        </div>`;
    }

    function scrollBottom() {
        chatBox.scrollTop = chatBox.scrollHeight;
    }

    let lastIds = new Set(@json($activeConversation->messages->pluck('id')->all()));

    async function poll() {
        try {
            const r = await fetch(pollUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!r.ok) return;
            const data = await r.json();
            if (presenceLine) {
                presenceLine.textContent = data.online ? 'Online' : (data.last_seen ? ('Last seen ' + data.last_seen) : 'Offline');
            }
            const msgs = data.messages || [];
            let appended = false;
            for (const m of msgs) {
                if (!lastIds.has(m.id)) {
                    chatBox.insertAdjacentHTML('beforeend', renderMessage(m));
                    lastIds.add(m.id);
                    appended = true;
                }
            }
            if (appended) scrollBottom();
        } catch (e) {}
    }

    setInterval(poll, 4000);
    scrollBottom();

    const micBtn = document.getElementById('mic-btn');
    const recordInd = document.getElementById('record-indicator');
    const fileInput = document.getElementById('attachment-input');
    let mediaRecorder = null;
    let chunks = [];

    if (micBtn && navigator.mediaDevices?.getUserMedia) {
        let pressTimer = null;
        micBtn.addEventListener('mousedown', startRec);
        micBtn.addEventListener('touchstart', function (e) { e.preventDefault(); startRec(); }, { passive: false });
        window.addEventListener('mouseup', stopRec);
        window.addEventListener('touchend', stopRec);
    }

    async function startRec() {
        if (mediaRecorder) return;
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            chunks = [];
            mediaRecorder = new MediaRecorder(stream);
            mediaRecorder.ondataavailable = e => { if (e.data.size) chunks.push(e.data); };
            mediaRecorder.onstop = () => {
                stream.getTracks().forEach(t => t.stop());
                const blob = new Blob(chunks, { type: mediaRecorder.mimeType || 'audio/webm' });
                const dt = new DataTransfer();
                dt.items.add(new File([blob], 'voice-note.webm', { type: blob.type }));
                if (fileInput) fileInput.files = dt.files;
                if (recordInd) { recordInd.classList.add('hidden'); recordInd.classList.remove('flex'); }
                mediaRecorder = null;
            };
            mediaRecorder.start();
            if (recordInd) { recordInd.classList.remove('hidden'); recordInd.classList.add('flex'); }
        } catch (e) {
            alert('Microphone access denied or not available.');
        }
    }

    function stopRec() {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
        }
    }
})();
</script>
@endpush
@endif

@endsection
