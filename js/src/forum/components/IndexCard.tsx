import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import type Mithril from 'mithril';
import { span, storyUrl, t, type CardData } from '../api';

const HIDDEN = 'storyChainCardHidden';

/** The round the card was hidden for. Kept here as well, for browsers that block storage (it then lasts until the page reloads). */
let hiddenHere: string | null = null;

function hiddenFor(): string | null {
  try {
    return localStorage.getItem(HIDDEN) || hiddenHere;
  } catch {
    return hiddenHere;
  }
}

function hide(key: string): void {
  try {
    localStorage.setItem(HIDDEN, key);
  } catch {
    // Storage is blocked (private mode, site data off): hiddenHere still hides it for this visit.
    hiddenHere = key;
  }
}

/**
 * Above the discussion list: the newest line of the story being written and
 * how long until the round closes. Hidden for the round on request; it
 * comes back when the next round opens.
 */
export default class IndexCard extends Component<{ card: CardData }> {
  private timer: number | undefined;

  oncreate(vnode: Mithril.VnodeDOM<{ card: CardData }>) {
    super.oncreate(vnode);
    this.timer = window.setInterval(() => !document.hidden && m.redraw(), 30000);
  }

  onremove(vnode: Mithril.VnodeDOM<{ card: CardData }>) {
    super.onremove(vnode);
    clearInterval(this.timer);
  }

  view() {
    const card = this.attrs.card;
    const key = `${card.id}:${card.round}`;
    if (hiddenFor() === key) return null;

    return (
      <div className={`StoryChainCard StoryChain-cover--${card.cover}`}>
        <div className="StoryChainCard-spine" aria-hidden="true">
          <Icon name="fas fa-feather-pointed" />
        </div>
        {this.body(card)}
        {this.actions(card, key)}
      </div>
    );
  }

  body(card: CardData): Mithril.Children {
    const left = Date.parse(card.endsAt) - Date.now();

    return (
      <div className="StoryChainCard-body">
        <div className="StoryChainCard-meta">
          <span>{t('card.label')}</span>
          <span aria-hidden="true">·</span>
          <span>{t('story.round', { round: card.round })}</span>
          <span aria-hidden="true">·</span>
          <span className="StoryChainCard-clock">{left > 0 ? t('story.closes_in', { time: span(left) }) : t('story.closing')}</span>
        </div>
        <Link className="StoryChainCard-title" href={storyUrl(card)}>
          {card.title}
        </Link>
        {card.last && (
          <p className="StoryChainCard-line">
            <span className="StoryChainCard-quote">…{card.last}</span>
            {card.by && <span className="StoryChainCard-by"> {card.by}</span>}
          </p>
        )}
        <div className="StoryChainCard-foot">
          <span>{t('card.offered', { count: card.offered })}</span>
        </div>
      </div>
    );
  }

  actions(card: CardData, key: string): Mithril.Children {
    const label = app.translator.trans('ernestdefoe-story-chain.forum.card.hide', {}, true);

    return (
      <div className="StoryChainCard-actions">
        <Link className="Button Button--primary StoryChainCard-cta" href={storyUrl(card)}>
          {t('card.cta')}
        </Link>
        <button
          type="button"
          className="Button Button--icon Button--link StoryChainCard-hide"
          aria-label={label}
          title={label}
          onclick={() => hide(key)}
        >
          <Icon name="fas fa-xmark" />
        </button>
      </div>
    );
  }
}
