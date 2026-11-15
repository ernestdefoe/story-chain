<?php

namespace ErnestDefoe\StoryChain\Api;

use ErnestDefoe\StoryChain\CardCache;
use ErnestDefoe\StoryChain\Settings;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Settings\SettingsRepositoryInterface;

/** The forum payload's fields: the viewer's own permissions (already loaded) and the cached card, so no query on a normal page. */
class ForumFields
{
    public function __construct(private readonly SettingsRepositoryInterface $settings, private readonly CardCache $card)
    {
    }

    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('storyChainCanView')
                ->get(fn ($forum, Context $context) => $context->getActor()->can(Settings::VIEW)),
            Schema\Boolean::make('storyChainCanStart')
                ->visible(fn ($forum, Context $context) => $context->getActor()->can(Settings::VIEW))
                ->get(fn ($forum, Context $context) => $context->getActor()->can(Settings::START)),
            Schema\Arr::make('storyChainCard')
                ->visible(fn ($forum, Context $context) => $context->getActor()->can(Settings::VIEW) && $this->settings->get(Settings::CARD))
                ->get(fn () => $this->card->get()),
        ];
    }
}
