<?php

namespace App\Http\Controllers\Concerns;

/**
 * The addresses the clinic infrastructure pages (branches, departments,
 * rooms) use to link to each other and to write, so a page never builds a
 * path by hand. Rows are written at `{collection}/{id}`.
 */
trait LinksInfrastructurePages
{
    /**
     * @return array{branches: string, departments: string, rooms: string}
     */
    protected function links(): array
    {
        return [
            'branches' => route('admin.branches.index'),
            'departments' => route('admin.departments.index'),
            'rooms' => route('admin.rooms.index'),
        ];
    }
}
