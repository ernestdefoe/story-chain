import Extend from 'flarum/common/extenders';
import { RoundNotification, WonNotification } from './notifications';

// The pages and the story form are one lazy chunk, fetched when a story route is first opened.
const page = (name: 'StoriesPage' | 'StoryPage') => () => import('./pages').then(async (pages) => (await pages.loadCore(), { default: pages[name] }));

export default [
  new Extend.Routes().add('story-chain', '/stories', page('StoriesPage')).add('story-chain.story', '/stories/:id', page('StoryPage')),

  new Extend.Notification().add('storyChainWon', WonNotification).add('storyChainRound', RoundNotification),
];
