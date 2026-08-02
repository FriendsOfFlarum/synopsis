<?php

/*
 * This file is part of fof/synopsis.
 *
 * (c) FriendsOfFlarum
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\Synopsis\Api;

use Flarum\Api\Context;
use Flarum\Api\Resource\EloquentBuffer;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Tag;
use s9e\TextFormatter\Utils;
use WeakMap;

class AddDiscussionExcerptField
{
    /**
     * Request-scoped excerpt configuration, keyed by API context so it is
     * computed once per request rather than once per serialized discussion.
     *
     * @var WeakMap<Context, object>|null
     */
    private static ?WeakMap $config = null;

    public function __invoke(): array
    {
        return [
            Schema\Str::make('synopsisExcerpt')
                ->nullable()
                ->visible(fn (Discussion $discussion, Context $context) => !self::config($context)->rich)
                ->get(function (Discussion $discussion, Context $context) {
                    $config = self::config($context);

                    // Batch the excerpt posts through the relationship
                    // buffer: one query per page. Pre-loading the relation at
                    // the endpoint instead would mark it loaded (null) where
                    // the constraint misses and break clients that include
                    // the relation explicitly.
                    EloquentBuffer::add($discussion, $config->relation);

                    return function () use ($discussion, $config, $context) {
                        if (!$discussion->relationLoaded($config->relation)) {
                            // Look the relationship up on the discussions
                            // resource itself — $context->collection is the
                            // REQUEST's primary resource, which is a different
                            // one whenever the discussion is serialized as an
                            // included resource (e.g. of a posts request), and
                            // a null relationship sends the buffer down its
                            // aggregate path.
                            $resource = $context->api->getResource('discussions');

                            /** @var Schema\Relationship\ToOne|null $relationship */
                            $relationship = collect($context->fields($resource))->first(fn ($field) => $field->name === $config->relation);

                            EloquentBuffer::load($discussion, $config->relation, $relationship, $context);
                        }

                        $post = $discussion->getRelation($config->relation);

                        if (!$post instanceof CommentPost || empty($post->parsed_content)) {
                            return null;
                        }

                        // Plain text straight from the stored XML: no
                        // formatter render, no extension callbacks, no
                        // per-post policies — the costs that made including
                        // the whole post expensive.
                        $plain = trim(preg_replace('/\s+/', ' ', Utils::removeFormatting($post->parsed_content)) ?? '');

                        return mb_substr($plain, 0, $config->length);
                    };
                }),
        ];
    }

    private static function config(Context $context): object
    {
        self::$config ??= new WeakMap();

        return self::$config[$context] ??= self::compute();
    }

    private static function compute(): object
    {
        $settings = resolve(SettingsRepositoryInterface::class);

        // Rich excerpts (globally or on any tag) render real HTML and keep
        // the full post include; the plain attribute stands down entirely.
        // Aggregated in PHP rather than SQL: the tags table is tiny, and
        // MAX() over a boolean column is not portable (PostgreSQL rejects
        // it outright).
        $tags = Tag::query()->get(['excerpt_length', 'rich_excerpts']);

        return (object) [
            'rich'     => (bool) $settings->get('fof-synopsis.rich-excerpts') || $tags->contains(fn (Tag $tag) => (bool) $tag->rich_excerpts),
            'relation' => $settings->get('fof-synopsis.excerpt-type') === 'last' ? 'lastPost' : 'firstPost',
            // Serialize enough for the longest length any tag is configured
            // to display; the frontend truncates to the applicable length.
            'length'   => max((int) $settings->get('fof-synopsis.excerpt_length'), (int) $tags->max('excerpt_length'), 200),
        ];
    }
}
