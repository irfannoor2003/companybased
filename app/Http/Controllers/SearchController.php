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

        // Settings pages are not database records, so they are not in the
        // SearchRegistry. Surface the ones the user may open when the term
        // matches, so Super Admin can reach the company configuration page
        // by typing "company".
        foreach ($this->settingsPages($request->user(), $term) as $page) {
            $results[] = $page;
        }

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

        foreach ($this->settingsPages($request->user(), $term) as $page) {
            $results[] = $page;
        }

        // Group by entity so the page reads as sections rather than one long list.
        $grouped = collect($results)->groupBy('label');

        return view('search.index', compact('term', 'grouped', 'entities'));
    }

    /**
     * Settings screens are not database records, but Super Admin (and anyone
     * else with the relevant permission) still expects to reach them by typing
     * a keyword such as "company" into the global search box.
     *
     * @return array<int, array<string, mixed>>
     */
    private function settingsPages(?object $user, string $term): array
    {
        if (! $user) {
            return [];
        }

        $term = strtolower(trim($term));

        if (mb_strlen($term) < 2) {
            return [];
        }

        $pages = [
            ['title' => 'Company Profile', 'type' => 'Settings', 'icon' => 'company', 'permission' => 'settings.company.view', 'url' => route('settings.company'), 'keywords' => 'company profile settings configuration location qr code'],
            ['title' => 'Mail Server', 'type' => 'Settings', 'icon' => 'mail', 'permission' => 'settings.mail.view', 'url' => route('settings.mail'), 'keywords' => 'mail email smtp server settings'],
            ['title' => 'Document Templates', 'type' => 'Settings', 'icon' => 'document', 'permission' => 'settings.templates.view', 'url' => route('settings.templates.index'), 'keywords' => 'document templates invoice quote order design'],
            ['title' => 'Modules', 'type' => 'Settings', 'icon' => 'modules', 'permission' => 'settings.modules.view', 'url' => route('settings.modules'), 'keywords' => 'modules settings'],
            ['title' => 'Backups', 'type' => 'Settings', 'icon' => 'backup', 'permission' => 'settings.backup.view', 'url' => route('settings.backups'), 'keywords' => 'backups settings'],
            ['title' => 'Subscription', 'type' => 'Settings', 'icon' => 'subscription', 'permission' => 'settings.subscription.view', 'url' => route('settings.subscription'), 'keywords' => 'subscription package settings'],
        ];

        $results = [];

        foreach ($pages as $page) {
            if (! $user->can($page['permission'])) {
                continue;
            }

            if (! str_contains($page['keywords'], $term)) {
                continue;
            }

            $results[] = [
                'entity' => 'settings_page',
                'type' => $page['type'],
                'label' => 'Settings',
                'icon' => $page['icon'],
                'title' => $page['title'],
                'subtitle' => null,
                'meta' => null,
                'url' => $page['url'],
            ];
        }

        return $results;
    }
}
