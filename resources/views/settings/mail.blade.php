<x-settings-layout page-title="Mail Server">
    <x-page-header title="Mail Server" description="Configure SMTP or transactional mail service for notifications and emails." icon="mail">
        <x-slot name="actions">
            <x-button href="{{ route('settings.company') }}" variant="secondary" icon="arrow-left">Back</x-button>
        </x-slot>
    </x-page-header>

    <form method="POST" action="{{ route('settings.mail.update') }}" class="mt-6 space-y-6 max-w-3xl">
        @csrf
        @method('PUT')

        <x-card title="Outgoing Mail" description="Choose the mail transport and server details.">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-select name="mailer" label="Mail driver" required>
                    @foreach (['smtp' => 'SMTP', 'sendmail' => 'Sendmail', 'ses' => 'Amazon SES', 'postmark' => 'Postmark', 'log' => 'Log (dev only)'] as $val => $lbl)
                        <option value="{{ $val }}" @selected(old('mailer', $mail['mailer']) === $val)>{{ $lbl }}</option>
                    @endforeach
                </x-select>

                <div></div>

                <x-input name="host" label="SMTP Host" placeholder="e.g. smtp.gmail.com" value="{{ old('host', $mail['host']) }}" hint="Ignored for non-SMTP drivers." />
                <x-input name="port" label="SMTP Port" type="number" min="1" max="65535" placeholder="587" value="{{ old('port', $mail['port']) }}" />

                <x-input name="username" label="Username" placeholder="e.g. user@gmail.com" value="{{ old('username', $mail['username']) }}" />
                <x-input name="password" label="Password" type="password" placeholder="App password or SMTP password" value="{{ old('password', $mail['password']) }}" />

                <x-select name="encryption" label="Encryption">
                    @foreach (['tls' => 'TLS (recommended)', 'ssl' => 'SSL', '' => 'None'] as $val => $lbl)
                        <option value="{{ $val }}" @selected(old('encryption', $mail['encryption']) === $val)>{{ $lbl }}</option>
                    @endforeach
                </x-select>
                <div></div>
            </div>
        </x-card>

        <x-card title="From Address" description="The email address and name that appear on all outgoing emails.">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-input name="from_address" label="From email" type="email" required placeholder="e.g. noreply@yourcompany.com" value="{{ old('from_address', $mail['from_address']) }}" />
                <x-input name="from_name" label="From name" required placeholder="e.g. Your Company" value="{{ old('from_name', $mail['from_name']) }}" />
            </div>
        </x-card>

        <div class="flex justify-end gap-3">
            <x-button href="{{ route('settings.company') }}" variant="ghost">Cancel</x-button>
            <x-button type="submit" icon="save">Save mail settings</x-button>
        </div>
    </form>

    <div class="mt-6 max-w-3xl">
        <x-card title="Test Email" description="Send a test email to verify your configuration is working.">
            <form method="POST" action="{{ route('settings.mail.test') }}" class="space-y-4">
                @csrf
                <div class="max-w-md">
                    <x-input name="test_email" label="Recipient email" type="email" value="{{ old('test_email', auth()->user()->email) }}" placeholder="Enter email address" />
                </div>
                <div class="flex justify-end">
                    <x-button type="submit" icon="send" size="sm" variant="secondary">Send test email</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-settings-layout>
