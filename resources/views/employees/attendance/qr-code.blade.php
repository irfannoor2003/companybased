<x-settings-layout page-title="Office QR Code">
    <x-page-header title="Office QR Code" description="Scan this QR code to mark attendance. Must be within office radius." icon="clock">
        <x-slot name="actions">
            <x-button href="{{ route('employees.attendance.qr-code.download') }}" variant="secondary" icon="download">Download QR Code</x-button>
            <x-button href="{{ route('employees.attendance.index') }}" variant="secondary" icon="arrow-left">Back</x-button>
        </x-slot>
    </x-page-header>

    <div class="mt-6 max-w-3xl space-y-6">
        <x-card title="Office Attendance QR Code" description="Employees must scan this QR code while within the office radius to mark attendance.">
            <div class="flex flex-col items-center gap-6">
                <div class="rounded-xl border-2 border-dashed border-line p-6 bg-surface">
                    <img src="{{ $base64 }}" alt="Office Attendance QR Code" class="block" />
                </div>

                <div class="text-center">
                    <p class="text-sm font-medium text-ink">QR Code Text</p>
                    <p class="mt-1 font-mono text-sm text-ink-soft bg-surface-muted px-3 py-1.5 rounded-lg">{{ $qrText }}</p>
                </div>

                <div class="flex gap-3">
                    <x-button href="{{ route('employees.attendance.qr-code.download') }}" icon="download" variant="secondary">Download PNG</x-button>
                </div>
            </div>
        </x-card>

        <x-card title="How it works" description="The attendance process for employees scanning this QR code.">
            <ol class="list-decimal list-inside space-y-2 text-sm text-ink-soft">
                <li>Employee opens the attendance scanner on their phone</li>
                <li>Scans this QR code displayed at the office entrance</li>
                <li>The system verifies the QR code text matches the office code</li>
                <li>The system verifies the employee's GPS location is within the office radius</li>
                <li>Both checks pass → attendance is recorded (clock-in or clock-out)</li>
            </ol>
        </x-card>
    </div>
</x-settings-layout>
