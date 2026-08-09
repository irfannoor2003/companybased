@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex items-center justify-between">
        <div class="flex flex-1 justify-between sm:hidden">
            @if ($paginator->onFirstPage())
                <span class="btn-secondary btn-sm cursor-not-allowed">Previous</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="btn-secondary btn-sm">Previous</a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="btn-secondary btn-sm">Next</a>
            @else
                <span class="btn-secondary btn-sm cursor-not-allowed">Next</span>
            @endif
        </div>

        <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
            <div>
                <p class="text-sm text-ink-faint">
                    Showing
                    <span class="font-medium text-ink">{{ $paginator->firstItem() }}</span>
                    to
                    <span class="font-medium text-ink">{{ $paginator->lastItem() }}</span>
                    of
                    <span class="font-medium text-ink">{{ $paginator->total() }}</span>
                    results
                </p>
            </div>

            <div>
                <span class="relative z-0 inline-flex items-center gap-1 rounded-lg">
                    @if ($paginator->onFirstPage())
                        <span aria-disabled="true" aria-label="Previous" class="btn-secondary btn-sm cursor-not-allowed" rel="prev">
                            <x-icon name="chevron-left" class="size-4" />
                        </span>
                    @else
                        <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous" class="btn-secondary btn-sm">
                            <x-icon name="chevron-left" class="size-4" />
                        </a>
                    @endif

                    @foreach ($elements as $element)
                        @if (is_string($element))
                            <span aria-disabled="true" class="px-2 text-sm text-ink-faint">{{ $element }}</span>
                        @endif

                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                @if ($page == $paginator->currentPage())
                                    <span aria-current="page" class="flex h-8 min-w-8 items-center justify-center rounded-lg bg-primary px-2 text-sm font-semibold text-white">{{ $page }}</span>
                                @else
                                    <a href="{{ $url }}" class="flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-sm font-medium text-ink-soft transition-colors hover:bg-surface-muted hover:text-ink">{{ $page }}</a>
                                @endif
                            @endforeach
                        @endif
                    @endforeach

                    @if ($paginator->hasMorePages())
                        <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next" class="btn-secondary btn-sm">
                            <x-icon name="chevron-right" class="size-4" />
                        </a>
                    @else
                        <span aria-disabled="true" aria-label="Next" class="btn-secondary btn-sm cursor-not-allowed">
                            <x-icon name="chevron-right" class="size-4" />
                        </span>
                    @endif
                </span>
            </div>
        </div>
    </nav>
@endif
