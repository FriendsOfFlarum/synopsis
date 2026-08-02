import { extend } from 'flarum/common/extend';
import app from 'flarum/forum/app';
import DiscussionListState from 'flarum/forum/states/DiscussionListState';
import DiscussionListItem from 'flarum/forum/components/DiscussionListItem';
import ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';
import Excerpt from './components/Excerpt';

/**
 * Rich excerpts render real post HTML, which only exists on a fully
 * serialized post — so they still include the post. Plain excerpts come from
 * the synopsisExcerpt attribute the backend serializes on each discussion,
 * with no posts in the payload at all.
 */
function richExcerptsInPlay(): boolean {
  return Boolean(app.forum.attribute<boolean>('synopsis.rich_excerpts')) || app.store.all('tags').some((tag: any) => Boolean(tag.richExcerpts?.()));
}

export default function addSummaryExcerpt() {
  extend(DiscussionListState.prototype, 'requestParams', function (params) {
    if (!richExcerptsInPlay()) return;

    if (typeof params.include === 'string') {
      params.include = [params.include];
    } else {
      params.include = params.include || [];
    }

    if (app.forum.attribute<string>('synopsis.excerpt_type') === 'first') {
      params.include.push('firstPost');
    } else {
      params.include.push('lastPost');
    }
  });

  extend(DiscussionListItem.prototype, 'infoItems', function (items: ItemList<Mithril.Children>) {
    const discussion = this.attrs.discussion;

    if (app.session.user && !app.session.user.preferences()?.showSynopsisExcerpts) {
      return;
    }

    const tags = discussion.tags();
    let tag;
    if (tags) {
      tag = tags[tags.length - 1];
    }

    const excerptLength = typeof tag?.excerptLength() === 'number' ? tag?.excerptLength() : app.forum.attribute<number>('synopsis.excerpt_length');
    const richExcerpt = typeof tag?.richExcerpts() === 'number' ? tag?.richExcerpts() : app.forum.attribute<boolean>('synopsis.rich_excerpts');
    const onMobile = app.session.user ? app.session.user.preferences()?.showSynopsisExcerptsOnMobile : false;

    // A length of zero means we don't want a synopsis for this discussion, so do nothing.
    if (excerptLength === 0) {
      return;
    }

    const excerptPost = richExcerpt
      ? app.forum.attribute<string>('synopsis.excerpt_type') === 'first'
        ? discussion.firstPost()
        : discussion.lastPost()
      : null;
    const plainExcerpt = richExcerpt ? null : (discussion.attribute<string>('synopsisExcerpt') as string | null);

    if (excerptPost || plainExcerpt) {
      const excerpt = <Excerpt post={excerptPost} plain={plainExcerpt} length={excerptLength} richExcerpt={richExcerpt} />;

      items.add('excerpt', excerpt, -100);
      onMobile && items.add('excerptM', excerpt, -100);
    }
  });
}
