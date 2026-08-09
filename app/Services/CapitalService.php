<?php

namespace App\Services;

use App\Models\CapitalContribution;
use App\Models\CapitalDrawing;
use Illuminate\Support\Collection;

class CapitalService
{
    public function totals(?string $from = null, ?string $to = null): array
    {
        $contributions = CapitalContribution::query()
            ->when($from, fn ($q) => $q->where('contribution_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('contribution_date', '<=', $to))
            ->sum('amount');

        $drawings = CapitalDrawing::query()
            ->when($from, fn ($q) => $q->where('drawing_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('drawing_date', '<=', $to))
            ->sum('amount');

        return [
            'contributions' => round((float) $contributions, 2),
            'drawings' => round((float) $drawings, 2),
            'equity' => round((float) $contributions - (float) $drawings, 2),
        ];
    }

    public function contributors(): Collection
    {
        $names = collect(CapitalContribution::query()->distinct()->orderBy('contributor')->pluck('contributor'))
            ->merge(CapitalDrawing::query()->distinct()->orderBy('recipient')->pluck('recipient'))
            ->filter()
            ->unique();

        return $names->values();
    }

    public function statement(?string $party = null, ?string $from = null, ?string $to = null): Collection
    {
        $contributions = CapitalContribution::query()
            ->when($party, fn ($q) => $q->where('contributor', $party))
            ->when($from, fn ($q) => $q->where('contribution_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('contribution_date', '<=', $to))
            ->get()
            ->map(fn (CapitalContribution $c) => [
                'date' => $c->contribution_date,
                'type' => 'contribution',
                'reference' => $c->reference,
                'party' => $c->contributor,
                'amount' => (float) $c->amount,
                'method' => $c->method,
            ]);

        $drawings = CapitalDrawing::query()
            ->when($party, fn ($q) => $q->where('recipient', $party))
            ->when($from, fn ($q) => $q->where('drawing_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('drawing_date', '<=', $to))
            ->get()
            ->map(fn (CapitalDrawing $d) => [
                'date' => $d->drawing_date,
                'type' => 'drawing',
                'reference' => $d->reference,
                'party' => $d->recipient,
                'amount' => -1 * (float) $d->amount,
                'method' => $d->method,
            ]);

        $balance = 0.0;

        return collect()
            ->merge($contributions)
            ->merge($drawings)
            ->sortBy('date')
            ->map(function (array $row) use (&$balance) {
                $balance = round($balance + $row['amount'], 2);
                $row['balance'] = $balance;

                return $row;
            })
            ->values();
    }
}