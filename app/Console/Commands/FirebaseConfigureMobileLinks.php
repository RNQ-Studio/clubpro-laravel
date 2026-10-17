<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Kreait\Laravel\Firebase\Facades\Firebase;
use Throwable;

class FirebaseConfigureMobileLinks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'firebase:configure-mobile-links 
                            {domain : Domain hosting Firebase (contoh: your-project.firebaseapp.com atau custom-domain.com)} 
                            {--project=app : Nama konfigurasi project Firebase di config/firebase.php}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Konfigurasi domain Firebase Hosting untuk Email Link Authentication di mobile app (menggantikan Firebase Dynamic Links)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $domain = trim($this->argument('domain'));
        $projectName = $this->option('project');

        // Sanitasi input domain (hapus http:// atau https:// jika diinput user)
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');

        $this->info("Menghubungkan ke proyek Firebase: [{$projectName}]...");

        try {
            // Mengambil instance Auth dari project yang sesuai
            $auth = Firebase::project($projectName)->auth();

            // Mengambil ProjectConfigManager jika tersedia
            if (! method_exists($auth, 'projectConfigManager')) {
                $this->error('Method projectConfigManager() tidak didukung pada versi library kreait/firebase-php ini.');
                $this->error('Pastikan versi paket minimal 7.x atau 8.x ke atas.');

                return self::FAILURE;
            }

            $projectConfigManager = $auth->projectConfigManager();

            $this->info("Mengupdate mobileLinksConfig dengan domain: {$domain}...");

            $updateRequest = [
                'mobileLinksConfig' => [
                    'domain' => $domain,
                ],
            ];

            $projectConfigManager->updateProjectConfig($updateRequest);

            $this->info('✅ Berhasil mengonfigurasi Firebase Hosting domain untuk Mobile Links.');
            Log::info('firebase:configure-mobile-links completed', [
                'domain' => $domain,
                'project' => $projectName,
            ]);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('❌ Terjadi kesalahan saat mengupdate konfigurasi: '.$e->getMessage());
            Log::error('firebase:configure-mobile-links failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }
}
