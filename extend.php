<?php

/*
 * Story Chain: the forum writes a story together, one line at a time.
 *
 * Every round, members each offer a next line and vote for one; when the
 * round closes the most-voted line (ties: the earliest offered) joins the
 * story, credited to its writer. The scheduler closes rounds every minute
 * (Closer: race-safe and idempotent), and the pages close any round they
 * show that ran out, for forums whose scheduler isn't running. Finished
 * stories become chapters in the library.
 *
 * Stories and the lines in them are JSON:API resources (story-chain-stories,
 * story-chain-lines). The story page, offering a line, voting and taking a
 * line back stay plain endpoints on purpose: while a round is open, who
 * offered each line and how many votes it has are left out of the answer
 * for anyone who may not see them, and its lines come in an order of their
 * own for each viewer. A JSON:API include could walk around that.
 *
 * forum.js only wires up the routes, the nav link, the index card and the
 * notifications. The pages and forms are lazy chunks.
 */

use ErnestDefoe\StoryChain\Api;
use ErnestDefoe\StoryChain\Console\CloseCommand;
use ErnestDefoe\StoryChain\Forget;
use ErnestDefoe\StoryChain\Gdpr\StoryChainData;
use ErnestDefoe\StoryChain\Http\PageContent;
use ErnestDefoe\StoryChain\Notification\LineWonBlueprint;
use ErnestDefoe\StoryChain\Notification\RoundBlueprint;
use ErnestDefoe\StoryChain\Refusal;
use Flarum\Api\Resource\ForumResource;
use Flarum\Extend;
use Flarum\Gdpr\Extend\UserData;
use Flarum\User\Event\Deleting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // 🚨 Not optional: the pages and forms are chunks in here, and undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/stories', 'story-chain', PageContent::class)
        ->route('/stories/{id:\d+(?:-[^/]*)?}', 'story-chain.story', PageContent::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())
        ->namespace('ernestdefoe-story-chain', __DIR__.'/views'),

    new Extend\ApiResource(Api\Resource\StoryResource::class),
    new Extend\ApiResource(Api\Resource\LineResource::class),

    // The story page and the open round stay plain endpoints on purpose: they leave out what a
    // viewer may not see yet (votes, who offered which line) and shuffle per viewer (see above).
    (new Extend\Routes('api'))
        ->get('/stories/{id:\d+}', 'story-chain.show', Api\ShowController::class)
        ->post('/stories/{id:\d+}/lines', 'story-chain.lines.offer', Api\OfferController::class)
        ->post('/stories/{id:\d+}/vote', 'story-chain.vote', Api\VoteController::class)
        ->delete('/stories/candidates/{id:\d+}', 'story-chain.candidates.withdraw', Api\OfferController::class)
        ->post('/stories/candidates/{id:\d+}', 'story-chain.candidates.moderate', Api\OfferController::class),

    (new Extend\ErrorHandling())
        ->handler(Refusal::class, Api\RefusalHandler::class),

    (new Extend\Console())
        ->command(CloseCommand::class)
        ->schedule(CloseCommand::class, function (ScheduledEvent $event): void {
            $event->everyMinute()->withoutOverlapping();
        }),

    (new Extend\Notification())
        ->type(LineWonBlueprint::class, ['alert', 'email'])
        ->type(RoundBlueprint::class, ['alert']),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(Api\ForumFields::class),

    // String keys, not Settings constants: extend.php must not autoload our classes at boot.
    (new Extend\Settings())
        ->default('ernestdefoe-story-chain.nav', true)
        ->default('ernestdefoe-story-chain.index_card', true)
        ->default('ernestdefoe-story-chain.live_votes', false)
        ->default('ernestdefoe-story-chain.show_authors', false)
        ->default('ernestdefoe-story-chain.no_repeat', true)
        ->serializeToForum('storyChainNav', 'ernestdefoe-story-chain.nav', 'boolval'),

    // Before the user row goes: recount the stories they wrote in (the foreign keys would only null their name).
    (new Extend\Event())
        ->listen(Deleting::class, fn (Deleting $event) => Forget::user($event->user, false)),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new UserData())->addType(StoryChainData::class),
        ]),
];
