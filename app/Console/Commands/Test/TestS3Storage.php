<?php

namespace App\Console\Commands\Test;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

// php artisan test:s3-storage --disk=s3 --keep
class TestS3Storage extends Command
{
    protected $signature = 'test:s3-storage
                            {--disk= : Disk to test (defaults to filesystems.app_disk, i.e. STORAGE_DRIVER)}
                            {--keep : Leave the uploaded test file in place instead of deleting it afterwards}';

    protected $description = 'Upload, read, publicly fetch, and delete a file in the test/ folder to verify the AWS S3 credentials and bucket configuration are working';

    public function handle(): int
    {
        $disk_name = $this->option('disk') ?: config('filesystems.app_disk');
        $disk = Storage::disk($disk_name);
        $disk_config = config("filesystems.disks.{$disk_name}", []);

        $this->components->info("Testing disk [{$disk_name}]...");
        $this->components->twoColumnDetail('Driver', (string) ($disk_config['driver'] ?? 'n/a'));
        $this->components->twoColumnDetail('Bucket', (string) ($disk_config['bucket'] ?? 'n/a'));
        $this->components->twoColumnDetail('Region', (string) ($disk_config['region'] ?? 'n/a'));

        $token = (string) Str::uuid();
        $path = "test/{$token}.txt";
        $contents = "97th Floor Workspace S3 connectivity test\nUploaded at: ".now()->toIso8601String()."\nToken: {$token}\n";

        try {
            $this->components->task('Uploading test file', function () use ($disk, $path, $contents) {
                return $disk->put($path, $contents);
            });

            if (! $disk->exists($path)) {
                $this->components->error("Upload reported success but [{$path}] was not found on the disk.");

                return self::FAILURE;
            }

            $this->components->task('Reading the file back', function () use ($disk, $path, $contents) {
                return $disk->get($path) === $contents;
            });

            $url = $disk->url($path);
            $this->components->twoColumnDetail('URL', $url);

            $this->verifyPubliclyReachable($url, $contents);
        } catch (Throwable $exception) {
            $this->components->error("S3 test failed: {$exception->getMessage()}");

            return self::FAILURE;
        } finally {
            if ($this->option('keep')) {
                $this->components->warn("Leaving [{$path}] in place (--keep was passed) — remember to delete it manually.");
            } elseif ($disk->exists($path)) {
                $this->components->task('Deleting the test file', fn () => $disk->delete($path));
            }
        }

        $this->components->success("Disk [{$disk_name}] is configured correctly.");

        return self::SUCCESS;
    }

    /**
     * Confirms the bucket's public-read policy is actually in effect by
     * fetching the object straight off its S3 URL over HTTP, rather than
     * only trusting the SDK call that wrote/read it.
     */
    private function verifyPubliclyReachable(string $url, string $expected_contents): void
    {
        $this->components->task('Fetching the file over HTTP (public bucket policy check)', function () use ($url, $expected_contents) {
            $response = Http::timeout(10)->get($url);

            return $response->ok() && $response->body() === $expected_contents;
        });
    }
}
