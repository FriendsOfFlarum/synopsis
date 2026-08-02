import Component, { ComponentAttrs } from 'flarum/common/Component';
import Post from 'flarum/common/models/Post';
import { truncate } from 'flarum/common/utils/string';
import type Mithril from 'mithril';
import truncateHtml from '../utils/truncateHtml';

export interface ExcerptAttrs extends ComponentAttrs {
  post: Post | null;
  plain: string | null;
  length: number;
  richExcerpt: boolean;
}

export default class Excerpt extends Component<ExcerptAttrs> {
  post!: Post | null;
  plain!: string | null;
  length!: number;
  richExcerpt!: boolean;

  oninit(vnode: Mithril.Vnode<ExcerptAttrs, this>) {
    super.oninit(vnode);

    this.post = this.attrs.post;
    this.plain = this.attrs.plain;
    this.length = this.attrs.length;
    this.richExcerpt = this.attrs.richExcerpt;
  }

  view() {
    return <div className="Synopsis-excerpt">{this.getContent()}</div>;
  }

  getContent(): Mithril.Vnode | string {
    if (this.richExcerpt) {
      return m.trust(truncateHtml(this.contentRich() ?? '', this.length));
    }

    return truncate(this.contentPlain() ?? '', this.length);
  }

  contentRich() {
    return this.post?.contentHtml();
  }

  contentPlain() {
    // Plain excerpts are served as a discussion attribute; the post model is
    // only present in rich mode.
    return this.plain ?? this.post?.contentPlain();
  }
}
