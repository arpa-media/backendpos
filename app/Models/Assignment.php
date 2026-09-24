<?php

namespace App\Models;

use App\Services\HumanResource\HrAssignmentHistoryService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Assignment extends Model
{
    use HasFactory;
    use HasUlids;

    /** @var array<string,mixed> */
    protected array $hrHistoryBefore = [];

    protected $fillable = [
        'employee_id',
        'outlet_id',
        'hr_assignment_id',
        'role_title',
        'start_date',
        'end_date',
        'is_primary',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_primary' => 'boolean',
            'imported_at' => 'datetime',
            'source_updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Assignment $assignment): void {
            app(HrAssignmentHistoryService::class)->recordModelEvent($assignment, 'created', null);
        });

        static::updating(function (Assignment $assignment): void {
            $assignment->hrHistoryBefore = app(HrAssignmentHistoryService::class)
                ->snapshotFromArray($assignment->getOriginal());
        });

        static::updated(function (Assignment $assignment): void {
            $service = app(HrAssignmentHistoryService::class);
            $after = $service->snapshot($assignment);
            $before = $assignment->hrHistoryBefore;

            if ($before === [] || $before == $after) {
                return;
            }

            $action = 'updated';
            if (($before['is_primary'] ?? false) && ! ($after['is_primary'] ?? false)) {
                $action = 'deactivated';
            } elseif (! ($before['is_primary'] ?? false) && ($after['is_primary'] ?? false)) {
                $action = 'activated';
            } elseif (strtolower((string) ($before['status'] ?? '')) !== 'inactive'
                && strtolower((string) ($after['status'] ?? '')) === 'inactive') {
                $action = 'deactivated';
            }

            $service->recordModelEvent($assignment, $action, $before);
            $assignment->hrHistoryBefore = [];
        });

        static::deleting(function (Assignment $assignment): void {
            app(HrAssignmentHistoryService::class)->recordModelEvent(
                $assignment,
                'deleted',
                app(HrAssignmentHistoryService::class)->snapshot($assignment),
                null,
            );
        });
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }
}
