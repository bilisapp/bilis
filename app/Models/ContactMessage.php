<?php

namespace App\Models;

use App\Enums\ContactTopic;
use Database\Factories\ContactMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message from the public contact form.
 *
 * The row is the record of the enquiry; the email is only a notification. It
 * is written first and unconditionally, so a mailer that is misconfigured or
 * down cannot lose someone's question.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property ContactTopic $topic
 * @property string $message
 * @property int|null $user_id
 * @property int|null $team_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Team|null $team
 */
#[Fillable(['name', 'email', 'topic', 'message', 'user_id', 'team_id', 'ip', 'user_agent'])]
class ContactMessage extends Model
{
    /** @use HasFactory<ContactMessageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'topic' => ContactTopic::class,
        ];
    }

    /**
     * The signed-in account that wrote, when there was one.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The team the writer was looking at, when there was one.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
