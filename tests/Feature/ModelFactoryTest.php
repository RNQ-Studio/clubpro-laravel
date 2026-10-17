<?php

namespace Tests\Feature;

use App\Models\AppConfig;
use App\Models\AppRelease;
use App\Models\AppVersion;
use App\Models\Article;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Notification;
use App\Models\OtpCode;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_model_factories_can_create_records(): void
    {
        $user = User::factory()->create();
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        $category = Category::factory()->create();
        $this->assertDatabaseHas('categories', ['id' => $category->id]);

        $userDevice = UserDevice::factory()->create(['user_id' => $user->id]);
        $this->assertDatabaseHas('user_devices', ['id' => $userDevice->id]);

        $appConfig = AppConfig::factory()->create();
        $this->assertDatabaseHas('app_configs', ['id' => $appConfig->id]);

        $appVersion = AppVersion::factory()->create();
        $this->assertDatabaseHas('app_versions', ['id' => $appVersion->id]);

        $appRelease = AppRelease::factory()->create();
        $this->assertDatabaseHas('app_releases', ['id' => $appRelease->id]);

        $banner = Banner::factory()->create();
        $this->assertDatabaseHas('banners', ['id' => $banner->id]);

        $notification = Notification::factory()->create(['user_id' => $user->id]);
        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);

        $otpCode = OtpCode::factory()->create();
        $this->assertDatabaseHas('otp_codes', ['id' => $otpCode->id]);

        $article = Article::factory()->create();
        $this->assertDatabaseHas('articles', ['id' => $article->id]);

        $tag = Tag::factory()->create();
        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    }
}
