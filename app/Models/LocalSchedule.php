<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LocalSchedule extends Model
{
    protected $guarded = [];
    protected $casts = ['enabled' => 'boolean'];
    public static function forKind(string $kind): self
    {
        abort_unless(in_array($kind, ['master', 'reports'], true), 422, 'Invalid schedule kind.');
        $defaults = ['master' => ['02:00', 'Quinos Master Sync'], 'reports' => ['02:30', 'Quinos Reports Sync']];
        return static::firstOrCreate(['kind' => $kind], ['run_time' => $defaults[$kind][0], 'task_name' => $defaults[$kind][1]]);
    }
}
