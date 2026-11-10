<?php

namespace ErnestDefoe\StoryChain\Gdpr;

use ErnestDefoe\StoryChain\Candidate;
use ErnestDefoe\StoryChain\Closer;
use ErnestDefoe\StoryChain\Line;
use ErnestDefoe\StoryChain\Story;
use ErnestDefoe\StoryChain\Vote;
use Flarum\Gdpr\Data\Type;

/**
 * A member's export: the lines of theirs in stories, the lines they offered
 * and their votes. Anonymizing keeps their lines in the stories without a
 * name; erasing also takes their lines out of the stories. Offered lines and
 * votes go either way.
 */
class StoryChainData extends Type
{
    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-story-chain.lib.gdpr.export');
    }

    public static function anonymizeDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-story-chain.lib.gdpr.anonymize');
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-story-chain.lib.gdpr.delete');
    }

    public function export(): ?array
    {
        $rows = [
            'lines' => Line::query()->where('user_id', $this->user->id)->get(['story_id', 'position', 'round', 'body', 'votes', 'created_at'])->toArray(),
            'offered' => Candidate::query()->where('user_id', $this->user->id)->get(['story_id', 'round', 'body', 'created_at'])->toArray(),
            'votes' => Vote::query()->where('user_id', $this->user->id)->get(['story_id', 'round', 'candidate_id', 'created_at'])->toArray(),
        ];

        return [['story-chain/story-chain.json' => $this->encodeForExport($rows)]];
    }

    public function anonymize(): void
    {
        $this->forget(false);
    }

    public function delete(): void
    {
        $this->forget(true);
    }

    private function forget(bool $erase): void
    {
        $stories = Line::query()->where('user_id', $this->user->id)->distinct()->pluck('story_id');

        Vote::query()->where('user_id', $this->user->id)->delete();
        Candidate::query()->where('user_id', $this->user->id)->delete();
        if ($erase) {
            Line::query()->where('user_id', $this->user->id)->whereNull('hidden_at')->update(['hidden_at' => now()]);
        }
        Line::query()->where('user_id', $this->user->id)->update(['user_id' => null]);
        Story::query()->where('user_id', $this->user->id)->update(['user_id' => null]);

        foreach ($stories as $id) {
            Story::query()->where('id', $id)->update([
                'line_count' => Line::query()->where('story_id', $id)->shown()->count(),
                'contributor_count' => Closer::contributors((int) $id),
            ]);
        }
    }
}
