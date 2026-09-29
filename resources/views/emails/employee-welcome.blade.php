<x-mail::message>
# Welcome aboard, {{ $employee->first_name }}

Your employee record at **{{ company_name() }}** is now active.

@if ($employee->employee_code)
**Employee code:** {{ $employee->employee_code }}
@endif
@if ($employee->job_title)
**Role:** {{ $employee->job_title }}
@endif
@if ($employee->date_hired)
**Start date:** {{ \Illuminate\Support\Carbon::parse($employee->date_hired)->format('d M Y') }}
@endif

## Signing in

@if ($temporaryPassword)
A login account has been created for you.

**Email:** {{ $employee->email }}
**Temporary password:** `{{ $temporaryPassword }}`

Please sign in and change your password straight away.

<x-mail::button :url="$loginUrl">
Sign in
</x-mail::button>
@else
No login account has been created for you yet. HR will send your credentials separately once it is set up.
@endif

## Your first steps

- Complete your profile and confirm your contact details.
- Read the attendance and leave policy, then mark your attendance each day.
- Submit leave requests through the portal whenever you need time off.

Welcome aboard,<br>
{{ company_name() }}
</x-mail::message>
