<x-app-layout :pageTitle="$employee->fullName()">
    <x-slot name="header">
        <x-page-header :title="$employee->fullName()" :description="$employee->employee_code.' · '.($employee->job_title ?: '—')" icon="employees">
            <x-slot name="actions">
                @if (auth()->user()->can('employees.employees.edit'))
                    <x-button href="{{ route('employees.employees.edit', $employee) }}" variant="secondary" icon="tag">Edit</x-button>
                @endif
                @if (auth()->user()->can('employees.attendance.view'))
                    <x-button href="{{ route('employees.attendance.index', ['employee_id' => $employee->id]) }}" variant="ghost" icon="clock">Attendance</x-button>
                @endif
                <x-button href="{{ route('employees.employees.index') }}" variant="ghost" icon="arrow-left">Back</x-button>
            </x-slot>
        </x-page-header>
    </x-slot>

    @include('employees._tabs')

    @php
        $qrTimestamp = now()->timestamp;
        $qrSignature = hash_hmac('sha256', $employee->employee_code.'|'.$qrTimestamp, (string) config('app.key'));
        $qrUrl = route('employees.attendance.qr', ['code' => $employee->employee_code, 't' => $qrTimestamp, 's' => $qrSignature]);
    @endphp

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Profile">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt class="text-ink-faint">Department</dt><dd class="text-ink">{{ $employee->department?->name ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Status</dt><dd><x-employees.status-badge :status="$employee->employment_status" /></dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Date hired</dt><dd class="text-ink">{{ $employee->date_hired->format('Y-m-d') }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Date of birth</dt><dd class="text-ink">{{ $employee->date_of_birth?->format('Y-m-d') ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Email</dt><dd class="text-ink">{{ $employee->email ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Phone</dt><dd class="text-ink">{{ $employee->phone ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Linked user</dt><dd class="text-ink">{{ $employee->user?->name ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-faint">Attendance</dt><dd><x-badge :color="$employee->attendance_enabled ? 'success' : 'neutral'">{{ $employee->attendance_enabled ? 'Enabled' : 'Disabled' }}</x-badge></dd></div>
                @if ($employee->address)
                    <div class="border-t border-line pt-3"><dt class="text-ink-faint">Address</dt><dd class="mt-1 text-ink-soft">{{ $employee->address }}</dd></div>
                @endif
            </dl>
        </x-card>

        <x-card title="Clock-in QR" description="Time-limited signed URL; valid for 3 minutes." class="lg:col-span-2">
            <div class="flex flex-col gap-3">
                <div class="flex items-center gap-2">
                    <input readonly value="{{ $qrUrl }}" class="input flex-1 font-mono text-xs" onclick="this.select()" />
                    <button type="button" class="btn-secondary btn-md shrink-0" onclick="navigator.clipboard.writeText('{{ $qrUrl }}'); this.textContent='Copied'; setTimeout(() => this.textContent='Copy', 1500);">
                        Copy
                    </button>
                </div>
                <p class="text-xs text-ink-faint">
                    Point a phone at this link to clock in/out as {{ $employee->fullName() }} without a login.
                    Reopen this page to regenerate an up-to-date link.
                </p>
            </div>
        </x-card>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Salary" :padding="false" class="lg:col-span-1">
            @php $activeStructure = $employee->salaryStructures->firstWhere('is_active', true); @endphp
            @if ($activeStructure)
                <dl class="space-y-3 px-5 py-5 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-faint">Effective from</dt><dd class="text-ink">{{ $activeStructure->effective_from->format('Y-m-d') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Basic</dt><dd class="text-ink">{{ money($activeStructure->basic_salary) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Allowances</dt><dd class="text-ink">{{ money($activeStructure->allowances()) }}</dd></div>
                    <div class="flex justify-between border-t border-line pt-3"><dt class="text-ink-faint">Gross</dt><dd class="font-semibold text-ink">{{ money($activeStructure->gross()) }}</dd></div>
                </dl>
            @else
                <p class="px-5 py-6 text-sm text-ink-faint">No active salary structure.</p>
            @endif
            @if (auth()->user()->can('employees.salary_structures.view'))
                <div class="border-t border-line px-5 py-3">
                    <a href="{{ route('employees.salary_structures.index', ['employee_id' => $employee->id]) }}" class="text-sm font-medium text-primary hover:underline">View all structures</a>
                </div>
            @endif
        </x-card>

        <x-card title="Recent attendance" :padding="false" class="lg:col-span-2">
            @if ($attendance->isEmpty())
                <p class="px-5 py-6 text-sm text-ink-faint">No attendance records yet.</p>
            @else
                <div class="table-wrap !border-0 !rounded-none">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>In</th>
                                <th>Out</th>
                                <th class="text-right">Work</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($attendance as $record)
                                <tr>
                                    <td class="text-ink">{{ $record->attendance_date->format('Y-m-d') }}</td>
                                    <td class="text-ink-soft">{{ $record->check_in_at?->format('H:i') ?: '—' }}</td>
                                    <td class="text-ink-soft">{{ $record->check_out_at?->format('H:i') ?: '—' }}</td>
                                    <td class="text-right text-ink-soft">{{ $record->work_minutes ? gmdate('H:i', $record->work_minutes * 60) : '—' }}</td>
                                    <td><x-employees.status-badge :status="$record->status ?? 'pending'" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Documents" :padding="false" class="lg:col-span-2">
            @if ($employee->documents->isEmpty())
                <p class="px-5 py-6 text-sm text-ink-faint">No documents uploaded.</p>
            @else
                <ul class="divide-y divide-line">
                    @foreach ($employee->documents as $document)
                        <li class="flex items-center justify-between gap-3 px-5 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-ink">{{ $document->title }}</p>
                                <p class="text-xs text-ink-faint">
                                    {{ $document->type ?: 'General' }}
                                    @if ($document->uploader) · by {{ $document->uploader->name }} @endif
                                    · {{ $document->created_at->format('Y-m-d') }}
                                </p>
                            </div>
                            <div class="inline-flex shrink-0 gap-1">
                                <a href="{{ route('employees.documents.download', $document) }}" class="btn-ghost btn-icon btn-sm" title="Download">
                                    <x-icon name="download" class="size-4" />
                                </a>
                                @if (auth()->user()->can('employees.documents.delete'))
                                    <form method="POST" action="{{ route('employees.documents.destroy', $document) }}" onsubmit="return confirm('Remove this document?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-ghost btn-icon btn-sm text-rose-500" title="Remove">
                                            <x-icon name="trash" class="size-4" />
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>

        @if (auth()->user()->can('employees.documents.create'))
            <x-card title="Upload document">
                <form method="POST" action="{{ route('employees.documents.store', $employee) }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <x-input name="title" label="Title" required placeholder="e.g. Employment contract" :error="$errors->first('title')" />
                    <x-input name="type" label="Type" placeholder="e.g. Contract, ID, Certificate" :error="$errors->first('type')" />
                    <div>
                        <label class="label" for="file">File <span class="text-rose-500">*</span></label>
                        <input type="file" name="file" id="file" class="input" />
                        @if ($errors->first('file'))
                            <p class="mt-1.5 text-xs text-rose-600 dark:text-rose-400">{{ $errors->first('file') }}</p>
                        @endif
                    </div>
                    <x-textarea name="notes" label="Notes">{{ old('notes') }}</x-textarea>
                    <x-button type="submit" icon="upload">Upload</x-button>
                </form>
            </x-card>
        @endif
    </div>
</x-app-layout>