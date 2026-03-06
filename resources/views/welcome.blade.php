<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Xander Global Scholars</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

:root{
--primary:#0F4C81;
--primary-dark:#0B3C5D;
--accent:#FFC107;
--light:#F5F5F5;
}

body{
font-family:'Segoe UI',sans-serif;
}

/* HERO */

.hero{
background:linear-gradient(135deg,var(--primary),var(--primary-dark));
color:white;
padding:120px 0;
}

.hero h1{
font-weight:700;
}

/* BUTTONS */

.btn-primary{
background:var(--primary);
border:none;
}

.btn-primary:hover{
background:var(--primary-dark);
}

.btn-accent{
background:var(--accent);
border:none;
color:#000;
font-weight:600;
}

.btn-accent:hover{
background:#e0a800;
}

/* FEATURES */

.feature-card{
transition:.3s ease;
border:none;
border-radius:12px;
}

.feature-card:hover{
transform:translateY(-5px);
box-shadow:0 10px 25px rgba(0,0,0,0.1);
}

/* PRICING */

.pricing-card{
border-radius:12px;
transition:.3s ease;
}

.pricing-card:hover{
transform:scale(1.03);
}

/* FOOTER */

footer{
background:#0B3C5D;
color:#ccc;
padding:40px 0;
}

footer a{
color:#ddd;
text-decoration:none;
}

footer a:hover{
color:white;
}

</style>
</head>
<body>

<!-- NAVBAR -->

<nav class="navbar navbar-expand-lg bg-white shadow-sm fixed-top">
<div class="container">

<a class="navbar-brand fw-bold" style="color:#0F4C81;">
Xander Global Scholars
</a>

<div class="ms-auto">
<a href="{{ route('login') }}" class="btn btn-outline-primary me-2">Login</a>
<a href="{{ route('register') }}" class="btn btn-accent">Register</a>
</div>

</div>
</nav>


<!-- HERO -->

<section class="hero text-center">
<div class="container">

<h1 class="display-4 mb-4">
Study Abroad Opportunities Made Simple
</h1>

<p class="lead mb-5">
Connect with global universities, receive guidance,
and start your journey to international education today.
</p>

<a href="{{ route('register') }}" class="btn btn-accent btn-lg me-3">
Start Your Journey
</a>

<a href="{{ route('login') }}" class="btn btn-outline-light btn-lg">
Student Login
</a>

</div>
</section>


<!-- FEATURES -->

<section class="py-5">
<div class="container text-center">

<h2 class="mb-5">Why Choose Xander Global Scholars</h2>

<div class="row g-4">

<div class="col-md-4">
<div class="card feature-card p-4 h-100">

<h5 class="fw-bold">Global University Access</h5>

<p class="text-muted">
Apply to top universities across the world with
our trusted international education partners.
</p>

</div>
</div>


<div class="col-md-4">
<div class="card feature-card p-4 h-100">

<h5 class="fw-bold">Expert Guidance</h5>

<p class="text-muted">
Our experienced consultants help you navigate
applications, visas, and admissions.
</p>

</div>
</div>


<div class="col-md-4">
<div class="card feature-card p-4 h-100">

<h5 class="fw-bold">Student Support</h5>

<p class="text-muted">
From application to arrival abroad,
we guide you every step of the way.
</p>

</div>
</div>

</div>
</div>
</section>


<!-- PRICING -->

<section class="bg-light py-5">
<div class="container text-center">

<h2 class="mb-5">Our Services</h2>

<div class="row justify-content-center g-4">

<div class="col-md-4">

<div class="card pricing-card shadow-sm p-4">

<h4 class="fw-bold">Basic Guidance</h4>

<h2 class="my-3">Free</h2>

<ul class="list-unstyled text-muted mb-4">
<li>Initial Consultation</li>
<li>University Selection</li>
<li>Email Support</li>
</ul>

<a href="{{ route('register') }}" class="btn btn-primary w-100">
Get Started
</a>

</div>

</div>


<div class="col-md-4">

<div class="card pricing-card shadow-lg border-primary p-4">

<h4 class="fw-bold" style="color:#0F4C81;">Premium Support</h4>

<h2 class="my-3">Full Service</h2>

<ul class="list-unstyled text-muted mb-4">
<li>University Application</li>
<li>Visa Assistance</li>
<li>Priority Support</li>
</ul>

<a href="{{ route('register') }}" class="btn btn-accent w-100">
Apply Now
</a>

</div>

</div>

</div>
</div>
</section>


<!-- FOOTER -->

<footer class="text-center">

<div class="container">

<p>© {{ date('Y') }} Xander Global Scholars. All rights reserved.</p>

<div class="mt-3">
<a href="/privacy-policy">Privacy Policy</a> |
<a href="/terms-of-service">Terms</a> |
<a href="/data-deletion">Data Deletion</a>
</div>

</div>

</footer>

</body>
</html>