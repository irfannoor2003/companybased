<div
    class="pointer-events-none fixed bottom-4 right-4 z-[70] flex w-full max-w-sm flex-col items-end gap-2 px-4"
    x-data
    x-init="(window.flashToasts ?? []).forEach(t => $store.toasts.push(t.message, t.type))"
>
    <template x-for="toast in $store.toasts.items" :key="toast.id">
        <div
            x-show="toast.visible"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-3"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="pointer-events-auto flex w-full items-start gap-3 rounded-xl border bg-surface p-3.5 shadow-lift"
            :class="{
                'border-emerald-200 dark:border-emerald-500/30': toast.type === 'success',
                'border-rose-200 dark:border-rose-500/30': toast.type === 'error',
                'border-sky-200 dark:border-sky-500/30': toast.type === 'info',
            }"
        >
            <span class="mt-0.5 shrink-0" :class="{
                'text-emerald-500': toast.type === 'success',
                'text-rose-500': toast.type === 'error',
                'text-sky-500': toast.type === 'info',
            }">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" class="size-5">
                    <template x-if="toast.type === 'success'">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                    </template>
                    <template x-if="toast.type === 'error'">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </template>
                    <template x-if="toast.type === 'info'">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                    </template>
                </svg>
            </span>
            <p class="flex-1 text-sm text-ink" x-text="toast.message"></p>
            <button type="button" class="shrink-0 text-ink-faint hover:text-ink" @click="$store.toasts.dismiss(toast.id)" aria-label="Dismiss">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" class="size-4">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </template>
</div>
