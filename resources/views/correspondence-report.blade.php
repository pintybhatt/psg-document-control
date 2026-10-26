<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: dhyana; color: #25354a; line-height: 1.6; }
        h1 { font-size: 18pt; color: #13263d; margin: 0 0 5mm; }
        h2 { font-size: 11pt; color: #087e83; margin: 6mm 0 3mm; page-break-after: avoid; }
        .workspace { font-size: 11pt; font-weight: bold; margin-bottom: 3mm; }
        .meta { font-size: 8.5pt; color: #526177; margin-bottom: 2mm; }
        .rule { border-top: 1mm solid #087e83; margin: 4mm 0 5mm; }
        table { border-collapse: collapse; width: 100%; font-size: 8.5pt; }
        thead { display: table-header-group; }
        th { background: #13263d; color: #ffffff; text-align: left; font-weight: bold; padding: 3mm 2mm; }
        td { border-bottom: 0.2mm solid #d7e0e9; padding: 3mm 2mm; vertical-align: top; }
        .even td { background: #f1f5f9; }
        .fields th { width: 30%; color: #526177; background: #f1f5f9; }
        .fields td { width: 70%; }
        p { margin: 2mm 0; }
        .empty { padding: 8mm; color: #526177; }
    </style>
</head>
<body>
    <h1>{{ $title }}{{ $detail ? ' · '.$report->label('detail', $language) : '' }}</h1>
    <div class="workspace">{{ $workspace }}</div>
    <div class="meta">{{ $report->label('generated', $language) }}: {{ $generated }}</div>
    @unless ($detail)
        <div class="meta">{{ $report->label('filters', $language) }}: {{ $filters }}</div>
        <div class="meta">{{ $report->label('total', $language) }}: {{ count($rows) }}</div>
    @endunless
    <div class="rule"></div>
    @if ($detail)
        @foreach ($sections as $section)
            <h2>{{ $section['title'] }}</h2>
            @if (!empty($section['text']))
                <p>{!! nl2br(e($section['text'])) !!}</p>
            @endif
            <table class="fields">
                @foreach ($section['fields'] as $field)
                    <tr><th>{{ $field['label'] }}</th><td>{!! nl2br(e($field['value'])) !!}</td></tr>
                @endforeach
            </table>
        @endforeach
    @else
        <table>
            <thead><tr>@foreach ($columns as $column)<th width="{{ ($columns[0] === 'internal_number' && in_array('official_number', $columns) ? [12, 12, 22, 15, 9, 9, 9, 6, 6] : [12, 23, 14, 9, 8, 9, 9, 8, 8])[$loop->index] }}%">{{ $report->label($column, $language) }}</th>@endforeach</tr></thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr class="{{ $loop->even ? 'even' : '' }}">
                        @foreach ($columns as $column)
                            <td>{!! nl2br(e($report->display($row[$column] ?? null))) !!}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($columns) }}" class="empty">{{ $report->label('empty', $language) }}</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif
</body>
</html>
