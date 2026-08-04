<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Page;
use App\Models\User;

class SearchController extends Controller
{
    /**
     * Performs a global search across all pages for the authenticated user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function globalSearch(Request $request)
    {
        $user = $request->user();
        $term = $request->input('term');

        if (!$term) {
            return response()->json(['message' => 'O termo de pesquisa é obrigatório.'], 400);
        }

        $pages = Page::whereNotNull('extracted_text')
                     ->where('extracted_text', 'like', '%' . $term . '%')
                     ->whereHas('notebook.subject', function ($query) use ($user) {
                         $query->where('user_id', $user->id);
                     })
                     ->with(['notebook:id,title', 'notebook.subject:id,title']) // Eager load relationships
                     ->get();

        return response()->json($pages);
    }
}
