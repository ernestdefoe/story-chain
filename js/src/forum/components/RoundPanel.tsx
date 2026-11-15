import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { call, now, span, t, type Candidate, type Detail, type Round } from '../api';
import { Person, user } from './parts';

export interface RoundPanelAttrs extends ComponentAttrs {
  data: Detail;
  /** Fetch the story again; quiet: no error page if it fails. */
  reload: (quiet?: boolean) => void;
}

/**
 * The open round under a story: the countdown, the box for your line (or why
 * you can't write one) and the lines in the running with their votes. What a
 * viewer may not see yet (votes, writers) never reaches it: the story page
 * endpoint leaves it out.
 */
export default class RoundPanel extends Component<RoundPanelAttrs> {
  draft = '';
  sending = false;
  voting: number | null = null;

  view(): Mithril.Children {
    const round = this.attrs.data.round!;

    return (
      <section className="StoryChainRound" aria-labelledby="sc-round">
        <div className="StoryChainRound-head">
          {this.title(round)}
          {this.clock()}
        </div>
        {this.write(round)}
        {this.candidates(round)}
      </section>
    );
  }

  title(round: Round): Mithril.Children {
    const { story, lines } = this.attrs.data;
    const last = [...lines].reverse().find((line) => !line.hidden && line.round > 0 && line.round === round.number - 1);

    return (
      <div>
        <h2 id="sc-round" className="StoryChainRound-title">
          {t('story.round', { round: round.number })}
          {story.lastRound && <span className="StoryChainBadge">{t('story.final')}</span>}
        </h2>
        {last && (
          <p className="StoryChainRound-last">
            {t('last.text', {
              name: user(last.user)?.displayName() || extractText(t('story.deleted_user')),
              votes: last.votes,
              rivals: last.rivals,
            })}
          </p>
        )}
      </div>
    );
  }

  clock(): Mithril.Children {
    const story = this.attrs.data.story;
    const left = Date.parse(story.endsAt) - now();
    const progress = Math.min(100, Math.max(0, 100 - (left / (story.roundHours * 3600000)) * 100));

    return (
      <div className="StoryChainClock" role="timer">
        <span className="StoryChainClock-label">{left > 0 ? t('story.closes_in', { time: '' }) : t('story.closing')}</span>
        {left > 0 && <span className="StoryChainClock-time">{span(left)}</span>}
        <span className="StoryChainClock-bar">
          <span style={{ width: `${progress}%` }} />
        </span>
      </div>
    );
  }

  /** Your line in the running, why you can't offer one, or the box to write it. */
  write(round: Round): Mithril.Children {
    const mine = round.candidates.find((c) => c.mine);
    if (mine) return this.mine(round, mine);

    if (round.write === 'guest') {
      return (
        <div className="StoryChainNote StoryChainNote--box">
          <Button className="Button Button--primary" onclick={() => app.modal.show(() => import('flarum/forum/components/LogInModal'))}>
            {app.translator.trans('core.forum.header.log_in_link')}
          </Button>
          <span>{t('write.guest')}</span>
        </div>
      );
    }
    if (round.write === 'repeat' || round.write === 'permission') {
      return <p className="StoryChainNote StoryChainNote--box">{t(`write.${round.write}`)}</p>;
    }

    return round.write === null ? this.form() : null;
  }

  mine(round: Round, mine: Candidate): Mithril.Children {
    return (
      <div className={classList('StoryChainMine', { 'StoryChainMine--removed': mine.hidden })}>
        <div className="StoryChainMine-label">{t('write.yours')}</div>
        <p className="StoryChainMine-body">{mine.body}</p>
        {mine.hidden ? (
          <p className="StoryChainNote">{t('write.removed')}</p>
        ) : (
          round.open && (
            <Button className="Button Button--link" icon="fas fa-rotate-left" onclick={() => this.withdraw(mine)}>
              {t('write.withdraw')}
            </Button>
          )
        )}
      </div>
    );
  }

  form(): Mithril.Children {
    const { story, lines } = this.attrs.data;
    const last = [...lines].reverse().find((line) => !line.hidden);
    const length = this.draft.trim().length;

    return (
      <form className="StoryChainWrite" onsubmit={(e: SubmitEvent) => this.offer(e)}>
        <label className="StoryChainWrite-label" for="sc-line">
          {t('write.heading')}
        </label>
        {last && (
          <p className="StoryChainWrite-context">
            <span>{t('story.so_far')}</span> <q>{last.body}</q>
          </p>
        )}
        <textarea
          id="sc-line"
          className="FormControl StoryChain-serif"
          rows={3}
          maxlength={story.maxLength}
          placeholder={extractText(t('write.placeholder'))}
          value={this.draft}
          oninput={(e: InputEvent) => (this.draft = (e.target as HTMLTextAreaElement).value.replace(/\s*\n\s*/g, ' '))}
        />
        <div className="StoryChainWrite-foot">
          <span className={classList('StoryChainCounter', { 'StoryChainCounter--near': length > story.maxLength * 0.9 })}>
            {length} / {story.maxLength}
          </span>
          <Button type="submit" className="Button Button--primary" icon="fas fa-feather-pointed" loading={this.sending} disabled={length < 2}>
            {t('write.submit')}
          </Button>
        </div>
      </form>
    );
  }

  candidates(round: Round): Mithril.Children {
    const shown = round.candidates.filter((c) => !c.mine);
    const most = Math.max(1, ...round.candidates.map((c) => c.votes || 0));

    return (
      <div className="StoryChainRunning">
        <div className="StoryChainRunning-head">
          <h3>{t('vote.heading', { count: round.offered })}</h3>
          <span className="StoryChainNote">
            {round.live ? t('vote.live_note') : t('vote.hidden_note')}
            {round.voters > 0 && !round.live && <> · {t('vote.voters', { count: round.voters })}</>}
          </span>
        </div>
        {!round.candidates.length && <p className="StoryChainEmpty">{t('vote.empty')}</p>}
        <ol className="StoryChainRunning-list">{round.candidates.map((c) => this.candidate(round, c, most))}</ol>
        {shown.length > 1 && <p className="StoryChainNote StoryChainRunning-ties">{t('vote.ties')}</p>}
      </div>
    );
  }

  candidate(round: Round, c: Candidate, most: number): Mithril.Children {
    const story = this.attrs.data.story;
    const voted = round.myVote === c.id;

    return (
      <li
        className={classList('StoryChainCandidate', {
          'StoryChainCandidate--voted': voted,
          'StoryChainCandidate--mine': c.mine,
          'StoryChainCandidate--removed': c.hidden,
        })}
      >
        <p className="StoryChainCandidate-body">{c.body}</p>
        {round.live && c.votes !== undefined && (
          <div className="StoryChainCandidate-bar" aria-hidden="true">
            <span style={{ width: `${(100 * (c.votes || 0)) / most}%` }} />
          </div>
        )}
        {(c.user || round.live || c.hidden || c.mine || story.canModerate || (round.canVote && round.open)) && (
          <div className="StoryChainCandidate-foot">
            {c.user ? Person({ id: c.user }) : <span />}
            <span className="StoryChainCandidate-actions">{this.actions(round, c, voted)}</span>
          </div>
        )}
      </li>
    );
  }

  actions(round: Round, c: Candidate, voted: boolean): Mithril.Children {
    const story = this.attrs.data.story;

    return [
      round.live && c.votes !== undefined && <span className="StoryChainCandidate-votes">{t('vote.count', { count: c.votes })}</span>,
      c.hidden && <span className="StoryChainBadge StoryChainBadge--muted">{t('vote.removed')}</span>,
      c.mine && !c.hidden && <span className="StoryChainBadge StoryChainBadge--muted">{t('vote.own')}</span>,
      story.canModerate && round.open && (
        <Button className="Button Button--link" icon={c.hidden ? 'fas fa-rotate-left' : 'fas fa-eye-slash'} onclick={() => this.moderate(c)}>
          {c.hidden ? t('manage.restore_line') : t('manage.remove_line')}
        </Button>
      ),
      round.canVote && round.open && !c.mine && !c.hidden && (
        <Button
          className={classList('Button', 'StoryChainVote', voted && 'Button--primary')}
          icon={voted ? 'fas fa-check' : 'far fa-heart'}
          loading={this.voting === c.id}
          aria-pressed={voted ? 'true' : 'false'}
          onclick={() => this.vote(voted ? null : c.id, c.id)}
        >
          {voted ? t('vote.voted') : t('vote.vote')}
        </Button>
      ),
    ];
  }

  offer(e: SubmitEvent) {
    e.preventDefault();
    const story = this.attrs.data.story;
    this.sending = true;
    call('POST', `/${story.id}/lines`, { body: this.draft })
      .then(() => {
        this.draft = '';
        app.alerts.show({ type: 'success' }, t('write.thanks'));
        this.attrs.reload();
      })
      .catch(() => this.attrs.reload(true))
      .finally(() => {
        this.sending = false;
        m.redraw();
      });
  }

  withdraw(candidate: Candidate) {
    call('DELETE', `/candidates/${candidate.id}`)
      .then(() => {
        this.draft = candidate.body;
        this.attrs.reload();
      })
      .catch(() => this.attrs.reload(true));
  }

  vote(candidate: number | null, pressed: number) {
    const { story, round } = this.attrs.data;
    const before = round!.myVote;
    round!.myVote = candidate;
    this.voting = pressed;
    call('POST', `/${story.id}/vote`, { candidate })
      .then(() => round!.live && this.attrs.reload(true))
      .catch(() => {
        round!.myVote = before;
        this.attrs.reload(true);
      })
      .finally(() => {
        this.voting = null;
        m.redraw();
      });
  }

  moderate(candidate: Candidate) {
    // call() shows what went wrong; either way the round reloads as it is now.
    call('POST', `/candidates/${candidate.id}`, { hidden: !candidate.hidden }).then(
      () => this.attrs.reload(),
      () => this.attrs.reload(true)
    );
  }
}
