<x-app-layout :pageTitle="'Customize sidebar'">
    <x-slot name="header">
        <x-page-header title="Customize sidebar" description="Choose which links appear in your sidebar." icon="menu">
            <x-slot name="actions">
                <x-button href="{{ route('dashboard.customize') }}" variant="secondary" icon="dashboard">Dashboard widgets</x-button>
                <x-button href="{{ route('dashboard') }}" variant="ghost" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-none">
        @include('sidebar.partials.editor')
    </div>
</x-app-layout>
