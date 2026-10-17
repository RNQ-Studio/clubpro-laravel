<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('app.name', 'Laravel Starter') }} — API Backend & Back-office</title>
    <meta name="description" content="Starter project berbasis Laravel + PostgreSQL sebagai fondasi API backend untuk Flutter dan back-office web UI dengan Filament. Siap dikembangkan, tanpa over-engineering.">

    {{-- Favicons and Apple Touch Icon --}}
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="apple-touch-icon-precomposed" href="{{ asset('apple-touch-icon-precomposed.png') }}">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">
    <meta name="theme-color" content="#2563eb">

    {{-- Project fonts (Instrument Sans via Bunny) --}}
    @fonts

    {{-- Styles / Scripts --}}
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif

    {{-- x-cloak must be in <head> so Alpine elements are hidden before Alpine loads --}}
    <style>
        [x-cloak] { display: none !important; }

        /* Smooth scroll */
        html { scroll-behavior: smooth; }

        /* Fade-in animation */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(24px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in-up {
            animation: fadeInUp 0.7s ease-out both;
        }
        .delay-100 { animation-delay: 0.1s; }
        .delay-200 { animation-delay: 0.2s; }
        .delay-300 { animation-delay: 0.3s; }
        .delay-400 { animation-delay: 0.4s; }

        /* Feature card hover lift */
        .feature-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .feature-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 32px -8px rgba(37, 99, 235, 0.12), 0 4px 12px -4px rgba(0, 0, 0, 0.06);
        }
    </style>
</head>
<body class="bg-white text-gray-900 antialiased" x-data="{ mobileMenu: false }">

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- NAVBAR                                                        --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <nav class="fixed top-0 left-0 right-0 z-50 bg-white/80 backdrop-blur-lg border-b border-gray-100">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="flex h-16 items-center justify-between">
                {{-- Logo / Brand --}}
                <a href="/" class="flex items-center gap-2.5 group">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-600 shadow-sm transition-shadow group-hover:shadow-md">
                        <svg class="h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75 22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3-4.5 16.5" />
                        </svg>
                    </div>
                    <span class="text-lg font-bold tracking-tight text-gray-900">Laravel Starter</span>
                </a>

                {{-- Desktop nav links --}}
                <div class="hidden items-center gap-2 md:flex">
                    <a href="#features" class="rounded-lg px-3 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-blue-50 hover:text-blue-600">Fitur</a>
                    <a href="#quickstart" class="rounded-lg px-3 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-blue-50 hover:text-blue-600">Quick Start</a>
                    <a href="/articles" class="rounded-lg px-3 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-blue-50 hover:text-blue-600">Berita AI</a>
                    <a href="/docs/api" class="rounded-lg px-3 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-blue-50 hover:text-blue-600">API Docs</a>
                    <a href="/admin" class="ml-1 inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-all hover:bg-blue-700 hover:shadow-md">
                        <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                        </svg>
                        Admin Panel
                    </a>
                </div>

                {{-- Mobile hamburger --}}
                <button @click="mobileMenu = !mobileMenu" class="rounded-lg p-2 text-gray-600 transition-colors hover:bg-gray-100 hover:text-gray-900 md:hidden" aria-label="Toggle menu">
                    <svg x-show="!mobileMenu" class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                    <svg x-show="mobileMenu" x-cloak class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            {{-- Mobile menu panel --}}
            <div x-show="mobileMenu"
                 x-cloak
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="flex flex-col gap-1 border-t border-gray-100 pb-4 pt-3 md:hidden">
                <a href="#features" @click="mobileMenu = false" class="rounded-lg px-3 py-2.5 text-sm font-medium text-gray-600 transition-colors hover:bg-blue-50 hover:text-blue-600">Fitur</a>
                <a href="#quickstart" @click="mobileMenu = false" class="rounded-lg px-3 py-2.5 text-sm font-medium text-gray-600 transition-colors hover:bg-blue-50 hover:text-blue-600">Quick Start</a>
                <a href="/articles" @click="mobileMenu = false" class="rounded-lg px-3 py-2.5 text-sm font-medium text-gray-600 transition-colors hover:bg-blue-50 hover:text-blue-600">Berita AI</a>
                <a href="/docs/api" class="rounded-lg px-3 py-2.5 text-sm font-medium text-gray-600 transition-colors hover:bg-blue-50 hover:text-blue-600">API Docs</a>
                <a href="/admin" class="mt-1 inline-flex items-center justify-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-all hover:bg-blue-700">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                    </svg>
                    Admin Panel
                </a>
            </div>
        </div>
    </nav>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- HERO SECTION                                                  --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <section class="relative overflow-hidden pt-32 pb-20 lg:pt-40 lg:pb-28" style="background: linear-gradient(135deg, #f9fafb 0%, #eff6ff 50%, #f9fafb 100%);">
        {{-- Subtle decorative dots --}}
        <div class="absolute inset-0" style="opacity: 0.03; background-image: radial-gradient(circle, #2563eb 1px, transparent 1px); background-size: 24px 24px;"></div>

        <div class="relative mx-auto max-w-6xl px-6 text-center lg:px-8">
            {{-- Badge --}}
            <div class="animate-fade-in-up mb-8 inline-flex items-center gap-2 rounded-full border border-blue-100 bg-blue-50 px-4 py-1.5">
                <span class="h-2 w-2 rounded-full bg-blue-500" style="animation: pulse 2s cubic-bezier(.4,0,.6,1) infinite;"></span>
                <span class="text-sm font-medium text-blue-700">Laravel 13 + Filament 5 + Passport OAuth2</span>
            </div>

            {{-- Headline --}}
            <h1 class="animate-fade-in-up delay-100 mx-auto max-w-4xl text-4xl font-extrabold tracking-tight text-gray-900 sm:text-5xl lg:text-6xl" style="line-height: 1.1;">
                Fondasi Backend yang
                <span style="background: linear-gradient(to right, #2563eb, #1d4ed8); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">Siap Produksi</span>
            </h1>

            {{-- Subheadline --}}
            <p class="animate-fade-in-up delay-200 mx-auto mt-6 max-w-2xl text-lg text-gray-500 sm:text-xl" style="line-height: 1.7;">
                Starter project berbasis Laravel &amp; PostgreSQL — dirancang sebagai fondasi <strong class="text-gray-700">API backend untuk Flutter</strong> dan <strong class="text-gray-700">back-office web UI</strong> dengan Filament.
                Bersih, konsisten, dan siap dikembangkan.
            </p>

            {{-- CTA Buttons --}}
            <div class="animate-fade-in-up delay-300 mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
                <a href="/admin" class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-7 py-3.5 text-base font-semibold text-white shadow-lg transition-all hover:bg-blue-700 hover:shadow-xl" style="box-shadow: 0 10px 25px -5px rgba(37, 99, 235, 0.35);">
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                    </svg>
                    Buka Admin Panel
                </a>
                <a href="/docs/api" class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-7 py-3.5 text-base font-semibold text-gray-700 shadow-sm transition-all hover:border-gray-300 hover:bg-gray-50 hover:shadow-md">
                    <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                    </svg>
                    Lihat API Docs
                </a>
            </div>

            {{-- Tech pills --}}
            <div class="animate-fade-in-up delay-400 mt-12 flex flex-wrap items-center justify-center gap-3">
                @foreach (['Laravel 13', 'PHP 8.3+', 'PostgreSQL', 'Filament 5', 'Passport OAuth2', 'Spatie RBAC', 'Redis'] as $tech)
                    <span class="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-medium text-gray-500">{{ $tech }}</span>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- FEATURES SECTION                                              --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <section id="features" class="bg-white py-20 lg:py-28">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            {{-- Section header --}}
            <div class="mb-16 text-center">
                <span class="mb-4 inline-block rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-blue-600">Fitur Unggulan</span>
                <h2 class="text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl">Semua yang Anda Butuhkan, Sudah Tersedia</h2>
                <p class="mx-auto mt-4 max-w-2xl text-lg text-gray-500">Satu starter project dengan arsitektur yang bersih, fitur enterprise-grade, dan konvensi yang konsisten.</p>
            </div>

            {{-- Feature cards grid --}}
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">

                {{-- Feature 1: API-First Backend --}}
                <div class="feature-card rounded-2xl border border-gray-100 p-7" style="background-color: rgba(249,250,251,0.5);">
                    <div class="mb-5 flex h-11 w-11 items-center justify-center rounded-xl bg-blue-50">
                        <svg class="h-5 w-5 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75 22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3-4.5 16.5" />
                        </svg>
                    </div>
                    <h3 class="mb-2 text-lg font-bold text-gray-900">API-First Backend</h3>
                    <p class="text-sm leading-relaxed text-gray-500">Token-based authentication via OAuth2 (Laravel Passport) dengan format JSON response yang konsisten — siap dikonsumsi oleh Flutter client.</p>
                </div>

                {{-- Feature 2: Filament Back-office --}}
                <div class="feature-card rounded-2xl border border-gray-100 p-7" style="background-color: rgba(249,250,251,0.5);">
                    <div class="mb-5 flex h-11 w-11 items-center justify-center rounded-xl bg-blue-50">
                        <svg class="h-5 w-5 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
                        </svg>
                    </div>
                    <h3 class="mb-2 text-lg font-bold text-gray-900">Filament Admin Panel</h3>
                    <p class="text-sm leading-relaxed text-gray-500">Back-office UI lengkap berbasis Filament 5 untuk user management, role &amp; permission, dan CRUD data master — tanpa perlu coding UI sendiri.</p>
                </div>

                {{-- Feature 3: RBAC Spatie --}}
                <div class="feature-card rounded-2xl border border-gray-100 p-7" style="background-color: rgba(249,250,251,0.5);">
                    <div class="mb-5 flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-50">
                        <svg class="h-5 w-5 text-emerald-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                        </svg>
                    </div>
                    <h3 class="mb-2 text-lg font-bold text-gray-900">RBAC Terpadu (Spatie)</h3>
                    <p class="text-sm leading-relaxed text-gray-500">Satu sistem role &amp; permission (spatie/laravel-permission) yang dipakai bersama oleh API guard dan web guard — single source of truth.</p>
                </div>

                {{-- Feature 4: API Docs (Scramble) --}}
                <div class="feature-card rounded-2xl border border-gray-100 p-7" style="background-color: rgba(249,250,251,0.5);">
                    <div class="mb-5 flex h-11 w-11 items-center justify-center rounded-xl bg-amber-50">
                        <svg class="h-5 w-5 text-amber-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                        </svg>
                    </div>
                    <h3 class="mb-2 text-lg font-bold text-gray-900">API Docs Interaktif</h3>
                    <p class="text-sm leading-relaxed text-gray-500">Dokumentasi API auto-generated oleh Scramble dengan OpenAPI spec — tersedia di <code style="font-size: 0.75rem; background: #f3f4f6; padding: 2px 6px; border-radius: 4px;">/docs/api</code> saat environment local.</p>
                </div>

                {{-- Feature 5: Firebase Push Notification --}}
                <div class="feature-card rounded-2xl border border-gray-100 p-7" style="background-color: rgba(249,250,251,0.5);">
                    <div class="mb-5 flex h-11 w-11 items-center justify-center rounded-xl bg-rose-50">
                        <svg class="h-5 w-5 text-rose-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                        </svg>
                    </div>
                    <h3 class="mb-2 text-lg font-bold text-gray-900">Firebase Push Notification</h3>
                    <p class="text-sm leading-relaxed text-gray-500">Integrasi Firebase Cloud Messaging (FCM) untuk pengiriman push notification asinkron ke Flutter client melalui queue worker.</p>
                </div>

                {{-- Feature 6: Database Wilayah Indonesia --}}
                <div class="feature-card rounded-2xl border border-gray-100 p-7" style="background-color: rgba(249,250,251,0.5);">
                    <div class="mb-5 flex h-11 w-11 items-center justify-center rounded-xl bg-sky-50">
                        <svg class="h-5 w-5 text-sky-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                        </svg>
                    </div>
                    <h3 class="mb-2 text-lg font-bold text-gray-900">Wilayah Global</h3>
                    <p class="text-sm leading-relaxed text-gray-500">Database 249.036 data administratif global (seluruh negara di dunia) dengan offline seeding via JSON fixtures.</p>
                </div>

                {{-- Feature 7: Asset Management & GCS Storage --}}
                <div class="feature-card rounded-2xl border border-gray-100 p-7" style="background-color: rgba(249,250,251,0.5);">
                    <div class="mb-5 flex h-11 w-11 items-center justify-center rounded-xl bg-blue-50">
                        <svg class="h-5 w-5 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                        </svg>
                    </div>
                    <h3 class="mb-2 text-lg font-bold text-gray-900">Manajemen Aset & GCS</h3>
                    <p class="text-sm leading-relaxed text-gray-500">Unggah file transaksional secara atomik ke Google Cloud Storage dengan enkapsulasi UUID, dedup checksum sha256, ekstraksi metadata gambar/PDF otomatis, dan pembersihan 2-fase harian.</p>
                </div>

            </div>
        </div>
    </section>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- QUICK START SECTION                                           --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <section id="quickstart" class="bg-gray-50 py-20 lg:py-28">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="mb-16 text-center">
                <span class="mb-4 inline-block rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-blue-600">Memulai Cepat</span>
                <h2 class="text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl">Siap dalam 12 Menit</h2>
                <p class="mx-auto mt-4 max-w-2xl text-lg text-gray-500">Clone repository, jalankan empat perintah berikut, dan langsung mulai develop.</p>
            </div>

            <div class="mx-auto max-w-3xl">
                {{-- Terminal-style code block --}}
                <div class="overflow-hidden rounded-2xl border border-gray-800 shadow-xl" style="background: #1e1e2e;">
                    {{-- Terminal header --}}
                    <div class="flex items-center gap-2 border-b border-gray-700 px-5 py-3">
                        <span class="h-3 w-3 rounded-full" style="background: #ff5f57;"></span>
                        <span class="h-3 w-3 rounded-full" style="background: #febc2e;"></span>
                        <span class="h-3 w-3 rounded-full" style="background: #28c840;"></span>
                        <span class="ml-3 text-xs text-gray-400">Terminal</span>
                    </div>
                    {{-- Code content --}}
                    <div class="p-6 font-mono text-sm leading-7" style="color: #cdd6f4;">
                        <div>
                            <span style="color: #6c7086;"># Langkah 1 — Install dependensi & build assets (Membuat .env)</span>
                        </div>
                        <div>
                            <span style="color: #a6e3a1;">$</span> <span>composer run setup</span>
                        </div>
                        <div class="mt-4">
                            <span style="color: #6c7086;"># Langkah 2 — Konfigurasi kredensial database Anda di file .env</span>
                        </div>
                        <div class="mt-4">
                            <span style="color: #6c7086;"># Langkah 3 — Migrasi database & seed data awal</span>
                        </div>
                        <div>
                            <span style="color: #a6e3a1;">$</span> <span>php artisan migrate:fresh --seed</span>
                        </div>
                        <div class="mt-4">
                            <span style="color: #6c7086;"># Langkah 4 — Kunci Passport & Storage Symlink</span>
                        </div>
                        <div>
                            <span style="color: #a6e3a1;">$</span> <span>php artisan passport:keys --force</span>
                        </div>
                        <div>
                            <span style="color: #a6e3a1;">$</span> <span>php artisan storage:link</span>
                        </div>
                        <div class="mt-4">
                            <span style="color: #6c7086;"># Langkah 5 — Buat Password Client (Salin Secret ke .env!)</span>
                        </div>
                        <div>
                            <span style="color: #a6e3a1;">$</span> <span>php artisan passport:client --password</span>
                        </div>
                        <div class="mt-4">
                            <span style="color: #6c7086;"># Langkah 6 — Unduh & jalankan seeder wilayah global offline</span>
                        </div>
                        <div>
                            <span style="color: #a6e3a1;">$</span> <span>php artisan regions:download</span>
                        </div>
                        <div>
                            <span style="color: #a6e3a1;">$</span> <span>php artisan db:seed --class=RegionSeeder</span>
                        </div>
                        <div class="mt-4">
                            <span style="color: #6c7086;"># Langkah 7 — Jalankan server dev lokal</span>
                        </div>
                        <div>
                            <span style="color: #a6e3a1;">$</span> <span>composer run dev</span>
                        </div>
                    </div>
                </div>

                {{-- Notes below terminal --}}
                <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:gap-6">
                    <div class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-amber-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                        </svg>
                        <p class="text-xs text-gray-500">Simpan <strong class="text-gray-700">Client ID</strong> &amp; <strong class="text-gray-700">Client Secret</strong> dari Passport ke <code style="font-size: 0.7rem; background: #f3f4f6; padding: 1px 5px; border-radius: 3px;">.env</code></p>
                    </div>
                    <div class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                        </svg>
                        <p class="text-xs text-gray-500">Untuk Docker, gunakan <code style="font-size: 0.7rem; background: #f3f4f6; padding: 1px 5px; border-radius: 3px;">./vendor/bin/sail</code> — lihat detail di README</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- ACCESS POINTS & CREDENTIALS SECTION                           --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <section id="access" class="bg-white py-20 lg:py-28">
        <div class="mx-auto max-w-6xl px-6 lg:px-8">
            <div class="mb-16 text-center">
                <span class="mb-4 inline-block rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-blue-600">Akses Aplikasi</span>
                <h2 class="text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl">Endpoint yang Tersedia</h2>
                <p class="mx-auto mt-4 max-w-2xl text-lg text-gray-500">Setelah server dev berjalan, akses layanan berikut di browser.</p>
            </div>

            {{-- Access Points Grid --}}
            <div class="mx-auto grid max-w-4xl grid-cols-1 gap-4 sm:grid-cols-2">
                {{-- Landing Page --}}
                <a href="/" class="group flex items-center gap-4 rounded-xl border border-gray-100 bg-gray-50 p-5 transition-all hover:border-blue-200 hover:bg-blue-50">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white shadow-sm">
                        <svg class="h-5 w-5 text-gray-400 transition-colors group-hover:text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-900">Landing Page</p>
                        <p class="text-xs text-gray-400">{{ request()->getHttpHost() }}</p>
                    </div>
                </a>

                {{-- Admin Panel --}}
                <a href="/admin" class="group flex items-center gap-4 rounded-xl border border-gray-100 bg-gray-50 p-5 transition-all hover:border-blue-200 hover:bg-blue-50">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white shadow-sm">
                        <svg class="h-5 w-5 text-gray-400 transition-colors group-hover:text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-900">Admin Panel (Filament)</p>
                        <p class="text-xs text-gray-400">{{ request()->getHttpHost() }}/admin</p>
                    </div>
                </a>

                {{-- API Docs --}}
                <a href="/docs/api" class="group flex items-center gap-4 rounded-xl border border-gray-100 bg-gray-50 p-5 transition-all hover:border-blue-200 hover:bg-blue-50">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white shadow-sm">
                        <svg class="h-5 w-5 text-gray-400 transition-colors group-hover:text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-900">API Docs (Scramble)</p>
                        <p class="text-xs text-gray-400">{{ request()->getHttpHost() }}/docs/api</p>
                    </div>
                </a>

                {{-- Berita AI (Public Blog) --}}
                <a href="/articles" class="group flex items-center gap-4 rounded-xl border border-gray-100 bg-gray-50 p-5 transition-all hover:border-blue-200 hover:bg-blue-50">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white shadow-sm">
                        <svg class="h-5 w-5 text-gray-400 transition-colors group-hover:text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621 0 1.125.504 1.125 1.125V18a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18V6.125c0-.621.504-1.125 1.125-1.125H9.75M8.25 21h8.25" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-900">Berita AI &amp; Blog (Tanpa Login)</p>
                        <p class="text-xs text-gray-400">{{ request()->getHttpHost() }}/articles</p>
                    </div>
                </a>

                {{-- Health Check --}}
                <a href="/api/v1/health" class="group flex items-center gap-4 rounded-xl border border-gray-100 bg-gray-50 p-5 transition-all hover:border-blue-200 hover:bg-blue-50">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white shadow-sm">
                        <svg class="h-5 w-5 text-gray-400 transition-colors group-hover:text-emerald-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-900">Health Check API</p>
                        <p class="text-xs text-gray-400">{{ request()->getHttpHost() }}/api/v1/health</p>
                    </div>
                </a>
            </div>

            {{-- Default Credentials --}}
            <div class="mx-auto mt-10 max-w-4xl">
                <div class="rounded-xl border border-blue-100 p-6" style="background: linear-gradient(135deg, #eff6ff 0%, #eff6ff 100%);">
                    <div class="flex items-start gap-3">
                        <svg class="mt-0.5 h-5 w-5 shrink-0 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" />
                        </svg>
                        <div>
                            <p class="text-sm font-semibold text-gray-900">Kredensial Default (Seeder)</p>
                            <p class="mt-1 text-sm text-gray-600">
                                Email: <code style="font-size: 0.8rem; background: rgba(255,255,255,0.7); padding: 2px 8px; border-radius: 4px; font-weight: 600;">admin@example.com</code>
                                &nbsp;·&nbsp;
                                Password: <code style="font-size: 0.8rem; background: rgba(255,255,255,0.7); padding: 2px 8px; border-radius: 4px; font-weight: 600;">password</code>
                                &nbsp;·&nbsp;
                                Role: <span class="font-medium text-blue-600">super-admin</span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ══════════════════════════════════════════════════════════════ --}}
    {{-- FOOTER                                                        --}}
    {{-- ══════════════════════════════════════════════════════════════ --}}
    <footer class="border-t border-gray-100 bg-gray-50">
        <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-6 py-10 sm:flex-row lg:px-8">
            <p class="text-sm text-gray-400">
                Laravel Starter &mdash; v{{ app()->version() }}
            </p>
            <div class="flex items-center gap-6">
                <a href="/admin" class="text-sm text-gray-500 transition-colors hover:text-blue-600">Admin Panel</a>
                <a href="/docs/api" class="text-sm text-gray-500 transition-colors hover:text-blue-600">API Docs</a>
                <a href="https://laravel.com/docs" target="_blank" rel="noopener" class="text-sm text-gray-500 transition-colors hover:text-blue-600">Laravel Docs</a>
            </div>
        </div>
    </footer>

    {{-- Alpine.js (lightweight, for mobile menu toggle only) --}}
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js"></script>
</body>
</html>
