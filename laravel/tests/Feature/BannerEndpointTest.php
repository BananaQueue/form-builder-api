<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BannerEndpointTest extends TestCase
{
    private ?string $originalBanner = null;

    protected function setUp(): void
    {
        parent::setUp();

        $path = public_path('uploads/banner.png');
        $this->originalBanner = is_file($path) ? file_get_contents($path) : null;

        if (is_file($path)) {
            @unlink($path);
        }
    }

    protected function tearDown(): void
    {
        $path = public_path('uploads/banner.png');
        if ($this->originalBanner !== null) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $this->originalBanner);
        } elseif (is_file($path)) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_upload_banner_requires_authenticated_session(): void
    {
        $response = $this->post('/upload_banner.php');

        $response->assertStatus(401)->assertJson(['error' => 'Authentication required']);
    }

    public function test_upload_banner_requires_super_admin_role(): void
    {
        $token = 'csrf-token';
        $response = $this->withSession([
            '_token' => $token,
            'logged_in' => true,
            'user_id' => 5,
            'role' => 'user',
        ])->withHeader('X-CSRF-TOKEN', $token)->post('/upload_banner.php');

        $response->assertStatus(403)->assertJson(['error' => 'Super admin access required']);
    }

    public function test_upload_banner_requires_file(): void
    {
        $token = 'csrf-token';
        $response = $this->withSession([
            '_token' => $token,
            'logged_in' => true,
            'user_id' => 5,
            'role' => 'super_admin',
        ])->withHeader('X-CSRF-TOKEN', $token)->post('/upload_banner.php');

        $response->assertOk()->assertJson(['success' => false, 'error' => 'No file uploaded']);
    }

    public function test_upload_banner_saves_png_and_audits(): void
    {
        $token = 'csrf-token';
        $file = $this->tinyPngUpload();
        $audit = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('audit_logs')->andReturn($audit);
        $audit->shouldReceive('insert')->once()->with(\Mockery::on(fn (array $row): bool => $row['action'] === 'BANNER_UPLOADED' && $row['entity_label'] === 'banner.png'))->andReturnTrue();

        $response = $this->withSession([
            '_token' => $token,
            'logged_in' => true,
            'username' => 'admin',
            'user_id' => 5,
            'role' => 'super_admin',
        ])->withHeader('X-CSRF-TOKEN', $token)->post('/upload_banner.php', ['banner' => $file]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertFileExists(public_path('uploads/banner.png'));
    }

    public function test_remove_banner_requires_super_admin_role(): void
    {
        $token = 'csrf-token';
        $response = $this->withSession([
            '_token' => $token,
            'logged_in' => true,
            'user_id' => 5,
            'role' => 'user',
        ])->withHeader('X-CSRF-TOKEN', $token)->post('/remove_banner.php');

        $response->assertStatus(403)->assertJson(['error' => 'Super admin access required']);
    }

    public function test_remove_banner_reports_missing_banner_like_legacy(): void
    {
        $token = 'csrf-token';
        $response = $this->withSession([
            '_token' => $token,
            'logged_in' => true,
            'user_id' => 5,
            'role' => 'super_admin',
        ])->withHeader('X-CSRF-TOKEN', $token)->post('/remove_banner.php');

        $response->assertOk()->assertJson(['success' => false, 'error' => 'No banner to remove']);
    }

    public function test_remove_banner_deletes_file_and_audits(): void
    {
        $token = 'csrf-token';
        $dir = public_path('uploads');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(public_path('uploads/banner.png'), base64_decode($this->tinyPngBase64()));

        $audit = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('audit_logs')->andReturn($audit);
        $audit->shouldReceive('insert')->once()->with(\Mockery::on(fn (array $row): bool => $row['action'] === 'BANNER_REMOVED' && $row['entity_label'] === 'banner.png'))->andReturnTrue();

        $response = $this->withSession([
            '_token' => $token,
            'logged_in' => true,
            'username' => 'admin',
            'user_id' => 5,
            'role' => 'super_admin',
        ])->withHeader('X-CSRF-TOKEN', $token)->post('/remove_banner.php');

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertFileDoesNotExist(public_path('uploads/banner.png'));
    }

    public function test_native_banner_route_requires_authenticated_session(): void
    {
        $response = $this->post('/api/banner');

        $response->assertStatus(401)->assertJson(['error' => 'Authentication required']);
    }

    public function test_native_banner_route_saves_png_and_audits(): void
    {
        $token = 'csrf-token';
        $file = $this->tinyPngUpload();
        $audit = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('audit_logs')->andReturn($audit);
        $audit->shouldReceive('insert')->once()->with(\Mockery::on(fn (array $row): bool => $row['action'] === 'BANNER_UPLOADED' && $row['entity_label'] === 'banner.png'))->andReturnTrue();

        $response = $this->withSession([
            '_token' => $token,
            'logged_in' => true,
            'username' => 'admin',
            'user_id' => 5,
            'role' => 'super_admin',
        ])->withHeader('X-CSRF-TOKEN', $token)->post('/api/banner', ['banner' => $file]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertFileExists(public_path('uploads/banner.png'));
    }

    public function test_native_banner_delete_route_removes_file_and_audits(): void
    {
        $token = 'csrf-token';
        $dir = public_path('uploads');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(public_path('uploads/banner.png'), base64_decode($this->tinyPngBase64()));

        $audit = \Mockery::mock();
        DB::shouldReceive('table')->once()->with('audit_logs')->andReturn($audit);
        $audit->shouldReceive('insert')->once()->with(\Mockery::on(fn (array $row): bool => $row['action'] === 'BANNER_REMOVED' && $row['entity_label'] === 'banner.png'))->andReturnTrue();

        $response = $this->withSession([
            '_token' => $token,
            'logged_in' => true,
            'username' => 'admin',
            'user_id' => 5,
            'role' => 'super_admin',
        ])->withHeader('X-CSRF-TOKEN', $token)->delete('/api/banner');

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertFileDoesNotExist(public_path('uploads/banner.png'));
    }

    private function tinyPngUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'banner_').'.png';
        file_put_contents($path, base64_decode($this->tinyPngBase64()));

        return new UploadedFile($path, 'banner.png', 'image/png', null, true);
    }

    private function tinyPngBase64(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/lm5wWQAAAABJRU5ErkJggg==';
    }
}
