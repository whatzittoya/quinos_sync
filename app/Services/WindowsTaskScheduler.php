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
        $s = LocalSchedule::forKind($kind); $this->validateTime($time); $php = PHP_BINARY;
        $flag = $kind === 'master' ? '--master-only' : '--reports-only';
        $command = '"'.$php.'" "'.base_path().DIRECTORY_SEPARATOR.'artisan" local:sync --scheduled '.$flag;
        $this->run(['/Create','/TN',$s->task_name,'/SC','DAILY','/ST',$time,'/TR',$command,'/F']);
        $s->update(['enabled' => true, 'run_time' => $time, 'last_action' => 'created', 'last_error' => null]);
    }
    public function enable(string $kind): void { $s=LocalSchedule::forKind($kind); $this->run(['/Change','/TN',$s->task_name,'/ENABLE']); $s->update(['enabled'=>true,'last_action'=>'enabled','last_error'=>null]); }
    public function disable(string $kind): void { $s=LocalSchedule::forKind($kind); $this->run(['/Change','/TN',$s->task_name,'/DISABLE']); $s->update(['enabled'=>false,'last_action'=>'disabled','last_error'=>null]); }
    public function delete(string $kind): void { $s=LocalSchedule::forKind($kind); $this->run(['/Delete','/TN',$s->task_name,'/F']); $s->update(['enabled'=>false,'last_action'=>'deleted','last_error'=>null]); }
    public function query(string $kind): string { $s=LocalSchedule::forKind($kind); return $this->run(['/Query','/TN',$s->task_name,'/FO','LIST']); }
    private function validateTime(string $time): void { if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) throw new RuntimeException('Run time must use HH:MM.'); }
}
