import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Dropdown from 'flarum/common/components/Dropdown';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Avatar from 'flarum/common/components/Avatar';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { call, doc, now, setSkew, t, type Detail, type Line } from '../api';
import RoundPanel from './RoundPanel';
import { Book, Person, date, pushUsers, useClock, user } from './parts';
import { IndexSidebar, PageStructure, openLineForm, openStoryForm } from '../pages';

/** A steady hue per writer, for "Color by writer". */
const hue = (id: number | null) => (id ? (id * 137) % 360 : 0);

/**
 * /stories/:id: the story as a book page (hover, focus or tap a line for its
 * writer), then the open round: the countdown, the box for your line and the
 * lines in the running with their votes; or, for a finished story, the end,
 * its numbers and its writers.
 */
export default class StoryPage extends Page {
  data: Detail | null = null;
  error = false;
  byWriter = false;
  /** The line whose card is showing, and whether a click pinned it. */
  focus: { line: Line; top: number; left: number } | null = null;
  pinned = false;
  private stopClock!: () => void;
  private refresh: number | undefined;
  private reloadAt = 0;
  private outside = (e: Event) => {
    const target = e.target as HTMLElement;
    if (this.pinned && !target.closest('.StoryChainText-line, .StoryChainLineCard')) {
      this.pinned = false;
      this.focus = null;
      m.redraw();
    }
  };

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.stopClock = useClock();
    this.load();
    this.refresh = window.setInterval(() => !document.hidden && this.load(true), 60000);
    document.addEventListener('click', this.outside);
  }

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    this.stopClock();
    clearInterval(this.refresh);
    document.removeEventListener('click', this.outside);
  }

  id(): string {
    return String(m.route.param('id') || '').split('-')[0];
  }

  load(quiet = false) {
    call<Detail>('GET', `/${this.id()}`, undefined, true)
      .then((data) => {
        pushUsers(data.users);
        setSkew(data.now);
        if (!this.data) {
          app.history.push('story-chain.story', data.story.title);
        }
        app.setTitle(data.story.title);
        app.setTitleCount(0);
        this.data = data;
        this.reloadAt = 0;
      })
      .catch(() => (this.error = !quiet && !this.data))
      .finally(() => m.redraw());
  }

  view() {
    return (
      <PageStructure className="StoryChainPage StoryChainStoryPage" sidebar={() => <IndexSidebar />}>
        {this.body()}
      </PageStructure>
    );
  }

  body(): Mithril.Children {
    const data = this.data;
    const back = (
      <Link className="StoryChainBack" href={app.route('story-chain')}>
        <Icon name="fas fa-arrow-left" /> {t('page.back')}
      </Link>
    );
    if (!data) return [back, this.error ? <p className="StoryChainEmpty">{t('page.not_found')}</p> : <LoadingIndicator />];

    const story = data.story;
    const round = data.round;

    // The round ran out: fetch the result after a short random wait, so open pages don't all ask at once.
    if (round?.open && Date.parse(story.endsAt) <= now() && !this.reloadAt) {
      this.reloadAt = Date.now();
      window.setTimeout(() => this.load(true), 1500 + Math.random() * 3500);
    }

    return (
      <div className={classList('StoryChainStory', `StoryChain-cover--${story.cover}`)}>
        {back}
        {this.header()}
        {this.text()}
        {story.status === 'finished' ? this.ending() : round && <RoundPanel data={data} reload={(quiet?: boolean) => this.load(quiet)} />}
        {this.credits()}
      </div>
    );
  }

  header(): Mithril.Children {
    const story = this.data!.story;
    const finished = story.status === 'finished';

    return (
      <header className="StoryChainHeader">
        {Book({ story, size: 'small' })}
        <div className="StoryChainHeader-text">
          <div className="StoryChainHeader-kicker">
            {finished ? (
              <span className="StoryChainBadge StoryChainBadge--done">
                <Icon name="fas fa-book" /> {t('story.finished')}
              </span>
            ) : (
              <span>{t('story.round', { round: story.round })}</span>
            )}
            {!finished && story.lastRound && <span className="StoryChainBadge">{t('story.final')}</span>}
          </div>
          <h1 className="StoryChainHeader-title">{story.title}</h1>
          {story.blurb && <p className="StoryChainHeader-blurb">{story.blurb}</p>}
          <p className="StoryChainHeader-meta">
            {finished && story.finishedAt
              ? t('story.finished_on', { date: date(story.finishedAt) })
              : t('story.started', { date: date(story.createdAt) })}
            {' · '}
            {story.maxLines && !finished ? t('story.line_of', { n: story.lines, max: story.maxLines }) : t('story.lines', { count: story.lines })}
            {' · '}
            {t('story.writers', { count: story.contributors })}
          </p>
        </div>
        {story.canModerate && this.manage()}
      </header>
    );
  }

  manage(): Mithril.Children {
    return (
      <Dropdown
        className="StoryChainManage"
        buttonClassName="Button"
        menuClassName="Dropdown-menu--right"
        label={t('manage.button')}
        icon="fas fa-sliders"
        accessibleToggleLabel={extractText(t('manage.button'))}
      >
        {this.manageItems()}
      </Dropdown>
    );
  }

  manageItems(): Mithril.Children[] {
    const story = this.data!.story;
    const active = story.status === 'active';
    // call() shows what went wrong; either way the page reloads to show the story as it is now.
    const refresh = () => this.load(true);
    const patch = (attributes: Record<string, any>) =>
      call('PATCH', `/story-chain-stories/${story.id}`, doc('story-chain-stories', attributes, story.id)).then(refresh, refresh);
    const opening = this.data!.lines.find((line) => line.position === 1);
    const item = (icon: string, label: string, onclick: () => unknown, className?: string) => (
      <Button icon={icon} className={className} onclick={onclick}>
        {t(label)}
      </Button>
    );

    return [
      item('fas fa-pen', 'manage.edit', () => openStoryForm({ story, opening, onsave: () => this.load() })),
      active &&
        item(
          'fas fa-forward-step',
          'manage.close',
          () => confirm(extractText(t('manage.confirm_close'))) && call('POST', `/story-chain-stories/${story.id}/close`).then(refresh, refresh)
        ),
      active && item('fas fa-flag-checkered', story.finalRound ? 'manage.not_final' : 'manage.final', () => patch({ finalRound: !story.finalRound })),
      active &&
        item(
          'fas fa-book',
          'manage.finish',
          () => confirm(extractText(t('manage.confirm_finish', { title: story.title }))) && patch({ status: 'finished' })
        ),
      item(
        'fas fa-trash',
        'manage.delete',
        () =>
          confirm(extractText(t('manage.confirm_delete', { title: story.title }))) &&
          call('DELETE', `/story-chain-stories/${story.id}`).then(() => m.route.set(app.route('story-chain')), refresh),
        'StoryChainManage-danger'
      ),
    ];
  }

  text(): Mithril.Children {
    const data = this.data!;
    const show = (line: Line, target: HTMLElement) => {
      const box = target.closest('.StoryChainText')!.getBoundingClientRect();
      const rect = target.getClientRects()[0] || target.getBoundingClientRect();
      this.focus = { line, top: rect.bottom - box.top + 8, left: Math.max(0, Math.min(rect.left - box.left, box.width - 300)) };
    };
    const hide = (line: Line) => {
      if (!this.pinned && this.focus?.line.id === line.id) this.focus = null;
    };

    return (
      <section className="StoryChainReader">
        <div className="StoryChainReader-tools">
          <label className="StoryChainToggle">
            <input type="checkbox" checked={this.byWriter} onchange={(e: Event) => (this.byWriter = (e.target as HTMLInputElement).checked)} />{' '}
            {t('story.color_by_writer')}
          </label>
        </div>
        <article className={classList('StoryChainText', { 'StoryChainText--writers': this.byWriter })}>
          <p>{data.lines.map((line, i) => [this.lineSpan(line, i, show, hide), ' '])}</p>
          {this.focus && this.lineCard(this.focus.line)}
        </article>
      </section>
    );
  }

  lineSpan(line: Line, i: number, show: (line: Line, target: HTMLElement) => void, hide: (line: Line) => void): Mithril.Children {
    return (
      <span
        className={classList('StoryChainText-line', {
          'StoryChainText-line--first': i === 0,
          'StoryChainText-line--hidden': line.hidden,
          'StoryChainText-line--active': this.focus?.line.id === line.id,
        })}
        style={{ '--sc-hue': String(hue(line.user)) }}
        tabindex="0"
        role="button"
        aria-label={extractText(this.lineLabel(line))}
        onpointerenter={(e: PointerEvent) => e.pointerType === 'mouse' && !this.pinned && show(line, e.currentTarget as HTMLElement)}
        onpointerleave={(e: PointerEvent) => e.pointerType === 'mouse' && hide(line)}
        onfocus={(e: FocusEvent) => !this.pinned && show(line, e.currentTarget as HTMLElement)}
        onblur={() => hide(line)}
        onclick={(e: MouseEvent) => {
          const same = this.pinned && this.focus?.line.id === line.id;
          this.pinned = !same;
          if (same) this.focus = null;
          else show(line, e.currentTarget as HTMLElement);
        }}
      >
        {line.body}
      </span>
    );
  }

  lineLabel(line: Line): Mithril.Children {
    const person = user(line.user);
    const name = person ? person.displayName() : extractText(t('story.deleted_user'));
    return `${name}: ${line.round ? extractText(t('story.line_votes', { round: line.round, votes: line.votes })) : extractText(t('story.opening'))}`;
  }

  /** Mirrors Line::rewrite: moderators any line; its writer while it's the newest line of a story still being written. */
  canFix(line: Line): boolean {
    const story = this.data!.story;
    if (line.body === '') return false;
    if (story.canModerate) return true;
    const me = app.session.user;
    return !!me && line.user === Number(me.id()) && story.status === 'active' && !line.hidden && line.round === story.round - 1;
  }

  lineCard(line: Line): Mithril.Children {
    const story = this.data!.story;
    const focus = this.focus!;

    return (
      <div className="StoryChainLineCard" style={{ top: `${focus.top}px`, left: `${focus.left}px` }} role="status">
        <div className="StoryChainLineCard-who">{Person({ id: line.user })}</div>
        <div className="StoryChainLineCard-what">
          {line.round ? t('story.line_votes', { round: line.round, votes: line.votes }) : t('story.opening')}
          {line.round > 0 && line.rivals > 0 && <span> · {t('story.line_rivals', { count: line.rivals })}</span>}
        </div>
        <div className="StoryChainLineCard-when">{date(line.at)}</div>
        {line.hidden && <div className="StoryChainLineCard-hidden">{t('story.removed_line')}</div>}
        {this.canFix(line) && (
          <Button
            className="Button Button--link StoryChainLineCard-mod"
            icon="fas fa-pen"
            onclick={() => {
              this.focus = null;
              this.pinned = false;
              openLineForm({ line, story, onsave: () => this.load() });
            }}
          >
            {t('manage.fix_line')}
          </Button>
        )}
        {story.canModerate && (
          <Button
            className="Button Button--link StoryChainLineCard-mod"
            icon={line.hidden ? 'fas fa-rotate-left' : 'fas fa-eye-slash'}
            onclick={() =>
              call('PATCH', `/story-chain-lines/${line.id}`, doc('story-chain-lines', { hidden: !line.hidden }, line.id)).then(
                () => {
                  this.focus = null;
                  this.pinned = false;
                  this.load();
                },
                () => this.load(true)
              )
            }
          >
            {t(line.hidden ? 'manage.restore_line' : 'manage.remove_line')}
          </Button>
        )}
      </div>
    );
  }

  ending(): Mithril.Children {
    const story = this.data!.story;
    const days = Math.max(1, Math.round((Date.parse(story.finishedAt || story.createdAt) - Date.parse(story.createdAt)) / 86400000));
    const stats: [number, string][] = [
      [story.lines, 'stats.lines'],
      [story.contributors, 'stats.writers'],
      [story.candidates, 'stats.offered'],
      [story.votes, 'stats.votes'],
      [days, 'stats.days'],
    ];

    return (
      <section className="StoryChainEnd">
        <p className="StoryChainEnd-mark">{t('story.the_end')}</p>
        <h2 className="StoryChainSection-title">{t('stats.heading')}</h2>
        <dl className="StoryChainStats">
          {stats.map(([n, key]) => (
            <div className="StoryChainStats-item">
              <dt>{t(key, { count: n })}</dt>
              <dd>{n.toLocaleString()}</dd>
            </div>
          ))}
        </dl>
      </section>
    );
  }

  credits(): Mithril.Children {
    const credits = this.data!.credits;
    if (!credits.length) return null;

    return (
      <section className="StoryChainCredits">
        <h2 className="StoryChainSection-title">{t('credits.heading')}</h2>
        <ul className="StoryChainCredits-list">
          {credits.map(([id, lines]) => {
            const person = user(id);
            return (
              <li className="StoryChainCredits-item" style={{ '--sc-hue': String(hue(id)) }}>
                {person ? (
                  <Link href={app.route.user(person)} className="StoryChainCredits-person">
                    <Avatar user={person} />
                    <span className="StoryChainCredits-name">{person.displayName()}</span>
                  </Link>
                ) : (
                  <span className="StoryChainCredits-person">{t('story.deleted_user')}</span>
                )}
                <span className="StoryChainCredits-lines">{t('credits.lines', { count: lines })}</span>
              </li>
            );
          })}
        </ul>
      </section>
    );
  }
}
