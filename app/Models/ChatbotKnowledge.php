<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ChatbotKnowledge extends Model
{
    protected $table = 'chatbot_knowledge';

    protected $fillable = [
        'title',
        'category',
        'question',
        'content',
        'keywords',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function toPromptSnippet(): string
    {
        $parts = [
            'Title: '.$this->title,
        ];

        if ($this->category) {
            $parts[] = 'Category: '.$this->category;
        }

        if ($this->question) {
            $parts[] = 'Question: '.$this->question;
        }

        $parts[] = 'Answer: '.$this->content;

        return implode("\n", $parts);
    }
}
