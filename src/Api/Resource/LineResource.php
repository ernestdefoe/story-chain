<?php

namespace ErnestDefoe\StoryChain\Api\Resource;

use ErnestDefoe\StoryChain\Line;
use ErnestDefoe\StoryChain\Lines;
use ErnestDefoe\StoryChain\Settings;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * The `story-chain-lines` JSON:API type: lines that are already part of a
 * story (never the ones still in the running, which are candidates).
 *
 * PATCH /api/story-chain-lines/{id} {body}    fix its wording (its writer while it's the newest
 *                                             line, a typo's worth once it has won; moderators)
 * PATCH /api/story-chain-lines/{id} {hidden}  a moderator removes it from the story, or puts it back
 *
 * Readers reach lines as a story's newestLine; removed ones only moderators see.
 *
 * @extends AbstractDatabaseResource<Line>
 */
class LineResource extends AbstractDatabaseResource
{
    public function __construct(private readonly Lines $lines)
    {
    }

    public function type(): string
    {
        return 'story-chain-lines';
    }

    public function model(): string
    {
        return Line::class;
    }

    /** @param Builder<Line> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        $actor = $context->getActor();
        if (! $actor->can(Settings::VIEW)) {
            $query->whereRaw('1 = 0');
        } elseif (! $actor->can(Settings::MODERATE)) {
            $query->whereNull('story_chain_lines.hidden_at');
        }
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Update::make()->authenticated(),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('position'),
            Schema\Integer::make('round'),
            Schema\Str::make('body')->writable()->set(fn () => null),
            Schema\Integer::make('votes'),
            Schema\Integer::make('rivals'),
            Schema\DateTime::make('createdAt')->property('created_at'),
            Schema\Boolean::make('hidden')->writable()->set(fn () => null)->get(fn (Line $line) => $line->hidden_at !== null),

            Schema\Relationship\ToOne::make('user')->includable(),
        ];
    }

    /** @param Context $context */
    public function update(object $model, BaseContext $context): object
    {
        $fresh = $this->lines->change($model, (array) ($context->body()['data']['attributes'] ?? []), $context->getActor());

        // The change was made on a copy read inside the lock: answer with what was saved.
        return $model->setRawAttributes($fresh->getAttributes(), true);
    }
}
