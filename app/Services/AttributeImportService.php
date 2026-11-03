<?php

namespace App\Services;

use App\Models\Attribute;
use App\Models\AttributeGroup;
use App\Models\InputType;
use App\Models\Unit;
use App\Support\Catalog\NameMatcher;
use App\Support\CsvFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bulk-creates attributes from a flat CSV (one attribute per row) — the
 * same shape as AttributeGroupImportService. Predefined values for
 * select/multi_select/color attributes are NOT part of this file; they're
 * imported separately by AttributeValueImportService, referencing the
 * attribute by name, so an admin runs this import first and the values
 * import second.
 *
 * Every row needs a name and an existing attribute group; a blank input type
 * means single-line text. Existing attributes are never changed, so an
 * exported file can be imported again safely. A row is skipped when:
 *  - its name matches an attribute or an earlier row, ignoring case, spaces
 *    and symbols ("Wi-Fi" = "WiFi") — with a note if the group or format differs;
 *  - its name is very similar to one ("Colour" / "Color") — a possible
 *    duplicate, created only if the admin ticks it in the preview ($createAnyway).
 *
 * Both preview() and import() share process() — the only difference is
 * whether missing attributes are actually written to the database ($write).
 */
class AttributeImportService
{
    private const VALID_INPUT_TYPES = ['text', 'textarea', 'number', 'select', 'multi_select', 'boolean', 'date', 'color'];

    private const DEFAULT_INPUT_TYPE = 'text';

    private const MAX_PLACEHOLDER_LENGTH = 150;

    /**
     * Parse an uploaded CSV into normalized rows. Expects a header row with
     * "name" and "attribute_group" columns; "input_type", "unit", "placeholder",
     * "is_required", "is_filterable", "is_variant" and "allow_custom_value" are
     * optional. Other columns (e.g. the rest of an exported file) are ignored.
     */
    public function parseCsv(UploadedFile $file): array
    {
        $csv = CsvFile::read($file);
        $header = $csv['header'];

        $nameCol = array_search('name', $header, true);
        if ($nameCol === false) {
            throw ValidationException::withMessages(['file' => 'The CSV must have a "name" column.']);
        }
        $groupCol = array_search('attribute_group', $header, true);
        if ($groupCol === false) {
            throw ValidationException::withMessages(['file' => 'The CSV must have an "attribute_group" column (e.g. Key Features).']);
        }
        $inputTypeCol = array_search('input_type', $header, true);
        $unitCol = array_search('unit', $header, true);
        $placeholderCol = array_search('placeholder', $header, true);
        $requiredCol = array_search('is_required', $header, true);
        $filterableCol = array_search('is_filterable', $header, true);
        $variantCol = array_search('is_variant', $header, true);
        $customValueCol = array_search('allow_custom_value', $header, true);

        $rows = [];
        foreach ($csv['lines'] as [$lineNumber, $line]) {
            $name = CsvFile::cell($line, $nameCol);
            $inputType = CsvFile::cell($line, $inputTypeCol);
            $group = CsvFile::cell($line, $groupCol);

            if ($name === '' && $inputType === '' && $group === '') {
                continue;
            }

            $rows[] = [
                'line' => $lineNumber,
                'name' => $name,
                'input_type' => $inputType,
                'attribute_group' => $group !== '' ? $group : null,
                'unit' => CsvFile::cell($line, $unitCol),
                'placeholder' => CsvFile::cell($line, $placeholderCol),
                'is_required' => $this->parseBool(CsvFile::cell($line, $requiredCol)),
                'is_filterable' => $this->parseBool(CsvFile::cell($line, $filterableCol)),
                'is_variant' => $this->parseBool(CsvFile::cell($line, $variantCol)),
                'allow_custom_value' => $this->parseBool(CsvFile::cell($line, $customValueCol)),
            ];
        }

        if (empty($rows)) {
            throw ValidationException::withMessages(['file' => 'No usable rows found — every row was empty.']);
        }

        return $rows;
    }

    /**
     * Non-destructive: reports what each row would do. Writes nothing to the
     * database.
     */
    public function preview(array $rows): array
    {
        return $this->process($rows, write: false);
    }

    /**
     * Actually creates the missing attributes.
     *
     * @param  list<int>  $createAnyway  file lines of possible duplicates the admin confirmed
     */
    public function import(array $rows, array $createAnyway = []): array
    {
        return DB::transaction(fn () => $this->process($rows, write: true, createAnyway: $createAnyway));
    }

    private function process(array $rows, bool $write, array $createAnyway = []): array
    {
        $existing = [];
        foreach (Attribute::with('attributeGroup')->orderByDesc('id')->get() as $attribute) {
            $existing[NameMatcher::key($attribute->name)] = $attribute;
        }
        $groups = [];
        foreach (AttributeGroup::orderByDesc('id')->get() as $group) {
            $groups[NameMatcher::key($group->name)] = $group;
        }
        $groupNames = array_map(fn ($group) => $group->name, $groups);
        $knownNames = array_map(fn ($attribute) => $attribute->name, $existing);
        $inputTypes = $this->inputTypeLookup();
        $inputTypeIds = InputType::pluck('id', 'code');
        $confirmedLines = array_map('intval', $createAnyway);

        $seenLines = [];
        $nextSortOrder = ((int) Attribute::max('sort_order')) + 10;
        $summary = ['rows' => [], 'created_count' => 0, 'existing_count' => 0, 'possible_count' => 0, 'error_count' => 0];

        $fail = function (array $row, string $message) use (&$summary) {
            $summary['rows'][] = ['line' => $row['line'], 'name' => $row['name'], 'error' => $message];
            $summary['error_count']++;
        };
        $skip = function (array $row, ?string $note = null, ?int $id = null) use (&$summary) {
            $summary['rows'][] = array_filter(['line' => $row['line'], 'name' => $row['name'], 'status' => 'existing', 'id' => $id, 'note' => $note]);
            $summary['existing_count']++;
        };

        foreach ($rows as $row) {
            $name = $row['name'];

            if ($name === '') {
                $fail($row, 'Name is required.');
                continue;
            }

            if (mb_strlen($name) > 150) {
                $fail($row, 'Name is longer than 150 characters.');
                continue;
            }

            $key = NameMatcher::key($name);
            $groupName = $row['attribute_group'] ?? null;
            $group = $groupName ? ($groups[NameMatcher::key($groupName)] ?? null) : null;
            $rawInputType = trim((string) ($row['input_type'] ?? ''));
            $inputType = $rawInputType === '' ? null : ($inputTypes[NameMatcher::key($rawInputType)] ?? null);

            if (isset($seenLines[$key])) {
                $skip($row, "Same name as line {$seenLines[$key]} — only the first is used.");
                continue;
            }

            if (isset($existing[$key])) {
                $seenLines[$key] = $row['line'];
                $skip($row, $this->differenceNote($existing[$key], $group, $inputType), $existing[$key]->id);
                continue;
            }

            if ($rawInputType !== '' && ! $inputType) {
                $fail($row, "Unknown input_type '{$rawInputType}' — must be one of: ".implode(', ', self::VALID_INPUT_TYPES).'.');
                continue;
            }
            $defaulted = $inputType === null;
            $inputType ??= self::DEFAULT_INPUT_TYPE;

            if (! $groupName) {
                $fail($row, 'Attribute group is required (e.g. Key Features).');
                continue;
            }

            if (! $group) {
                $hint = NameMatcher::findSimilar(NameMatcher::key($groupName), $groupNames);
                $fail($row, "Attribute group '{$groupName}' not found — create it first on the Attribute Groups tab.".($hint ? " Did you mean '{$hint}'?" : ''));
                continue;
            }

            $unitId = null;
            if (($row['unit'] ?? '') !== '') {
                $unitId = $this->findUnitId($row['unit']);
                if (! $unitId) {
                    $fail($row, "Unit '{$row['unit']}' not found — add it under Units first.");
                    continue;
                }
            }

            if (mb_strlen($row['placeholder'] ?? '') > self::MAX_PLACEHOLDER_LENGTH) {
                $fail($row, 'Placeholder is longer than '.self::MAX_PLACEHOLDER_LENGTH.' characters.');
                continue;
            }

            $similar = NameMatcher::findSimilar($key, $knownNames);
            $seenLines[$key] = $row['line'];

            if ($similar && ! in_array((int) $row['line'], $confirmedLines, true)) {
                $summary['rows'][] = ['line' => $row['line'], 'name' => $name, 'status' => 'possible_duplicate', 'similar_to' => $similar, 'input_type' => $inputType];
                $summary['possible_count']++;
                continue;
            }

            $knownNames[$key] = $name;

            if ($write) {
                $attribute = Attribute::create([
                    'attribute_group_id' => $group->id,
                    'name' => $name,
                    'slug' => $this->generateUniqueSlug($name),
                    'input_type' => $inputType,
                    'input_type_id' => $inputTypeIds[$inputType] ?? null,
                    'allow_custom_value' => $row['allow_custom_value'] ?? false,
                    'unit_id' => $unitId,
                    'placeholder' => ($row['placeholder'] ?? '') !== '' ? $row['placeholder'] : null,
                    'is_required' => $row['is_required'],
                    'is_filterable' => $row['is_filterable'],
                    'is_variant' => $row['is_variant'],
                    'is_active' => true,
                    'sort_order' => $nextSortOrder,
                ]);
                $summary['rows'][] = ['line' => $row['line'], 'name' => $name, 'status' => 'created', 'id' => $attribute->id, 'input_type' => $inputType];
            } else {
                $summary['rows'][] = array_filter([
                    'line' => $row['line'], 'name' => $name, 'status' => 'would_create', 'input_type' => $inputType,
                    'group' => $group->name, 'note' => $defaulted ? 'No input type given — using single-line text.' : null,
                ]);
            }
            $summary['created_count']++;

            $nextSortOrder += 10;
        }

        return $summary;
    }

    /** Accepted input_type text → code: the code itself or the input type's display name. */
    private function inputTypeLookup(): array
    {
        $lookup = [];
        foreach (self::VALID_INPUT_TYPES as $code) {
            $lookup[NameMatcher::key($code)] = $code;
        }
        foreach (InputType::whereIn('code', self::VALID_INPUT_TYPES)->get() as $type) {
            $lookup[NameMatcher::key($type->name)] = $type->code;
        }

        return $lookup;
    }

    /** A global unit by name or exact symbol. */
    private function findUnitId(string $text): ?int
    {
        $units = Unit::global()->get();
        $key = NameMatcher::key($text);

        return ($units->first(fn (Unit $unit) => NameMatcher::key($unit->name) === $key)
            ?? $units->firstWhere('symbol', $text))?->id;
    }

    /** Tells the admin when the file disagrees with the existing attribute (which stays as it is). */
    private function differenceNote(Attribute $attribute, ?AttributeGroup $group, ?string $inputType): ?string
    {
        $differences = [];
        if ($group && (int) $attribute->attribute_group_id !== (int) $group->id) {
            $differences[] = 'group is '.($attribute->attributeGroup?->name ?? 'none').", file says {$group->name}";
        }
        if ($inputType && $attribute->input_type !== $inputType) {
            $differences[] = "input type is {$attribute->input_type}, file says {$inputType}";
        }

        return $differences ? 'Not changed: '.implode('; ', $differences).'.' : null;
    }

    private function parseBool(mixed $raw): bool
    {
        return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes'], true);
    }

    private function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'attribute';
        $slug = $base;
        $i = 2;

        while (Attribute::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
