<x-settings-layout page-title="Mail Server">
    <x-page-header title="Mail Server" description="Configure SMTP or transactional mail service for notifications and emails." icon="mail">
        <x-slot name="actions">
            <x-button href="{{ route('settings.company') }}" variant="secondary" icon="arrow-left">Back</x-button>
        </x-slot>
    </x-page-header>

    <form method="POST" action="{{ route('settings.mail.update') }}" class="mt-6 space-y-6 max-w-3xl">
            {{--
                Every reason this form refused to save.

                Rendered because it was NOT rendered, and that is the whole bug. The
                save path rejects a half-configured SMTP login -- a blank password with
                no stored one -- which is exactly the state of a newly deployed site.
                With no error summary and no per-field messages, the browser posted,
                the server redirected back, the form looked untouched, and the only
                conclusion available to the operator was "this does not save".

                `x-input` only renders a message when it is handed `:error`
                explicitly; it does not read the error bag. So a summary here is the
                single place every rejection is guaranteed to be visible, rather than
                relying on each field being wired to say so.
            --}}
            @if ($errors->any())
                <div class="rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 dark:border-rose-500/40 dark:bg-rose-500/10">
                    <p class="text-sm font-semibold text-rose-700 dark:text-rose-400">
                        Mail settings were not saved. Fix the following:
                    </p>
                    <ul class="mt-2 space-y-1 text-sm text-rose-700 dark:text-rose-400">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
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

                <x-input name="username" label="Username" placeholder="e.g. user@gmail.com" value="{{ old('username', $mail['username']) }}" :required="$mail['mailer'] !== 'log'" />
                <x-input
                    name="password"
                    label="Password"
                    type="password"
                    :required="! $mail['password_is_set']"
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

        <x-card title="Automated Email (No-Reply)" description="Used for welcome emails, order status updates and low stock alerts. Replies are redirected to the Reply-To address below, so nothing gets lost.">
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

        <x-card title="Second Mailbox Login (info@)" description="On shared hosting such as Hostinger and cPanel, every mailbox is a separate login with its own password, even though they all relay through the same server. Set the team mailbox login here when its password differs from the no-reply one.">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-input
                    name="team_host"
                    label="Team SMTP host"
                    placeholder="{{ $mail['host'] ?: 'smtp.example.com' }}"
                    value="{{ old('team_host', $mail['team_host']) }}"
                    hint="Leave blank to use the primary SMTP host."
                />
                <x-input
                    name="team_port"
                    label="Team SMTP port"
                    type="number"
                    min="1"
                    max="65535"
                    placeholder="{{ $mail['port'] ?: 587 }}"
                    value="{{ old('team_port', $mail['team_port']) }}"
                    hint="Leave blank to use the primary SMTP port."
                />
                <x-select name="team_encryption" label="Team encryption">
                    @foreach (['' => 'Inherit primary', 'tls' => 'TLS (recommended)', 'ssl' => 'SSL'] as $val => $lbl)
                        <option value="{{ $val }}" @selected(old('team_encryption', $mail['team_encryption']) === $val)>{{ $lbl }}</option>
                    @endforeach
                </x-select>
                <div></div>
                <x-input
                    name="team_username"
                    label="Team username"
                    placeholder="e.g. info@yourcompany.com"
                    value="{{ old('team_username', $mail['team_username']) }}"
                    hint="The info@ mailbox. Leave blank to reuse the username above."
                />
                <x-input
                    name="team_password"
                    label="Team password"
                    type="password"
                    autocomplete="new-password"
                    placeholder="{{ $mail['team_password_is_set'] ? '•••••••• (stored — leave blank to keep)' : 'Leave blank to reuse the password above' }}"
                    value=""
                    hint="{{ $mail['team_password_is_set'] ? 'A team password is already stored and is never shown. Leave this blank to keep it.' : 'Only needed if it differs from the password above.' }}"
                />

                <div class="sm:col-span-2">
                    <p class="text-xs text-ink-soft">
                        Staff email sent from the portal authenticates with these credentials. Automated email
                        (welcome messages, order updates, alerts) always uses the no-reply login in the
                        <span class="font-medium text-ink-soft">Outgoing Mail</span> card above.
                        To go back to a single login, clear both boxes and save.
                    </p>
                </div>
            </div>
        </x-card>

        <x-card title="Accounts & Invoicing Email" description="Used when dispatching sales invoices, credit notes, customer statements, and payment receipts.">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-input name="accounts_from_address" label="Accounts from email" type="email" placeholder="billing@yourcompany.com" value="{{ old('accounts_from_address', $mail['accounts_from_address']) }}" hint="Leave blank to use the automated system address." />
                <x-input name="accounts_from_name" label="Accounts from name" placeholder="{{ company_name() }} Accounts" value="{{ old('accounts_from_name', $mail['accounts_from_name']) }}" />

                <x-input
                    name="accounts_host"
                    label="Accounts SMTP host"
                    placeholder="{{ $mail['host'] ?: 'smtp.example.com' }}"
                    value="{{ old('accounts_host', $mail['accounts_host']) }}"
                    hint="Leave blank to use the primary SMTP host."
                />
                <x-input
                    name="accounts_port"
                    label="Accounts SMTP port"
                    type="number"
                    min="1"
                    max="65535"
                    placeholder="{{ $mail['port'] ?: 587 }}"
                    value="{{ old('accounts_port', $mail['accounts_port']) }}"
                    hint="Leave blank to use the primary SMTP port."
                />
                <x-select name="accounts_encryption" label="Accounts encryption">
                    @foreach (['' => 'Inherit primary', 'tls' => 'TLS (recommended)', 'ssl' => 'SSL'] as $val => $lbl)
                        <option value="{{ $val }}" @selected(old('accounts_encryption', $mail['accounts_encryption']) === $val)>{{ $lbl }}</option>
                    @endforeach
                </x-select>
                <div></div>
                <x-input
                    name="accounts_username"
                    label="Accounts mailbox username"
                    placeholder="e.g. billing@yourcompany.com"
                    value="{{ old('accounts_username', $mail['accounts_username']) }}"
                    hint="Optional separate login. Leave blank to reuse the primary SMTP credentials."
                />
                <x-input
                    name="accounts_password"
                    label="Accounts mailbox password"
                    type="password"
                    autocomplete="new-password"
                    placeholder="{{ $mail['accounts_password_is_set'] ? '•••••••• (stored — leave blank to keep)' : 'Leave blank to reuse primary SMTP password' }}"
                    value=""
                    hint="{{ $mail['accounts_password_is_set'] ? 'Stored password kept if blank.' : 'Only needed if accounts mailbox has its own password.' }}"
                />
            </div>
        </x-card>

        <x-card title="Sales & Quotes Email" description="Used when dispatching sales quotes, order confirmations, and proposal emails to prospects and customers.">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-input name="sales_from_address" label="Sales from email" type="email" placeholder="sales@yourcompany.com" value="{{ old('sales_from_address', $mail['sales_from_address']) }}" hint="Leave blank to use the shared team mailbox." />
                <x-input name="sales_from_name" label="Sales from name" placeholder="{{ company_name() }} Sales" value="{{ old('sales_from_name', $mail['sales_from_name']) }}" />

                <div class="sm:col-span-2">
                    <label class="flex items-start gap-3 rounded-lg border border-line bg-surface-muted p-3">
                        <input type="checkbox" name="sales_use_salesman_address" value="1" class="mt-0.5 rounded border-line" @checked(old('sales_use_salesman_address', $mail['sales_use_salesman_address']))>
                        <span>
                            <span class="block text-sm font-medium text-ink">Send quotes and orders from the assigned Salesman's email address</span>
                            <span class="block text-xs text-ink-soft">When enabled, the email arrives directly from the assigned representative with their name. Falls back to company sales address if no salesman is assigned.</span>
                        </span>
                    </label>
                </div>

                <x-input
                    name="sales_host"
                    label="Sales SMTP host"
                    placeholder="{{ $mail['host'] ?: 'smtp.example.com' }}"
                    value="{{ old('sales_host', $mail['sales_host']) }}"
                    hint="Leave blank to use the primary SMTP host."
                />
                <x-input
                    name="sales_port"
                    label="Sales SMTP port"
                    type="number"
                    min="1"
                    max="65535"
                    placeholder="{{ $mail['port'] ?: 587 }}"
                    value="{{ old('sales_port', $mail['sales_port']) }}"
                    hint="Leave blank to use the primary SMTP port."
                />
                <x-select name="sales_encryption" label="Sales encryption">
                    @foreach (['' => 'Inherit primary', 'tls' => 'TLS (recommended)', 'ssl' => 'SSL'] as $val => $lbl)
                        <option value="{{ $val }}" @selected(old('sales_encryption', $mail['sales_encryption']) === $val)>{{ $lbl }}</option>
                    @endforeach
                </x-select>
                <div></div>
                <x-input
                    name="sales_username"
                    label="Sales mailbox username"
                    placeholder="e.g. sales@yourcompany.com"
                    value="{{ old('sales_username', $mail['sales_username']) }}"
                    hint="Optional separate login. Leave blank to reuse primary SMTP."
                />
                <x-input
                    name="sales_password"
                    label="Sales mailbox password"
                    type="password"
                    autocomplete="new-password"
                    placeholder="{{ $mail['sales_password_is_set'] ? '•••••••• (stored — leave blank to keep)' : 'Leave blank to reuse primary SMTP password' }}"
                    value=""
                    hint="{{ $mail['sales_password_is_set'] ? 'Stored password kept if blank.' : 'Only needed if sales mailbox has its own password.' }}"
                />
            </div>
        </x-card>

        <div class="flex justify-end gap-3">
            <x-button href="{{ route('settings.company') }}" variant="ghost">Cancel</x-button>
            <x-button type="submit" icon="save">Save mail settings</x-button>
        </div>
    </form>

    <div class="mt-6 max-w-3xl">
        <x-card title="Test Email" description="Send a test email to verify any configured mailbox identity and transport.">
            <form method="POST" action="{{ route('settings.mail.test') }}" class="space-y-4">
                @csrf
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="test_email" label="Recipient email" type="email" value="{{ old('test_email', auth()->user()->email) }}" placeholder="Enter recipient email" required />
                    <x-select name="test_identity" label="Test email as">
                        <option value="system">Automated (No-Reply)</option>
                        <option value="accounts">Accounts & Invoicing</option>
                        <option value="sales">Sales & Orders</option>
                        <option value="team">Team Mailbox (info@)</option>
                    </x-select>
                </div>
                <div class="flex justify-end">
                    <x-button type="submit" icon="send" size="sm" variant="secondary">Send test email</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-settings-layout>
