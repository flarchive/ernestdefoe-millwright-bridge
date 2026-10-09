import app from 'flarum/admin/app';
import BridgePage from './BridgePage';

app.initializers.add('ernestdefoe-millwright-bridge', () => {
  app.extensionData.for('ernestdefoe-millwright-bridge').registerPage(BridgePage);
});
