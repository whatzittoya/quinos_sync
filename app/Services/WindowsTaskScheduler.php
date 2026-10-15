<?php
namespace App\Services;
use App\Models\LocalSchedule;
use Symfony\Component\Process\Process;
use RuntimeException;
class WindowsTaskScheduler
{
    private function run(array $args): string
    {
        if (PHP_OS_FAMILY !== 'Windows') throw new RuntimeException('Windows Task Scheduler is only available on Windows.');
        $p = new Process(array_merge(['schtasks.exe'], $args)); $p->setTimeout(30); $p->run();
        if (!$p->isSuccessful()) throw new RuntimeException(trim($p->getErrorOutput()) ?: 'Windows Task Scheduler command failed.');
        return trim($p->getOutput());
    }
    public function create(string $kind, string $time): void
    {
        $s = LocalSchedule::forKind($kind); $this->validateTime($time); $php = $this->phpBinary();
        $flag = $kind === 'master' ? '--master-only' : '--reports-only';
        $command = '"'.$php.'" "'.base_path().DIRECTORY_SEPARATOR.'artisan" local:sync --scheduled '.$flag;
        $this->run(['/Create','/TN',$s->task_name,'/SC','DAILY','/ST',$time,'/TR',$command,'/F']);
        $s->update(['enabled' => true, 'run_time' => $time, 'last_action' => 'created', 'last_error' => null]);
    }
    public function enable(string $kind): void { $s=LocalSchedule::forKind($kind); $this->run(['/Change','/TN',$s->task_name,'/ENABLE']); $s->update(['enabled'=>true,'last_action'=>'enabled','last_error'=>null]); }
    public function disable(string $kind): void { $s=LocalSchedule::forKind($kind); $this->run(['/Change','/TN',$s->task_name,'/DISABLE']); $s->update(['enabled'=>false,'last_action'=>'disabled','last_error'=>null]); }
    public function delete(string $kind): void { $s=LocalSchedule::forKind($kind); $this->run(['/Delete','/TN',$s->task_name,'/F']); $s->update(['enabled'=>false,'last_action'=>'deleted','last_error'=>null]); }
    public function query(string $kind): string { $s=LocalSchedule::forKind($kind); return $this->run(['/Query','/TN',$s->task_name,'/FO','LIST']); }
    /**
     * The command-line php.exe for the task. Under XAMPP the page runs inside Apache, where PHP_BINARY is
     * httpd.exe and would make every scheduled run fail, so look next to php.ini as well.
     */
    public function phpBinary(): string
    {
        $dirs = array_filter([dirname((string) php_ini_loaded_file()), PHP_BINDIR]);

        return self::pickPhp(array_merge(
            [config('sync.php_cli'), PHP_BINARY],
            array_map(fn ($dir) => $dir.DIRECTORY_SEPARATOR.'php.exe', $dirs),
        ));
    }

    /** @param  list<mixed>  $candidates */
    public static function pickPhp(array $candidates): string
    {
        foreach ($candidates as $path) {
            if (is_string($path) && $path !== '' && in_array(strtolower(basename(str_replace('\\', '/', $path))), ['php.exe', 'php'], true) && is_file($path)) {
                return $path;
            }
        }

        throw new RuntimeException('Could not find php.exe. Set SYNC_PHP_CLI in .env to its full path, for example C:\xampp\php\php.exe.');
    }

    private function validateTime(string $time): void { if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) throw new RuntimeException('Run time must use HH:MM.'); }
}
