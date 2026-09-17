<?php

namespace App\Services\Chatbot;

use App\Models\ChatbotKnowledge;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ChatbotKnowledgeRetriever
{
    /**
     * @return Collection<int, ChatbotKnowledge>
     */
    public function findRelevant(string $query, ?int $limit = null): Collection
    {
        $limit = $limit ?? (int) config('chatbot.max_knowledge_entries', 8);
        $terms = $this->extractTerms($query);

        $entries = ChatbotKnowledge::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        if ($entries->isEmpty()) {
            return collect();
        }

        if ($terms === []) {
            return $entries->take($limit)->values();
        }

        $scored = $entries
            ->map(function (ChatbotKnowledge $entry) use ($terms) {
                $haystack = Str::lower(implode(' ', array_filter([
                    $entry->title,
                    $entry->category,
                    $entry->question,
                    $entry->content,
                    $entry->keywords,
                ])));

                $score = 0;
                foreach ($terms as $term) {
                    if (Str::contains($haystack, $term)) {
                        $score += Str::contains(Str::lower((string) $entry->title), $term) ? 3 : 1;
                        if (Str::contains(Str::lower((string) $entry->keywords), $term)) {
                            $score += 2;
                        }
                        if (Str::contains(Str::lower((string) $entry->question), $term)) {
                            $score += 2;
                        }
                    }
                }

                return ['entry' => $entry, 'score' => $score];
            })
            ->filter(fn (array $row) => $row['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('entry')
            ->values();

        if ($scored->isNotEmpty()) {
            return $scored;
        }

        return $entries->take(min(3, $limit))->values();
    }

    /**
     * @return list<string>
     */
    private function extractTerms(string $query): array
    {
        $normalized = Str::lower(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $query) ?? '');
        $parts = preg_split('/\s+/', trim($normalized)) ?: [];

        $stopWords = [
            'a', 'an', 'the', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
            'to', 'of', 'in', 'on', 'for', 'with', 'at', 'by', 'from', 'as',
            'and', 'or', 'but', 'if', 'then', 'so', 'than', 'too', 'very',
            'can', 'could', 'should', 'would', 'will', 'do', 'does', 'did',
            'what', 'which', 'who', 'whom', 'how', 'when', 'where', 'why',
            'i', 'me', 'my', 'we', 'our', 'you', 'your', 'it', 'its', 'this', 'that',
            'please', 'help', 'tell', 'about',
        ];

        $terms = [];
        foreach ($parts as $part) {
            if (strlen($part) < 2 || in_array($part, $stopWords, true)) {
                continue;
            }
            $terms[] = $part;
        }

        return array_values(array_unique($terms));
    }
}
