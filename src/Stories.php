<?php

namespace ErnestDefoe\StoryChain;

use Carbon\Carbon;
use ErnestDefoe\StoryChain\Api\RateLimit;
use ErnestDefoe\StoryChain\Job\SendRoundNews;
use Flarum\User\User;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Starting, editing, finishing and deleting stories (the story-chain-stories
 * resource). Counters, rounds and lines are the Closer's alone; "finish"
 * ends a story without a line, and the opening line is fixed through
 * Line::rewrite, like any other. Every change to a story already being
 * written holds it (StoryLock) and runs with its row locked.
 */
class Stories
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Closer $closer,
        private readonly RateLimit $limits,
        private readonly CardCache $card,
        private readonly Queue $queue,
        private readonly StoryLock $lock,
    ) {
    }

    /**
     * A story from its opening line (storyChain.start).
     *
     * @param array<string, mixed> $input
     */
    public function start(Story $story, array $input, User $actor): Story
    {
        $actor->assertRegistered();
        $actor->assertCan(Settings::VIEW);
        $actor->assertCan(Settings::START);
        if (! $actor->can(Settings::MODERATE) && ($this->limits->exceeded($actor, 'start', 2) || $this->limits->dailyExceeded($actor, 'start', 3))) {
            throw new Refusal('too_many_stories', 429);
        }

        $story->user_id = $actor->id;
        $story->status = Story::ACTIVE;
        $story->round = 1;
        $story->round_hours = 24;
        $story->max_length = 200;
        $story->cover = Settings::COVERS[0];
        $this->fill($story, $input);

        $opening = Text::clean($input['opening'] ?? null);
        if (Text::length($opening) < 2) {
            throw new Refusal('opening_empty');
        }
        if (Text::length($opening) > Settings::MAX_LENGTH) {
            throw new Refusal('line_too_long');
        }

        $now = Carbon::now();
        $story->round_ends_at = $now->copy()->addHours($story->round_hours);
        $story->line_count = 1;
        $story->contributor_count = 1;
        $story->last_line_at = $now;

        $this->db->transaction(function () use ($story, $opening, $actor, $now) {
            $story->save();

            $line = new Line();
            $line->story_id = $story->id;
            $line->position = 1;
            $line->round = 0;
            $line->user_id = $actor->id;
            $line->body = $opening;
            $line->created_at = $now;
            $line->save();
        });
        $this->card->forget();

        return $story;
    }

    /**
     * A moderator edits a story (the opening line too), makes the open round
     * the last, or finishes it ("status": "finished").
     *
     * @param array<string, mixed> $input
     */
    public function edit(Story $story, array $input, User $actor): Story
    {
        $actor->assertCan(Settings::MODERATE);

        $finished = null;
        // A full closure, not fn (): $finished must reach this scope by reference.
        $story = $this->lock->hold($story->id, function () use ($story, $input, $actor, &$finished) {
            return $this->db->transaction(function () use ($story, $input, $actor, &$finished) {
                $story = Story::locked($story->id) ?? throw new Refusal('story_not_found', 404);
                $this->fill($story, $input);

                if (array_key_exists('opening', $input)) {
                    /** @var Line|null $opening */
                    $opening = Line::query()->where('story_id', $story->id)->where('position', 1)->first();
                    if ($opening && $opening->body !== Text::clean($input['opening']) && ($error = $opening->rewrite($story, $actor, $input['opening']))) {
                        throw new Refusal($error, $error === 'line_erased' ? 409 : 422);
                    }
                }

                if (array_key_exists('finalRound', $input) && $story->isActive()) {
                    $story->final_round = (bool) $input['finalRound'];
                }

                if (($input['status'] ?? null) === Story::FINISHED && $story->isActive()) {
                    $now = Carbon::now();
                    $story->status = Story::FINISHED;
                    $story->finished_at = $now;
                    $story->round_ends_at = $now;
                    $finished = $story->round;
                }

                $story->save();

                return $story;
            });
        });
        $this->card->forget();

        // Everyone taking part hears it finished, after the commit.
        if ($finished !== null) {
            $this->queue->push(new SendRoundNews($story->id, $finished, null, true));
        }

        return $story;
    }

    /** A moderator closes the open round now and adds its winning line. */
    public function closeNow(Story $story, User $actor): Story
    {
        $actor->assertCan(Settings::MODERATE);
        if (! $this->closer->close($story->id, true)) {
            throw new Refusal('round_closed', 409);
        }

        return $story->fresh() ?? $story;
    }

    /** A moderator deletes a story and everything in it. */
    public function delete(Story $story, User $actor): void
    {
        $actor->assertCan(Settings::MODERATE);

        // The foreign keys do this too, where the database enforces them (SQLite may not).
        $this->lock->hold($story->id, fn () => $this->db->transaction(function () use ($story) {
            Vote::query()->where('story_id', $story->id)->delete();
            Candidate::query()->where('story_id', $story->id)->delete();
            Line::query()->where('story_id', $story->id)->delete();
            $story->delete();
        }));
        $this->card->forget();
    }

    /**
     * The editable fields, checked. A new round length applies from the next
     * round; the open round keeps the close time its writers were promised.
     *
     * @param array<string, mixed> $input
     */
    private function fill(Story $story, array $input): void
    {
        $creating = ! $story->exists;

        if ($creating || array_key_exists('title', $input)) {
            $title = Text::clean($input['title'] ?? null);
            if (Text::length($title) < 2 || Text::length($title) > 120) {
                throw new Refusal('title_invalid');
            }
            $story->title = $title;
            $story->slug = Str::slug($title) ?: 'story';
        }

        if (array_key_exists('blurb', $input)) {
            $blurb = Text::clean($input['blurb']);
            $story->blurb = $blurb === '' ? null : mb_substr($blurb, 0, 300);
        }

        if (array_key_exists('cover', $input)) {
            if (! in_array($input['cover'], Settings::COVERS, true)) {
                throw new Refusal('cover_invalid');
            }
            $story->cover = $input['cover'];
        }

        if (array_key_exists('roundHours', $input)) {
            if (! in_array((int) $input['roundHours'], Settings::ROUND_HOURS, true)) {
                throw new Refusal('hours_invalid');
            }
            $story->round_hours = (int) $input['roundHours'];
        }

        if (array_key_exists('maxLength', $input)) {
            $length = (int) $input['maxLength'];
            if ($length < Settings::MIN_LENGTH || $length > Settings::MAX_LENGTH) {
                throw new Refusal('length_invalid');
            }
            $story->max_length = $length;
        }

        if (array_key_exists('maxLines', $input)) {
            $lines = $input['maxLines'] === null || $input['maxLines'] === '' ? null : (int) $input['maxLines'];
            // An end the story has already passed would finish it at the next close, which is what "make this the last round" is for.
            if ($lines !== null && ($lines < 3 || $lines > Settings::MAX_LINES || (! $creating && $lines <= $story->line_count))) {
                throw new Refusal('lines_invalid');
            }
            $story->max_lines = $lines;
        }
    }
}
