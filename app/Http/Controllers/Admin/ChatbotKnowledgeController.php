<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatbotKnowledge;
use Illuminate\Http\Request;

class ChatbotKnowledgeController extends Controller
{
    public function index(Request $request)
    {
        $query = ChatbotKnowledge::query()->orderBy('sort_order')->orderBy('title');

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->string('status')->toString() === 'active');
        }

        if ($request->filled('q')) {
            $term = '%'.$request->string('q')->toString().'%';
            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', $term)
                    ->orWhere('question', 'like', $term)
                    ->orWhere('content', 'like', $term)
                    ->orWhere('keywords', 'like', $term);
            });
        }

        $entries = $query
            ->paginate(config('app.admin_list_per_page'))
            ->withQueryString();

        $categories = ChatbotKnowledge::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return view('admin.chatbot.knowledge.index', compact('entries', 'categories'));
    }

    public function create()
    {
        return view('admin.chatbot.knowledge.create');
    }

    public function store(Request $request)
    {
        $validated = $this->validateEntry($request);

        ChatbotKnowledge::create([
            ...$validated,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()
            ->route('admin.chatbot.knowledge.index')
            ->with('success', 'Knowledge entry created successfully.');
    }

    public function show(ChatbotKnowledge $knowledge)
    {
        return view('admin.chatbot.knowledge.show', compact('knowledge'));
    }

    public function edit(ChatbotKnowledge $knowledge)
    {
        return view('admin.chatbot.knowledge.edit', compact('knowledge'));
    }

    public function update(Request $request, ChatbotKnowledge $knowledge)
    {
        $validated = $this->validateEntry($request);

        $knowledge->update([
            ...$validated,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()
            ->route('admin.chatbot.knowledge.index')
            ->with('success', 'Knowledge entry updated successfully.');
    }

    public function destroy(ChatbotKnowledge $knowledge)
    {
        $knowledge->delete();

        return redirect()
            ->route('admin.chatbot.knowledge.index')
            ->with('success', 'Knowledge entry deleted successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateEntry(Request $request): array
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'nullable|string|max:100',
            'question' => 'nullable|string|max:500',
            'content' => 'required|string',
            'keywords' => 'nullable|string|max:1000',
            'sort_order' => 'nullable|integer|min:0|max:999999',
            'is_active' => 'sometimes|boolean',
        ]);

        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 0);
        $validated['category'] = !empty($validated['category']) ? $validated['category'] : null;
        $validated['question'] = !empty($validated['question']) ? $validated['question'] : null;
        $validated['keywords'] = !empty($validated['keywords']) ? $validated['keywords'] : null;
        unset($validated['is_active']);

        return $validated;
    }
}
