<?php

namespace App\Services;

use App\Models\AttributeGroup;
use App\Support\Catalog\NameMatcher;
use App\Support\CsvFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bulk-creates attribute groups from a flat CSV (one group per row), the
 * same shape as CategoryImportService but without any path/tree parsing
 * since attribute groups are a flat list, not a hierarchy.
 *
 * The CSV only carries the two fields an admin actually has an opinion
 * about — name and description. Everything else (is_active, sort_order,
 * created_by, timestamps) is set automatically, the same as it would be
 * for any other row in the table: is_active always starts true, sort_order
 * is generated (appended after whatever already exists), and created_by is
 * whoever ran the import.
 *
 * Existing groups are never changed, so an exported file can be imported
 * again safely. A row is skipped when its name matches a group or an earlier
 * row, ignoring case, spaces and symbols; a very similar name is a possible
 * duplicate, created only if the admin ticks it in the preview ($createAnyway).
 *
 * Both preview() and import() share process() — the only difference is
 * whether missing groups are actually written to the database ($write).
 */
class AttributeGroupImportService
{
    /**
     * Parse an uploaded CSV into normalized rows. Expects a header row with
     * both a "name" and a "description" column. Other columns (e.g. the rest
     * of an exported file) are ignored.
     */
    public function parseCsv(UploadedFile $file): array
    {
        $csv = CsvFile::read($file);

        $nameCol = array_search('name', $csv['header'], true);
        if ($nameCol === false) {
            throw ValidationException::withMessages(['file' => 'The CSV must have a "name" column.']);
        }
        $descriptionCol = array_search('description', $csv['header'], true);
        if ($descriptionCol === false) {
            throw ValidationException::withMessages(['file' => 'The CSV must have a "description" column.']);
        }

        $rows = [];
        foreach ($csv['lines'] as [$lineNumber, $line]) {
            $name = CsvFile::cell($line, $nameCol);
            $description = CsvFile::cell($line, $descriptionCol);

            if ($name === '' && $description === '') {
                continue;
            }

            $rows[] = [
                'line' => $lineNumber,
                'name' => $name,
                'description' => $description,
            ];
        }

        if (empty($rows)) {
            throw ValidationException::withMessages(['file' => 'No usable rows found — every row was empty.']);
        }

        return $rows;
    }

    /**
     * Non-destructive: reports which rows already exist and which would be
     * newly created. Writes nothing to the database.
     */
    public function preview(array $rows): array
    {
        return $this->process($rows, write: false, actorUserId: null);
    }

    /**
     * Actually creates the missing groups, reusing the same slug-generation
     * approach as manual creation (AttributeGroupController::store()) so
     * imported rows behave identically to hand-created ones.
     *
     * @param  list<int>  $createAnyway  file lines of possible duplicates the admin confirmed
     */
    public function import(array $rows, int $actorUserId, array $createAnyway = []): array
    {
        return DB::transaction(fn () => $this->process($rows, write: true, actorUserId: $actorUserId, createAnyway: $createAnyway));
    }

    private function process(array $rows, bool $write, ?int $actorUserId, array $createAnyway = []): array
    {
        $existing = [];
        foreach (AttributeGroup::orderByDesc('id')->get() as $group) {
            $existing[NameMatcher::key($group->name)] = $group;
        }
        $knownNames = array_map(fn ($group) => $group->name, $existing);
        $confirmedLines = array_map('intval', $createAnyway);
        $seenLines = [];
        $nextSortOrder = ((int) AttributeGroup::max('sort_order')) + 10;
        $summary = ['rows' => [], 'created_count' => 0, 'existing_count' => 0, 'possible_count' => 0, 'error_count' => 0];

        foreach ($rows as $row) {
            $name = $row['name'];

            if ($name === '') {
                $summary['rows'][] = ['line' => $row['line'], 'name' => $name, 'error' => 'Name is required.'];
                $summary['error_count']++;
                continue;
            }

            if (mb_strlen($name) > 150) {
                $summary['rows'][] = ['line' => $row['line'], 'name' => $name, 'error' => 'Name is longer than 150 characters.'];
                $summary['error_count']++;
                continue;
            }

            $key = NameMatcher::key($name);

            if (isset($seenLines[$key])) {
                $summary['rows'][] = ['line' => $row['line'], 'name' => $name, 'status' => 'existing', 'note' => "Same name as line {$seenLines[$key]} — only the first is used."];
                $summary['existing_count']++;
                continue;
            }

            if (isset($existing[$key])) {
                $seenLines[$key] = $row['line'];
                $summary['rows'][] = ['line' => $row['line'], 'name' => $name, 'status' => 'existing', 'id' => $existing[$key]->id];
                $summary['existing_count']++;
                continue;
            }

            if ($row['description'] === '') {
                $summary['rows'][] = ['line' => $row['line'], 'name' => $name, 'error' => 'Description is required.'];
                $summary['error_count']++;
                continue;
            }

            $similar = NameMatcher::findSimilar($key, $knownNames);
            $seenLines[$key] = $row['line'];

            if ($similar && ! in_array((int) $row['line'], $confirmedLines, true)) {
                $summary['rows'][] = ['line' => $row['line'], 'name' => $name, 'status' => 'possible_duplicate', 'similar_to' => $similar];
                $summary['possible_count']++;
                continue;
            }

            $knownNames[$key] = $name;

            if ($write) {
                $group = AttributeGroup::create([
                    'name' => $name,
                    'slug' => $this->generateUniqueSlug($name),
                    'description' => $row['description'],
                    'sort_order' => $nextSortOrder,
                    'is_active' => true,
                    'created_by_user_id' => $actorUserId,
                ]);
                $summary['rows'][] = ['line' => $row['line'], 'name' => $name, 'status' => 'created', 'id' => $group->id];
            } else {
                $summary['rows'][] = ['line' => $row['line'], 'name' => $name, 'status' => 'would_create'];
            }
            $summary['created_count']++;

            $nextSortOrder += 10;
        }

        return $summary;
    }

    private function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'group';
        $slug = $base;
        $i = 2;

        // withTrashed: a deleted group still holds its slug in the unique index.
        while (AttributeGroup::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
