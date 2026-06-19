@extends('layouts.admin')

@section('title', 'Edit campaign')

@section('content')

<div class="mx-auto max-w-3xl space-y-8">

<div class="min-w-0">
    <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Edit campaign</h1>
    <p class="mt-1 text-sm text-slate-600">
        Update campaign settings and sync changes to Meta when connected.
    </p>
</div>

<div class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm ring-1 ring-slate-900/5">

<form method="POST" action="{{ route('admin.campaigns.update', $campaign) }}">
@csrf
@method('PUT')

<div class="p-8 space-y-8 sm:p-10">

@if ($errors->any())
<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700">
<ul class="list-disc space-y-1 pl-5 text-sm">
@foreach ($errors->all() as $error)
<li>{{ $error }}</li>
@endforeach
</ul>
</div>
@endif

<div class="space-y-2">
<label class="block text-sm font-semibold text-slate-700">Campaign name</label>
<input
    type="text"
    name="name"
    value="{{ old('name', $campaign->name) }}"
    required
    class="w-full rounded-xl border border-slate-200 px-4 py-3 shadow-sm focus:border-xander-navy focus:ring-2 focus:ring-xander-navy/20"
>
</div>

<div class="space-y-2">
<label class="block text-sm font-semibold text-slate-700">Objective</label>
<select
    name="objective"
    class="w-full rounded-xl border border-slate-200 px-4 py-3 shadow-sm focus:border-xander-navy focus:ring-2 focus:ring-xander-navy/20"
>
@php $objective = old('objective', $normalizedObjective ?? $campaign->objective); @endphp
<option value="OUTCOME_TRAFFIC" @selected($objective === 'OUTCOME_TRAFFIC')>Website traffic</option>
<option value="OUTCOME_LEADS" @selected($objective === 'OUTCOME_LEADS')>Lead generation</option>
<option value="OUTCOME_ENGAGEMENT" @selected($objective === 'OUTCOME_ENGAGEMENT')>Engagement</option>
<option value="OUTCOME_AWARENESS" @selected($objective === 'OUTCOME_AWARENESS')>Awareness</option>
<option value="OUTCOME_SALES" @selected($objective === 'OUTCOME_SALES')>Sales</option>
</select>
<p class="text-xs text-slate-500">
Saved in xanderbot immediately. Meta keeps the original objective on linked campaigns; sync will not overwrite your local choice.
</p>
</div>

@php
    $budgetDollars = '';
    if (old('daily_budget') !== null) {
        $budgetDollars = old('daily_budget');
    } elseif ($campaign->daily_budget) {
        $budgetDollars = $campaign->daily_budget >= 100
            ? $campaign->daily_budget / 100
            : $campaign->daily_budget;
    } elseif ($campaign->budget) {
        $budgetDollars = $campaign->budget;
    }
@endphp

<div class="space-y-2">
<label class="block text-sm font-semibold text-slate-700">Daily budget (optional)</label>
<input
    type="number"
    name="daily_budget"
    value="{{ $budgetDollars }}"
    min="0"
    step="0.01"
    class="w-full rounded-xl border border-slate-200 px-4 py-3 shadow-sm focus:border-xander-navy focus:ring-2 focus:ring-xander-navy/20"
>
<p class="text-xs text-slate-500">Leave empty to keep the current budget.</p>
</div>

<div class="space-y-2">
<label class="block text-sm font-semibold text-slate-700">Status</label>
<select
    name="status"
    class="w-full rounded-xl border border-slate-200 px-4 py-3 shadow-sm focus:border-xander-navy focus:ring-2 focus:ring-xander-navy/20"
>
@php $status = strtoupper(old('status', $campaign->status ?? 'PAUSED')); @endphp
<option value="PAUSED" @selected($status === 'PAUSED')>Paused</option>
<option value="ACTIVE" @selected($status === 'ACTIVE')>Active</option>
<option value="DRAFT" @selected($status === 'DRAFT')>Draft</option>
<option value="COMPLETED" @selected($status === 'COMPLETED')>Completed</option>
</select>
</div>

@if($campaign->meta_id)
<p class="text-xs text-slate-500">Meta campaign ID: {{ $campaign->meta_id }}</p>
@endif

</div>

<div class="flex items-center justify-between border-t border-slate-200 bg-slate-50/80 px-8 py-5 sm:px-10">
    <a href="{{ route('admin.campaigns.index') }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">
        Cancel
    </a>
    <button
        type="submit"
        class="rounded-xl bg-xander-navy px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-xander-secondary"
    >
        Save changes
    </button>
</div>

</form>

</div>

</div>

@endsection
