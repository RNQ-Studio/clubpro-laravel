<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use App\Models\Category;
use App\Models\Tag;
use App\Models\User;
use App\Support\Enums\ArticleStatus;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ArticleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guest_cannot_access_articles(): void
    {
        $this->getJson('/api/v1/articles')
            ->assertUnauthorized()
            ->assertJson(['code' => 'UNAUTHENTICATED']);
    }

    public function test_admin_can_list_articles_with_filter_sort_and_pagination(): void
    {
        Passport::actingAs($this->userWithRole('admin'));

        $category = Category::factory()->create();
        $author = User::factory()->create();

        Article::factory()->create([
            'title' => 'Beta Article',
            'slug' => 'beta-article',
            'status' => ArticleStatus::Draft,
            'category_id' => $category->id,
            'author_id' => $author->id,
        ]);

        Article::factory()->create([
            'title' => 'Alpha Article',
            'slug' => 'alpha-article',
            'status' => ArticleStatus::Published,
            'published_at' => now()->subDay(),
        ]);

        $this->getJson("/api/v1/articles?filter[category_id]={$category->id}&filter[status]=draft&sort=title&per_page=1")
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Beta Article')
            ->assertJsonPath('data.0.author.id', $author->id)
            ->assertJsonMissingPath('data.0.author.email')
            ->assertJsonMissingPath('data.0.author.phone')
            ->assertJsonMissingPath('data.0.author.birth_date')
            ->assertJsonMissingPath('data.0.author.pin_google_reauthenticated')
            ->assertJsonMissingPath('data.0.author.permissions')
            ->assertJsonPath('meta.pagination.per_page', 1)
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_admin_can_create_show_update_and_delete_article_with_reading_time_and_tags(): void
    {
        Passport::actingAs($this->userWithRole('admin'));

        $category = Category::factory()->create();
        $tag1 = Tag::factory()->create(['name' => 'Tech']);
        $tag2 = Tag::factory()->create(['name' => 'PHP']);

        // 1. Create Article
        $articleData = [
            'category_id' => $category->id,
            'title' => 'My Medium Article',
            'excerpt' => 'A short summary.',
            'content' => 'This is a beautiful and simple post written for standard testing. It contains a few words to verify the calculation of the reading time, which should automatically evaluate to one minute.',
            'status' => 'draft',
            'tags' => [$tag1->id, $tag2->id],
        ];

        $articleId = $this->postJson('/api/v1/articles', $articleData)
            ->assertCreated()
            ->assertJsonPath('data.title', 'My Medium Article')
            ->assertJsonPath('data.slug', 'my-medium-article')
            ->assertJsonPath('data.reading_time', 1) // ~32 words, rounded up to 1 min
            ->assertJsonCount(2, 'data.tags')
            ->json('data.id');

        $this->assertDatabaseHas('article_tag', [
            'article_id' => $articleId,
            'tag_id' => $tag1->id,
        ]);

        // 2. Show Article
        $this->getJson("/api/v1/articles/{$articleId}")
            ->assertOk()
            ->assertJsonPath('data.title', 'My Medium Article')
            ->assertJsonPath('data.reading_time', 1);

        // 3. Update Article content (adding 250 words to increase reading time to 2 mins)
        $newContent = str_repeat('word ', 250);
        $this->putJson("/api/v1/articles/{$articleId}", [
            'title' => 'My Updated Medium Article',
            'content' => $newContent,
            'status' => 'published',
            'tags' => [$tag1->id], // sync down to just 1 tag
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'My Updated Medium Article')
            ->assertJsonPath('data.slug', 'my-updated-medium-article')
            ->assertJsonPath('data.reading_time', 2) // 250 words / 200 = 1.25 -> 2 mins
            ->assertJsonCount(1, 'data.tags');

        $this->assertDatabaseMissing('article_tag', [
            'article_id' => $articleId,
            'tag_id' => $tag2->id,
        ]);

        // 4. Delete Article
        $this->deleteJson("/api/v1/articles/{$articleId}")
            ->assertOk()
            ->assertJson(['message' => 'Article deleted']);

        $this->assertSoftDeleted('articles', ['id' => $articleId]);
    }

    public function test_staff_cannot_delete_article(): void
    {
        Passport::actingAs($this->userWithRole('staff'));
        $article = Article::factory()->create();

        $this->deleteJson("/api/v1/articles/{$article->getKey()}")
            ->assertForbidden();

        $this->assertNotSoftDeleted('articles', ['id' => $article->getKey()]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
