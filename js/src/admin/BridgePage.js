import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

const t = (key, params) => app.translator.trans('ernestdefoe-millwright-bridge.admin.' + key, params);
const url = () => app.forum.attribute('apiUrl') + '/bridge';

/**
 * Check, build, upgrade: three steps, each one only offered once the one
 * before it has succeeded.
 */
export default class BridgePage extends ExtensionPage {
  oninit(vnode) {
    super.oninit(vnode);

    this.report = null;
    this.busy = null; // 'check' | 'stage' | 'upgrade'
    this.staged = null;
    this.result = null;
    this.error = null;

    this.check();
  }

  content() {
    return (
      <div className="BridgePage container">
        <p className="BridgePage-intro">{t('intro')}</p>
        {this.error ? <div className="BridgePage-error">{this.error}</div> : null}
        {this.result ? this.resultView() : this.steps()}
      </div>
    );
  }

  steps() {
    if (!this.report) return <LoadingIndicator />;

    const r = this.report;
    const built = this.staged || r.staged;

    return [
      <section className="BridgeStep">
        <h3>{t('step_check')}</h3>
        <p>{t('php', { version: r.php })}</p>
        {r.blockers.length ? (
          <ul className="BridgeList BridgeList--blockers">
            {r.blockers.map((b) => (
              <li>{b}</li>
            ))}
          </ul>
        ) : (
          <p className="BridgeOk">{t('can_upgrade')}</p>
        )}
        {r.notes.length ? (
          <ul className="BridgeList BridgeList--notes">
            {r.notes.map((n) => (
              <li>{n}</li>
            ))}
          </ul>
        ) : null}
        {this.extensions(r.extensions)}
        <Button className="Button" loading={this.busy === 'check'} disabled={!!this.busy} onclick={() => this.check()}>
          {t('check_again')}
        </Button>
      </section>,

      <section className={'BridgeStep' + (r.ok ? '' : ' is-locked')}>
        <h3>{t('step_build')}</h3>
        <p>{t('build_help')}</p>
        {built ? <p className="BridgeOk">{t('built', { version: this.staged || '2' })}</p> : null}
        {this.output ? <pre className="BridgeOutput">{this.output}</pre> : null}
        <Button className="Button" loading={this.busy === 'stage'} disabled={!r.ok || !!this.busy} onclick={() => this.stage()}>
          {built ? t('build_again') : t('build')}
        </Button>
      </section>,

      <section className={'BridgeStep' + (r.ok && built ? '' : ' is-locked')}>
        <h3>{t('step_upgrade')}</h3>
        <p>{t('upgrade_help')}</p>
        {this.busy === 'upgrade' ? <p className="BridgeWorking">{t('upgrading')}</p> : null}
        <Button className="Button Button--primary" loading={this.busy === 'upgrade'} disabled={!r.ok || !built || !!this.busy} onclick={() => this.upgrade()}>
          {t('upgrade')}
        </Button>
      </section>,
    ];
  }

  extensions(list) {
    const names = Object.keys(list || {});
    if (!names.length) return null;

    return (
      <table className="BridgeTable">
        <thead>
          <tr>
            <th>{t('col_extension')}</th>
            <th>{t('col_now')}</th>
            <th>{t('col_flarum2')}</th>
          </tr>
        </thead>
        <tbody>
          {names.map((name) => {
            const e = list[name];

            return (
              <tr className={'is-' + e.status}>
                <td>
                  <code>{name}</code>
                </td>
                <td>{e.installed}</td>
                <td>
                  {name === 'ernestdefoe/millwright-bridge'
                    ? t('status_self')
                    : e.status === 'retired' && e.replacement
                      ? t('status_retired_by', { replacement: e.replacement })
                      : t('status_' + e.status, { version: e.version || '', replacement: e.replacement || '' })}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    );
  }

  resultView() {
    const r = this.result;

    if (r.ok) {
      return (
        <section className="BridgeStep BridgeStep--done">
          <h3>{t('done_title', { version: r.version })}</h3>
          <p>{t('done_help')}</p>
          <Button
            className="Button Button--primary"
            onclick={() => {
              // Bridge's own page is gone in Flarum 2; Millwright's is the next stop.
              window.location.hash = '#/extension/ernestdefoe-millwright';
              window.location.reload();
            }}
          >
            {t('open_admin')}
          </Button>
        </section>
      );
    }

    return (
      <section className="BridgeStep BridgeStep--failed">
        <h3>{t('failed_title')}</h3>
        <p>{r.error}</p>
        {r.undone && r.undone.length ? (
          <ul className="BridgeList">
            {r.undone.map((u) => (
              <li>{u}</li>
            ))}
          </ul>
        ) : null}
        {(r.log || []).length ? <pre className="BridgeOutput">{r.log.join('\n')}</pre> : null}
        <Button className="Button" onclick={() => window.location.reload()}>
          {t('reload')}
        </Button>
      </section>
    );
  }

  check() {
    this.busy = 'check';
    app
      .request({ method: 'GET', url: url() })
      .then((r) => {
        this.report = r;
      })
      .catch(() => {
        this.error = t('check_failed');
      })
      .finally(() => {
        this.busy = null;
        m.redraw();
      });
  }

  stage() {
    this.busy = 'stage';
    this.error = null;
    this.output = null;
    app
      .request({ method: 'POST', url: url(), body: { action: 'stage' }, timeout: 0 })
      .then((r) => {
        if (r.ok) this.staged = r.core;
        else {
          this.error = r.error;
          this.output = r.output || null;
        }
      })
      .catch(() => {
        this.error = t('stage_failed');
      })
      .finally(() => {
        this.busy = null;
        m.redraw();
      });
  }

  upgrade() {
    if (!confirm(t('confirm'))) return;

    this.busy = 'upgrade';
    this.error = null;
    m.redraw();

    app
      .request({ method: 'POST', url: url(), body: { action: 'upgrade' }, timeout: 0, errorHandler: () => {} })
      .then((r) => {
        this.result = r;
      })
      .catch(() => {
        // The request outlived the page's patience or the swap ended the
        // response oddly. The forum knows what happened; reloading shows it.
        this.result = { ok: false, error: t('lost_contact'), undone: [], log: [] };
      })
      .finally(() => {
        this.busy = null;
        m.redraw();
      });
  }
}
