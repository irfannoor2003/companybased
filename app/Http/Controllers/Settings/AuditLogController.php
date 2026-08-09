<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $logs = $this->filter($request)->paginate(20)->withQueryString();

        $modules = AuditLog::query()->whereNotNull('module')->distinct()->orderBy('module')->pluck('module');
        $events = ['created', 'updated', 'deleted', 'restored'];

        return view('settings.audit-log', compact('logs', 'modules', 'events'));
    }

    public function export(Request $request): StreamedResponse
    {
        $logs = $this->filter($request)->limit(5000)->get();

        $filename = 'audit-log-'.now()->format('Y-m-d-His').'.csv';

        $stream = function () use ($logs) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Date', 'User', 'Module', 'Event', 'Entity', 'Description', 'IP Address']);

            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->id,
                    $log->created_at?->toDateTimeString(),
                    $log->user?->displayName(),
                    $log->module,
                    $log->event,
                    class_basename((string) $log->auditable_type).($log->auditable_id ? " #{$log->auditable_id}" : ''),
                    $log->description,
                    $log->ip_address,
                ]);
            }

            fclose($handle);
        };

        return Response::streamDownload($stream, $filename, ['Content-Type' => 'text/csv']);
    }

    private function filter(Request $request)
    {
        return AuditLog::query()
            ->with(['user', 'auditable'])
            ->when($request->filled('module'), fn ($q) => $q->where('module', $request->module))
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->event))
            ->when($request->filled('user'), fn ($q) => $q->whereHas('user', fn ($q) => $q
                ->where('name', 'like', "%{$request->user}%")
                ->orWhere('email', 'like', "%{$request->user}%")
                ->orWhere('first_name', 'like', "%{$request->user}%")
                ->orWhere('last_name', 'like', "%{$request->user}%")))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->latest('id');
    }

    public function users(): \Illuminate\Support\Collection
    {
        return User::query()->orderBy('name')->get(['id', 'name']);
    }
}
