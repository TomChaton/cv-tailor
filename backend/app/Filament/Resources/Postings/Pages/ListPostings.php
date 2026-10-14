<?php

namespace App\Filament\Resources\Postings\Pages;

use App\Filament\Resources\Postings\PostingResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The list is the whole resource. Nothing here creates a posting: a posting
 * arrives from the extension's capture and from nowhere else.
 */
class ListPostings extends ListRecords
{
    protected static string $resource = PostingResource::class;
}
