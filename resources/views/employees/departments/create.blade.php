<x-app-layout :pageTitle="'New department'">
    <x-slot name="header">
        <x-page-header title="New department" description="Create a department and optionally assign a head." icon="building" />
    </x-slot>

    @include('employees._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('employees.departments.store') }}" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="name" label="Name" required placeholder="e.g. Engineering" value="{{ old('name') }}" :error="$errors->first('name')" />
                    <x-input name="code" label="Code" placeholder="e.g. ENG" value="{{ old('code') }}" :error="$errors->first('code')" />
                </div>

                <x-select name="head_of_department_id" label="Head of department" :error="$errors->first('head_of_department_id')">
                    <option value="">No head assigned</option>
                    @foreach ($employees as $employee)
                        <option value="{{ $employee->id }}" @selected(old('head_of_department_id') == $employee->id)>{{ $employee->fullName() }}</option>
                    @endforeach
                </x-select>

                <x-textarea name="description" label="Description" :error="$errors->first('description')">{{ old('description') }}</x-textarea>

                <x-toggle name="is_active" label="Active" description="Inactive departments are hidden from new employee assignments." :checked="old('is_active', true)" />

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Create department</x-button>
                    <x-button href="{{ route('employees.departments.index') }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
