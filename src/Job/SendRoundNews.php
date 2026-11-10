<?php

namespace ErnestDefoe\StoryChain\Job;

use ErnestDefoe\StoryChain\Candidate;
use ErnestDefoe\StoryChain\Line;
use ErnestDefoe\StoryChain\Notification\LineWonBlueprint;
use ErnestDefoe\StoryChain\Notification\RoundBlueprint;
use ErnestDefoe\StoryChain\Settings;
use ErnestDefoe\StoryChain\Story;
use ErnestDefoe\StoryChain\Vote;
use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\Notification\NotificationSyncer;
use Flarum\Queue\AbstractJob;
use Flarum\User\User;

/**
 * After a round closes: "your line won" to its writer, and "round N is open"
 * (or "the story is finished") to everyone who has taken part in the story:
 * offered a line, voted, or has a line in it. Runs on the queue, never in the
 * request that closed the round. Syncing is idempotent (one blueprint per
 * round), so a retried job sends nothing twice.
 */
class SendRoundNews extends AbstractJob
{
    public function __construct(public int $storyId, public int $round, public ?int $lineId, public bool $finished)
    {
    }

    public function handle(NotificationSyncer $notifications): void
    {
        /** @var Story|null $story */
        $story = Story::query()->find($this->storyId);
        if (! $story) {
            return;
        }

        /** @var Line|null $line */
        $line = $this->lineId ? Line::query()->with('user')->find($this->lineId) : null;
        $winner = $line?->user ?? $story->user;
        if (! $winner) {
            return;
        }

        if ($line?->user && $line->hidden_at === null) {
            $notifications->sync(new LineWonBlueprint($story, $line->user, $this->round, $line->body, $this->finished), [$line->user]);
        }

        $people = Candidate::query()->where('story_id', $story->id)->distinct()->pluck('user_id')
            ->merge(Vote::query()->where('story_id', $story->id)->distinct()->pluck('user_id'))
            ->merge(Line::query()->where('story_id', $story->id)->whereNotNull('user_id')->distinct()->pluck('user_id'))
            ->unique()->values()->all();

        $readers = $this->readerGroups();
        $recipients = User::query()->whereIn('id', $people)->where('id', '!=', $winner->id)->with('groups')->get()
            ->filter(fn (User $user) => $readers === true || $user->groups->pluck('id')->intersect($readers)->isNotEmpty())
            ->values()->all();

        if ($recipients) {
            $notifications->sync(new RoundBlueprint($story, $winner, $this->finished ? $this->round : $this->round + 1, $this->finished), $recipients);
        }
    }

    /**
     * The groups that may read stories, without a permission query per
     * person: true when every member may.
     *
     * @return true|list<int>
     */
    private function readerGroups(): bool|array
    {
        $groups = Permission::query()->where('permission', Settings::VIEW)->pluck('group_id')->map(fn ($id) => (int) $id)->all();
        if (in_array(Group::GUEST_ID, $groups, true) || in_array(Group::MEMBER_ID, $groups, true)) {
            return true;
        }
        $groups[] = Group::ADMINISTRATOR_ID;

        return $groups;
    }
}
