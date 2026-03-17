<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Xander Global Scholars</title>

<link rel="icon" href="{{ asset('img/logo.png') }}">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet"/>

@vite(['resources/css/app.css','resources/js/app.js'])

</head>

<body class="font-sans bg-gray-50 text-gray-800">

<!-- NAVBAR -->
<header class="bg-white border-b">

<div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">

<div class="flex items-center gap-3">

<img src="{{ asset('img/logo.png') }}" class="w-9">

<span class="font-semibold text-lg text-blue-900">
Xander Global Scholars
</span>

</div>

<a
href="{{ route('login') }}"
class="px-6 py-2 border border-blue-900 text-blue-900 rounded-lg hover:bg-blue-50 font-medium">

Login

</a>

</div>

</header>


<!-- HERO -->
<section class="py-28 bg-white">

<div class="max-w-6xl mx-auto px-6 text-center">

<h1 class="text-4xl md:text-5xl font-bold mb-6 text-gray-900">

AI Chatbot & Meta Ads Management  
<span class="text-blue-900">In One Powerful Dashboard</span>

</h1>

<p class="text-lg text-gray-600 max-w-2xl mx-auto mb-10">

Automate WhatsApp conversations, manage Meta advertising campaigns,
track leads generated from your ads, and grow your business with
intelligent automation tools.

</p>

<a
href="{{ route('login') }}"
class="bg-blue-900 hover:bg-blue-800 text-white px-8 py-4 rounded-xl text-lg font-semibold shadow">

Access Dashboard

</a>

</div>

</section>


<!-- FEATURES -->
<section class="py-24 bg-gray-50">

<div class="max-w-6xl mx-auto px-6">

<h2 class="text-3xl font-bold text-center mb-14 text-gray-900">
Platform Features
</h2>

<div class="grid md:grid-cols-3 gap-8">

<div class="bg-white p-8 rounded-xl border hover:shadow-lg transition">
<h3 class="font-semibold text-lg mb-3 text-blue-900">
Meta Ads Management
</h3>
<p class="text-gray-600 text-sm">
Create and manage Facebook and Instagram advertising campaigns,
control budgets, monitor ad sets, and track performance directly
from your dashboard.
</p>
</div>

<div class="bg-white p-8 rounded-xl border hover:shadow-lg transition">
<h3 class="font-semibold text-lg mb-3 text-blue-900">
AI Chatbot Automation
</h3>
<p class="text-gray-600 text-sm">
Automatically reply to WhatsApp leads generated from your ads using
AI-powered chatbot automation designed to engage prospects instantly.
</p>
</div>

<div class="bg-white p-8 rounded-xl border hover:shadow-lg transition">
<h3 class="font-semibold text-lg mb-3 text-blue-900">
Real-Time Analytics
</h3>
<p class="text-gray-600 text-sm">
Track ad spend, clicks, conversations, and lead performance with
real-time analytics and actionable insights.
</p>
</div>

</div>

</div>

</section>


<!-- CTA -->
<section class="py-24 bg-blue-900 text-white">

<div class="max-w-5xl mx-auto px-6 text-center">

<h2 class="text-3xl font-bold mb-6">
Manage Ads and Conversations Smarter
</h2>

<p class="text-blue-100 mb-10">

Access your dashboard to manage campaigns, automate conversations,
and track performance in one place.

</p>

<a
href="{{ route('login') }}"
class="bg-yellow-500 hover:bg-yellow-400 text-blue-900 px-10 py-4 rounded-xl text-lg font-semibold shadow">

Login to Dashboard

</a>

</div>

</section>


<!-- FOOTER -->
<footer class="bg-gray-900 text-gray-400 py-12">

<div class="max-w-6xl mx-auto px-6 text-center">

<p class="mb-4">
© {{ date('Y') }} Xander Global Scholars. All rights reserved.
</p>

<div class="space-x-6 text-sm">

<a href="/privacy-policy" class="hover:text-white">Privacy Policy</a>
<a href="/terms-of-service" class="hover:text-white">Terms</a>
<a href="/data-deletion" class="hover:text-white">Data Deletion</a>

</div>

</div>

</footer>

</body>
</html>