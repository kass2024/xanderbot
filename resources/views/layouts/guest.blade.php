<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>

<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">

<title>{{ config('app.name', 'Xander Global Scholars') }}</title>

<link rel="icon" href="{{ asset('img/logo.png') }}">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet"/>

@vite(['resources/css/app.css','resources/js/app.js'])

</head>


<body class="font-sans antialiased bg-gray-100">

<div class="min-h-screen flex items-center justify-center px-6">

<div class="w-full max-w-5xl">

<div class="bg-white rounded-3xl shadow-xl overflow-hidden grid lg:grid-cols-2">

{{-- LEFT BRAND PANEL --}}
<div class="hidden lg:flex bg-blue-900 text-white items-center justify-center p-12">

<div class="text-center max-w-sm">

<img
src="{{ asset('img/logo.png') }}"
class="w-20 mx-auto mb-6">

<h1 class="text-3xl font-bold mb-4">
Xander Global Scholars
</h1>

<p class="text-blue-100 leading-relaxed text-sm">
AI chatbot automation and Meta Ads management platform.
Manage advertising campaigns, automate WhatsApp conversations,
and track marketing performance from one powerful dashboard.
</p>

<div class="mt-8">

<span class="inline-block bg-yellow-500 text-blue-900 px-4 py-2 rounded-lg text-sm font-semibold">
Smart Marketing Automation
</span>

</div>

</div>

</div>



{{-- RIGHT AUTH FORM --}}
<div class="flex items-center justify-center p-10">

<div class="w-full max-w-md">

{{-- MOBILE BRAND --}}
<div class="lg:hidden text-center mb-8">

<img
src="{{ asset('img/logo.png') }}"
class="w-16 mx-auto mb-3">

<h2 class="text-lg font-semibold text-gray-800">
Xander Global Scholars
</h2>

<p class="text-gray-500 text-sm">
Ads Management & AI Chatbot Platform
</p>

</div>


{{ $slot }}

</div>

</div>


</div>

</div>

</div>

</body>
</html>