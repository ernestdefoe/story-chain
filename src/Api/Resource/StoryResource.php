<?php

namespace ErnestDefoe\StoryChain\Api\Resource;

use Carbon\Carbon;
use ErnestDefoe\StoryChain\Closer;
use ErnestDefoe\StoryChain\Settings;
use ErnestDefoe\StoryChain\Stories;
use ErnestDefoe\StoryChain\Story;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Pagination\Pagination;

/**
 * The `story-chain-stories` JSON:API type.
 *
 * GET    /api/story-chain-stories?filter[status]=active|finished   stories being written (soonest
 *        to close first, with their newest line and how many lines are in the running) or the
 *        library (newest first), a page at a time
 * GET    /api/story-chain-stories/{id}
 * POST   /api/story-chain-stories             start one, with its opening line
 * PATCH  /api/story-chain-stories/{id}        edit it (the opening line too), make the open round
 *                                             the last, or finish it (moderators)
 * DELETE /api/story-chain-stories/{id}
 * POST   /api/story-chain-stories/{id}/close  close the open round now (moderators)
 *
 * A story carries its title, settings and numbers. Its open round (the
 * lines in the running, their votes and writers) is served only by the
 * plain story page endpoint, which leaves out what a viewer may not see.
 *
 * @extends AbstractDatabaseResource<Story>
 */
class StoryResource extends AbstractDatabaseResource
{
    public function __construct(private readonly Stories $stories, private readonly Closer $closer)
    {
    }

    public function type(): string
    {
        return 'story-chain-stories';
    }

    public function model(): string
    {
        return Story::class;
    }

    /** @param Builder<Story> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        if (! $context->getActor()->can(Settings::VIEW)) {
            $query->whereRaw('1 = 0');
        }
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->paginate(24, 50)
                ->defaultInclude(['newestLine', 'newestLine.user'])
                // Core reads an included user's groups for a moderator's view: load them at once, not per user.
                ->eagerLoad(['newestLine.user.groups'])
                ->query(fn (Builder $query, ?Pagination $pagination, Context $context, array $filters) => $this->listing($query, $pagination, $context, $filters)),
            Endpoint\Show::make(),
            Endpoint\Create::make()->authenticated(),
            Endpoint\Update::make()->authenticated(),
            Endpoint\Delete::make()->authenticated(),
            Endpoint\Endpoint::make('story-chain-stories.close')
                ->route('POST', '/{id}/close')
                ->authenticated()
                ->action(fn (Context $context) => $this->stories->closeNow($context->model, $context->getActor())),
        ];
    }

    public function fields(): array
    {
        // The fields only declare what may be sent; Stories applies them together (start/edit).
        $input = fn ($field) => $field->writable()->set(fn () => null);

        return [
            $input(Schema\Str::make('title')),
            Schema\Str::make('slug'),
            $input(Schema\Str::make('blurb')->nullable()),
            $input(Schema\Str::make('cover')),
            $input(Schema\Str::make('status')),
            $input(Schema\Str::make('opening'))->get(fn () => null)->visible(false),
            Schema\Integer::make('round'),
            Schema\DateTime::make('endsAt')->property('round_ends_at'),
            $input(Schema\Integer::make('roundHours')->property('round_hours')),
            $input(Schema\Integer::make('maxLines')->nullable()->property('max_lines')),
            $input(Schema\Integer::make('maxLength')->property('max_length')),
            $input(Schema\Boolean::make('finalRound')->property('final_round')),
            Schema\Boolean::make('lastRound')->get(fn (Story $story) => $story->isActive() && $story->isLastRound()),
            Schema\Integer::make('lines')->property('line_count'),
            Schema\Integer::make('contributors')->property('contributor_count'),
            Schema\Integer::make('candidates')->property('candidate_count'),
            Schema\Integer::make('votes')->property('vote_count'),
            Schema\Integer::make('creator')->nullable()->property('user_id'),
            Schema\DateTime::make('createdAt')->property('created_at'),
            Schema\DateTime::make('lastLineAt')->nullable()->property('last_line_at'),
            Schema\DateTime::make('finishedAt')->nullable()->property('finished_at'),
            // On the list of stories being written: how many lines are in the running now.
            Schema\Integer::make('offered')
                ->visible(fn (Story $story) => array_key_exists('offered_count', $story->getAttributes()))
                ->get(fn (Story $story) => (int) $story->getAttribute('offered_count')),
            Schema\Boolean::make('canModerate')->get(fn (Story $story, Context $context) => $context->getActor()->can(Settings::MODERATE)),

            // Never a removed line: the relation leaves hidden ones out.
            Schema\Relationship\ToOne::make('newestLine')
                ->type('story-chain-lines')
                ->includable(),
        ];
    }

    /**
     * @param Builder<Story> $query
     * @param array<string, mixed> $filters
     */
    private function listing(Builder $query, ?Pagination $pagination, Context $context, array $filters): Context
    {
        if (($filters['status'] ?? null) === Story::FINISHED) {
            $query->where('story_chain_stories.status', Story::FINISHED)
                ->orderByDesc('story_chain_stories.finished_at')->orderByDesc('story_chain_stories.id');
        } else {
            // Forums without a scheduler: close what ran out before showing it (a no-op when the scheduler keeps up).
            $due = Story::query()->where('status', Story::ACTIVE)->where('round_ends_at', '<=', Carbon::now())->limit(5)->pluck('id');
            foreach ($due as $id) {
                $this->closer->closeIfFree((int) $id);
            }

            // The open round's lines only: compared with the story's own round.
            $query->where('story_chain_stories.status', Story::ACTIVE)
                ->withCount(['candidates as offered_count' => fn (Builder $q) => $q
                    ->whereColumn('story_chain_candidates.round', 'story_chain_stories.round')
                    ->whereNull('story_chain_candidates.hidden_at')])
                ->orderBy('story_chain_stories.round_ends_at')->orderBy('story_chain_stories.id');
        }

        // A query closure owns its pagination: core applies it only on its own path.
        if ($pagination && method_exists($pagination, 'apply')) {
            $pagination->apply($query);
        }

        return $context->withQuery($query);
    }

    /** @param Context $context */
    public function create(object $model, BaseContext $context): object
    {
        return $this->stories->start($model, $this->attributes($context), $context->getActor());
    }

    /** @param Context $context */
    public function update(object $model, BaseContext $context): object
    {
        $fresh = $this->stories->edit($model, $this->attributes($context), $context->getActor());

        // The change was made on a copy read inside the lock: answer with what was saved.
        return $model->setRawAttributes($fresh->getAttributes(), true);
    }

    /** @param Context $context */
    public function delete(object $model, BaseContext $context): void
    {
        $this->stories->delete($model, $context->getActor());
    }

    /**
     * The attributes as sent.
     *
     * @return array<string, mixed>
     */
    private function attributes(BaseContext $context): array
    {
        return (array) ($context->body()['data']['attributes'] ?? []);
    }
}
