<?php

namespace App\Models;

use App\Enums\TemplateStatus;
use Database\Factories\MessageTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A local, application-side reusable text. Not a Meta-approved WhatsApp template.
 *
 * @property int $id
 * @property string $name
 * @property string|null $category
 * @property string $body
 * @property TemplateStatus $status
 */
#[Fillable(['name', 'category', 'body', 'status'])]
class MessageTemplate extends Model
{
    /** @use HasFactory<MessageTemplateFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TemplateStatus::class,
        ];
    }
}
