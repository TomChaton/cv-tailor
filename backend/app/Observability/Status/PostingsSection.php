<?php

namespace App\Observability\Status;

use App\Models\Posting;
use App\Postings\PostingStatus;
use Illuminate\Database\Eloquent\Builder;

class PostingsSection extends CountByStatusSection
{
    public function heading(): string
    {
        return 'Postings';
    }

    protected function query(): Builder
    {
        return Posting::query();
    }

    protected function statuses(): array
    {
        return PostingStatus::values();
    }
}
