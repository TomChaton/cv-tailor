<?php

namespace App\Observability\Status;

use App\Models\Revision;
use App\Revisions\RevisionStatus;
use Illuminate\Database\Eloquent\Builder;

class RevisionsSection extends CountByStatusSection
{
    public function heading(): string
    {
        return 'Revisions';
    }

    protected function query(): Builder
    {
        return Revision::query();
    }

    protected function statuses(): array
    {
        return RevisionStatus::values();
    }
}
