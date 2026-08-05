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
        // Using a simple LIKE query. For production, consider a full-text search index.
        $query->where('extracted_text', 'LIKE', '%' . $term . '%');

        // 4. Select the required fields and join with related tables
        $query->join('notebooks', 'pages.notebook_id', '=', 'notebooks.id')
              ->join('subjects', 'notebooks.subject_id', '=', 'subjects.id')
              ->select(
                  'pages.id as page_id',
                  'pages.page_number',
                  'notebooks.id as notebook_id',
                  'notebooks.title as notebook_title',
                  'subjects.id as subject_id',
                  'subjects.name as subject_name',
                  'pages.extracted_text as preview_text' // We'll trim this later
                  // 'score' is hard to calculate without full-text search, so we'll omit it for now
              );
        
        // 5. Paginate the results
        $paginatedResults = $query->paginate(20);

        // 6. Post-process results to create a preview snippet
        $paginatedResults->getCollection()->transform(function ($item) use ($term) {
            $item->preview_text = $this->generatePreview($item->preview_text, $term);
            // Add a dummy score
            $item->score = round(strlen($term) / strlen($item->preview_text) * 100, 2);
            return $item;
        });

        // 7. Return the paginated response
        return response()->json($paginatedResults);
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
