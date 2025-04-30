import app from 'flarum/forum/app';
import addSummaryExcerpt from './addSummaryExcerpt';
import addUserPreference from './addUserPreference';

export { default as extend } from './extend';

export * from './components';
export * from './utils';

app.initializers.add('fof-synopsis', () => {
  addSummaryExcerpt();
  addUserPreference();
});
