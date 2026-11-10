<x-mail::html.notification>
    <x-slot:body>
        {!! $formatter->convert($translator->trans('ernestdefoe-story-chain.email.won.html.body', [
            '{title}' => e($blueprint->story->title),
            '{round}' => $blueprint->round,
            '{line}' => e($blueprint->line),
            '{url}' => $url->to('forum')->route('story-chain.story', ['id' => $blueprint->story->id.'-'.$blueprint->story->slug]),
        ])) !!}
    </x-slot:body>
</x-mail::html.notification>
