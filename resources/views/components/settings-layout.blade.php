@props(['pageTitle' => 'Settings'])

<x-app-layout :pageTitle="$pageTitle">
    <x-slot name="header">
        <x-page-header title="Settings" description="Configure this company's profile, branding, modules, users and roles." icon="settings" />
    </x-slot>

    @include('settings.partials.nav')

    {{ $slot }}
</x-app-layout>
