<?php

namespace App\Http\Controllers\Backend\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\AttributeGroup;
use App\Models\AttributeValue;
use App\Support\CsvFile;

/**
 * CSV exports for the Category Builder: every column as stored, plus the
 * names behind the ids (attribute_group, unit, attribute_name, values_count).
 * The import screens read the columns they know and ignore the rest, so an
 * exported file can be imported again without changing anything.
 */
class AttributeExportController extends Controller
{
    public function groups()
    {
        $this->authorize('platform.attributes.manage');

        $rows = AttributeGroup::ordered()->orderBy('id')->lazy()->map(fn (AttributeGroup $g) => [
            $g->id, $g->name, $g->slug, $g->description, $g->sort_order, $g->is_active,
            $g->created_by_user_id, $g->created_at, $g->updated_at,
        ]);

        return CsvFile::download($this->fileName('attribute_groups'), [
            'id', 'name', 'slug', 'description', 'sort_order', 'is_active',
            'created_by_user_id', 'created_at', 'updated_at',
        ], $rows);
    }

    public function attributes()
    {
        $this->authorize('platform.attributes.manage');

        $rows = Attribute::with(['attributeGroup', 'unit'])->withCount('values')->ordered()->orderBy('id')->lazy()->map(fn (Attribute $a) => [
            $a->id, $a->name, $a->slug, $a->attribute_group_id, $a->attributeGroup?->name,
            $a->input_type, $a->input_type_id, $a->unit_id, $a->unit?->name, $a->placeholder, $a->validation_rules,
            $a->allow_custom_value, $a->is_filterable, $a->is_variant, $a->is_required, $a->is_active,
            $a->sort_order, $a->values_count, $a->created_at, $a->updated_at,
        ]);

        return CsvFile::download($this->fileName('attributes'), [
            'id', 'name', 'slug', 'attribute_group_id', 'attribute_group',
            'input_type', 'input_type_id', 'unit_id', 'unit', 'placeholder', 'validation_rules',
            'allow_custom_value', 'is_filterable', 'is_variant', 'is_required', 'is_active',
            'sort_order', 'values_count', 'created_at', 'updated_at',
        ], $rows);
    }

    public function values()
    {
        $this->authorize('platform.attributes.manage');

        $rows = AttributeValue::with('attribute')->orderBy('attribute_id')->orderBy('sort_order')->orderBy('id')->lazy()->map(fn (AttributeValue $v) => [
            $v->id, $v->attribute_id, $v->attribute?->name, $v->value, $v->slug, $v->color_hex,
            $v->sort_order, $v->is_active, $v->created_at, $v->updated_at,
        ]);

        return CsvFile::download($this->fileName('attribute_values'), [
            'id', 'attribute_id', 'attribute_name', 'value', 'slug', 'color_hex',
            'sort_order', 'is_active', 'created_at', 'updated_at',
        ], $rows);
    }

    private function fileName(string $name): string
    {
        return $name.'_'.now()->format('Y-m-d').'.csv';
    }
}
