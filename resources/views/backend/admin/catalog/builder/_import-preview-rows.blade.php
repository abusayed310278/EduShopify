{{--
    The review step of the attribute and attribute group imports: counts, then
    one line per CSV row. A possible duplicate has a "create anyway" tick that
    belongs to the confirm form ($formId) outside this list.
--}}
<div class="py-4 flex flex-wrap items-center gap-2 shrink-0">
    <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
        <i class="fa-solid fa-plus"></i> {{ $preview['created_count'] }} new
    </span>
    <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full bg-gray-50 text-gray-600 border border-gray-200">
        <i class="fa-solid fa-check"></i> {{ $preview['existing_count'] }} already exist (skipped)
    </span>
    @if($preview['possible_count'] > 0)
        <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200">
            <i class="fa-solid fa-code-compare"></i> {{ $preview['possible_count'] }} possible duplicate{{ $preview['possible_count'] === 1 ? '' : 's' }} (skipped unless ticked)
        </span>
    @endif
    @if($preview['error_count'] > 0)
        <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full bg-red-50 text-red-700 border border-red-200">
            <i class="fa-solid fa-triangle-exclamation"></i> {{ $preview['error_count'] }} row error{{ $preview['error_count'] === 1 ? '' : 's' }}
        </span>
    @endif
</div>

<div class="flex-1 overflow-y-auto space-y-2 pr-1">
    @foreach($preview['rows'] as $row)
        @if(isset($row['error']))
            <div class="border border-red-100 rounded-lg px-3 py-2">
                <p class="text-xs text-red-600"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Line {{ $row['line'] }}: {{ $row['name'] }} — {{ $row['error'] }}</p>
            </div>
        @elseif($row['status'] === 'possible_duplicate')
            <label class="block border border-amber-200 bg-amber-50/40 rounded-lg px-3 py-2 cursor-pointer">
                <span class="text-xs flex flex-wrap items-center gap-2">
                    <input type="checkbox" name="create_anyway[]" value="{{ $row['line'] }}" form="{{ $formId }}" style="accent-color:var(--theme-primary)">
                    <span class="font-semibold text-amber-800">{{ $row['name'] }}</span>
                    @isset($row['input_type'])
                        <span class="text-[10px] text-gray-400 uppercase font-semibold">{{ str_replace('_', ' ', $row['input_type']) }}</span>
                    @endisset
                    <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-800">Possible duplicate</span>
                </span>
                <span class="block mt-1 text-[11px] text-gray-500">Line {{ $row['line'] }}: very similar to “{{ $row['similar_to'] }}”. Tick to create it anyway.</span>
            </label>
        @else
            <div class="border border-gray-100 rounded-lg px-3 py-2">
                <p class="text-xs flex flex-wrap items-center gap-2">
                    <span class="{{ $row['status'] === 'existing' ? 'text-gray-500' : 'text-emerald-700 font-semibold' }}">{{ $row['name'] }}</span>
                    @isset($row['group'])
                        <span class="text-[10px] text-gray-400">{{ $row['group'] }}</span>
                    @endisset
                    @isset($row['input_type'])
                        <span class="text-[10px] text-gray-400 uppercase font-semibold">{{ str_replace('_', ' ', $row['input_type']) }}</span>
                    @endisset
                    <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full {{ $row['status'] === 'would_create' ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-50 text-gray-500' }}">
                        {{ $row['status'] === 'would_create' ? 'Creates new' : 'Already exists' }}
                    </span>
                </p>
                @isset($row['note'])
                    <p class="mt-1 text-[11px] text-amber-700"><i class="fa-solid fa-circle-info mr-1"></i>Line {{ $row['line'] }}: {{ $row['note'] }}</p>
                @endisset
            </div>
        @endif
    @endforeach
</div>
