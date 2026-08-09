<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\SalesCustomer;
use App\Models\Visit;
use App\Models\VisitPitstop;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class VisitsSeeder extends Seeder
{
    public function run(): void
    {
        $customers = SalesCustomer::query()->orderBy('company_name')->get();
        $reps = Employee::query()->where('employment_status', 'active')->get();

        if ($customers->isEmpty()) {
            return;
        }

        $reps = $reps->isEmpty() ? collect([null]) : $reps;

        // Accra area waypoints for realistic GPS data.
        $points = [
            [5.6037, -0.1870], [5.6172, -0.2050], [5.6300, -0.1680],
            [5.5900, -0.2100], [5.6400, -0.1900], [5.5750, -0.1950],
        ];

        $statuses = ['completed', 'completed', 'completed', 'started', 'pending', 'pending', 'cancelled'];
        $outcomes = ['attended', 'closed_deal', 'rescheduled', 'no_contact'];

        foreach ($customers as $index => $customer) {
            $rep = $reps->get($index % $reps->count());

            $scheduled = Carbon::now()->subDays($index % 20)->addDays(($index % 5) - 1);

            $status = $statuses[$index % count($statuses)];

            $visit = Visit::create([
                'visit_number' => next_document_number('visit', 'VIS'),
                'customer_id' => $customer->id,
                'sales_rep_id' => $rep?->id,
                'purpose' => ['Product demonstration', 'Monthly account review', 'Contract renewal', 'New prospect meeting'][$index % 4],
                'notes' => $index % 3 === 0 ? 'Bring the updated price list and samples.' : null,
                'status' => $status,
                'scheduled_at' => $scheduled->toDateString(),
                'start_lat' => $points[$index % count($points)][0],
                'start_lng' => $points[$index % count($points)][1],
            ]);

            if ($status === 'completed') {
                $visit->update([
                    'started_at' => $scheduled->copy()->setTime(9, 30),
                    'completed_at' => $scheduled->copy()->setTime(12, 0),
                    'outcome' => $outcomes[$index % count($outcomes)],
                    'outcome_notes' => 'Customer confirmed follow-up for next month.',
                ]);
            } elseif ($status === 'started') {
                $visit->update(['started_at' => now()->subHour()]);
            }

            $pitStopCount = $index % 3;
            for ($i = 1; $i <= $pitStopCount; $i++) {
                $nextCustomer = $customers->get(($index + $i) % $customers->count());

                VisitPitstop::create([
                    'visit_id' => $visit->id,
                    'customer_id' => $nextCustomer?->id,
                    'purpose' => ['Quick check-in', 'Collect payment', 'Drop documents'][$i % 3],
                    'distance_km' => (string) (2 + $i * 1.5),
                    'visited_at' => $visit->started_at?->copy()->addHours($i) ?? $scheduled->copy()->setTime(10 + $i, 0),
                    'lat' => $points[($index + $i) % count($points)][0] + 0.003 * $i,
                    'lng' => $points[($index + $i) % count($points)][1] + 0.004 * $i,
                ]);
            }

            if ($status === 'completed') {
                $visit->update(['distance_km' => (string) $visit->totalDistanceKm()]);
            }
        }
    }
}
