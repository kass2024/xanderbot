@extends('layouts.admin')

@section('content')

@php
    $ig = $igDelivery ?? [];
    $igStatus = $ig['status'] ?? 'not_configured';
    $statusBadge = match ($igStatus) {
        'live' => 'bg-fuchsia-100 text-fuchsia-800',
        'enabled' => 'bg-emerald-100 text-emerald-800',
        'pending' => 'bg-amber-100 text-amber-800',
        default => 'bg-slate-100 text-slate-700',
    };
    $redactToken = static function (string $cmd): string {
        return preg_replace('/access_token=[^&"\']+/i', 'access_token=REDACTED', $cmd) ?? $cmd;
    };
@endphp

<div class="max-w-7xl mx-auto py-10 space-y-8">

<div class="flex flex-wrap items-start justify-between gap-4">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">Ad insights</h1>
        <p class="text-sm text-gray-500 mt-1">{{ $ad->name }}</p>
        @if($ad->meta_ad_id)
            <p class="text-xs text-gray-400 mt-1 font-mono">Meta ad {{ $ad->meta_ad_id }}</p>
        @endif
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('admin.ads.index') }}" class="bg-gray-800 text-white px-4 py-2 rounded-lg text-sm hover:bg-black">Back to ads</a>
        @if(in_array($igStatus, ['pending', 'not_configured'], true) && $ad->meta_ad_id)
            <form method="POST" action="{{ route('admin.ads.enable-instagram', $ad) }}">
                @csrf
                <button type="submit" class="bg-fuchsia-700 text-white px-4 py-2 rounded-lg text-sm hover:bg-fuchsia-800">Enable IG on Meta</button>
            </form>
        @endif
    </div>
</div>

{{-- Instagram delivery (primary clarity block) --}}
<div class="bg-white rounded-2xl shadow border p-6 space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold text-gray-900">Instagram delivery</h2>
        <span class="text-xs font-semibold px-3 py-1 rounded-full {{ $statusBadge }}">
            {{ $ig['status_label'] ?? 'Unknown' }}
        </span>
    </div>

    <p class="text-sm text-gray-600">
        <strong>IG enabled</strong> means Meta has the ad set, creative, and ad configured for Instagram.
        <strong>IG live</strong> means Meta insights show Instagram impressions — this can lag by hours after enabling.
    </p>

    @if(!empty($ig['delivery_warning']))
        <div class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-900">
            {{ $ig['delivery_warning'] }}
        </div>
    @endif

    <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-4 text-sm">
        <div class="rounded-xl bg-gray-50 p-4">
            <p class="text-xs text-gray-500">Facebook impressions</p>
            <p class="text-xl font-bold tabular-nums">{{ number_format($ig['facebook_impressions'] ?? 0) }}</p>
            <p class="text-xs text-gray-500">${{ number_format($ig['facebook_spend'] ?? 0, 2) }} spend</p>
        </div>
        <div class="rounded-xl bg-gray-50 p-4">
            <p class="text-xs text-gray-500">Instagram impressions</p>
            <p class="text-xl font-bold tabular-nums text-fuchsia-700">{{ number_format($ig['instagram_impressions'] ?? 0) }}</p>
            <p class="text-xs text-gray-500">${{ number_format($ig['instagram_spend'] ?? 0, 2) }} spend</p>
        </div>
        <div class="rounded-xl bg-gray-50 p-4">
            <p class="text-xs text-gray-500">instagram_user_id</p>
            <p class="font-mono text-xs break-all">{{ $ig['instagram_user_id'] ?? '—' }}</p>
        </div>
        <div class="rounded-xl bg-gray-50 p-4">
            <p class="text-xs text-gray-500">Audience Network impr.</p>
            <p class="text-xl font-bold tabular-nums text-amber-700">{{ number_format($ig['audience_network_impressions'] ?? 0) }}</p>
            <p class="text-xs text-gray-500">Not Facebook/Instagram</p>
        </div>
        <div class="rounded-xl bg-gray-50 p-4">
            <p class="text-xs text-gray-500">Enabled at (local)</p>
            <p class="text-sm font-medium">{{ $ig['instagram_enabled_at'] ? \Carbon\Carbon::parse($ig['instagram_enabled_at'])->format('M j, Y g:i A') : '—' }}</p>
        </div>
    </div>

    <div>
        <h3 class="text-sm font-semibold text-gray-800 mb-2">Configuration checklist</h3>
        <ul class="space-y-1 text-sm">
            @foreach($ig['checks'] ?? [] as $check)
                <li class="{{ ($check['ok'] ?? false) ? 'text-emerald-700' : 'text-amber-800' }}">
                    {{ ($check['ok'] ?? false) ? '✓' : '✗' }} {{ $check['label'] }}
                    @if(!empty($check['note']))
                        <span class="text-gray-500">({{ $check['note'] }})</span>
                    @endif
                </li>
            @endforeach
        </ul>
        @if(!empty($ig['meta_creative_error']))
            <p class="text-xs text-red-600 mt-2">Meta creative check: {{ $ig['meta_creative_error'] }}</p>
        @endif
    </div>

    @if(!empty($ig['targets']))
        <p class="text-xs text-gray-500">Ad set targets: {{ implode(', ', $ig['targets']) }}</p>
    @endif
</div>

{{-- Ad summary --}}
<div class="bg-white rounded-2xl shadow border p-6">
    <div class="grid grid-cols-2 md:grid-cols-4 gap-6 text-sm">
        <div>
            <p class="text-xs text-gray-500">Delivery</p>
            @if($ad->status === 'ACTIVE')
                <span class="bg-green-100 text-green-700 text-xs px-3 py-1 rounded-full">Active</span>
            @else
                <span class="bg-yellow-100 text-yellow-700 text-xs px-3 py-1 rounded-full">Paused</span>
            @endif
        </div>
        <div>
            <p class="text-xs text-gray-500">Ad set</p>
            <p class="font-medium">{{ $ad->adSet->name ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-500">Campaign</p>
            <p class="font-medium">{{ $ad->adSet->campaign->name ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-500">Daily budget</p>
            <p class="font-medium">${{ number_format($ad->daily_budget ?? 0, 2) }}</p>
        </div>
    </div>
</div>

{{-- Performance KPIs --}}
<div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <div class="bg-white border rounded-2xl shadow p-5">
        <p class="text-xs text-gray-500">Impressions</p>
        <p class="text-2xl font-bold">{{ number_format($ad->impressions ?? 0) }}</p>
    </div>
    <div class="bg-white border rounded-2xl shadow p-5">
        <p class="text-xs text-gray-500">Clicks</p>
        <p class="text-2xl font-bold text-blue-600">{{ number_format($ad->clicks ?? 0) }}</p>
    </div>
    <div class="bg-white border rounded-2xl shadow p-5">
        <p class="text-xs text-gray-500">CTR</p>
        <p class="text-2xl font-bold text-purple-600">
            @if(($ad->impressions ?? 0) > 0)
                {{ round(($ad->clicks / $ad->impressions) * 100, 2) }}%
            @else
                0%
            @endif
        </p>
    </div>
    <div class="bg-white border rounded-2xl shadow p-5">
        <p class="text-xs text-gray-500">Spend</p>
        <p class="text-2xl font-bold text-green-600">${{ number_format($ad->spend ?? 0, 2) }}</p>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-6">
    {{-- Platform breakdown --}}
    <div class="bg-white border rounded-2xl shadow p-6">
        <h2 class="text-lg font-semibold mb-4">Delivery by platform (Meta)</h2>
        <table class="w-full text-sm">
            <thead class="text-gray-500 border-b">
                <tr>
                    <th class="text-left py-2">Platform</th>
                    <th class="text-right">Impr.</th>
                    <th class="text-right">Clicks</th>
                    <th class="text-right">Spend</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse($placements ?? [] as $row)
                    <tr>
                        <td class="py-2 capitalize">{{ $row['placement'] ?? '—' }}</td>
                        <td class="text-right tabular-nums">{{ number_format($row['impressions'] ?? 0) }}</td>
                        <td class="text-right tabular-nums">{{ number_format($row['clicks'] ?? 0) }}</td>
                        <td class="text-right tabular-nums">${{ number_format($row['spend'] ?? 0, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-gray-400 py-6">No platform breakdown yet</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Cost metrics --}}
    <div class="bg-white border rounded-2xl shadow p-6">
        <h2 class="text-lg font-semibold mb-4">Cost metrics</h2>
        <dl class="space-y-3 text-sm">
            <div class="flex justify-between">
                <dt class="text-gray-500">CPC</dt>
                <dd class="font-semibold">
                    @if(($ad->clicks ?? 0) > 0)
                        ${{ number_format($ad->spend / $ad->clicks, 2) }}
                    @else
                        —
                    @endif
                </dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-gray-500">CPM</dt>
                <dd class="font-semibold">
                    @if(($ad->impressions ?? 0) > 0)
                        ${{ number_format(($ad->spend / $ad->impressions) * 1000, 2) }}
                    @else
                        —
                    @endif
                </dd>
            </div>
        </dl>
        <div class="mt-4 space-y-1 text-sm">
            @if($ad->status === 'ACTIVE')
                <div class="text-green-600">✓ Ad is active on Meta</div>
            @else
                <div class="text-amber-600">⚠ Ad is not active</div>
            @endif
            @if(($ad->daily_spend ?? 0) >= ($ad->daily_budget ?? 0) && ($ad->daily_budget ?? 0) > 0)
                <div class="text-red-600">⚠ Daily budget reached</div>
            @endif
        </div>
    </div>
</div>

{{-- Audience + devices --}}
<div class="grid lg:grid-cols-2 gap-6">
    <div class="bg-white border rounded-2xl shadow p-6">
        <h2 class="text-lg font-semibold mb-4">Audience</h2>
        <div class="grid sm:grid-cols-3 gap-4 text-sm">
            <div>
                <h3 class="font-semibold mb-2">Countries</h3>
                @forelse($audience['countries'] ?? [] as $country => $impressions)
                    <div class="flex justify-between"><span>{{ $country }}</span><span>{{ number_format($impressions) }}</span></div>
                @empty
                    <p class="text-gray-400">No data</p>
                @endforelse
            </div>
            <div>
                <h3 class="font-semibold mb-2">Age</h3>
                @foreach($audience['age'] ?? [] as $age => $impressions)
                    <div class="flex justify-between"><span>{{ $age }}</span><span>{{ number_format($impressions) }}</span></div>
                @endforeach
            </div>
            <div>
                <h3 class="font-semibold mb-2">Gender</h3>
                @foreach($audience['gender'] ?? [] as $gender => $impressions)
                    <div class="flex justify-between"><span>{{ ucfirst($gender) }}</span><span>{{ number_format($impressions) }}</span></div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="bg-white border rounded-2xl shadow p-6">
        <h2 class="text-lg font-semibold mb-4">Devices</h2>
        <table class="w-full text-sm">
            <thead class="text-gray-500 border-b">
                <tr><th class="text-left py-2">Device</th><th class="text-right">Impr.</th><th class="text-right">Clicks</th></tr>
            </thead>
            <tbody class="divide-y">
                @forelse($devices ?? [] as $device)
                    <tr>
                        <td class="py-2">{{ $device['device'] }}</td>
                        <td class="text-right">{{ number_format($device['impressions']) }}</td>
                        <td class="text-right">{{ number_format($device['clicks']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-center text-gray-400 py-4">No device data</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Curl debug (server) --}}
<div class="bg-slate-900 rounded-2xl shadow p-6 text-slate-100">
    <h2 class="text-lg font-semibold mb-2">Debug with curl (run on server)</h2>
    <p class="text-xs text-slate-400 mb-4">
        Or run: <code class="bg-slate-800 px-1 rounded">php artisan meta:debug-ad-ig {{ $ad->id }} --run</code>
    </p>
    @foreach($ig['curl_commands'] ?? [] as $block)
        <div class="mb-4">
            <p class="text-xs text-fuchsia-300 mb-1">{{ $block['title'] }}</p>
            <pre class="text-xs overflow-x-auto bg-slate-800 p-3 rounded-lg whitespace-pre-wrap break-all">{{ $redactToken($block['command']) }}</pre>
        </div>
    @endforeach
</div>

@if($ad->creative)
<div class="bg-white border rounded-2xl shadow p-6">
    <h2 class="text-lg font-semibold mb-4">Creative preview</h2>
    <div class="max-w-sm mx-auto bg-gray-50 border rounded-2xl overflow-hidden">
        @php
            $image = $ad->creative->image_url;
            if ($image && !str_starts_with($image, 'http')) {
                $image = asset('storage/creatives/' . basename($image));
            }
        @endphp
        @if($image)
            <img src="{{ $image }}" class="w-full h-56 object-cover" alt="">
        @else
            <div class="h-56 flex items-center justify-center text-gray-400">No image</div>
        @endif
        <div class="p-4 space-y-2">
            @if($ad->creative->headline)
                <p class="font-semibold">{{ $ad->creative->headline }}</p>
            @endif
            @if($ad->creative->body)
                <p class="text-sm text-gray-600">{{ $ad->creative->body }}</p>
            @endif
        </div>
    </div>
</div>
@endif

</div>

@endsection
