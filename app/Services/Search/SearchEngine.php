<?php

namespace App\Services\Search;

use Illuminate\Support\Collection;

interface SearchEngine
{
    /**
     * Search the web.
     *
     * @return Collection<int, array{title: string, url: string, snippet: string}>
     *
     * @throws SearchException
     */
    public function search(string $query, int $limit): Collection;
}
