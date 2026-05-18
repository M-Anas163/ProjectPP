<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StartWorkerPool extends Command
{
    protected $signature   = 'worker:pool-start
                             {--workers= : عدد Workers (إذا فارغ يُحسب تلقائياً)}
                             {--queue=orders : اسم الـ Queue}';
    protected $description = 'تشغيل Thread Pool محسوب لمعالجة الطلبات';

    public function handle(): void
    {
        $optimalWorkers = $this->calculateOptimalWorkers();
        $workers        = (int) ($this->option('workers') ?? $optimalWorkers);
        $queue          = $this->option('queue');

        $this->displayPoolInfo($workers, $optimalWorkers);

        $pids = [];
        for ($i = 1; $i <= $workers; $i++) {
            $command = "php artisan queue:work --queue={$queue} " .
                       "--tries=3 --timeout=60 --memory=128 " .
                       "--sleep=1 --max-jobs=100";

            $pid  = shell_exec("nohup {$command} > storage/logs/worker-{$i}.log 2>&1 & echo $!");
            $pids[] = trim($pid);

            $this->info("✓ Worker #{$i} بدأ العمل (PID: " . trim($pid) . ")");
        }

        $this->info("\n★ Thread Pool جاهز: {$workers} workers نشطون");
        $this->table(
            ['Worker', 'PID', 'Queue', 'Max Memory', 'Timeout'],
            array_map(fn($i, $pid) => [
                "Worker #{$i}", $pid, $queue, '128MB', '60s'
            ], range(1, count($pids)), $pids)
        );
    }

    private function calculateOptimalWorkers(): int
    {
        $cpuCores = $this->getCpuCores();

        $availableMemoryMB  = $this->getAvailableMemoryMB();
        $memoryPerWorkerMB  = 128; // كل worker يأخذ ~128MB

        $workersByCpu    = $cpuCores * 2;

        $workersByMemory = (int) floor($availableMemoryMB / $memoryPerWorkerMB);

        $optimal = min($workersByCpu, $workersByMemory);


        return max(1, min($optimal, 16));
    }

    private function getCpuCores(): int
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $cores = (int) shell_exec('nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null');
            return $cores > 0 ? $cores : 2;
        }

        // Windows
        $cores = (int) getenv('NUMBER_OF_PROCESSORS');
        return $cores > 0 ? $cores : 2;
    }

    private function getAvailableMemoryMB(): int
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $memInfo  = file_get_contents('/proc/meminfo');
            preg_match('/MemAvailable:\s+(\d+) kB/', $memInfo ?? '', $matches);
            return isset($matches[1]) ? (int) round($matches[1] / 1024) : 512;
        }

        return 512;
    }

    private function displayPoolInfo(int $workers, int $optimal): void
    {
        $cores  = $this->getCpuCores();
        $memory = $this->getAvailableMemoryMB();

        $this->info("═══════════════════════════════════");
        $this->info("     حساب Thread Pool المثالي      ");
        $this->info("═══════════════════════════════════");
        $this->info("أنوية المعالج:          {$cores}");
        $this->info("الذاكرة المتاحة:        {$memory} MB");
        $this->info("Workers بحساب CPU:      " . ($cores * 2));
        $this->info("Workers بحساب RAM:      " . floor($memory / 128));
        $this->info("العدد الأمثل المحسوب:   {$optimal}");
        $this->info("العدد المُشغَّل:         {$workers}");
        $this->info("═══════════════════════════════════\n");
    }
}
