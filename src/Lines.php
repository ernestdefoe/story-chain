<?php

namespace ErnestDefoe\StoryChain;

use Carbon\Carbon;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Changing a line that is already in a story (the story-chain-lines
 * resource): fixing its wording (Line::rewrite says who may and when), or a
 * moderator removing it from the story and putting it back. The row stays,
 * so positions never shift; the story's line and contributor counts follow.
 */
class Lines
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly CardCache $card,
        private readonly StoryLock $lock,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function change(Line $line, array $input, User $actor): Line
    {
        $actor->assertRegistered();
        $actor->assertCan(Settings::VIEW);
        if (! array_key_exists('body', $input)) {
            $actor->assertCan(Settings::MODERATE);
        }

        $line = $this->lock->hold($line->story_id, fn () => $this->db->transaction(function () use ($line, $input, $actor) {
            $story = Story::locked($line->story_id) ?? throw new Refusal('story_not_found', 404);
            // Re-read inside the story's lock: the round may have just closed.
            $line = $line->fresh() ?? throw new Refusal('line_not_found', 404);

            if (array_key_exists('body', $input)) {
                if ($error = $line->rewrite($story, $actor, $input['body'])) {
                    throw new Refusal($error, ['line_locked' => 403, 'line_erased' => 409][$error] ?? 422);
                }
            }

            if (array_key_exists('hidden', $input)) {
                $actor->assertCan(Settings::MODERATE);
                $hidden = (bool) $input['hidden'];
                if (! $hidden && $line->body === '') {
                    // Erased at its writer's request (GDPR): never back.
                    throw new Refusal('line_erased', 409);
                }

                $line->hidden_at = $hidden ? Carbon::now() : null;
                $line->hidden_by = $hidden ? $actor->id : null;
                $line->save();

                $story->line_count = Line::query()->where('story_id', $story->id)->shown()->count();
                $story->contributor_count = Closer::contributors($story->id);
                $story->save();
            }

            return $line;
        }));
        $this->card->forget();

        return $line;
    }
}
