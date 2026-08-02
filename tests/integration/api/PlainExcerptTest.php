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
}
