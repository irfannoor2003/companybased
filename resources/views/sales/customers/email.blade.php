<x-app-layout :pageTitle="'Email '.$customer->company_name">
    <x-slot name="header">
        <x-page-header title="Email {{ $customer->company_name }}" description="Send an email to {{ $customer->contact_name ?: $customer->company_name }}" icon="mail">
            <x-slot name="actions">
                <x-button href="{{ route('sales.customers.show', $customer) }}" variant="secondary" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="mt-6 max-w-2xl">
        @if (!$customer->email)
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800">
                This customer has no email address on file. Please edit the customer and add an email before sending.
            </div>
        @else
            <x-card title="Compose Email">
                <form method="POST" action="{{ route('sales.customers.email.send', $customer) }}" class="space-y-5">
                    @csrf

                    <div class="rounded-lg border border-line bg-surface-muted p-3 text-sm">
                        <p><strong class="text-ink">To:</strong> {{ $customer->company_name }} &lt;{{ $customer->email }}&gt;</p>
                        <p><strong class="text-ink">From:</strong> {{ auth()->user()->name }} &lt;{{ auth()->user()->email }}&gt;</p>
                    </div>

                    <x-input name="subject" label="Subject" required placeholder="e.g. Follow-up on your recent order" value="{{ old('subject') }}" />

                    <x-textarea name="body" label="Message" rows="10" required placeholder="Write your message here...">{{ old('body') }}</x-textarea>

                    <div class="flex justify-end gap-3 border-t border-line pt-4">
                        <x-button href="{{ route('sales.customers.show', $customer) }}" variant="ghost">Cancel</x-button>
                        <x-button type="submit" icon="send">Send email</x-button>
                    </div>
                </form>
            </x-card>
        @endif
    </div>
</x-app-layout>
