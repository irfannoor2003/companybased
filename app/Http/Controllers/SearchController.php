<?php

namespace App\Http\Controllers;

use App\Support\SearchRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    /**
     * JSON endpoint backing the global search box in the topbar.
     *
     * Results are permission-scoped by SearchRegistry::forUser(), so a user
     * only ever sees entities whose permission they hold and whose module is
     * enabled for this deployment.
     */
    public function index(Request $request): JsonResponse
    {
        $term = (string) $request->query('q', '');

        $results = SearchRegistry::query(SearchRegistry::forUser($request->user()), $term);

        return response()->json([
            'query' => $term,
            'results' => $results,
            'count' => count($results),
        ]);
    }

    /**
     * Full results page, for users who prefer a list to the dropdown.
     */
    public function page(Request $request): View
    {
        $term = trim((string) $request->query('q', ''));
        $entities = SearchRegistry::forUser($request->user());

        $results = SearchRegistry::query($entities, $term, perEntity: 25);

        // Group by entity so the page reads as sections rather than one long list.
        $grouped = collect($results)->groupBy('label');

        return view('search.index', compact('term', 'grouped', 'entities'));
    }
}
