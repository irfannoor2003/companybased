<x-app-layout :pageTitle="'Edit department'">
    <x-slot name="header">
        <x-page-header :title="'Edit '.$department->name" description="Update department details and head." icon="tag" />
    </x-slot>

    @include('employees._tabs')

    <div class="mt-6 max-w-3xl">
        <x-card>
            <form method="POST" action="{{ route('employees.departments.update', $department) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-input name="name" label="Name" required placeholder="e.g. Engineering" value="{{ old('name', $department->name) }}" :error="$errors->first('name')" />
                    <x-input name="code" label="Code" placeholder="e.g. ENG" value="{{ old('code', $department->code) }}" :error="$errors->first('code')" />
                </div>

                <x-select name="head_of_department_id" label="Head of department" :error="$errors->first('head_of_department_id')">
                    <option value="">No head assigned</option>
                    @foreach ($employees as $employee)
                        <option value="{{ $employee->id }}" @selected(old('head_of_department_id', $department->head_of_department_id) == $employee->id)>{{ $employee->fullName() }}</option>
                    @endforeach
                </x-select>

                <x-textarea name="description" label="Description" :error="$errors->first('description')">{{ old('description', $department->description) }}</x-textarea>

                <x-toggle name="is_active" label="Active" description="Inactive departments are hidden from new employee assignments." :checked="old('is_active', $department->is_active)" />

                <div class="flex items-center gap-2 pt-1">
                    <x-button type="submit" icon="save">Save changes</x-button>
                    <x-button href="{{ route('employees.departments.show', $department) }}" variant="secondary">Cancel</x-button>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
