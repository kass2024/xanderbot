@php
    $p = is_array($placement ?? null) ? $placement : [];
    $status = $p['status'] ?? null;
    $targets = $p['targets'] ?? [];
    $igImp = (int) ($p['instagram_impressions'] ?? 0);
    $igClicks = (int) ($p['instagram_clicks'] ?? 0);
    $fbImp = (int) ($p['facebook_impressions'] ?? 0);
    $targetsIg = (bool) ($p['targets_instagram'] ?? false);
    $showEnable = in_array($status, ['pending', 'not_configured'], true) && !empty($ad->meta_ad_id);
@endphp
<div class="space-y-1">
    @if(count($targets))
        <div class="text-[11px] text-slate-500" title="Ad set placement settings">
            Target: {{ implode(', ', $targets) }}
        </div>
    @endif

    @if($status === 'live')
        <span class="inline-flex rounded-md bg-fuchsia-50 px-2 py-0.5 text-xs font-semibold text-fuchsia-800 ring-1 ring-fuchsia-600/15" title="{{ number_format($igImp) }} impressions, {{ number_format($igClicks) }} clicks on Instagram">
            IG live · {{ number_format($igImp) }} impr.
        </span>
    @elseif($status === 'enabled')
        <span class="inline-flex rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-800 ring-1 ring-emerald-600/15" title="{{ $p['status_label'] ?? 'IG enabled on Meta' }}">
            IG enabled
        </span>
        @if($fbImp > 0)
            <span class="text-[11px] text-slate-500">FB {{ number_format($fbImp) }} impr. · IG impressions pending</span>
        @endif
    @elseif($status === 'pending')
        <span class="inline-flex rounded-md bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800 ring-1 ring-amber-600/15">
            FB only · IG pending
        </span>
    @elseif($fbImp > 0)
        <span class="inline-flex rounded-md bg-sky-50 px-2 py-0.5 text-xs font-semibold text-sky-800 ring-1 ring-sky-600/15">
            Facebook only
        </span>
    @elseif($targetsIg)
        <span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600 ring-1 ring-slate-400/20">
            IG targeted · no data yet
        </span>
    @else
        <span class="text-xs text-slate-400">—</span>
    @endif

    @if($showEnable)
        <form method="POST" action="{{ route('admin.ads.enable-instagram', $ad) }}" class="m-0">
            @csrf
            <button type="submit" class="text-[11px] font-semibold text-fuchsia-700 underline decoration-fuchsia-300 underline-offset-2 hover:text-fuchsia-900">
                Enable IG
            </button>
        </form>
    @endif
</div>
