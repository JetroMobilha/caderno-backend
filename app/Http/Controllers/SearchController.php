<?php

namespace App\Http\Controllers;

use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SearchController extends Controller
{
    /**
     * Perform a global search across all accessible pages for the user.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function globalSearch(Request $request)
    {
        // 1. Validation
        if (!$request->has('term')) {
            return response()->json(['message' => 'O termo de pesquisa é obrigatório.'], 400);
        }

        $validated = $request->validate([
            'term' => 'required|string|min:3',
        ]);
        $term = $validated['term'];
        $user = $request->user();

        // 2. Build the base query for accessible pages
        $query = Page::query()
            ->whereNotNull('extracted_text')
            ->where('extracted_text', '!=', '')
            ->where(function ($query) use ($user) {
                // Pages from user's own notebooks
                $query->whereHas('notebook.subject', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                })
                // Pages from notebooks shared with the user
                ->orWhereHas('notebook.sharedUsers', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                });
            });

        // 3. Apply the search term filter
        $query->where('extracted_text', 'LIKE', '%' . $term . '%');

        // 4. Load relations
        $query->with(['notebook.subject']);

        // 5. Paginate the results
        $paginatedResults = $query->paginate(20);

        // 6. Post-process results to create a preview snippet
        $paginatedResults->getCollection()->transform(function ($item) use ($term) {
            $item->preview_text = $this->generatePreview($item->extracted_text, $term);
            $item->score = round(strlen($term) / max(1, strlen($item->extracted_text)) * 100, 2);
            return $item;
        });

        // 7. Return the results
        return response()->json($paginatedResults->items());
    }

    /**
     * Generates a preview snippet from the text, highlighting the search term.
     *
     * @param string|null $text
     * @param string $term
     * @return string
     */
    private function generatePreview(?string $text, string $term): string
    {
        if (!$text) {
            return '';
        }

        $position = stripos($text, $term);
        if ($position === false) {
            return Str::limit($text, 150);
        }

        $start = max(0, $position - 50);
        $length = strlen($term) + 100;
        $snippet = substr($text, $start, $length);

        return '...' . trim($snippet) . '...';
    }
}
