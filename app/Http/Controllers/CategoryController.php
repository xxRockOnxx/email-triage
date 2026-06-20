<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Categories/Index', [
            'categories' => Category::withCount('triageResults')->orderBy('name')->get(),
            'pendingReview' => Category::where('status', 'pending_review')
                ->withCount('triageResults')
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:categories,name'],
            'description' => ['nullable', 'string', 'max:1000'],
            'confidence_threshold' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        Category::create($validated + ['source' => 'user', 'status' => 'active']);

        return back()->with('success', 'Category created.');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', 'unique:categories,name,'.$category->id],
            'description' => ['nullable', 'string', 'max:1000'],
            'confidence_threshold' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $category->update($validated);

        return back()->with('success', 'Category updated.');
    }

    /**
     * Accept a pending_review (LLM-proposed) category, making it active and
     * eligible for matching in future triage prompts.
     */
    public function accept(Category $category): RedirectResponse
    {
        $category->update(['status' => 'active']);

        return back()->with('success', "\"{$category->name}\" accepted as a new category.");
    }

    /**
     * Merge a pending (or any) category into an existing one — re-points all
     * triage_results and marks this category as merged rather than deleting,
     * so historical data stays intact.
     */
    public function merge(Request $request, Category $category): RedirectResponse
    {
        $validated = $request->validate([
            'merge_into_id' => ['required', 'exists:categories,id', 'different:category'],
        ]);

        $target = Category::findOrFail($validated['merge_into_id']);

        $category->triageResults()->update(['category_id' => $target->id]);
        $category->update(['status' => 'merged', 'merged_into_id' => $target->id]);

        return back()->with('success', "Merged \"{$category->name}\" into \"{$target->name}\".");
    }

    public function reject(Category $category): RedirectResponse
    {
        $category->update(['status' => 'rejected']);

        return back()->with('success', "\"{$category->name}\" rejected.");
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->is_system_default) {
            return back()->withErrors(['category' => 'System default categories cannot be deleted.']);
        }

        $category->delete();

        return back()->with('success', 'Category deleted.');
    }
}
