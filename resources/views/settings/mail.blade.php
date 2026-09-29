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

                <x-input name="username" label="Username" placeholder="e.g. user@gmail.com" value="{{ old('username', $mail['username']) }}" @required($mail['mailer'] !== 'log') />
                <x-input
                    name="password"
                    label="Password"
                    type="password"
                    @required(! $mail['password_is_set'])
                    autocomplete="new-password"
                    placeholder="{{ $mail['password_is_set'] ? '•••••••• (stored — leave blank to keep)' : 'Required — app password or SMTP password' }}"
                    value=""
                    hint="{{ $mail['password_is_set'] ? 'A password is already stored and is never shown. Leave this blank to keep it.' : 'Required for SMTP, SES and Postmark. Cannot be left empty.' }}"
                />

                <x-select name="encryption" label="Encryption">
                    @foreach (['tls' => 'TLS (recommended)', 'ssl' => 'SSL', '' => 'None'] as $val => $lbl)
                        <option value="{{ $val }}" @selected(old('encryption', $mail['encryption']) === $val)>{{ $lbl }}</option>
                    @endforeach
                </x-select>
                <div></div>
            </div>
        </x-card>

        <x-card title="Automated Email (No-Reply)" description="Used for welcome emails, order status updates, low stock alerts and password resets. Replies are redirected to the Reply-To address below, so nothing gets lost.">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-input name="system_from_address" label="From email" type="email" required placeholder="noreply@yourcompany.com" value="{{ old('system_from_address', $mail['system_from_address']) }}" />
                <x-input name="system_from_name" label="From name" placeholder="{{ company_name() }}" value="{{ old('system_from_name', $mail['system_from_name']) }}" />
            </div>
        </x-card>

        <x-card title="Reply-To (Team Mailbox)" description="Where replies to automated emails are delivered. Point this at the mailbox your team actually watches.">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-input name="reply_to_address" label="Reply-To email" type="email" required placeholder="info@yourcompany.com" value="{{ old('reply_to_address', $mail['reply_to_address']) }}" />
                <x-input name="reply_to_name" label="Reply-To name" placeholder="{{ company_name() }}" value="{{ old('reply_to_name', $mail['reply_to_name']) }}" />
            </div>
        </x-card>

        <x-card title="Personal Email (Staff)" description="Used when a member of staff emails a customer directly from their record. Sending from the shared mailbox keeps the conversation on the company domain and working even if that person leaves.">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-input name="personal_from_address" label="From email" type="email" required placeholder="info@yourcompany.com" value="{{ old('personal_from_address', $mail['personal_from_address']) }}" />
                <x-input name="personal_from_name" label="From name" placeholder="{{ company_name() }}" value="{{ old('personal_from_name', $mail['personal_from_name']) }}" />

                <div class="sm:col-span-2">
                    <label class="flex items-start gap-3 rounded-lg border border-line bg-surface-muted p-3">
                        <input type="checkbox" name="personal_use_shared_address" value="1" class="mt-0.5 rounded border-line" @checked(old('personal_use_shared_address', $mail['personal_use_shared_address']))>
                        <span>
                            <span class="block text-sm font-medium text-ink">Send staff email from the shared mailbox</span>
                            <span class="block text-xs text-ink-soft">Recommended. Uncheck to send as the individual staff member's own address instead.</span>
                        </span>
                    </label>
                </div>
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
