<?php

/*
 * This file is part of fof/synopsis.
 *
 * (c) FriendsOfFlarum
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\Synopsis\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Tags\Tag;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class PlainExcerptTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setup(): void
    {
        parent::setup();

        $this->extension('flarum-tags', 'fof-synopsis');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'First', 'created_at' => Carbon::now(), 'last_posted_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 1, 'last_post_id' => 2, 'comment_count' => 2],
                ['id' => 2, 'title' => 'Second', 'created_at' => Carbon::now(), 'last_posted_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 3, 'last_post_id' => 4, 'comment_count' => 2],
            ],
            Post::class => [
                ['id' => 1, 'number' => 1, 'discussion_id' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Opening words of the <STRONG><s>**</s>first<e>**</e></STRONG> discussion</p></t>'],
                ['id' => 2, 'number' => 2, 'discussion_id' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Closing words of the first discussion</p></t>'],
                ['id' => 3, 'number' => 1, 'discussion_id' => 2, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>'.str_repeat('Lengthy opener. ', 40).'</p></t>'],
                ['id' => 4, 'number' => 2, 'discussion_id' => 2, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t><p>Closing words of the second discussion</p></t>'],
            ],
            Tag::class => [
                ['id' => 1, 'name' => 'General', 'slug' => 'general', 'position' => 0],
            ],
            'discussion_tag' => [
                ['discussion_id' => 1, 'tag_id' => 1],
                ['discussion_id' => 2, 'tag_id' => 1],
            ],
        ]);
    }

    /**
     * @return array{0: mixed, 1: string[]} body and post-table queries
     */
    protected function listDiscussions(array $query = []): array
    {
        $db = $this->database();
        $db->enableQueryLog();
        $db->flushQueryLog();

        $response = $this->send(
            $this->request('GET', '/api/discussions', ['authenticatedAs' => 2])->withQueryParams($query)
        );

        $this->assertEquals(200, $response->getStatusCode());

        $table = 'from '.$db->getTablePrefix().'posts';
        $postsQueries = array_values(array_filter(
            array_column($db->getQueryLog(), 'query'),
            fn (string $sql) => str_contains(str_replace(['`', '"'], '', $sql), $table)
        ));
        $db->flushQueryLog();

        return [json_decode($response->getBody()->getContents(), true), $postsQueries];
    }

    protected function includedPosts(array $body): array
    {
        return array_values(array_filter($body['included'] ?? [], fn (array $r) => $r['type'] === 'posts'));
    }

    protected function attributesById(array $body): array
    {
        $byId = [];
        foreach ($body['data'] as $discussion) {
            $byId[$discussion['id']] = $discussion['attributes'];
        }

        return $byId;
    }

    #[Test]
    public function plain_mode_serializes_excerpt_attributes_and_no_posts(): void
    {
        [$body, $postsQueries] = $this->listDiscussions();

        $this->assertCount(0, $this->includedPosts($body), 'Plain excerpts must not serialize posts.');

        $attrs = $this->attributesById($body);
        $this->assertSame('Opening words of the first discussion', $attrs['1']['synopsisExcerpt'] ?? null);
        $this->assertStringStartsWith('Lengthy opener.', $attrs['2']['synopsisExcerpt'] ?? '');

        $this->assertCount(1, $postsQueries, "One batched posts query expected. Ran:\n".implode("\n", $postsQueries));
    }

    #[Test]
    public function last_post_mode_serializes_the_last_posts_text(): void
    {
        $this->setting('fof-synopsis.excerpt-type', 'last');

        [$body] = $this->listDiscussions();

        $attrs = $this->attributesById($body);
        $this->assertSame('Closing words of the first discussion', $attrs['1']['synopsisExcerpt'] ?? null);
        $this->assertSame('Closing words of the second discussion', $attrs['2']['synopsisExcerpt'] ?? null);
        $this->assertCount(0, $this->includedPosts($body));
    }

    #[Test]
    public function excerpt_length_covers_the_largest_tag_override(): void
    {
        // Global length is 200; a tag asking for 400 must not receive an
        // excerpt truncated below what it will display.
        $this->setting('fof-synopsis.excerpt_length', 200);
        $this->database()->table('tags')->where('id', 1)->update(['excerpt_length' => 400]);

        [$body] = $this->listDiscussions();

        $attrs = $this->attributesById($body);
        $this->assertGreaterThan(300, mb_strlen($attrs['2']['synopsisExcerpt'] ?? ''), 'Excerpt must be long enough for the largest tag override.');
    }

    #[Test]
    public function rich_mode_keeps_including_full_posts(): void
    {
        // Rich excerpts render real HTML; that genuinely needs the rendered
        // post, so the admin who opts in keeps today's behaviour.
        $this->setting('fof-synopsis.rich-excerpts', true);

        [$body] = $this->listDiscussions();

        $this->assertCount(2, $this->includedPosts($body), 'Rich mode still includes the excerpt posts.');
    }

    #[Test]
    public function a_single_rich_tag_override_keeps_including_full_posts(): void
    {
        $this->database()->table('tags')->where('id', 1)->update(['rich_excerpts' => true]);

        [$body] = $this->listDiscussions();

        $this->assertCount(2, $this->includedPosts($body), 'A rich tag override still includes the excerpt posts.');
    }

    #[Test]
    public function a_discussion_included_in_a_posts_payload_serializes_safely(): void
    {
        // The posts index default-includes each post's discussion; the
        // excerpt field then runs while the request's primary resource is
        // posts, so the relationship lookup must target the discussions
        // resource explicitly.
        $response = $this->send(
            $this->request('GET', '/api/posts', ['authenticatedAs' => 2])
                ->withQueryParams(['filter' => ['discussion' => '1']])
        );

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);
        $discussions = array_values(array_filter($body['included'] ?? [], fn (array $r) => $r['type'] === 'discussions'));

        $this->assertNotEmpty($discussions);
        $this->assertSame('Opening words of the first discussion', $discussions[0]['attributes']['synopsisExcerpt'] ?? null);
    }

    #[Test]
    public function explicitly_requesting_first_posts_still_serializes_them_for_every_row(): void
    {
        $response = $this->send(
            $this->request('GET', '/api/discussions', ['authenticatedAs' => 2])
                ->withQueryParams(['include' => 'firstPost'])
        );

        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertCount(2, $this->includedPosts($body), 'Explicit include must serialize every first post.');
    }

    /**
     * A first post with no extractable text — an empty parsed body, or one
     * that is only an image/attachment — must not fatal the discussion list.
     * `Utils::removeFormatting()` throws a ValueError on empty XML, which
     * surfaces as an unrendered 500.
     *
     * @dataProvider textlessContentProvider
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('textlessContentProvider')]
    public function a_textless_first_post_does_not_fatal_the_list(string $label, string $content): void
    {
        $this->database()->table('posts')->where('id', 1)->update(['content' => $content]);

        // Mirror production: Flarum's error handler escalates PHP warnings to
        // exceptions, so a warning from inside removeFormatting() becomes an
        // unrendered 500 on a live forum even where the test harness would let
        // it limp through. Scope to E_WARNING only, so unrelated PHP 8.5
        // deprecations in the middleware stack don't mask the signal.
        set_error_handler(function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        }, E_WARNING);

        try {
            [$body] = $this->listDiscussions();
        } finally {
            restore_error_handler();
        }

        // listDiscussions() already asserts a 200 — the regression is a 500.
        // The excerpt for a textless post should simply be null/empty.
        $attrs = $this->attributesById($body);
        $this->assertArrayHasKey('1', $attrs, "Discussion 1 must serialize with $label content.");
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function textlessContentProvider(): array
    {
        return [
            'empty string'      => ['empty string', ''],
            'whitespace only'   => ['whitespace only', '   '],
            'bare root'         => ['bare root', '<t></t>'],
            'image only'        => ['image only', '<r><UPL-IMAGE-PREVIEW url="https://example.com/a.jpg">[img]</UPL-IMAGE-PREVIEW></r>'],
        ];
    }

    #[Test]
    public function images_are_left_out_of_a_plain_excerpt()
    {
        $this->prepareDatabase([
            Discussion::class => [
                ['id' => 3, 'title' => 'BBCode image', 'created_at' => Carbon::now(), 'last_posted_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 5, 'last_post_id' => 5, 'comment_count' => 1],
                ['id' => 4, 'title' => 'Markdown image', 'created_at' => Carbon::now(), 'last_posted_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 6, 'last_post_id' => 6, 'comment_count' => 1],
                ['id' => 5, 'title' => 'fof/upload image preview', 'created_at' => Carbon::now(), 'last_posted_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 7, 'last_post_id' => 7, 'comment_count' => 1],
                ['id' => 6, 'title' => 'fof/upload image', 'created_at' => Carbon::now(), 'last_posted_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 8, 'last_post_id' => 8, 'comment_count' => 1],
            ],
            Post::class => [
                // [CENTER][IMG]https://example.com/logo.webp[/IMG][/CENTER] then a line of text, as BBCode stores it.
                ['id' => 5, 'number' => 1, 'discussion_id' => 3, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<r><CENTER><s>[CENTER]</s><IMG src="https://example.com/logo.webp"><s>[IMG]</s>https://example.com/logo.webp<e>[/IMG]</e></IMG><e>[/CENTER]</e></CENTER>'."\n".'Flarum is distributed under the MIT license.</r>'],
                ['id' => 6, 'number' => 1, 'discussion_id' => 4, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<r><p>Before <IMG alt="a picture" src="https://example.com/pic.png"><s>![</s>a picture<e>](https://example.com/pic.png)</e></IMG> after.</p></r>'],
                // fof/upload's default image BBCode is self-closing, so its whole source is the tag's text.
                ['id' => 7, 'number' => 1, 'discussion_id' => 5, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<r><UPL-IMAGE-PREVIEW alt="cat" url="https://example.com/a.png" uuid="abc">[upl-image-preview uuid=abc url=https://example.com/a.png alt=cat]</UPL-IMAGE-PREVIEW> Hello world</r>'],
                ['id' => 8, 'number' => 1, 'discussion_id' => 6, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<r><UPL-IMAGE size="2kb" url="https://example.com/b.png" uuid="d"><s>[upl-image uuid=d size=2kb url=https://example.com/b.png]</s>b.png<e>[/upl-image]</e></UPL-IMAGE> Hello again</r>'],
            ],
            'discussion_tag' => [
                ['discussion_id' => 3, 'tag_id' => 1],
                ['discussion_id' => 4, 'tag_id' => 1],
                ['discussion_id' => 5, 'tag_id' => 1],
                ['discussion_id' => 6, 'tag_id' => 1],
            ],
        ]);

        [$body] = $this->listDiscussions();
        $excerpts = array_column(array_map(fn ($d) => ['id' => $d['id'], 'e' => $d['attributes']['synopsisExcerpt'] ?? null], $body['data']), 'e', 'id');

        $this->assertSame('Flarum is distributed under the MIT license.', $excerpts['3']);
        $this->assertSame('Before after.', $excerpts['4']);
        $this->assertSame('Hello world', $excerpts['5']);
        $this->assertSame('Hello again', $excerpts['6']);
    }
}
