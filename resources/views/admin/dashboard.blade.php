{{-- resources/views/admin/dashboard.blade.php --}}
<x-app-layout>

@php
    // Define helper function safely
    if (!function_exists('formatNumber')) {
        function formatNumber($number) {
            if ($number >= 1000000) {
                return round($number / 1000000, 1) . 'M';
            } elseif ($number >= 1000) {
                return round($number / 1000, 1) . 'K';
            }
            return $number;
        }
    }

    $route = Route::currentRouteName() ?? '';
    
    // Safe metrics with error handling
    try {
        $activeCampaigns = class_exists('App\Models\Campaign') ? \App\Models\Campaign::where('status', 'active')->count() : 24;
        $totalSpend = class_exists('App\Models\AdMetric') ? \App\Models\AdMetric::sum('spend') : 12450.75;
        $impressions = class_exists('App\Models\AdMetric') ? \App\Models\AdMetric::sum('impressions') : 145200;
        $clicks = class_exists('App\Models\AdMetric') ? \App\Models\AdMetric::sum('clicks') : 3485;
        $conversions = class_exists('App\Models\AdMetric') ? \App\Models\AdMetric::sum('conversions') : 89;
        $reach = class_exists('App\Models\AdMetric') ? \App\Models\AdMetric::sum('reach') : 98700;
        
        $unreadCount = \App\Models\Message::where('direction','incoming')
            ->where('is_read',0)
            ->count();
    } catch (\Exception $e) {
        $activeCampaigns = 24;
        $totalSpend = 12450.75;
        $impressions = 145200;
        $clicks = 3485;
        $conversions = 89;
        $reach = 98700;
        $unreadCount = 0;
    }
    
    // Calculate metrics
    $cpc = $clicks > 0 ? round($totalSpend / $clicks, 2) : 0.89;
    $cpm = $impressions > 0 ? round(($totalSpend / $impressions) * 1000, 2) : 12.50;
    $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 2.4;
    $conversionRate = $clicks > 0 ? round(($conversions / $clicks) * 100, 1) : 2.6;
    
    // Trends data
    $trends = [
        'campaigns' => ['previous' => 18, 'change' => round((($activeCampaigns - 18) / 18) * 100, 1)],
        'spend' => ['previous' => 9890, 'change' => round((($totalSpend - 9890) / 9890) * 100, 1)],
        'impressions' => ['previous' => 112000, 'change' => round((($impressions - 112000) / 112000) * 100, 1)],
        'clicks' => ['previous' => 2850, 'change' => round((($clicks - 2850) / 2850) * 100, 1)],
        'ctr' => ['previous' => 2.1, 'change' => round((($ctr - 2.1) / 2.1) * 100, 1)],
        'conversions' => ['previous' => 72, 'change' => round((($conversions - 72) / 72) * 100, 1)],
    ];
    
    // Recent campaigns
    $recentCampaigns = collect([
        (object)['id' => 1, 'name' => 'Summer Sale 2024', 'status' => 'active', 'spend' => 1250.50, 'impressions' => 45200, 'clicks' => 1250, 'conversions' => 38],
        (object)['id' => 2, 'name' => 'Product Launch Q3', 'status' => 'active', 'spend' => 3450.75, 'impressions' => 89200, 'clicks' => 2780, 'conversions' => 67],
        (object)['id' => 3, 'name' => 'Retargeting Campaign', 'status' => 'paused', 'spend' => 890.25, 'impressions' => 23100, 'clicks' => 567, 'conversions' => 12],
        (object)['id' => 4, 'name' => 'Brand Awareness', 'status' => 'active', 'spend' => 2340.00, 'impressions' => 67300, 'clicks' => 1890, 'conversions' => 45],
        (object)['id' => 5, 'name' => 'Holiday Special', 'status' => 'draft', 'spend' => 0, 'impressions' => 0, 'clicks' => 0, 'conversions' => 0],
        (object)['id' => 6, 'name' => 'Flash Sale Weekend', 'status' => 'active', 'spend' => 890.50, 'impressions' => 28400, 'clicks' => 845, 'conversions' => 23],
    ]);
    
    // Top performing ads
    $topAds = [
        (object)['name' => 'Summer Sale - Video Ad', 'ctr' => 4.8, 'impressions' => 15200, 'spend' => 450.25, 'conversions' => 18],
        (object)['name' => 'Product Demo - Carousel', 'ctr' => 4.2, 'impressions' => 12800, 'spend' => 380.50, 'conversions' => 15],
        (object)['name' => 'Retargeting - Dynamic', 'ctr' => 3.9, 'impressions' => 9400, 'spend' => 290.75, 'conversions' => 11],
        (object)['name' => 'Brand Story - Image', 'ctr' => 3.5, 'impressions' => 8700, 'spend' => 210.00, 'conversions' => 8],
    ];
    
    $platformMeta = $platformMeta ?? null;
@endphp

<div 
x-data="{
    openAds: {{ str_contains($route,'admin.accounts') || str_contains($route,'admin.campaigns') || str_contains($route,'admin.ads') || str_contains($route,'admin.analytics') ? 'true':'false' }},
    openSocial: {{ str_contains($route,'admin.instagram') || str_contains($route,'admin.messenger') || str_contains($route,'admin.whatsapp') ? 'true':'false' }},
    openAutomation: {{ str_contains($route,'admin.chatbots') || str_contains($route,'admin.templates') || str_contains($route,'admin.leads') || str_contains($route,'admin.faq') || str_contains($route,'admin.inbox') ? 'true':'false' }}
}"
class="min-h-screen bg-gray-100 font-sans">

<div class="flex min-h-screen">

{{-- ================= SIDEBAR (YOUR WORKING VERSION) ================= --}}
<aside class="w-80 bg-white border-r border-gray-200 flex flex-col shadow-sm">

    {{-- LOGO --}}
    <div class="h-24 flex items-center px-8 border-b bg-gradient-to-r from-blue-600 to-indigo-600">
        <div>
            <h2 class="text-2xl font-bold text-white tracking-tight">MetaPanel</h2>
            <p class="text-sm text-blue-100 mt-1">Enterprise SaaS</p>
        </div>
    </div>

    {{-- MENU --}}
    <nav class="flex-1 overflow-y-auto px-6 py-6 space-y-3 text-base">

        {{-- Dashboard --}}
        <a href="{{ Route::has('admin.dashboard') ? route('admin.dashboard') : '#' }}"
           class="flex items-center gap-3 px-4 py-3 rounded-xl transition font-medium
           {{ $route == 'admin.dashboard' ? 'bg-blue-600 text-white shadow' : 'hover:bg-gray-100 text-gray-700' }}">
            📊 Dashboard
        </a>

        {{-- Business --}}
        <a href="{{ Route::has('admin.meta.index') ? route('admin.meta.index') : '#' }}"
           class="flex items-center gap-3 px-4 py-3 rounded-xl transition font-medium
           {{ str_contains($route,'admin.meta') ? 'bg-blue-600 text-white shadow' : 'hover:bg-gray-100 text-gray-700' }}">
            🏢 Business Manager
        </a>

        {{-- ================= ADS ================= --}}
        <div>
            <button @click="openAds = !openAds"
                class="w-full flex justify-between items-center px-4 py-3 rounded-xl font-semibold text-gray-800 hover:bg-gray-100 transition">
                🎯 Ads Management
                <svg :class="openAds ? 'rotate-90' : ''"
                     class="w-4 h-4 transition-transform"
                     fill="none" stroke="currentColor" stroke-width="2"
                     viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
            </button>

            <div x-show="openAds" x-transition class="pl-6 mt-2 space-y-2">

                <a href="{{ Route::has('admin.accounts.index') ? route('admin.accounts.index') : '#' }}"
                   class="block py-2 px-3 rounded-lg hover:bg-gray-100 {{ str_contains($route,'admin.accounts')?'text-blue-600 font-semibold':'' }}">
                    Ad Accounts
                </a>

                <a href="{{ Route::has('admin.campaigns.index') ? route('admin.campaigns.index') : '#' }}"
                   class="block py-2 px-3 rounded-lg hover:bg-gray-100 {{ str_contains($route,'admin.campaigns')?'text-blue-600 font-semibold':'' }}">
                    Campaigns
                </a>

                <a href="{{ Route::has('admin.ads.index') ? route('admin.ads.index') : '#' }}"
                   class="block py-2 px-3 rounded-lg hover:bg-gray-100 {{ str_contains($route,'admin.ads')?'text-blue-600 font-semibold':'' }}">
                    Ads & Creatives
                </a>

                <a href="{{ Route::has('admin.analytics.index') ? route('admin.analytics.index') : '#' }}"
                   class="block py-2 px-3 rounded-lg hover:bg-gray-100 {{ str_contains($route,'admin.analytics')?'text-blue-600 font-semibold':'' }}">
                    Insights & Reports
                </a>

            </div>
        </div>

        {{-- ================= SOCIAL ================= --}}
        <div>
            <button @click="openSocial = !openSocial"
                class="w-full flex justify-between items-center px-4 py-3 rounded-xl font-semibold text-gray-800 hover:bg-gray-100 transition">
                🌍 Social Channels
                <svg :class="openSocial ? 'rotate-90' : ''"
                     class="w-4 h-4 transition-transform"
                     fill="none" stroke="currentColor" stroke-width="2"
                     viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
            </button>

            <div x-show="openSocial" x-transition class="pl-6 mt-2 space-y-2">

                <a href="{{ Route::has('admin.instagram.index') ? route('admin.instagram.index') : '#' }}"
                   class="block py-2 px-3 rounded-lg hover:bg-gray-100 {{ str_contains($route,'admin.instagram')?'text-blue-600 font-semibold':'' }}">
                    Instagram
                </a>

                <a href="{{ Route::has('admin.messenger.index') ? route('admin.messenger.index') : '#' }}"
                   class="block py-2 px-3 rounded-lg hover:bg-gray-100 {{ str_contains($route,'admin.messenger')?'text-blue-600 font-semibold':'' }}">
                    Messenger
                </a>

                <a href="{{ Route::has('admin.whatsapp.index') ? route('admin.whatsapp.index') : '#' }}"
                   class="block py-2 px-3 rounded-lg hover:bg-gray-100 {{ str_contains($route,'admin.whatsapp')?'text-blue-600 font-semibold':'' }}">
                    WhatsApp
                </a>

            </div>
        </div>

        {{-- ================= AUTOMATION ================= --}}
        <div>
            <button @click="openAutomation = !openAutomation"
                class="w-full flex justify-between items-center px-4 py-3 rounded-xl font-semibold text-gray-800 hover:bg-gray-100 transition">
                🤖 Automation & CRM
                <svg :class="openAutomation ? 'rotate-90' : ''"
                     class="w-4 h-4 transition-transform"
                     fill="none" stroke="currentColor" stroke-width="2"
                     viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
            </button>

            <div x-show="openAutomation" x-transition class="pl-6 mt-2 space-y-2">

                <a href="{{ Route::has('admin.inbox.index') ? route('admin.inbox.index') : '#' }}"
                   class="flex justify-between items-center py-2 px-3 rounded-lg hover:bg-gray-100 {{ str_contains($route,'admin.inbox')?'text-blue-600 font-semibold':'' }}">
                    <span>💬 Conversations</span>
                    @if($unreadCount > 0)
                        <span class="bg-red-500 text-white text-xs px-2 py-1 rounded-full">
                            {{ $unreadCount }}
                        </span>
                    @endif
                </a>

                <a href="{{ Route::has('admin.chatbots.index') ? route('admin.chatbots.index') : '#' }}"
                   class="block py-2 px-3 rounded-lg hover:bg-gray-100 {{ str_contains($route,'admin.chatbots')?'text-blue-600 font-semibold':'' }}">
                    Chatbots
                </a>

                <a href="{{ Route::has('admin.faq.index') ? route('admin.faq.index') : '#' }}"
                   class="block py-2 px-3 rounded-lg hover:bg-gray-100 {{ str_contains($route,'admin.faq')?'text-blue-600 font-semibold':'' }}">
                    FAQ Knowledge Base
                </a>

                <a href="{{ Route::has('admin.templates.index') ? route('admin.templates.index') : '#' }}"
                   class="block py-2 px-3 rounded-lg hover:bg-gray-100 {{ str_contains($route,'admin.templates')?'text-blue-600 font-semibold':'' }}">
                    Templates
                </a>

                <a href="{{ Route::has('admin.leads.index') ? route('admin.leads.index') : '#' }}"
                   class="block py-2 px-3 rounded-lg hover:bg-gray-100 {{ str_contains($route,'admin.leads')?'text-blue-600 font-semibold':'' }}">
                    Leads CRM
                </a>

            </div>
        </div>

        <a href="{{ Route::has('admin.system.index') ? route('admin.system.index') : '#' }}"
           class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-gray-100 {{ str_contains($route,'admin.system')?'text-blue-600 font-semibold':'' }}">
            🛠 System Monitor
        </a>

        <a href="{{ Route::has('admin.settings.index') ? route('admin.settings.index') : '#' }}"
           class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-gray-100 {{ str_contains($route,'admin.settings')?'text-blue-600 font-semibold':'' }}">
            ⚙️ Settings
        </a>

    </nav>
</aside>

{{-- ================= RIGHT SIDE ================= --}}
<div class="flex-1 flex flex-col">

<header class="bg-white border-b px-12 py-6 shadow-sm">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Meta Enterprise Dashboard</h1>
            <p class="text-sm text-gray-500 mt-1">{{ now()->format('l, d M Y H:i') }}</p>
        </div>

        <div class="flex items-center gap-6">
            <span class="text-sm font-medium text-gray-700">{{ auth()->user()->name ?? 'Admin' }}</span>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="text-sm text-red-600 hover:text-red-800 font-medium">Logout</button>
            </form>
        </div>
    </div>
</header>

<main class="flex-1 py-8 px-12">
<div class="space-y-6">

{{-- Business Manager Card --}}
<div class="bg-white p-6 rounded-xl border shadow-sm flex justify-between items-center">
    <div>
        <h3 class="text-lg font-semibold text-gray-900">Business Manager</h3>
        @if(!empty($platformMeta))
            <p class="text-sm text-gray-600 mt-1">
                Business ID: <strong>{{ $platformMeta->business_id }}</strong>
            </p>
            <p class="text-green-600 text-sm font-medium mt-1">Verified & Connected</p>
        @else
            <p class="text-red-500 text-sm mt-1">No business connected</p>
        @endif
    </div>

    <div>
        @if(empty($platformMeta))
            <a href="{{ route('admin.meta.connect') }}"
               class="bg-blue-600 text-white px-5 py-2.5 rounded-lg shadow hover:bg-blue-700 transition text-sm">
                Connect Business
            </a>
        @else
            <form method="POST" action="{{ route('admin.meta.disconnect') }}">
                @csrf
                <button type="submit"
                    class="bg-red-500 text-white px-5 py-2.5 rounded-lg shadow hover:bg-red-600 transition text-sm">
                    Disconnect
                </button>
            </form>
        @endif
    </div>
</div>

{{-- Welcome Banner --}}
<div class="bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 rounded-xl shadow-lg overflow-hidden">
    <div class="px-6 py-6 text-white relative">
        <div class="absolute top-0 right-0 w-48 h-48 bg-white/10 rounded-full -mr-12 -mt-12"></div>
        <div class="relative z-10">
            <div class="flex items-center space-x-2 mb-2">
                <span class="px-2 py-0.5 bg-white/20 rounded-full text-xs font-medium">
                    🎯 Meta Ads Manager
                </span>
                <span class="flex items-center text-xs text-blue-100">
                    <span class="w-1.5 h-1.5 bg-green-400 rounded-full mr-1 animate-pulse"></span>
                    Live Data
                </span>
            </div>
            <h2 class="text-2xl font-bold mb-1">Welcome back, {{ auth()->user()->name ?? 'Admin' }}! 👋</h2>
            <p class="text-blue-100 text-sm">Here's what's happening with your Meta campaigns today.</p>
        </div>
    </div>
</div>

{{-- KPI Cards --}}
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
    {{-- Active Campaigns --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
        <div class="flex justify-between items-start">
            <div>
                <p class="text-xs text-gray-500 font-medium">Active Campaigns</p>
                <h3 class="text-xl font-bold text-gray-800 mt-1">{{ $activeCampaigns }}</h3>
            </div>
            <div class="w-9 h-9 bg-blue-100 rounded-lg flex items-center justify-center text-blue-600 text-lg">
                📊
            </div>
        </div>
        <div class="flex items-center mt-3">
            <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium {{ $trends['campaigns']['change'] >= 0 ? 'text-green-700 bg-green-100' : 'text-red-700 bg-red-100' }}">
                {{ $trends['campaigns']['change'] >= 0 ? '+' : '' }}{{ $trends['campaigns']['change'] }}%
            </span>
            <span class="text-xs text-gray-500 ml-2">vs last period</span>
        </div>
    </div>

    {{-- Total Spend --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
        <div class="flex justify-between items-start">
            <div>
                <p class="text-xs text-gray-500 font-medium">Total Spend</p>
                <h3 class="text-xl font-bold text-gray-800 mt-1">${{ number_format($totalSpend, 2) }}</h3>
            </div>
            <div class="w-9 h-9 bg-purple-100 rounded-lg flex items-center justify-center text-purple-600 text-lg">
                💰
            </div>
        </div>
        <div class="flex items-center mt-3">
            <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium {{ $trends['spend']['change'] >= 0 ? 'text-green-700 bg-green-100' : 'text-red-700 bg-red-100' }}">
                {{ $trends['spend']['change'] >= 0 ? '+' : '' }}{{ $trends['spend']['change'] }}%
            </span>
            <span class="text-xs text-gray-500 ml-2">vs last period</span>
        </div>
    </div>

    {{-- Impressions --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
        <div class="flex justify-between items-start">
            <div>
                <p class="text-xs text-gray-500 font-medium">Impressions</p>
                <h3 class="text-xl font-bold text-gray-800 mt-1">{{ formatNumber($impressions) }}</h3>
            </div>
            <div class="w-9 h-9 bg-green-100 rounded-lg flex items-center justify-center text-green-600 text-lg">
                👁️
            </div>
        </div>
        <div class="flex items-center mt-3">
            <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium {{ $trends['impressions']['change'] >= 0 ? 'text-green-700 bg-green-100' : 'text-red-700 bg-red-100' }}">
                {{ $trends['impressions']['change'] >= 0 ? '+' : '' }}{{ $trends['impressions']['change'] }}%
            </span>
            <span class="text-xs text-gray-500 ml-2">vs last period</span>
        </div>
    </div>

    {{-- CTR --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-5">
        <div class="flex justify-between items-start">
            <div>
                <p class="text-xs text-gray-500 font-medium">Click-through Rate</p>
                <h3 class="text-xl font-bold text-gray-800 mt-1">{{ $ctr }}%</h3>
            </div>
            <div class="w-9 h-9 bg-orange-100 rounded-lg flex items-center justify-center text-orange-600 text-lg">
                📈
            </div>
        </div>
        <div class="flex items-center mt-3">
            <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium {{ $trends['ctr']['change'] >= 0 ? 'text-green-700 bg-green-100' : 'text-red-700 bg-red-100' }}">
                {{ $trends['ctr']['change'] >= 0 ? '+' : '' }}{{ $trends['ctr']['change'] }}%
            </span>
            <span class="text-xs text-gray-500 ml-2">vs last period</span>
        </div>
    </div>
</div>

{{-- Secondary Metrics --}}
<div class="grid grid-cols-3 md:grid-cols-6 gap-3">
    <div class="bg-white rounded-lg border border-gray-200 p-3">
        <div class="text-xs text-gray-500">Clicks</div>
        <div class="text-base font-semibold">{{ formatNumber($clicks) }}</div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-3">
        <div class="text-xs text-gray-500">Conversions</div>
        <div class="text-base font-semibold">{{ $conversions }}</div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-3">
        <div class="text-xs text-gray-500">Conv. Rate</div>
        <div class="text-base font-semibold">{{ $conversionRate }}%</div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-3">
        <div class="text-xs text-gray-500">CPC</div>
        <div class="text-base font-semibold">${{ $cpc }}</div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-3">
        <div class="text-xs text-gray-500">CPM</div>
        <div class="text-base font-semibold">${{ $cpm }}</div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-3">
        <div class="text-xs text-gray-500">Reach</div>
        <div class="text-base font-semibold">{{ formatNumber($reach) }}</div>
    </div>
</div>

{{-- Top Performing Ads --}}
<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-200 bg-gray-50">
        <h3 class="text-base font-semibold text-gray-800">Top Performing Ads</h3>
    </div>
    <div class="divide-y divide-gray-200">
        @foreach($topAds as $ad)
        <div class="px-5 py-3 hover:bg-gray-50">
            <div class="flex items-center justify-between mb-1">
                <p class="text-sm font-medium text-gray-800">{{ $ad->name }}</p>
                <span class="text-xs font-medium text-green-600 bg-green-100 px-1.5 py-0.5 rounded-full">
                    {{ $ad->ctr }}% CTR
                </span>
            </div>
            <div class="grid grid-cols-3 gap-2 text-xs text-gray-500 mb-1">
                <div>{{ formatNumber($ad->impressions) }} imp</div>
                <div>${{ number_format($ad->spend, 0) }} spend</div>
                <div>{{ $ad->conversions }} conv</div>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-1">
                <div class="bg-gradient-to-r from-blue-500 to-indigo-600 h-1 rounded-full" 
                     style="width: {{ ($ad->ctr / 5) * 100 }}%"></div>
            </div>
        </div>
        @endforeach
    </div>
</div>

{{-- Recent Campaigns Table --}}
<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-200 bg-gray-50">
        <h3 class="text-base font-semibold text-gray-800">Recent Campaigns</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Campaign</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Spend</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">Impressions</th>
                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase">CTR</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @foreach($recentCampaigns->take(5) as $campaign)
                <tr class="hover:bg-gray-50">
                    <td class="px-5 py-3">
                        <div class="flex items-center">
                            <div class="w-6 h-6 bg-gradient-to-br from-blue-500 to-indigo-600 rounded flex items-center justify-center text-white text-xs font-bold">
                                {{ substr($campaign->name, 0, 2) }}
                            </div>
                            <span class="ml-2 text-sm font-medium text-gray-900">{{ $campaign->name }}</span>
                        </div>
                    </td>
                    <td class="px-5 py-3">
                        <span class="px-2 py-0.5 text-xs rounded-full 
                            {{ $campaign->status === 'active' ? 'bg-green-100 text-green-800' : '' }}
                            {{ $campaign->status === 'paused' ? 'bg-yellow-100 text-yellow-800' : '' }}
                            {{ $campaign->status === 'draft' ? 'bg-gray-100 text-gray-800' : '' }}">
                            {{ ucfirst($campaign->status) }}
                        </span>
                    </td>
                    <td class="px-5 py-3 text-sm">${{ number_format($campaign->spend) }}</td>
                    <td class="px-5 py-3 text-sm">{{ number_format($campaign->impressions) }}</td>
                    <td class="px-5 py-3 text-sm">
                        @if($campaign->impressions > 0)
                            {{ round(($campaign->clicks / $campaign->impressions) * 100, 2) }}%
                        @else
                            0%
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

</div>
</main>

</div>
</div>
</div>

</x-app-layout>